<?php

namespace Tests\Feature;

use App\Contracts\MedicalAiClient;
use App\Models\CarePlan;
use App\Models\Document;
use App\Models\Event;
use App\Models\LabResult;
use App\Models\PatientCondition;
use App\Models\Tenant;
use App\Services\Ai\FakeMedicalAiClient;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentIngestTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    private function upload(string $name = 'don.png'): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));

        return $this->withHeaders($this->auth)->post("/api/v1/patients/{$this->patientId}/documents", ['file' => $file], ['Accept' => 'application/json']);
    }

    private function inTenant(): void
    {
        app(TenantContext::class)->set(Tenant::findOrFail($this->tenantId));
    }

    public function test_ai_reads_image_and_never_stores_citizen_id_or_insurance_number(): void
    {
        $response = $this->upload()->assertCreated();
        $response->assertJsonPath('status', 'stored')
            ->assertJsonPath('data.type', 'don')
            ->assertJsonPath('data.ai_status', 'done')
            ->assertJsonPath('data.medications_count', 1);
        $this->assertGreaterThan(0, $response->json('data.masked_count'));

        // Không chỗ nào trong CSDL chứa số CCCD / BHYT.
        $dump = json_encode([
            DB::table('documents')->get(), DB::table('lab_results')->get(), DB::table('patient_conditions')->get(), DB::table('events')->get(),
        ], JSON_UNESCAPED_UNICODE);
        foreach (['001234567890', '001 234 567 890', 'DN4797931234567', '"cccd"'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }

        $this->inTenant();
        $document = Document::firstOrFail();
        $this->assertSame(1, LabResult::where('document_id', $document->id)->count());
        $this->assertSame('Sinh hóa máu — HbA1c', LabResult::first()->metric);
        $this->assertSame(1, PatientCondition::count());
        $this->assertSame(1, Event::where('type', 'appointment')->count());

        // Ảnh gốc được lưu nhưng đã mã hoá.
        $raw = Storage::disk('local')->get($document->encrypted_path);
        $this->assertStringNotContainsString('PNG', $raw);
        $this->assertStringEndsWith('.png.enc', $document->encrypted_path);

        // Tải ảnh qua API có kiểm quyền, không cache.
        $file = $this->withHeaders($this->auth)->get("/api/v1/patients/{$this->patientId}/documents/{$document->id}/file");
        $file->assertOk();
        $this->assertStringContainsString('no-store', $file->headers->get('Cache-Control'));
    }

    public function test_identity_card_photo_is_rejected_and_not_stored(): void
    {
        $this->app->bind(MedicalAiClient::class, fn () => new class implements MedicalAiClient
        {
            public function analyzeDocument(string $binary, string $mime): array
            {
                return ['document_type' => 'id_card', 'id_number' => '001234567890'];
            }

            public function generateCarePlan(array $context): array
            {
                return [];
            }

            public function generateDailyMenu(array $context): array
            {
                return [];
            }

            public function model(): string
            {
                return 'test';
            }
        });

        $this->upload('cccd.png')->assertOk()->assertJsonPath('status', 'rejected_identity')->assertJsonPath('data', null);

        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_extracted_medications_become_schedule_then_care_plan(): void
    {
        $this->upload()->assertCreated();

        $pending = $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/pending-medications")->assertOk()->json('data');
        $this->assertCount(1, $pending);
        $med = $pending[0]['medications'][0];
        $this->assertSame('Metformin 500mg', $med['drug_name']);
        $this->assertSame([['anchor' => 'breakfast', 'offset_min' => 30, 'amount_text' => '1 viên'], ['anchor' => 'dinner', 'offset_min' => 30, 'amount_text' => '1 viên']], $med['usage_rule']['doses']);

        $today = now()->toDateString();
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/prescriptions", [
            'document_id' => $pending[0]['document_id'],
            'prescribed_at' => $today,
            'starts_at' => $today,
            'items' => [['drug_name' => $med['drug_name'], 'dose_text' => $med['dose_text'], 'usage_rule' => $med['usage_rule'], 'prescribed_quantity' => 60, 'quantity_unit' => 'viên']],
        ])->assertCreated();

        $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/pending-medications")->assertOk()->assertJsonCount(0, 'data');

        $day = $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/day/{$today}")->assertOk()->json('data.items');
        $this->assertContains('07:30', array_column(array_filter($day, fn ($i) => $i['type'] === 'medication'), 'time'));

        // Kế hoạch chăm sóc: chế độ ăn gắn vào mốc bữa ăn trong lịch ngày.
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/care-plan")->assertCreated()
            ->assertJsonPath('data.content.diet.sample_day.breakfast', 'Cháo yến mạch + 1 quả trứng')
            ->assertJsonStructure(['data' => ['disclaimer', 'content' => ['summary', 'key_issues', 'diet', 'monitoring', 'warning_signs']]]);
        $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/care-plan")->assertOk()->assertJsonPath('data.sources.medications', 1);

        // Thực đơn 7 ngày: món của đúng thứ hôm nay, cả trên mốc bữa ăn lẫn thẻ "Thực đơn hôm nay".
        $expected = app(MedicalAiClient::class)->generateCarePlan([])['diet']['weekly_menu'][now()->dayOfWeekIso - 1]['breakfast'];
        $day = $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/day/{$today}")->json('data');
        $this->assertSame($expected, collect($day['items'])->firstWhere('key', 'meal:breakfast')['diet_note']);
        $this->assertSame($expected, $day['menu']['breakfast']);
        $this->assertSame('1 hộp sữa chua không đường', $day['menu']['snacks']);
    }

    public function test_care_plan_context_is_anonymous(): void
    {
        $this->upload()->assertCreated();
        $this->inTenant();
        $context = app(\App\Services\CarePlan\CarePlanService::class)->context(\App\Models\Patient::firstOrFail());

        $flat = json_encode($context, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Trần Thị Dung', $flat);
        $this->assertStringNotContainsString('0900000111', $flat);
        $this->assertSame(1, count($context['lab_results']));
        $this->assertIsInt($context['age']);
    }

    public function test_deleting_document_removes_encrypted_file_and_labs(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->withHeaders($this->auth)->deleteJson("/api/v1/patients/{$this->patientId}/documents/{$id}")->assertOk();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, DB::table('lab_results')->count());
    }

    public function test_deleting_account_removes_own_health_book_and_files(): void
    {
        $this->upload()->assertCreated();
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/care-plan")->assertCreated();

        $this->withHeaders($this->auth)->postJson('/api/v1/account/delete')->assertOk();

        $this->assertSame([], Storage::disk('local')->allFiles());
        foreach (['users', 'tenants', 'patients', 'documents', 'lab_results', 'care_plans'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_other_tenant_cannot_read_documents(): void
    {
        $id = $this->upload()->assertCreated()->json('data.id');
        $other = $this->postJson('/api/v1/auth/register', [
            'name' => 'Người Lạ', 'phone' => '0900000222', 'birth_date' => '1990-01-01', 'accepted' => true,
        ])->json('data');

        // Test dùng chung một app: quên người dùng đã xác thực ở request trước.
        $this->app["auth"]->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$other['token'], 'X-Tenant-ID' => $this->tenantId])
            ->get("/api/v1/patients/{$this->patientId}/documents/{$id}/file")->assertNotFound();
        $this->withHeaders(['Authorization' => 'Bearer '.$other['token'], 'X-Tenant-ID' => $other['tenant']['id']])
            ->get("/api/v1/patients/{$this->patientId}/documents/{$id}/file")->assertNotFound();
    }
}
