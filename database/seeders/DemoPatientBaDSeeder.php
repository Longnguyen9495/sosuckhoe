<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DefaultPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoPatientBaDSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::query()->firstOrCreate(
                ['phone' => '0900000001'],
                ['name' => 'Người chăm sóc demo', 'phone_verified_at' => now()],
            );
            $tenantId = $this->stableId('tenants', ['name' => 'Gia đình bà D.'], ['type' => 'family', 'plan' => 'free', 'status' => 'active']);
            $patientId = $this->stableId('patients', ['tenant_id' => $tenantId, 'full_name' => 'Bà D.'], [
                'birth_year' => 1968, 'gender' => 'female', 'allergies' => 'Không ghi nhận dị ứng thuốc trong hồ sơ nguồn.', 'timezone' => 'Asia/Ho_Chi_Minh',
            ]);

            $this->stableId('tenant_members', ['tenant_id' => $tenantId, 'user_id' => $user->getKey()], ['role' => 'owner']);
            $this->stableId('patient_access', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'user_id' => $user->getKey()], ['role' => 'caregiver']);
            $this->stableId('patient_routines', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'effective_from' => '2026-09-27'], [
                'wake_time' => '06:00', 'breakfast_time' => '07:00', 'lunch_time' => '12:00', 'dinner_time' => '18:30', 'sleep_time' => '22:00',
            ]);

            $this->seedConditions($tenantId, $patientId);
            $this->seedThresholds($tenantId, $patientId);
            $this->seedPrescriptions($tenantId, $patientId);
            $this->seedEvents($tenantId, $patientId);
            $documents = $this->seedDocuments($tenantId, $patientId);
            $this->seedLabs($tenantId, $patientId, $documents['IMG_1313']);
            $this->seedQuestions($tenantId, $patientId, (string) $user->getKey());
            $this->seedLocalDemoAccounts($tenantId, $patientId);
            $this->seedScheduleAndMonitoring($tenantId, $patientId);
        });
    }

    private function seedLocalDemoAccounts(string $tenantId, string $patientId): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $doctor = User::query()->firstOrCreate(
            ['phone' => '0900000002'],
            ['name' => 'Bác sĩ demo', 'phone_verified_at' => now()],
        );
        $doctor->forceFill([
            'two_factor_secret' => Crypt::encryptString('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'two_factor_confirmed_at' => $doctor->two_factor_confirmed_at ?? now(),
        ])->save();

        // Vai trò bác sĩ thuộc phạm vi từng bệnh nhân; tenant_members hiện chỉ có owner/member.
        $this->stableId('tenant_members', ['tenant_id' => $tenantId, 'user_id' => $doctor->getKey()], ['role' => 'member']);
        $this->stableId('patient_access', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'user_id' => $doctor->getKey()], ['role' => 'doctor']);

        User::query()->firstOrCreate(
            ['phone' => '0900000003'],
            ['name' => 'Tài khoản đăng ký demo', 'phone_verified_at' => now()],
        );

        // Chỉ môi trường local/testing: mật khẩu mặc định = tên (bỏ dấu, liền) + 4 số cuối SĐT.
        User::query()->whereIn('phone', ['0900000001', '0900000002', '0900000003'])->whereNull('password')->get()
            ->each(fn (User $u) => $u->forceFill(['password' => DefaultPassword::for($u->name, $u->phone)])->save());
    }

    private function seedConditions(string $tenantId, string $patientId): void
    {
        $conditions = [
            ['Đái tháo đường típ 2 (E11.9) — đang điều trị > 10 năm', 'diabetes_insulin', 'high', 'Chưa kiểm soát. HbA1c 10,16%; đường huyết đói 8,8 mmol/L.'],
            ['Viêm gan virus B mạn (B18.19) + men gan tăng', 'hepatitis_b_treatment', 'high', 'Bắt đầu điều trị kháng virus lâu dài theo hồ sơ nguồn.'],
            ['Gan nhiễm mỡ (siêu âm: nhu mô tăng âm)', null, 'normal', 'Theo dõi. Liên quan đái tháo đường và men gan tăng.'],
            ['Tăng huyết áp (I10) + rối loạn mỡ máu hỗn hợp (E78.2)', 'hypertension', 'normal', 'Duy trì thuốc cũ theo lời dặn trong hồ sơ; thuốc cũ không có tên trong ba đơn mới.'],
            ['Nghi nhiễm trùng tiết niệu (N39.0)', null, 'normal', 'Bạch cầu niệu 100 Leu/µL; hồ sơ ghi cần cấy nước tiểu.'],
            ['Tăng acid uric máu (E79.0) — chưa có gút', 'lipid_uric_acid', 'normal', 'Acid uric 351 µmol/L, tăng nhẹ.'],
            ['Thoái hóa cột sống cổ + viêm quanh khớp vai 2 bên + thiếu vitamin D', null, 'normal', 'Điều trị phục hồi chức năng 30 ngày theo hồ sơ.'],
            ['X-quang ngực: nốt mờ hạ đòn trái + quai động mạch chủ vồng', null, 'high', 'Hồ sơ ghi cần khám chuyên khoa Hô hấp.'],
            ['Công thức máu: hồng cầu nhỏ nhược sắc nhưng số lượng cao', null, 'normal', 'Chưa có chẩn đoán trên phiếu; câu hỏi xét nghiệm thêm được lưu ở mục câu hỏi bác sĩ.'],
            ['Nang thận phải 11 mm (thành mỏng, dịch trong)', null, 'normal', 'Chức năng thận trong hồ sơ: creatinin 59 µmol/L, eGFR 101,4.'],
        ];

        foreach ($conditions as [$label, $templateCode, $priority, $detail]) {
            $templateId = $templateCode === null ? null : DB::table('condition_templates')->where('code', $templateCode)->value('id');
            $this->stableId('patient_conditions', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'notes' => $label], [
                'condition_template_id' => $templateId, 'diagnosed_at' => '2026-09-26', 'priority' => $priority,
                'notes' => $label."\n".$detail,
            ], ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'notes' => $label."\n".$detail]);
        }
    }

    private function seedThresholds(string $tenantId, string $patientId): void
    {
        $thresholds = [
            ['blood_glucose', 'pre_meal', ['target' => [4.4, 7.2], 'attention' => [[3.9, 4.4], [7.2, 10]], 'red' => ['below' => 3.9, 'above' => 10, 'critical_above' => 16.7]]],
            ['blood_glucose', 'post_meal_2h', ['target_below' => 10, 'attention' => [10, 16.7], 'red' => ['below' => 3.9, 'above' => 16.7]]],
            ['blood_pressure', 'general', ['target_below' => ['systolic' => 130, 'diastolic' => 80], 'red_at_or_above' => ['systolic' => 160, 'diastolic' => 100], 'red_below' => ['systolic' => 90, 'diastolic' => 60]]],
            ['heart_rate', 'resting', ['target' => [60, 100], 'attention' => [100, 120], 'red' => ['below' => 50, 'above' => 120]]],
        ];
        foreach ($thresholds as [$metric, $context, $ranges]) {
            $this->stableId('patient_thresholds', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'metric' => $metric, 'context' => $context], [
                'ranges' => json_encode($ranges, JSON_UNESCAPED_UNICODE), 'source' => 'template', 'confirmed_by' => null, 'confirmed_at' => null,
            ]);
        }
    }

    private function seedPrescriptions(string $tenantId, string $patientId): void
    {
        $prescriptions = [
            ['TS Nguyễn Thị Thanh Thủy (Nội tiết)', '2026-09-26', null, [
                ['Janumet', 'Janumet 50/850 mg', 'Sáng 1 viên, tối 1 viên — SAU ăn', 60, null, 'viên', true],
                ['NovoMix 30 FlexPen', 'NovoMix 30 FlexPen', 'Sáng 16 UI, tối 14 UI — tiêm dưới da NGAY TRƯỚC ăn 5 phút', 1200, null, 'UI (4 bút × 300 UI)', true],
                ['Kim NovoFine 31G', 'Kim NovoFine 31G · 6 mm', 'Thay kim sau 1–2 lần tiêm', 30, null, 'cái', true],
                ['Esserose', 'Esserose 450 mg', 'Sáng 1 viên, tối 1 viên — sau ăn', 60, null, 'viên', true],
            ]],
            ['BSCKII Nguyễn Danh Đức (Nội chung)', '2026-09-26', null, [
                ['Hepazid', 'Hepazid 25 mg', '1 viên/ngày sau ăn — ĐÚNG GIỜ, hằng ngày, KHÔNG tự bỏ thuốc', 90, null, 'viên', true],
                ['Livosil', 'Livosil 140 mg', 'Sáng 1 viên, tối 1 viên — sau ăn', 180, 90, 'viên', true],
            ]],
            ['PGS.TS Nguyễn Thị Kim Liên (PHCN)', '2026-09-26', '2026-10-26', [
                ['Etiheso', 'Etiheso 40 mg', '1 viên buổi sáng, TRƯỚC ăn 1 giờ', 30, null, 'viên', false],
                ['Celebrex', 'Celebrex 200 mg', '1 viên/ngày sau ăn sáng no', 30, null, 'viên', false],
                ['Abricotis', 'Abricotis', 'Sáng 1 viên, trưa 1 viên — sau ăn', 60, null, 'viên', false],
                ['Oztis', 'Oztis', 'Sáng 1 viên, tối 1 viên — sau ăn', 60, null, 'viên', false],
                ['Myopain', 'Myopain 50 mg', 'Sáng 1 viên, tối 1 viên — sau ăn', 60, null, 'viên', false],
                ['Gel Nociceptol', 'Gel Nociceptol 120 ml', 'Bôi vùng đau 3 lần/ngày', 1, null, 'tuýp', false],
            ]],
        ];

        foreach ($prescriptions as [$doctor, $date, $endsAt, $items]) {
            $prescriptionId = $this->stableId('prescriptions', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'doctor_name' => $doctor, 'prescribed_at' => $date], [
                'document_id' => null, 'starts_at' => '2026-09-27', 'ends_at' => $endsAt, 'status' => 'active',
            ]);
            foreach ($items as [$brand, $snapshot, $dose, $prescribed, $purchased, $unit, $longTerm]) {
                $drugId = DB::table('drugs')->where('brand_name', $brand)->value('id');
                $rule = self::usageRules()[$brand] ?? null;
                $this->stableId('prescription_items', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'prescription_id' => $prescriptionId, 'drug_name_snapshot' => $snapshot], [
                    'drug_id' => $drugId, 'dose_text' => $dose,
                    'usage_rule' => $rule === null ? null : json_encode($rule, JSON_UNESCAPED_UNICODE),
                    'prescribed_quantity' => $prescribed, 'purchased_quantity' => $purchased, 'quantity_unit' => $unit,
                    'is_long_term' => $longTerm, 'requires_manual_time' => $rule === null,
                ]);
            }
        }
    }

    /**
     * Quy tắc giờ dùng lấy từ 3 đơn (PLAN.md mục 3 và 4.1). Chỉ mã hoá GIỜ; lượng dùng giữ nguyên văn.
     * units_per_day chỉ dùng để tính ngày hết thuốc (tồn kho), không phải liều.
     */
    public static function usageRules(): array
    {
        $dose = fn (string $anchor, int $offset, string $amount) => ['anchor' => $anchor, 'offset_min' => $offset, 'amount_text' => $amount];
        $daily = ['type' => 'daily'];

        return [
            'Janumet' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 2, 'purpose' => 'Hạ đường huyết (thuốc uống)', 'doses' => [$dose('breakfast', 30, '1 viên'), $dose('dinner', 30, '1 viên')]],
            'NovoMix 30 FlexPen' => ['type' => 'insulin', 'days' => $daily, 'units_per_day' => 34, 'purpose' => 'Insulin — kiểm soát đường huyết', 'warning' => 'Bút chưa dùng để ngăn mát 2–8 °C; bút đang dùng dưới 30 °C, tối đa 4 tuần. Tiêm ngay trước ăn 5 phút.', 'doses' => [$dose('breakfast', -5, '16 UI'), $dose('dinner', -5, '14 UI')]],
            'Kim NovoFine 31G' => ['type' => 'supply', 'days' => $daily, 'units_per_day' => 1, 'purpose' => 'Kim cho bút NovoMix — thay sau 1–2 lần tiêm', 'doses' => []],
            'Esserose' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 2, 'purpose' => 'Hỗ trợ gan nhiễm mỡ', 'doses' => [$dose('breakfast', 30, '1 viên'), $dose('dinner', 30, '1 viên')]],
            'Hepazid' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 1, 'purpose' => 'Thuốc kháng virus viêm gan B — dùng lâu dài', 'warning' => 'Uống cùng một giờ mỗi ngày, không tự ngừng thuốc.', 'doses' => [['fixed_time' => '12:30', 'amount_text' => '1 viên']]],
            'Livosil' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 2, 'purpose' => 'Hỗ trợ bảo vệ tế bào gan', 'doses' => [$dose('breakfast', 30, '1 viên'), $dose('dinner', 30, '1 viên')]],
            'Etiheso' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 1, 'purpose' => 'Bảo vệ dạ dày khi dùng Celebrex', 'doses' => [$dose('breakfast', -60, '1 viên')]],
            'Celebrex' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 1, 'purpose' => 'Giảm đau, chống viêm cổ – vai', 'warning' => 'Có thể làm tăng huyết áp; phân đen hoặc đau thượng vị thì ngừng và đi khám.', 'doses' => [$dose('breakfast', 30, '1 viên')]],
            'Abricotis' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 2, 'purpose' => 'Bù calci và vitamin D', 'doses' => [$dose('breakfast', 30, '1 viên'), $dose('lunch', 30, '1 viên')]],
            'Oztis' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 2, 'purpose' => 'Hỗ trợ sụn khớp', 'doses' => [$dose('breakfast', 30, '1 viên'), $dose('dinner', 30, '1 viên')]],
            'Myopain' => ['type' => 'medication', 'days' => $daily, 'units_per_day' => 2, 'purpose' => 'Giãn cơ cổ – vai', 'warning' => 'Có thể gây chóng mặt nhẹ — đứng dậy từ từ.', 'doses' => [$dose('breakfast', 30, '1 viên'), $dose('dinner', 30, '1 viên')]],
            'Gel Nociceptol' => ['type' => 'topical', 'days' => $daily, 'purpose' => 'Bôi giảm đau tại chỗ', 'doses' => [$dose('breakfast', 40, 'Bôi vùng đau'), $dose('lunch', 40, 'Bôi vùng đau'), $dose('dinner', 40, 'Bôi vùng đau')]],
        ];
    }

    /** Các giai đoạn đo (PLAN.md mục 4.2). Thứ trong tuần theo ISO: 1 = Thứ hai … 7 = Chủ nhật. */
    public static function monitoringPhases(): array
    {
        return [
            ['metric' => 'blood_glucose', 'phase' => 'phase_1', 'starts_at' => '2026-09-27', 'ends_at' => '2026-10-10', 'schedule' => [
                'label' => 'Giai đoạn 1 · Tăng cường', 'description' => 'Đo 4 lần/ngày: lúc đói, trước trưa, trước tối, trước ngủ.',
                'daily' => ['fasting', 'pre_lunch', 'pre_dinner', 'bedtime'],
            ]],
            ['metric' => 'blood_glucose', 'phase' => 'phase_2', 'starts_at' => '2026-10-11', 'ends_at' => '2026-10-26', 'schedule' => [
                'label' => 'Giai đoạn 2 · Ổn định', 'description' => 'Lúc đói và trước tối mỗi ngày; Chủ nhật đo đủ 6 điểm.',
                'daily' => ['fasting', 'pre_dinner'], 'weekdays' => ['7' => ['post_breakfast', 'pre_lunch', 'post_lunch', 'post_dinner']],
            ]],
            ['metric' => 'blood_glucose', 'phase' => 'phase_3', 'starts_at' => '2026-10-27', 'ends_at' => null, 'schedule' => [
                'label' => 'Giai đoạn 3 · Duy trì', 'description' => 'Lúc đói mỗi ngày, trước tối thứ 2/4/6 — cập nhật theo bác sĩ nội tiết.',
                'daily' => ['fasting'], 'weekdays' => ['1' => ['pre_dinner'], '3' => ['pre_dinner'], '5' => ['pre_dinner']],
            ]],
            ['metric' => 'blood_pressure', 'phase' => 'bp_1', 'starts_at' => '2026-09-27', 'ends_at' => '2026-10-10', 'schedule' => [
                'label' => 'Huyết áp sáng và tối', 'description' => 'Đo huyết áp + mạch buổi sáng và buổi tối.', 'daily' => ['bp_morning', 'bp_evening'],
            ]],
            ['metric' => 'blood_pressure', 'phase' => 'bp_2', 'starts_at' => '2026-10-11', 'ends_at' => null, 'schedule' => [
                'label' => 'Huyết áp buổi sáng', 'description' => 'Đo huyết áp + mạch buổi sáng.', 'daily' => ['bp_morning'],
            ]],
        ];
    }

    private function seedScheduleAndMonitoring(string $tenantId, string $patientId): void
    {
        $context = app(\App\Support\TenantContext::class);
        $previous = $context->current();
        $context->setFromId($tenantId);
        try {
            $orchestrator = app(\App\Services\Schedule\PatientScheduleOrchestrator::class);
            $orchestrator->regenerateSchedule($patientId, \Carbon\CarbonImmutable::parse('2026-09-27'), $tenantId);
            $orchestrator->seedMonitoringPlans($patientId, self::monitoringPhases(), $tenantId);
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    private function seedEvents(string $tenantId, string $patientId): void
    {
        $events = [
            ['2026-09-26', null, 'appointment', 'Khám tổng quát tại BV ĐH Y Hà Nội', 'Nội tiết · Nội chung (VGB) · Phục hồi chức năng — nhận 3 đơn thuốc.', 'completed'],
            ['2026-09-27', null, 'other', 'Ngày 1 — bắt đầu toàn bộ phác đồ', 'Đặt báo thức và chuẩn bị hộp chia thuốc theo hồ sơ nguồn.', 'pending'],
            ['2026-09-28', '2026-09-30', 'test', 'Cấy nước tiểu (gần nhà)', 'BS dặn cấy nước tiểu vì bạch cầu niệu 100/µL. Lấy nước tiểu giữa dòng, buổi sáng. Chưa tự uống kháng sinh trước khi cấy.', 'pending'],
            ['2026-09-29', '2026-10-03', 'test', 'Làm XN viêm gan B tại BV tỉnh', 'BS đề nghị HBV-DNA, HBeAg/anti-HBe, AFP, siêu âm gan và Fibroscan; danh sách để trao đổi với bác sĩ.', 'pending'],
            ['2026-10-05', '2026-10-10', 'appointment', 'Khám chuyên khoa Hô hấp', 'X-quang: nốt mờ hạ đòn trái. Mang phim/kết quả và hỏi bác sĩ về bước kiểm tra tiếp theo.', 'pending'],
            ['2026-10-10', null, 'other', 'Tổng kết giai đoạn 1 theo dõi đường huyết', 'Tổng hợp sổ theo dõi để trao đổi với bác sĩ; ứng dụng không tự điều chỉnh liều.', 'pending'],
            ['2026-10-12', null, 'vaccination', 'Tiêm phòng cúm mùa', 'BS ghi tay “Tiêm phòng cúm, phế cầu”; hỏi bác sĩ hoặc điểm tiêm về lịch phù hợp.', 'pending'],
            ['2026-10-20', null, 'purchase', 'Mua thêm kim NovoFine 31G', 'Nhắc mua vật tư theo số lượng hồ sơ nguồn.', 'pending'],
            ['2026-10-24', null, 'appointment', 'Tái khám Phục hồi chức năng (sáng thứ 7)', 'Mang đơn cũ, báo mức đau và huyết áp.', 'pending'],
            ['2026-10-26', null, 'appointment', 'Tái khám Nội tiết (sau 1 tháng)', 'Mang sổ đường huyết, máy đo và các kết quả xét nghiệm; mọi thay đổi điều trị do bác sĩ quyết định.', 'pending'],
            ['2026-11-05', null, 'purchase', 'Mua thêm Livosil (90 viên)', 'Bảng kê ghi đã mua 90/180 viên theo đơn.', 'pending'],
            ['2026-11-26', null, 'appointment', 'Tái khám Nội tiết lần 2 (dự kiến)', 'Theo lịch hẹn mới của bác sĩ ở buổi 26/10.', 'pending'],
            ['2026-12-18', null, 'purchase', 'Hepazid sắp hết — đặt lịch tái khám VGB', 'Nhắc liên hệ cơ sở khám trước lịch tái khám.', 'pending'],
            ['2026-12-25', null, 'appointment', 'Tái khám Viêm gan B (3 tháng, sáng thứ 6/7)', 'Mang hồ sơ cũ và hỏi bác sĩ về các xét nghiệm cần thực hiện.', 'pending'],
        ];
        foreach ($events as [$date, $due, $type, $title, $description, $status]) {
            $this->stableId('events', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'event_date' => $date, 'title' => $title], [
                'due_date' => $due, 'type' => $type, 'description' => $description, 'status' => $status,
            ]);
        }
    }

    private function seedDocuments(string $tenantId, string $patientId): array
    {
        $records = [
            ['IMG_1308','don','Đơn thuốc số 2 — Nội tiết P3','TS Nguyễn Thị Thanh Thủy · 09:36',null,['Chẩn đoán: ĐTĐ típ 2 không biến chứng / tăng men gan – acid uric – BC niệu; RL lipid hỗn hợp; tăng acid uric; THA nguyên phát; nhiễm trùng tiết niệu.','Janumet 50/850 mg × 60 viên — sáng 1, tối 1 sau ăn.','NovoMix 30 FlexPen × 4 bút — sáng 16 UI, tối 14 UI, tiêm ngay trước ăn 5 phút.','Esserose 450 mg × 60 viên — sáng 1, tối 1 sau ăn.','Lời dặn: duy trì thuốc THA & mỡ máu; uống nhiều nước; cấy nước tiểu gần nhà; tái khám sau 1 tháng.']],
            ['IMG_1309','don','Đơn thuốc số 3 — Nội chung P4 (Viêm gan B)','BSCKII Nguyễn Danh Đức · 09:48',null,['Chẩn đoán: Viêm gan B mạn / tăng men gan, gan nhiễm mỡ, ĐTĐ, THA.','Hepazid 25 mg × 90 viên — 1 viên/ngày sau ăn.','Livosil 140 mg × 180 viên — sáng 1, tối 1 sau ăn.','Tái khám sau 3 tháng.']],
            ['IMG_1310','don','Đơn thuốc — Phục hồi chức năng P11','PGS.TS Nguyễn Thị Kim Liên · 09:24',null,['Celebrex 200 mg × 30 — 1 viên sau ăn sáng no.','Abricotis × 60 — sáng 1, trưa 1 sau ăn.','Oztis × 60 · Myopain 50 × 60 — sáng 1, tối 1 sau ăn.','Etiheso 40 mg × 30 — sáng 1 viên trước ăn 1 giờ.']],
            ['IMG_1312','cdha','Siêu âm khớp vai PHẢI','BS Trần Lê Sơn · 08:38',null,['Vôi hóa điểm bám gân cơ trên gai, lớn nhất 4 mm.','Dịch quanh đầu dài gân cơ nhị đầu dày 2 mm.','Thoái hóa khớp cùng vai đòn.']],
            ['IMG_1313','xn','XN sinh hóa + miễn dịch (trang 1/2)','Lấy mẫu 07:36',null,['HBsAg 1811 COI — dương tính.','Vitamin D 28 ng/mL.','Glucose 8,8 · HbA1c 10,16%.','Acid uric 351; AST 42; ALT 73.']],
            ['IMG_1314','xn','Tổng phân tích tế bào máu','Khoa Huyết học · 08:03',null,['Hồng cầu 6,62; MCV 66,8; MCH 20; MCHC 303; RDW 18,1; Hb 134.','Bạch cầu 7,56; Mono 9,8%; tiểu cầu 312.']],
            ['IMG_1316','xn','Tổng phân tích nước tiểu (trang 2/2)','Lấy mẫu 07:45',null,['Bạch cầu niệu 100 Leu/µL.','Nitrit, glucose, protein, ceton, hồng cầu âm tính; pH 6; tỷ trọng 1,026.']],
            ['IMG_1317','cdha','X-quang cột sống cổ thẳng – nghiêng','BS Nguyễn Thái Hoàng · 08:03',null,['Cột sống giảm độ cong bình thường.','Mỏ xương thoái hóa; không trượt đốt sống; khe đĩa đệm không hẹp.']],
            ['IMG_1319','cdha','X-quang tim phổi thẳng – nghiêng','BS Nguyễn Thái Hoàng · 08:02',null,['Nốt mờ hạ đòn trái.','Hình tim không to; quai động mạch chủ vồng.']],
            ['IMG_1320','cdha','Siêu âm ổ bụng','BS Trần Lê Sơn · 08:42',null,['Gan nhu mô tăng âm, không có khối.','Thận phải: nang 11 mm, thành mỏng, dịch trong.']],
            ['IMG_1322','cdha','Siêu âm khớp vai TRÁI','BS Trần Lê Sơn · 08:39',null,['Dịch quanh đầu dài gân cơ nhị đầu dày 2 mm.','Thoái hóa khớp cùng vai đòn trái.']],
            ['IMG_1323','kham','Kết quả khám — Nội tiết P3','TS Nguyễn Thị Thanh Thủy',null,['Tiền sử: ĐTĐ, THA; không dị ứng thuốc.','Mạch 106 l/ph, HA 124/96 mmHg.','Duy trì thuốc THA và mỡ máu; cấy nước tiểu; tái khám 1 tháng.']],
            ['IMG_1324','hd','Phiếu theo dõi đường huyết hằng ngày','BS ghi tay',null,['Mục tiêu BS ghi: trước ăn 4,4–7,2 mmol/L; sau ăn 2 giờ < 10 mmol/L.','Phiếu nguồn có các dòng liều insulin; ứng dụng chỉ lưu nguyên văn, không tính hoặc đề xuất liều.']],
            ['IMG_1325','hd','Hướng dẫn chế độ ăn — Đái tháo đường (1)','Tờ hướng dẫn',null,['Hạn chế đường, bánh kẹo và nước ngọt.','Chia nhỏ bữa, cố định giờ ăn; nội dung nguồn cần bác sĩ duyệt trước khi sử dụng như hướng dẫn.']],
            ['IMG_1326','don','Đơn thuốc PHCN (bản chụp 2)','', 'IMG_1310',['Trùng nội dung với ảnh IMG_1310.']],
            ['IMG_1327','don','Phiếu tư vấn — Gel giảm đau Nociceptol','PGS.TS Nguyễn Thị Kim Liên',null,['Thiết bị y tế, 1 tuýp 120 ml.','Bôi chỗ đau 3 lần/ngày.']],
            ['IMG_1328','kham','Kết quả khám — PHCN P11 (trang 1/3)','PGS.TS Nguyễn Thị Kim Liên',null,['Đau cổ gáy; mạch 106; HA 124/96.','Tổng hợp chẩn đoán và bảng sinh hóa trong hồ sơ.']],
            ['IMG_1329','kham','Kết quả khám — PHCN (trang 2/3)','',null,['Tổng hợp nước tiểu và công thức máu.']],
            ['IMG_1330','kham','Kết quả khám — PHCN (trang 3/3)','',null,['Tổng hợp miễn dịch và năm kết quả chẩn đoán hình ảnh.']],
            ['IMG_1331','don','Đơn thuốc PHCN (bản chụp 3)','', 'IMG_1310',['Trùng nội dung với ảnh IMG_1310.']],
            ['IMG_1332','don','Đơn thuốc số 2 (bản chụp 2)','', 'IMG_1308',['Trùng nội dung với ảnh IMG_1308.']],
            ['IMG_1333','don','Phiếu tư vấn — Kim tiêm NovoFine 31G','TS Nguyễn Thị Thanh Thủy',null,['Kim NovoFine 31G 0,25 × 6 mm × 30 cái.','Thay kim sau 1–2 lần tiêm.']],
            ['IMG_1334','kham','Kết quả khám Nội tiết (bản chụp 2)','', 'IMG_1323',['Trùng nội dung với ảnh IMG_1323.']],
            ['IMG_1335','don','Bảng kê chi phí — Nhà thuốc BV số 1','10:03',null,['Hepazid 25 mg: 90 viên.','Livosil 140 mg: 90 viên; đơn ghi 180 viên.']],
            ['IMG_1336','don','Đơn thuốc số 3 (bản chụp 2)','', 'IMG_1309',['Trùng nội dung với ảnh IMG_1309.']],
            ['IMG_1337','kham','Kết quả khám — Nội chung P4 (Viêm gan B)','BSCKII Nguyễn Danh Đức · 09:50',null,['VGB chưa điều trị; có chỉ định điều trị thuốc kháng virus lâu dài.','Đề nghị làm thêm xét nghiệm tại cơ sở y tế; tái khám sau 3 tháng.']],
            ['IMG_1338','hd','Chế độ ăn giảm acid uric / gút','Tờ hướng dẫn',null,['Nội dung chế độ ăn từ tài liệu nguồn; cần bác sĩ duyệt trước khi sử dụng như hướng dẫn cá nhân.']],
            ['IMG_1339','hd','Chế độ ăn cho người tiểu đường (2)','Tờ hướng dẫn',null,['Nội dung khẩu phần và giờ ăn từ tài liệu nguồn; cần bác sĩ duyệt trước khi sử dụng như hướng dẫn cá nhân.']],
        ];

        $ids = [];
        foreach ($records as [$image, $type, $title, $by, $duplicate, $findings]) {
            $analysis = json_encode(['title' => $title, 'findings' => $findings, 'source' => 'prototype', 'image' => $image], JSON_UNESCAPED_UNICODE);
            $existing = DB::table('documents')->where('tenant_id', $tenantId)->where('patient_id', $patientId)->where('analysis', 'like', '%"image":"'.$image.'"%')->first()
                ?? DB::table('documents')->where('tenant_id', $tenantId)->where('patient_id', $patientId)->where('encrypted_path', 'demo/prototype/'.$image.'.jpg')->first();
            $path = $existing?->encrypted_path;
            if ($path === null || str_starts_with($path, 'demo/') || ! app(\App\Services\Document\DocumentEncryptionService::class)->exists($path)) {
                $path = $this->importDemoImage($image) ?? 'demo/prototype/'.$image.'.jpg';
            }
            $values = [
                'encrypted_path' => $path, 'type' => $type, 'document_date' => '2026-09-26', 'department' => $title, 'doctor_name' => $by ?: null,
                'analysis' => $analysis, 'duplicate_of_id' => null, 'updated_at' => now(),
            ];
            if ($existing !== null) {
                DB::table('documents')->where('id', $existing->id)->update($values);
                $ids[$image] = $existing->id;
            } else {
                $ids[$image] = (string) Str::ulid();
                DB::table('documents')->insert([...$values, 'id' => $ids[$image], 'tenant_id' => $tenantId, 'patient_id' => $patientId, 'created_at' => now()]);
            }
        }
        foreach ($records as [$image, , , , $duplicate]) {
            if ($duplicate !== null) {
                DB::table('documents')->where('id', $ids[$image])->update(['duplicate_of_id' => $ids[$duplicate], 'updated_at' => now()]);
            }
        }
        return $ids;
    }

    /** Mã hoá ảnh demo từ prototype/assets/img; bỏ qua khi chạy test để không ghi file. */
    private function importDemoImage(string $image): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }
        $source = base_path('archive/prototype/assets/img/'.$image.'.jpg');
        if (! is_file($source)) {
            return null;
        }

        return app(\App\Services\Document\DocumentEncryptionService::class)->storeEncryptedContent((string) file_get_contents($source), 'jpg');
    }

    private function seedLabs(string $tenantId, string $patientId, string $documentId): void
    {
        $groups = [
            'Sinh hóa máu' => [['Glucose máu','8,8','mmol/L','4,11–5,6','H'],['HbA1c','10,16','%','4,8–5,9','H'],['Creatinin','59','µmol/L','44–80','N'],['eGFR (CKD-EPI)','101,4','ml/ph/1,73m²','≥ 90','N'],['Acid uric','351','µmol/L','142,8–339,2','H'],['Cholesterol TP','4,31','mmol/L','< 5,2','N'],['Triglycerid','0,81','mmol/L','< 1,7','N'],['LDL-C','2,59','mmol/L','< 3,34 (mục tiêu NCTM cao < 1,8)','W'],['AST (GOT)','42','U/L','< 32','H'],['ALT (GPT)','73','U/L','< 33','H'],['Calci ion hóa','1,25','mmol/L','1,14–1,33','N']],
            'Miễn dịch' => [['HBsAg','1811 (dương tính)','COI','< 0,8','H'],['25-OH Vitamin D','28','ng/mL','30–50','L']],
            'Công thức máu' => [['Hồng cầu (RBC)','6,62','T/L','4–5,4','H'],['Hemoglobin','134','g/L','120–160','N'],['Hematocrit','0,44','L/L','0,37–0,46','N'],['MCV','66,8','fL','80–100','L'],['MCH','20','pg','26–34','L'],['MCHC','303','g/L','315–363','L'],['RDW-CV','18,1','%','11–17','H'],['Bạch cầu (WBC)','7,56','G/L','4–10','N'],['Mono %','9,8','%','0–8','H'],['Tiểu cầu','312','G/L','150–450','N']],
            'Nước tiểu' => [['Bạch cầu (LEU)','100','Leu/µL','Âm tính','H'],['Nitrit','Âm tính','','Âm tính','N'],['Glucose','Âm tính','','Âm tính','N'],['Protein','Âm tính','','Âm tính','N'],['Ceton','Âm tính','','Âm tính','N'],['Hồng cầu','Âm tính','','Âm tính','N'],['pH','6','','','N'],['Tỷ trọng','1,026','','1,005–1,03','N']],
            'Khám lâm sàng' => [['Mạch','106','lần/phút','60–100','H'],['Huyết áp','124/96','mmHg','< 130/80','W']],
        ];
        foreach ($groups as $group => $results) {
            foreach ($results as [$metric, $value, $unit, $reference, $flag]) {
                $this->stableId('lab_results', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'metric' => $group.' — '.$metric, 'measured_at' => '2026-09-26'], [
                    'document_id' => $documentId, 'value' => $value, 'unit' => $unit ?: null, 'reference_range' => $reference ?: null, 'flag' => $flag,
                ]);
            }
        }
    }

    private function seedQuestions(string $tenantId, string $patientId, string $userId): void
    {
        $groups = [
            'Nội tiết|TS Nguyễn Thị Thanh Thủy|2026-10-26' => [
                'Ngày 26/9 phiếu ghi insulin trưa 6 UI — từ ngày 27/9 mẹ chỉ tiêm sáng 16 UI, tối 14 UI đúng không ạ?',
                'Nếu đường huyết lúc đói nhiều ngày > 7,2 hoặc có lần < 4,4 mmol/L thì gia đình có được chỉnh liều không, chỉnh thế nào?',
                'Mạch 106 và huyết áp 124/96: thuốc huyết áp hiện tại có cần điều chỉnh không?',
                'Mục tiêu LDL-cholesterol của mẹ là bao nhiêu? (hiện 2,59 mmol/L)',
                'Kết quả cấy nước tiểu như vầy thì có cần dùng kháng sinh không?',
                'Có cần làm albumin niệu, soi đáy mắt, khám bàn chân định kỳ không?',
                'Hồng cầu nhỏ (MCV 66,8) nhưng số lượng cao — có nên làm ferritin, điện di huyết sắc tố?',
            ],
            'Viêm gan B|BSCKII Nguyễn Danh Đức|2026-12-25' => [
                'Đây là kết quả HBV-DNA, HBeAg, AFP, Fibroscan làm ở BV tỉnh — có cần thay đổi điều trị không?',
                'Hepazid dùng lâu dài — nếu quên 1 liều thì xử lý thế nào? Có tương tác với thuốc tiểu đường, huyết áp không?',
                'Livosil cần dùng trong bao lâu?',
                'Người nhà sống cùng có cần xét nghiệm và tiêm phòng viêm gan B không?',
            ],
            'Phục hồi chức năng|PGS.TS Nguyễn Thị Kim Liên|2026-10-24' => [
                'Celebrex nên dùng tối đa bao lâu? Mẹ bị tăng huyết áp thì có cần lưu ý gì thêm?',
                'Lịch tập PHCN tại khoa mấy buổi/tuần, gồm những gì? Bài tập nào mẹ tự tập ở nhà được?',
                'Vôi hóa gân trên gai 4 mm vai phải có cần điều trị riêng (sóng xung kích…) không?',
            ],
            'Hô hấp|Chuyên khoa Hô hấp|2026-10-10' => [
                'X-quang có nốt mờ hạ đòn trái — mẹ có cần chụp CT ngực liều thấp không?',
                'Mẹ có nên tiêm vắc xin phế cầu và cúm cùng lúc không?',
            ],
        ];
        // Dữ liệu cũ ghép chuyên khoa vào đầu câu hỏi ("[Nội tiết — …] …"): xoá để tách ra cột riêng.
        DB::table('questions')->where('tenant_id', $tenantId)->where('patient_id', $patientId)->where('question', 'like', '[%')->delete();
        foreach ($groups as $group => $questions) {
            [$specialty, $doctor, $due] = explode('|', $group);
            foreach ($questions as $question) {
                $this->stableId('questions', ['tenant_id' => $tenantId, 'patient_id' => $patientId, 'question' => $question], [
                    'asked_by' => $userId, 'specialty' => $specialty, 'doctor_name' => $doctor, 'due_date' => $due, 'doctor_id' => null,
                ]);
            }
        }
    }

    private function stableId(string $table, array $identity, array $values, ?array $lookup = null): string
    {
        $id = DB::table($table)->where($lookup ?? $identity)->value('id');
        if ($id !== null) {
            DB::table($table)->where('id', $id)->update([...$values, 'updated_at' => now()]);
            return $id;
        }

        $id = (string) Str::ulid();
        DB::table($table)->insert([...$identity, ...$values, 'id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }
}
