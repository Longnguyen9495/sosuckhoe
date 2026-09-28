<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Services\Dedup\ActiveMedications;
use App\Services\Schedule\PatientScheduleOrchestrator;
use App\Services\Schedule\UsageRuleException;
use App\Services\Schedule\UsageRuleParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Nhập đơn thuốc mới: xem trước lịch, lưu, kết thúc đơn cũ.
 * Chỉ quyết định GIỜ dùng; liều và số lượng lấy nguyên văn từ đơn.
 */
final class PrescriptionDraftController extends Controller
{
    public function __construct(
        private readonly UsageRuleParser $parser,
        private readonly PatientScheduleOrchestrator $orchestrator,
        private readonly ActiveMedications $activeMedications,
    ) {}

    private static function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.drug_id' => ['nullable', 'string', 'exists:drugs,id'],
            'items.*.drug_name' => ['required', 'string', 'max:255'],
            'items.*.dose_text' => ['required', 'string', 'max:500'],
            'items.*.usage_rule' => ['nullable', 'array'],
            'items.*.prescribed_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.purchased_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity_unit' => ['nullable', 'string', 'max:60'],
            'items.*.is_long_term' => ['nullable', 'boolean'],
        ];
    }

    /**
     * POST /patients/{patient}/prescriptions/preview — lịch một ngày từ đơn nháp, chưa lưu.
     */
    public function preview(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate(self::itemRules() + ['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = CarbonImmutable::parse($validated['date'] ?? now()->toDateString());

        $routine = $this->orchestrator->activeRoutine($patient->id, $patient->tenant_id, $date);
        if ($routine === null) {
            return response()->json(['error' => 'MISSING_ROUTINES', 'message' => 'Chưa có giờ sinh hoạt của người bệnh.'], 400);
        }

        $items = [];
        $warnings = [];
        foreach ($validated['items'] as $index => $item) {
            try {
                $rule = $this->parser->parse($item['usage_rule'] ?? null);
            } catch (UsageRuleException $e) {
                $warnings[] = ['index' => $index, 'drug_name' => $item['drug_name'], 'message' => 'Chưa có giờ dùng — cần chọn giờ.'];

                continue;
            }
            foreach ($rule['doses'] as $dose) {
                $items[] = [
                    'index' => $index,
                    'scheduled_time' => isset($dose['fixed_time'])
                        ? $dose['fixed_time']
                        : PatientScheduleOrchestrator::timeFromRoutine($routine, $dose['anchor'], $dose['offset_min'] ?? 0),
                    'title' => $item['drug_name'],
                    'amount_text' => $dose['amount_text'],
                    'type' => $rule['type'],
                ];
            }
        }
        usort($items, fn ($a, $b) => [$a['scheduled_time'], $a['title']] <=> [$b['scheduled_time'], $b['title']]);

        return response()->json(['date' => $date->toDateString(), 'routine' => $routine, 'items' => $items, 'warnings' => $warnings]);
    }

    /**
     * POST /patients/{patient}/prescriptions — lưu đơn và sinh lịch từ ngày bắt đầu.
     */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        $validated = $request->validate(self::itemRules() + [
            'doctor_name' => ['nullable', 'string', 'max:255'],
            'prescribed_at' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            // Phiếu (ảnh đơn) mà AI đã đọc ra các thuốc này.
            'document_id' => ['nullable', 'string', Rule::exists('documents', 'id')->where('patient_id', $patient->id)],
            // Người dùng đã xác nhận muốn thêm dù thuốc đang có trong đơn khác.
            'allow_duplicates' => ['nullable', 'boolean'],
        ]);

        // Không đưa cùng một thuốc vào lịch hai lần (VD cùng đơn chụp nhiều ảnh): báo lại để người dùng quyết định.
        if (! ($validated['allow_duplicates'] ?? false)) {
            $current = $this->activeMedications->items($patient->id);
            $duplicates = collect($validated['items'])
                ->map(fn (array $item) => [$item['drug_name'], $this->activeMedications->match($current, $item['drug_name'])])
                ->filter(fn ($pair) => $pair[1] !== null)
                ->map(fn ($pair) => ['drug_name' => $pair[0], 'existing' => $pair[1]->drug_name, 'label' => ActiveMedications::label($pair[1])])
                ->values();
            if ($duplicates->isNotEmpty()) {
                return response()->json([
                    'code' => 'DUPLICATE_MEDICATION',
                    'message' => 'Thuốc đã có trong đơn đang dùng: '.$duplicates->pluck('drug_name')->unique()->join(', ').'.',
                    'duplicates' => $duplicates,
                ], 409);
            }
        }

        $prescription = DB::transaction(function () use ($validated, $patient) {
            $prescription = Prescription::create([
                'patient_id' => $patient->id,
                'doctor_name' => $validated['doctor_name'] ?? null,
                'prescribed_at' => $validated['prescribed_at'],
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'] ?? null,
                'document_id' => $validated['document_id'] ?? null,
                'status' => 'active',
            ]);

            foreach ($validated['items'] as $item) {
                PrescriptionItem::create([
                    'patient_id' => $patient->id,
                    'prescription_id' => $prescription->id,
                    'drug_id' => $item['drug_id'] ?? null,
                    'drug_name_snapshot' => $item['drug_name'],
                    'dose_text' => $item['dose_text'],
                    'usage_rule' => $item['usage_rule'] ?? null,
                    'prescribed_quantity' => $item['prescribed_quantity'] ?? null,
                    'purchased_quantity' => $item['purchased_quantity'] ?? null,
                    'quantity_unit' => $item['quantity_unit'] ?? null,
                    'is_long_term' => (bool) ($item['is_long_term'] ?? false),
                    'requires_manual_time' => empty($item['usage_rule']),
                ]);
            }

            if (! empty($validated['document_id'])) {
                Document::whereKey($validated['document_id'])->update(['prescription_id' => $prescription->id]);
            }

            return $prescription;
        });

        $result = $this->orchestrator->regenerateSchedule($patient->id, CarbonImmutable::parse($validated['starts_at']), $patient->tenant_id);

        return response()->json([
            'prescription_id' => $prescription->id,
            'schedule_items_created' => $result['created'],
            'manual_items' => $result['manual_items'],
        ], 201);
    }

    /**
     * POST /patients/{patient}/prescriptions/{prescription}/close — kết thúc đơn hôm nay; lịch từ mai không còn thuốc của đơn này.
     */
    public function close(Request $request, Patient $patient, Prescription $prescription): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'continued_item_ids' => ['array'],
            'continued_item_ids.*' => ['string', Rule::exists('prescription_items', 'id')->where('prescription_id', $prescription->id)],
        ]);

        $prescription->update(['status' => 'closed', 'ends_at' => now()->toDateString()]);
        $this->orchestrator->regenerateFromTomorrow($patient);

        // Thuốc được chọn "tiếp tục" do màn hình nhập đơn mới chép sang đơn mới.
        return response()->json([
            'closed_id' => $prescription->id,
            'continued_item_ids' => $validated['continued_item_ids'] ?? [],
        ]);
    }
}
