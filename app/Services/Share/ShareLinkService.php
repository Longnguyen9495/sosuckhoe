<?php

namespace App\Services\Share;

use App\Models\Document;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\PatientCondition;
use App\Models\PrescriptionItem;
use App\Models\Reading;
use App\Models\ScheduleItem;
use App\Models\ShareLink;
use App\Models\ShareLinkView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dedup\DuplicateMatcher;
use App\Support\TenantContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Link chia sẻ hồ sơ chỉ xem, do người bệnh tự tạo (gửi dược sĩ, bác sĩ, người thân).
 *  - Mã link ngẫu nhiên 40 ký tự, chỉ lưu SHA-256; link dạng /#/s/{mã} nên mã không lọt vào log máy chủ.
 *  - PIN 4–6 số do người bệnh đặt (tuỳ chọn), lưu bcrypt; chặn PIN dễ đoán; sai 5 lần khoá 15 phút.
 *  - Có hạn dùng, thu hồi được; mỗi lượt mở ghi thời điểm, IP đã che, loại thiết bị.
 *  - Không bao giờ trả SĐT, CCCD, BHYT; họ tên mặc định chỉ hiện chữ cái đầu.
 */
final class ShareLinkService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public const SESSION_MINUTES = 30;

    public const EXPIRY_DAYS = [1, 7, 30];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array{link: ShareLink, token: string, url: string, qr_svg: string} */
    public function create(Patient $patient, User $user, array $o): array
    {
        $token = Str::random(40);
        $link = ShareLink::create([
            'patient_id' => $patient->id,
            'created_by' => $user->id,
            'label' => $o['label'],
            'token_hash' => hash('sha256', $token),
            'pin_hash' => ($o['pin'] ?? null) ? Hash::make($o['pin']) : null,
            'show_full_name' => (bool) ($o['show_full_name'] ?? false),
            'include_documents' => (bool) ($o['include_documents'] ?? false),
            'expires_at' => now()->addDays((int) $o['expires_in_days']),
            'consented_at' => now(),
        ]);
        $url = $this->url($token);

        return ['link' => $link, 'token' => $token, 'url' => $url, 'qr_svg' => $this->qr($url)];
    }

    public function url(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/#/s/'.$token;
    }

    /**
     * Lý do PIN bị từ chối (dễ đoán), hoặc null nếu dùng được.
     * Chặn: không phải 4–6 chữ số, một số lặp lại (1111), dãy liên tiếp (1234, 9876), cặp lặp (1212),
     * năm sinh, ngày-tháng sinh, 4 số cuối SĐT của người tạo.
     */
    public static function weakPinReason(string $pin, Patient $patient, User $user): ?string
    {
        if (! preg_match('/^\d{4,6}$/', $pin)) {
            return 'PIN gồm 4 đến 6 chữ số.';
        }
        $digits = array_map('intval', str_split($pin));
        $steps = array_unique(array_map(fn ($i) => $digits[$i + 1] - $digits[$i], range(0, count($digits) - 2)));
        if (count(array_unique($digits)) === 1 || (count($steps) === 1 && in_array(reset($steps), [1, -1], true))) {
            return 'PIN quá dễ đoán (số lặp hoặc liên tiếp). Chọn số khác.';
        }
        if (preg_match('/^(\d{2})\1+$/', $pin) || in_array($pin, ['2580', '0852', '1122', '6969', '1004', '2000', '2468', '1357'], true)) {
            return 'PIN quá phổ biến. Chọn số khác.';
        }
        $birth = $patient->birth_date ?? $user->birth_date;
        $avoid = array_filter([
            $patient->birth_year ? (string) $patient->birth_year : null,
            $birth ? $birth->format('Y') : null,
            $birth ? $birth->format('dm') : null,
            $birth ? $birth->format('dmy') : null,
            $birth ? $birth->format('dmY') : null,
            $user->phone ? substr(preg_replace('/\D/', '', $user->phone), -4) : null,
            $user->phone ? substr(preg_replace('/\D/', '', $user->phone), -6) : null,
        ]);
        if (in_array($pin, $avoid, true)) {
            return 'Không dùng năm sinh, ngày sinh hay số cuối điện thoại làm PIN.';
        }

        return null;
    }

    /** PIN ngẫu nhiên 4 số, không dễ đoán — gợi ý sẵn cho người bệnh. */
    public static function suggestPin(Patient $patient, User $user): string
    {
        do {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::weakPinReason($pin, $patient, $user) !== null);

        return $pin;
    }

    /** Link còn hiệu lực theo mã (null nếu sai mã / hết hạn / đã thu hồi). Đặt luôn ngữ cảnh tenant của link. */
    public function resolve(string $token): ?ShareLink
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }
        $link = ShareLink::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first();
        if ($link === null || ! $link->isActive()) {
            return null;
        }
        $this->tenants->set(Tenant::findOrFail($link->tenant_id));

        return $link;
    }

    /**
     * Mở link: kiểm PIN (nếu có), ghi lượt xem, cấp phiên xem 30 phút để tải ảnh phiếu.
     *
     * @return array{ok: bool, session?: string, error?: string, retry_after?: int}
     */
    public function open(ShareLink $link, ?string $pin, Request $request): array
    {
        if ($link->locked_until !== null && $link->locked_until->isFuture()) {
            return ['ok' => false, 'error' => 'locked', 'retry_after' => (int) ceil(now()->diffInSeconds($link->locked_until) / 60)];
        }
        if ($link->pin_hash !== null) {
            if ($pin === null || $pin === '') {
                return ['ok' => false, 'error' => 'pin_required'];
            }
            if (! Hash::check($pin, $link->pin_hash)) {
                $link->failed_attempts++;
                if ($link->failed_attempts >= self::MAX_ATTEMPTS) {
                    $link->forceFill(['failed_attempts' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES), 'last_lockout_at' => now()])->save();

                    return ['ok' => false, 'error' => 'locked', 'retry_after' => self::LOCK_MINUTES];
                }
                $link->save();

                return ['ok' => false, 'error' => 'pin_wrong', 'attempts_left' => self::MAX_ATTEMPTS - $link->failed_attempts];
            }
        }

        $link->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'view_count' => $link->view_count + 1, 'last_viewed_at' => now()])->save();
        ShareLinkView::create(['share_link_id' => $link->id, 'viewed_at' => now(), 'ip_hint' => self::maskIp($request->ip()), 'device' => self::device((string) $request->userAgent())]);

        $session = Str::random(40);
        Cache::put('share-session:'.hash('sha256', $session), $link->id, now()->addMinutes(self::SESSION_MINUTES));

        return ['ok' => true, 'session' => $session];
    }

    /** Link của một phiên xem (cho tải ảnh phiếu), null nếu phiên hết hạn hoặc link đã bị thu hồi. */
    public function fromSession(?string $session): ?ShareLink
    {
        if ($session === null || ! preg_match('/^[A-Za-z0-9]{40}$/', $session)) {
            return null;
        }
        $id = Cache::get('share-session:'.hash('sha256', $session));
        $link = $id ? ShareLink::withoutGlobalScopes()->find($id) : null;
        if ($link === null || ! $link->isActive()) {
            return null;
        }
        $this->tenants->set(Tenant::findOrFail($link->tenant_id));

        return $link;
    }

    /** Nội dung trang chia sẻ — chỉ dữ liệu y khoa cần cho người xem, không có thông tin liên lạc / định danh. */
    public function summary(ShareLink $link): array
    {
        $patient = Patient::findOrFail($link->patient_id);
        $today = CarbonImmutable::today();
        $birth = $patient->birth_date ?? ($patient->birth_year ? CarbonImmutable::create($patient->birth_year) : null);

        // Bệnh nền, gộp trùng (cùng tên sau chuẩn hoá / cùng mã ICD).
        $conditions = [];
        foreach (PatientCondition::where('patient_id', $patient->id)->orderBy('created_at')->pluck('notes') as $notes) {
            $title = trim(explode("\n", (string) $notes)[0]);
            if ($title !== '' && ! collect($conditions)->contains(fn ($c) => DuplicateMatcher::sameDiagnosis($c, $title))) {
                $conditions[] = $title;
            }
        }

        $times = ScheduleItem::where('patient_id', $patient->id)
            ->whereDate('starts_at', '<=', $today->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today->toDateString()))
            ->orderBy('scheduled_time')->get(['prescription_item_id', 'scheduled_time', 'amount_text'])->groupBy('prescription_item_id');
        $medications = PrescriptionItem::query()
            ->join('prescriptions', 'prescriptions.id', '=', 'prescription_items.prescription_id')
            ->where('prescription_items.patient_id', $patient->id)
            ->where('prescriptions.status', 'active')
            ->where(fn ($q) => $q->whereNull('prescriptions.ends_at')->orWhere('prescriptions.ends_at', '>=', $today->toDateString()))
            ->orderBy('prescriptions.prescribed_at')
            ->get(['prescription_items.id', 'prescription_items.drug_name_snapshot', 'prescription_items.dose_text', 'prescription_items.usage_rule', 'prescription_items.is_long_term', 'prescriptions.doctor_name', 'prescriptions.prescribed_at'])
            ->map(function ($i) use ($times) {
                $rule = is_array($i->usage_rule) ? $i->usage_rule : json_decode((string) $i->usage_rule, true);

                return [
                    'drug' => $i->drug_name_snapshot,
                    'how_to_use' => $i->dose_text,
                    'type' => $rule['type'] ?? 'medication',
                    'times' => $times->get($i->id, collect())->map(fn ($t) => ['time' => substr((string) $t->scheduled_time, 0, 5), 'amount' => $t->amount_text])->values()->all(),
                    'long_term' => (bool) $i->is_long_term,
                    'prescriber' => $i->doctor_name,
                    'prescribed_at' => substr((string) $i->prescribed_at, 0, 10),
                ];
            })->values()->all();

        $labs = LabResult::where('patient_id', $patient->id)
            ->whereDate('measured_at', '>=', $today->subYear()->toDateString())
            ->orderByDesc('measured_at')->get()
            ->unique(fn (LabResult $r) => DuplicateMatcher::labName($r->metric))
            ->map(function (LabResult $r) {
                $parts = explode(' — ', (string) $r->metric, 2);

                return [
                    'group' => count($parts) === 2 ? $parts[0] : 'Kết quả',
                    'name' => count($parts) === 2 ? $parts[1] : $r->metric,
                    'value' => $r->value,
                    'unit' => $r->unit,
                    'reference' => $r->reference_range,
                    'flag' => $r->flag,
                    'date' => substr((string) $r->measured_at, 0, 10),
                ];
            })->values()->all();

        $readings = Reading::where('patient_id', $patient->id)
            ->where('measured_at', '>=', $today->subDays(30))
            ->orderBy('measured_at')->get()
            ->map(fn (Reading $r) => [
                'type' => $r->type,
                'context' => $r->context,
                'values' => $r->values,
                'at' => substr((string) $r->measured_at, 0, 16),
                // Mức đánh giá theo ngưỡng của người bệnh (good / warn / red …) — chỉ lấy mức, không lấy ghi chú.
                'evaluation' => (is_string($r->evaluation) ? json_decode($r->evaluation, true) : $r->evaluation)['level'] ?? null,
            ])
            ->values()->all();

        $documents = $link->include_documents
            ? Document::where('patient_id', $patient->id)->whereNull('duplicate_of_id')->orderByDesc('document_date')->get()
                ->map(fn (Document $d) => ['id' => $d->id, 'type' => $d->type, 'title' => $d->analysis['title'] ?? $d->department ?? 'Phiếu khám', 'date' => $d->document_date?->toDateString()])->values()->all()
            : [];

        return [
            'label' => $link->label,
            'expires_at' => $link->expires_at->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
            'patient' => [
                'name' => $link->show_full_name ? $patient->full_name : self::initials($patient->full_name),
                'age' => $birth ? (int) $birth->diffInYears($today) : null,
                'gender' => $patient->gender,
                'allergies' => $patient->allergies,
            ],
            'conditions' => $conditions,
            'medications' => $medications,
            'lab_results' => $labs,
            'readings' => $readings,
            'documents' => $documents,
        ];
    }

    /** "Trần Thị Dung" → "D." (tên gọi viết tắt), đủ để người xem xưng hô mà không lộ họ tên. */
    public static function initials(?string $name): string
    {
        $words = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $last = end($words) ?: '';

        return $last === '' ? 'Người bệnh' : mb_strtoupper(mb_substr($last, 0, 1)).'.';
    }

    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
        }
        $p = explode('.', $ip);

        return count($p) === 4 ? "{$p[0]}.{$p[1]}.{$p[2]}.x" : null;
    }

    /** Loại thiết bị + trình duyệt ngắn gọn, không lưu nguyên User-Agent. */
    public static function device(string $ua): string
    {
        $kind = preg_match('/iPhone|Android.+Mobile|Mobile/i', $ua) ? 'Điện thoại' : (preg_match('/iPad|Tablet|Android/i', $ua) ? 'Máy tính bảng' : 'Máy tính');
        $app = match (true) {
            (bool) preg_match('/Zalo/i', $ua) => 'Zalo',
            (bool) preg_match('/FBAN|FBAV|Messenger/i', $ua) => 'Facebook',
            (bool) preg_match('/Edg\//', $ua) => 'Edge',
            (bool) preg_match('/CriOS|Chrome\//', $ua) => 'Chrome',
            (bool) preg_match('/Safari\//', $ua) => 'Safari',
            (bool) preg_match('/Firefox\//', $ua) => 'Firefox',
            default => 'Trình duyệt khác',
        };

        return "{$kind} · {$app}";
    }

    private function qr(string $url): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd())))->writeString($url);
    }
}
