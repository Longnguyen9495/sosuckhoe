<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\LabResult;
use App\Models\PatientCondition;
use App\Models\PrescriptionItem;
use App\Models\Tenant;
use App\Services\Dedup\DuplicateMatcher;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Chống trùng: ảnh tải lại, bản chụp lại, xét nghiệm, chẩn đoán, thuốc — và lệnh dọn dữ liệu cũ. */
class DuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private array $auth;

    private string $patientId;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $data = $this->postJson('/api/v1/auth/register', [
            'name' => 'Trần Thị Dung', 'phone' => '0900000111', 'birth_date' => '1968-05-02', 'accepted' => true,
        ])->assertCreated()->json('data');

        $this->auth = ['Authorization' => 'Bearer '.$data['token'], 'X-Tenant-ID' => $data['tenant']['id']];
        $this->patientId = $data['patient']['id'];
        $this->tenantId = $data['tenant']['id'];
    }

    /** Ảnh PNG 2x2 màu tuỳ chọn — màu khác nhau thì nội dung file khác nhau. */
    private function png(int $rgb): string
    {
        $img = imagecreatetruecolor(2, 2);
        imagefill($img, 0, 0, $rgb);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function upload(int $rgb = 0xFFFFFF): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('don.png', $this->png($rgb));

        return $this->withHeaders($this->auth)->post("/api/v1/patients/{$this->patientId}/documents", ['file' => $file], ['Accept' => 'application/json']);
    }

    private function inTenant(): void
    {
        app(TenantContext::class)->set(Tenant::findOrFail($this->tenantId));
    }

    private function savePrescription(array $names, bool $allowDuplicates = false): \Illuminate\Testing\TestResponse
    {
        $today = now()->toDateString();

        return $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/prescriptions", [
            'prescribed_at' => $today,
            'starts_at' => $today,
            'allow_duplicates' => $allowDuplicates,
            'items' => array_map(fn ($n) => ['drug_name' => $n, 'dose_text' => 'Sáng 1 viên sau ăn', 'usage_rule' => ['type' => 'medication', 'doses' => [['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => '1 viên']]]], $names),
        ]);
    }

    public function test_matcher_rules(): void
    {
        // Thuốc: AI đọc nhầm một chữ, tên thương mại trong ngoặc, khác hoa thường → cùng thuốc.
        $this->assertTrue(DuplicateMatcher::sameDrug('Esomeprazole (ETHESO) 40mg', 'Esomeprazole (ETIHESO) 40mg'));
        $this->assertTrue(DuplicateMatcher::sameDrug('Celebrex 200mg', 'Celecoxib (CELEBREX) 200mg'));
        $this->assertTrue(DuplicateMatcher::sameDrug('Amlodipin 5mg', 'amlodipine 5 mg'));
        // Khác hàm lượng / khác hoạt chất → khác thuốc.
        $this->assertFalse(DuplicateMatcher::sameDrug('Amlodipin 5mg', 'Amlodipin 10mg'));
        $this->assertFalse(DuplicateMatcher::sameDrug('Losartan 50mg', 'Valsartan 50mg'));
        $this->assertFalse(DuplicateMatcher::sameDrug('Thuốc Insulin', 'NovoMix 30 FlexPen'));
        $this->assertFalse(DuplicateMatcher::sameDrug('Prednisolon 5mg', 'Prednison 5mg'));
        // AI đọc lệch 2 ký tự ở tên dài, cùng hàm lượng → cùng thuốc.
        $this->assertTrue(DuplicateMatcher::sameDrug('Silymarin (LIVISOL) 140mg', 'Livosil/140mg'));
        $this->assertFalse(DuplicateMatcher::sameDrug('Metoprolol 50mg', 'Metformin 50mg'));

        // Chẩn đoán: hoa thường, "typ" / "típ", ngoặc "(+)" không bị cắt, cùng mã ICD.
        $this->assertTrue(DuplicateMatcher::sameDiagnosis('Viêm gan B-HBsAg (+)', 'Viêm gan B-HbsAg (+)'));
        $this->assertTrue(DuplicateMatcher::sameDiagnosis('Bệnh đái tháo đường típ 2 (E11.9)', 'Bệnh đái tháo đường typ 2 (E11.9)'));
        $this->assertTrue(DuplicateMatcher::sameDiagnosis('Nốt mờ hạ đơn trái', 'Nốt mờ hạ đòn trái'));
        $this->assertFalse(DuplicateMatcher::sameDiagnosis('Viêm gan B-HBsAg (+)', 'Viêm gan B-HBsAg (-)'));
        $this->assertFalse(DuplicateMatcher::sameDiagnosis('Tăng huyết áp', 'Tăng lipid máu'));

        // Xét nghiệm: dấu phẩy thập phân, tên nhóm khác nhau.
        $this->assertSame(
            DuplicateMatcher::labKey('Hóa sinh — HbA1c', '2026-09-26', '10,16'),
            DuplicateMatcher::labKey('Sinh hóa máu — HbA1c', '2026-09-26 00:00:00', '10.16'),
        );

        // Phiếu: cùng ngày nhưng khác tiêu đề (siêu âm ổ bụng / khớp vai) là hai phiếu khác nhau.
        $shared = ['type' => 'cdha', 'date' => '2026-09-26', 'diagnoses' => [['name' => 'Đau cổ gáy, 2 vai']]];
        $this->assertFalse(DuplicateMatcher::sameDocument($shared + ['title' => 'Siêu âm ổ bụng'], $shared + ['title' => 'Siêu âm khớp vai phải']));
        $this->assertTrue(DuplicateMatcher::sameDocument($shared + ['title' => 'Siêu âm ổ bụng'], $shared + ['title' => 'Siêu âm ổ bụng']));

        // Hai ảnh của cùng "Đơn thuốc số 3": khoa, tên thuốc lệch vài ký tự, chẩn đoán gộp / tách dòng khác nhau.
        $rx = ['type' => 'don', 'date' => '2026-09-26', 'title' => 'Đơn thuốc số 3'];
        $this->assertTrue(DuplicateMatcher::sameDocument(
            $rx + ['department' => 'KCBTƯC Nội chung P4', 'medications' => [['drug_name' => 'Tenofovir (HEPAZID) 25mg'], ['drug_name' => 'Silymarin (LIVISOL) 140mg']], 'diagnoses' => [['name' => 'Viêm gan B mạn / Tăng men gan, Gan nhiễm mỡ']]],
            $rx + ['department' => 'KCBTVC Nội chung P4', 'medications' => [['drug_name' => 'Tenofovir (HEPAZID) 25mg'], ['drug_name' => 'Silymarin (LIVOSIL) 140mg']], 'diagnoses' => [['name' => 'Viêm gan B mạn'], ['name' => 'Tăng men gan'], ['name' => 'Gan nhiễm mỡ']]],
        ));
        // Cùng tiêu đề nhưng thuốc khác hẳn → hai đơn khác nhau.
        $this->assertFalse(DuplicateMatcher::sameDocument(
            $rx + ['medications' => [['drug_name' => 'Tenofovir (HEPAZID) 25mg'], ['drug_name' => 'Silymarin 140mg']]],
            $rx + ['medications' => [['drug_name' => 'Metformin 500mg'], ['drug_name' => 'Amlodipin 5mg']]],
        ));
    }

    public function test_same_image_uploaded_twice_is_not_stored_again(): void
    {
        $first = $this->upload()->assertCreated()->json('data.id');

        $this->upload()->assertOk()
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('data.id', $first);

        $this->inTenant();
        $this->assertSame(1, Document::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(1, LabResult::count());
    }

    public function test_retake_of_same_paper_is_marked_and_not_imported_again(): void
    {
        $first = $this->upload(0xFFFFFF)->assertCreated()->json('data.id');

        // Ảnh khác (chụp lại), AI đọc ra cùng nội dung.
        $this->upload(0xEEEEEE)->assertCreated()
            ->assertJsonPath('status', 'stored_retake')
            ->assertJsonPath('data.duplicate_of_id', $first);

        $this->inTenant();
        $this->assertSame(2, Document::count());
        $this->assertSame(1, LabResult::count(), 'Xét nghiệm của bản chụp lại không được nhập lần hai.');
        $this->assertSame(1, PatientCondition::count());
    }

    public function test_lab_already_recorded_from_another_paper_is_skipped(): void
    {
        $this->inTenant();
        LabResult::create(['patient_id' => $this->patientId, 'metric' => 'Hóa sinh — HbA1c', 'value' => '8.5', 'unit' => '%', 'measured_at' => now()->toDateString()]);

        $this->upload()->assertCreated(); // AI giả đọc ra "Sinh hóa máu — HbA1c = 8,5" cùng ngày

        $this->inTenant();
        $this->assertSame(1, LabResult::count());
    }

    public function test_medication_already_in_use_is_flagged_and_blocked(): void
    {
        $this->savePrescription(['Metformin 500mg'])->assertCreated();
        $this->upload()->assertCreated(); // đơn mới có Metformin 500mg

        $pending = $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/pending-medications")->assertOk()->json('data.0.medications.0');
        $this->assertSame('active', $pending['duplicate']['reason']);

        $this->savePrescription(['METFORMIN 500 mg'])->assertStatus(409)
            ->assertJsonPath('code', 'DUPLICATE_MEDICATION')
            ->assertJsonPath('duplicates.0.existing', 'Metformin 500mg');

        // Người dùng xác nhận (bác sĩ kê thêm) thì vẫn lưu được.
        $this->savePrescription(['METFORMIN 500 mg'], allowDuplicates: true)->assertCreated();
    }

    public function test_same_drug_twice_in_one_prescription_is_allowed(): void
    {
        // VD các mũi insulin khác liều trong ngày: nhiều dòng cùng tên trong MỘT đơn không phải trùng.
        $this->savePrescription(['Thuốc Insulin', 'Thuốc Insulin'])->assertCreated();
    }

    public function test_dedupe_command_dry_run_then_apply(): void
    {
        $this->savePrescription(['Celecoxib (CELEBREX) 200mg', 'Esomeprazole (ETHESO) 40mg'])->assertCreated();
        $this->savePrescription(['Celecoxib (CELEBREX) 200mg', 'Esomeprazole (ETIHESO) 40mg'], allowDuplicates: true)->assertCreated();
        $this->inTenant();
        foreach (['8,8', '8.8'] as $v) {
            LabResult::create(['patient_id' => $this->patientId, 'metric' => 'Hóa sinh — Glucose', 'value' => $v, 'measured_at' => '2026-09-26']);
        }
        foreach (['Viêm gan B-HBsAg (+)', 'Viêm gan B-HbsAg (+)'] as $t) {
            PatientCondition::create(['patient_id' => $this->patientId, 'notes' => $t]);
        }

        $this->artisan('health:dedupe')->assertSuccessful();
        $this->inTenant();
        $this->assertSame(4, PrescriptionItem::count(), 'Chạy thử không được sửa dữ liệu.');
        $this->assertSame(2, LabResult::count());

        $this->artisan('health:dedupe', ['--apply' => true])->assertSuccessful();
        $this->inTenant();
        $this->assertSame(['Celecoxib (CELEBREX) 200mg', 'Esomeprazole (ETHESO) 40mg'], PrescriptionItem::orderBy('drug_name_snapshot')->pluck('drug_name_snapshot')->all());
        $this->assertSame(1, \App\Models\Prescription::count(), 'Đơn không còn thuốc nào bị xoá.');
        $this->assertSame(1, LabResult::count());
        $this->assertSame(1, PatientCondition::count());
    }
}
