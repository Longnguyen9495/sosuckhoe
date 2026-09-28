<?php

namespace Tests\Feature;

use App\Models\DailyPlan;
use App\Models\Patient;
use App\Models\Tenant;
use App\Services\CarePlan\DailyPlanService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Thực đơn + bài tập đổi mỗi ngày (lệnh careplan:daily). */
class DailyPlanTest extends TestCase
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
            'name' => 'Trần Thị Dung', 'phone' => '0900000111', 'birth_date' => '1950-05-02', 'accepted' => true,
        ])->assertCreated()->json('data');
        $this->auth = ['Authorization' => 'Bearer '.$data['token'], 'X-Tenant-ID' => $data['tenant']['id']];
        $this->patientId = $data['patient']['id'];
        $this->tenantId = $data['tenant']['id'];

        $file = UploadedFile::fake()->createWithContent('don.png', base64_decode(self::PNG));
        $this->withHeaders($this->auth)->post("/api/v1/patients/{$this->patientId}/documents", ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/care-plan")->assertCreated();
    }

    private function day(string $date): array
    {
        return $this->withHeaders($this->auth)->getJson("/api/v1/patients/{$this->patientId}/day/{$date}")->assertOk()->json('data');
    }

    public function test_command_creates_ai_menu_and_day_api_uses_it(): void
    {
        $today = now()->toDateString();
        $this->artisan('careplan:daily')->assertSuccessful();

        app(TenantContext::class)->set(Tenant::findOrFail($this->tenantId));
        $row = DailyPlan::firstOrFail();
        $this->assertSame('ai', $row->source);
        // Tên thứ AI ghi kèm bị bỏ.
        $this->assertStringStartsWith('Bún gạo lứt', $row->menu['breakfast']);

        $day = $this->day($today);
        $this->assertSame($row->menu['breakfast'], $day['menu']['breakfast']);
        $this->assertSame($row->menu['breakfast'], collect($day['items'])->firstWhere('key', 'meal:breakfast')['diet_note']);
        $this->assertSame('ai', $day['daily']['source']);
        $this->assertNotEmpty($day['daily']['tip']);
        $this->assertNotEmpty($day['daily']['exercises']);
        $this->assertNotContains(DailyPlanService::TIPS_ID, array_column($day['daily']['exercises'], 'id'), 'Bài kiến thức không nằm trong vòng luân phiên.');
    }

    public function test_running_twice_the_same_day_updates_instead_of_duplicating(): void
    {
        $this->artisan('careplan:daily')->assertSuccessful();
        $this->artisan('careplan:daily')->assertSuccessful();
        // AI lỗi ở lần chạy sau: vẫn giữ thực đơn AI đã có, không tụt về thực đơn tuần.
        $this->app->bind(\App\Contracts\MedicalAiClient::class, fn () => new class implements \App\Contracts\MedicalAiClient
        {
            public function analyzeDocument(string $binary, string $mime): array
            {
                return (new \App\Services\Ai\FakeMedicalAiClient)->analyzeDocument($binary, $mime);
            }

            public function generateCarePlan(array $context): array
            {
                return (new \App\Services\Ai\FakeMedicalAiClient)->generateCarePlan($context);
            }

            public function generateDailyMenu(array $context): array
            {
                throw new \RuntimeException('Dịch vụ AI trả lỗi (503).');
            }

            public function model(): string
            {
                return 'test';
            }
        });
        $this->artisan('careplan:daily')->assertSuccessful()->expectsOutputToContain('thực đơn AI mới');

        app(TenantContext::class)->set(Tenant::findOrFail($this->tenantId));
        $this->assertSame(1, DailyPlan::count());
        $this->assertSame('ai', DailyPlan::firstOrFail()->source);
    }

    public function test_exercises_rotate_between_days(): void
    {
        app(TenantContext::class)->set(Tenant::findOrFail($this->tenantId));
        $service = app(DailyPlanService::class);
        $patient = Patient::findOrFail($this->patientId);
        $plan = app(\App\Services\CarePlan\CarePlanService::class)->latest($patient);

        $monday = CarbonImmutable::parse('2026-09-28');
        $picks = array_map(fn ($i) => $service->rotation($plan, $patient, $monday->addDays($i)), range(0, 6));

        $this->assertNotSame($picks[0], $picks[1], 'Hôm sau khác hôm trước.');
        $this->assertGreaterThanOrEqual(4, count(array_unique(array_merge(...$picks))), 'Một tuần xoay qua nhiều bài khác nhau.');
        foreach ($picks as $p) {
            $this->assertSame($p, array_values(array_unique($p)), 'Không lặp bài trong cùng một ngày.');
        }
    }

    public function test_without_daily_row_falls_back_to_weekly_menu(): void
    {
        $day = $this->day(now()->toDateString());
        $this->assertSame('weekly', $day['daily']['source']);
        $this->assertNotEmpty($day['menu']['breakfast']);
        $this->assertNotEmpty($day['daily']['exercises']);
    }

    public function test_new_care_plan_clears_upcoming_daily_plans(): void
    {
        $this->artisan('careplan:daily')->assertSuccessful();
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/care-plan")->assertCreated();

        app(TenantContext::class)->set(Tenant::findOrFail($this->tenantId));
        $this->assertSame(0, DailyPlan::count());
    }
}
