<?php

namespace Tests\Unit;

use App\Services\Alerts\ReadingEvaluator;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadingEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private ReadingEvaluator $evaluator;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ReadingEvaluator();
        $this->tenantId = (string) Str::ulid();
        $ctx = app()->make(TenantContext::class);
        $ctx->setFromId($this->tenantId);
        DB::table('tenants')->insert(['id' => $this->tenantId, 'name' => 'Test', 'type' => 'family', 'created_at' => now(), 'updated_at' => now()]);
    }
    public function test_evaluates_blood_glucose_good(): void
    {
        $this->seedThreshold('bg_patient', 'blood_glucose', 'pre_meal', [
            'target' => [4.4, 7.2],
            'attention' => [[3.9, 4.4], [7.2, 10]],
            'red' => ['below' => 3.9, 'above' => 10, 'critical_above' => 16.7],
        ]);

        $result = $this->evaluator->evaluate('bg_patient', 'blood_glucose', 'pre_meal', ['value' => 6.0], $this->tenantId);

        $this->assertSame('good', $result['evaluation']);
        $this->assertNull($result['alert_level']);
    }

    public function test_evaluates_blood_glucose_red_below(): void
    {
        $this->seedThreshold('bg_patient', 'blood_glucose', 'pre_meal', [
            'target' => [4.4, 7.2],
            'attention' => [[3.9, 4.4], [7.2, 10]],
            'red' => ['below' => 3.9, 'above' => 10, 'critical_above' => 16.7],
        ]);

        $result = $this->evaluator->evaluate('bg_patient', 'blood_glucose', 'pre_meal', ['value' => 3.5], $this->tenantId);

        $this->assertSame('red', $result['evaluation']);
        $this->assertSame('red', $result['alert_level']);
        // Số thập phân kiểu Việt Nam và có hướng dẫn xử trí hạ đường huyết.
        $this->assertStringContainsString('3,5', $result['alert_content']);
        $this->assertStringContainsString('15 g đường', $result['alert_content']);
        $this->assertStringContainsString('[NHÁP — CẦN DUYỆT]', $result['alert_content']);
    }

    public function test_evaluates_blood_pressure_red_high(): void
    {
        $this->seedThreshold('bp_patient', 'blood_pressure', 'general', [
            'target_below' => ['systolic' => 130, 'diastolic' => 80],
            'red_at_or_above' => ['systolic' => 160, 'diastolic' => 100],
            'red_below' => ['systolic' => 90, 'diastolic' => 60],
        ]);

        $result = $this->evaluator->evaluate('bp_patient', 'blood_pressure', 'general', ['systolic' => 165, 'diastolic' => 105], $this->tenantId);

        $this->assertSame('red', $result['evaluation']);
        $this->assertSame('red', $result['alert_level']);
        $this->assertStringContainsString('165/105', $result['alert_content']);
    }

    public function test_no_threshold_returns_unknown(): void
    {
        $result = $this->evaluator->evaluate('missing_patient', 'blood_glucose', 'pre_meal', ['value' => 5.0], $this->tenantId);

        $this->assertSame('unknown', $result['evaluation']);
        $this->assertNull($result['alert_level']);
    }

    public function test_threshold_from_another_tenant_is_not_used(): void
    {
        $this->seedThreshold('isolated_patient', 'blood_glucose', 'pre_meal', [
            'target' => [4.4, 7.2],
            'red' => ['below' => 3.9, 'above' => 10],
        ]);

        $result = $this->evaluator->evaluate(
            'isolated_patient',
            'blood_glucose',
            'pre_meal',
            ['value' => 2.0],
            (string) Str::ulid(),
        );

        $this->assertSame('unknown', $result['evaluation']);
        $this->assertNull($result['alert_level']);
    }

    public function test_save_alert_only_for_red(): void
    {
        $this->seedThreshold('save_patient', 'blood_glucose', 'pre_meal', [
            'target' => [4.4, 7.2],
            'attention' => [[3.9, 4.4], [7.2, 10]],
            'red' => ['below' => 3.9, 'above' => 10],
        ]);

        $red = $this->evaluator->evaluate('save_patient', 'blood_glucose', 'pre_meal', ['value' => 2.0], $this->tenantId);
        $this->evaluator->saveAlertIfRed('save_patient', 'blood_glucose', 'pre_meal', $red, $this->tenantId);

        $good = $this->evaluator->evaluate('save_patient', 'blood_glucose', 'pre_meal', ['value' => 6.0], $this->tenantId);
        $this->evaluator->saveAlertIfRed('save_patient', 'blood_glucose', 'pre_meal', $good, $this->tenantId);

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('alerts', ['patient_id' => 'save_patient', 'level' => 'red']);
    }

    private function seedThreshold(string $patientId, string $metric, string $context, array $ranges): void
    {
        if (! DB::table('patients')->where('id', $patientId)->exists()) {
            DB::table('patients')->insert([
                'id' => $patientId,
                'tenant_id' => $this->tenantId,
                'full_name' => 'Test Patient ' . $patientId,
                'gender' => 'male',
                'birth_year' => 1980,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('patient_thresholds')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $patientId,
            'metric' => $metric,
            'context' => $context,
            'ranges' => json_encode($ranges, JSON_UNESCAPED_UNICODE),
            'source' => 'template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
