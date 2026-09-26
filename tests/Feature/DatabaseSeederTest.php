<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactor\TotpService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_is_complete_safe_and_idempotent_with_stable_ulids(): void
    {
        $this->seed(DatabaseSeeder::class);

        $patientId = DB::table('patients')->where('full_name', 'Bà D.')->value('id');
        $drugIds = DB::table('drugs')->orderBy('brand_name')->pluck('id', 'brand_name')->all();
        $before = $this->counts($patientId);
        $patientIds = $this->patientRecordIds($patientId);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($drugIds, DB::table('drugs')->orderBy('brand_name')->pluck('id', 'brand_name')->all());
        $this->assertSame($before, $this->counts($patientId));
        $this->assertSame($patientIds, $this->patientRecordIds($patientId));
        $this->assertSame([
            'conditions' => 10,
            'documents' => 28,
            'events' => 14,
            'labs' => 33,
            'prescription_items' => 12,
            'prescriptions' => 3,
            'questions' => 16,
        ], $before);

        $this->assertDatabaseHas('prescription_items', [
            'patient_id' => $patientId,
            'drug_name_snapshot' => 'NovoMix 30 FlexPen',
            'dose_text' => 'Sáng 16 UI, tối 14 UI — tiêm dưới da NGAY TRƯỚC ăn 5 phút',
        ]);
        $this->assertDatabaseHas('prescription_items', ['patient_id' => $patientId, 'drug_name_snapshot' => 'Gel Nociceptol 120 ml']);
        $this->assertSame(5, DB::table('documents')->where('patient_id', $patientId)->whereNotNull('duplicate_of_id')->count());

        $sensitiveDump = json_encode(DB::table('patients')->where('id', $patientId)->first(), JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('/\b\d{10}\b/', $sensitiveDump, 'Không lưu mã bệnh nhân / mã hồ sơ của bệnh viện.');
        $this->assertStringNotContainsString('BHYT', $sensitiveDump);
        $this->assertStringNotContainsString('CCCD', $sensitiveDump);
    }

    public function test_local_demo_accounts_include_doctor_with_two_factor_and_empty_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $doctor = User::query()->where('phone', '0900000002')->firstOrFail();
        $emptyAccount = User::query()->where('phone', '0900000003')->firstOrFail();
        $tenantId = DB::table('tenants')->where('name', 'Gia đình bà D.')->value('id');
        $patientId = DB::table('patients')->where('full_name', 'Bà D.')->value('id');

        $this->assertNotNull($doctor->two_factor_confirmed_at);
        $this->assertSame(
            'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            app(TotpService::class)->decryptSecret($doctor),
        );
        $this->assertDatabaseHas('tenant_members', [
            'tenant_id' => $tenantId,
            'user_id' => $doctor->getKey(),
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('patient_access', [
            'patient_id' => $patientId,
            'user_id' => $doctor->getKey(),
            'role' => 'doctor',
        ]);
        $this->assertSame(0, DB::table('tenant_members')->where('user_id', $emptyAccount->getKey())->count());
        $this->assertSame(0, DB::table('patient_access')->where('user_id', $emptyAccount->getKey())->count());
    }

    public function test_drug_catalog_contains_common_groups_and_all_warnings_are_drafts(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['Metformin', 'Empagliflozin', 'Amlodipine', 'Losartan', 'Atorvastatin', 'Rosuvastatin'] as $brand) {
            $this->assertDatabaseHas('drugs', ['brand_name' => $brand]);
        }

        $warnings = DB::table('drugs')->whereNotNull('general_warning')->pluck('general_warning');
        $this->assertNotEmpty($warnings);
        foreach ($warnings as $warning) {
            $this->assertStringStartsWith('[NHÁP — CẦN DUYỆT] ', $warning);
        }
    }

    private function counts(string $patientId): array
    {
        return [
            'conditions' => DB::table('patient_conditions')->where('patient_id', $patientId)->count(),
            'documents' => DB::table('documents')->where('patient_id', $patientId)->count(),
            'events' => DB::table('events')->where('patient_id', $patientId)->count(),
            'labs' => DB::table('lab_results')->where('patient_id', $patientId)->count(),
            'prescription_items' => DB::table('prescription_items')->where('patient_id', $patientId)->count(),
            'prescriptions' => DB::table('prescriptions')->where('patient_id', $patientId)->count(),
            'questions' => DB::table('questions')->where('patient_id', $patientId)->count(),
        ];
    }

    private function patientRecordIds(string $patientId): array
    {
        $tables = ['patient_conditions', 'documents', 'events', 'lab_results', 'prescription_items', 'prescriptions', 'questions'];
        $ids = [];
        foreach ($tables as $table) {
            $ids[$table] = DB::table($table)->where('patient_id', $patientId)->orderBy('id')->pluck('id')->all();
        }
        return $ids;
    }
}
