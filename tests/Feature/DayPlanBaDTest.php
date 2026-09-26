<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dữ liệu mẫu bà D. phải cho ra đúng lịch trong PLAN.md mục 4.1–4.2 và ngày hết thuốc mục 3.
 */
class DayPlanBaDTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private string $tenantId;
    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);

        $user = User::where('phone', '0900000001')->firstOrFail();
        $this->token = $user->createToken('test', ['basic'])->plainTextToken;
        $this->tenantId = DB::table('tenants')->where('name', 'Gia đình bà D.')->value('id');
        $this->patientId = DB::table('patients')->where('tenant_id', $this->tenantId)->value('id');
    }

    private function api(string $path)
    {
        app(TenantContext::class)->clear();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'X-Tenant-ID' => $this->tenantId])->getJson('/api/v1/patients/'.$this->patientId.$path);
    }

    public function test_day_one_matches_plan_section_4_1(): void
    {
        $data = $this->api('/day/2026-09-27')->assertOk()->json('data');

        $rows = array_map(
            fn ($item) => $item['time'].' '.$item['type'].' '.$item['title'].(isset($item['amount_text']) && $item['amount_text'] ? ' · '.$item['amount_text'] : ''),
            array_values(array_filter($data['items'], fn ($item) => $item['type'] !== 'meal')),
        );

        $this->assertSame([
            '06:00 medication Etiheso 40 mg · 1 viên',
            '06:45 measurement Đường huyết lúc đói (trước ăn sáng)',
            '06:50 measurement Huyết áp + mạch buổi sáng',
            '06:55 insulin NovoMix 30 FlexPen · 16 UI',
            '07:30 medication Abricotis · 1 viên',
            '07:30 medication Celebrex 200 mg · 1 viên',
            '07:30 medication Esserose 450 mg · 1 viên',
            '07:30 medication Janumet 50/850 mg · 1 viên',
            '07:30 medication Livosil 140 mg · 1 viên',
            '07:30 medication Myopain 50 mg · 1 viên',
            '07:30 medication Oztis · 1 viên',
            '07:40 topical Gel Nociceptol 120 ml · Bôi vùng đau',
            '11:50 measurement Đường huyết trước ăn trưa',
            '12:30 medication Abricotis · 1 viên',
            '12:30 medication Hepazid 25 mg · 1 viên',
            '12:40 topical Gel Nociceptol 120 ml · Bôi vùng đau',
            '18:15 measurement Đường huyết trước ăn tối',
            '18:25 insulin NovoMix 30 FlexPen · 14 UI',
            '19:00 medication Esserose 450 mg · 1 viên',
            '19:00 medication Janumet 50/850 mg · 1 viên',
            '19:00 medication Livosil 140 mg · 1 viên',
            '19:00 medication Myopain 50 mg · 1 viên',
            '19:00 medication Oztis · 1 viên',
            '19:10 topical Gel Nociceptol 120 ml · Bôi vùng đau',
            '21:00 measurement Huyết áp + mạch buổi tối',
            '21:30 measurement Đường huyết trước khi ngủ',
        ], $rows);
        $this->assertSame(['done' => 0, 'total' => 26, 'percent' => 0], $data['summary']);
        $this->assertSame('phase_1', $data['monitoring_phase']['phase']);
    }

    public function test_sunday_in_phase_two_measures_six_glucose_points(): void
    {
        $data = $this->api('/day/2026-10-11')->assertOk()->json('data');
        $points = array_values(array_map(fn ($i) => $i['point'], array_filter($data['items'], fn ($i) => $i['type'] === 'measurement')));

        $this->assertSame(['fasting', 'bp_morning', 'post_breakfast', 'pre_lunch', 'post_lunch', 'pre_dinner', 'post_dinner'], $points);
    }

    public function test_rehab_course_ends_on_26_october(): void
    {
        $titles = fn ($date) => array_column($this->api('/day/'.$date)->json('data.items'), 'title');

        $this->assertContains('Celebrex 200 mg', $titles('2026-10-26'));
        $this->assertNotContains('Celebrex 200 mg', $titles('2026-10-27'));
        $this->assertContains('Hepazid 25 mg', $titles('2026-10-27'));
    }

    public function test_run_out_dates_match_plan_section_3(): void
    {
        $items = collect($this->api('/prescriptions?date=2026-09-27')->assertOk()->json('data'))->flatMap(fn ($p) => $p['items'])->keyBy('drug_name');

        $this->assertSame('2026-10-31', $items['NovoMix 30 FlexPen']['runs_out_on']);
        $this->assertSame('2026-11-10', $items['Livosil 140 mg']['runs_out_on'], 'Livosil tính theo 90 viên đã mua, không phải 180 viên kê.');
        $this->assertSame('2026-12-25', $items['Hepazid 25 mg']['runs_out_on']);
        $this->assertSame([['time' => '06:55', 'amount_text' => '16 UI'], ['time' => '18:25', 'amount_text' => '14 UI']], $items['NovoMix 30 FlexPen']['times']);
    }

    public function test_ticking_a_dose_and_logging_low_glucose_updates_the_day(): void
    {
        $day = $this->api('/day/2026-09-27')->json('data');
        $etiheso = collect($day['items'])->firstWhere('title', 'Etiheso 40 mg');

        $headers = ['Authorization' => 'Bearer '.$this->token, 'X-Tenant-ID' => $this->tenantId];
        app(TenantContext::class)->clear();
        $this->withHeaders($headers)->postJson('/api/v1/patients/'.$this->patientId.'/logs', [
            'log_date' => '2026-09-27', 'schedule_item_id' => $etiheso['schedule_item_id'], 'completed' => true,
        ])->assertCreated();

        app(TenantContext::class)->clear();
        $reading = $this->withHeaders($headers)->postJson('/api/v1/patients/'.$this->patientId.'/readings', [
            'type' => 'blood_glucose', 'context' => 'fasting', 'measured_at' => '2026-09-27 06:45:00', 'values' => ['value' => 3.6],
        ])->assertCreated();
        $this->assertSame('red', $reading->json('data.evaluation.level'));
        $this->assertStringContainsString('15 g đường', $reading->json('alert'));

        $after = $this->api('/day/2026-09-27')->json('data');
        $this->assertTrue(collect($after['items'])->firstWhere('title', 'Etiheso 40 mg')['done']);
        $fasting = collect($after['items'])->firstWhere('point', 'fasting');
        $this->assertTrue($fasting['done']);
        $this->assertSame(3.6, (float) $fasting['reading']['values']['value']);
        $this->assertSame(2, $after['summary']['done']);
        $this->assertDatabaseHas('alerts', ['patient_id' => $this->patientId, 'level' => 'red']);
    }
}
