<?php

namespace Tests\Feature;

use App\Contracts\MedicalAiClient;
use App\Services\Ai\FakeMedicalAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Nhận xét đường huyết từng ngày: số liệu do code tính, AI viết lời, lọc lời khuyên đổi liều, dự phòng khi AI lỗi. */
class GlucoseNoteTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private array $auth;

    private string $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $data = $this->postJson('/api/v1/auth/register', [
            'name' => 'Trần Thị Dung', 'phone' => '0900000111', 'birth_date' => '1968-05-02', 'accepted' => true,
        ])->assertCreated()->json('data');
        $this->auth = ['Authorization' => 'Bearer '.$data['token'], 'X-Tenant-ID' => $data['tenant']['id']];
        $this->patientId = $data['patient']['id'];
        // Đơn thuốc mẫu của AI giả có HbA1c 8,5%.
        $file = UploadedFile::fake()->createWithContent('don.png', base64_decode(self::PNG));
        $this->withHeaders($this->auth)->post("/api/v1/patients/{$this->patientId}/documents", ['file' => $file], ['Accept' => 'application/json'])->assertCreated();
    }

    private function reading(string $date, string $time, string $context, float $value): void
    {
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/readings", [
            'type' => 'blood_glucose', 'context' => $context, 'measured_at' => "{$date} {$time}:00", 'values' => ['value' => $value],
        ])->assertCreated();
    }

    private function note(string $date, string $method = 'get'): array
    {
        return $this->withHeaders($this->auth)->{$method.'Json'}("/api/v1/patients/{$this->patientId}/glucose-note/{$date}")->assertOk()->json('data');
    }

    public function test_day_without_readings_needs_no_ai(): void
    {
        $data = $this->note(now()->toDateString());

        $this->assertFalse($data['needs_ai']);
        $this->assertNull($data['note']);
        $this->assertSame('Hôm nay chưa đo đường huyết.', $data['fallback']['summary']);
        $this->assertNull($this->note(now()->toDateString(), 'post')['note'], 'Không gọi AI khi chưa có số đo.');
    }

    public function test_stats_compare_targets_last_week_and_hba1c_then_ai_writes_safe_note(): void
    {
        $day = now()->startOfDay();
        foreach ([3, 2, 1] as $ago) {
            $this->reading($day->copy()->subDays($ago)->toDateString(), '06:30', 'fasting', 9.0);
        }
        $this->reading($day->toDateString(), '06:30', 'fasting', 8.0);
        $this->reading($day->toDateString(), '14:00', 'post_lunch', 3.5);

        $data = $this->note($day->toDateString());
        $stats = $data['stats'];
        $this->assertTrue($data['needs_ai']);
        $this->assertNull($data['note']);
        $this->assertSame(2, $stats['today']['count']);
        $this->assertSame(1, $stats['today']['hypo']);
        $this->assertSame(1, $stats['today']['high']);
        $this->assertSame(4, $stats['last_7_days']['fasting_high_streak_days']);
        $this->assertEquals(9.0, $stats['last_7_days']['fasting_avg']);
        $this->assertEquals(-1.0, $stats['last_7_days']['same_point'][0]['diff']);
        // eAG = (28,7 × 8,5 − 46,7) / 18 ≈ 11,0 mmol/L.
        $this->assertEquals(8.5, $stats['hba1c']['value']);
        $this->assertEquals(11.0, $stats['hba1c']['estimated_avg_glucose']);
        $points = implode(' | ', array_column($data['fallback']['points'], 'text'));
        $this->assertStringContainsString('dưới 3,9', $points);
        $this->assertStringContainsString('4 ngày liền', $points);
        $this->assertNotNull($data['fallback']['ask_doctor']);

        $written = $this->note($day->toDateString(), 'post');
        $this->assertFalse($written['needs_ai']);
        $this->assertSame('ai', $written['note']['source']);
        $this->assertCount(2, $written['note']['points'], 'Ý "tăng liều insulin" của AI bị loại.');
        $this->assertStringNotContainsString('liều', implode(' ', array_column($written['note']['points'], 'text')));

        // Đã có nhận xét khớp dữ liệu: mở lại không cần AI; thêm số đo thì cần viết lại.
        $this->assertFalse($this->note($day->toDateString())['needs_ai']);
        $this->reading($day->toDateString(), '18:00', 'pre_dinner', 6.1);
        $again = $this->note($day->toDateString());
        $this->assertTrue($again['needs_ai']);
        $this->assertNull($again['note']);
    }

    public function test_ai_failure_or_unsafe_summary_falls_back_to_rule_note(): void
    {
        $this->reading(now()->toDateString(), '06:30', 'fasting', 6.0);
        $this->app->bind(MedicalAiClient::class, fn () => new class implements MedicalAiClient
        {
            public function analyzeDocument(string $binary, string $mime): array
            {
                return (new FakeMedicalAiClient)->analyzeDocument($binary, $mime);
            }

            public function generateCarePlan(array $context): array
            {
                return [];
            }

            public function generateDailyMenu(array $context): array
            {
                return [];
            }

            public function generateGlucoseNote(array $context): array
            {
                return ['summary' => 'Nên giảm liều insulin tối nay.', 'points' => []];
            }

            public function model(): string
            {
                return 'test';
            }
        });

        $data = $this->note(now()->toDateString(), 'post');
        $this->assertSame('rule', $data['note']['source']);
        $this->assertSame('Hôm nay đo 1 lần: 1 lần trong mục tiêu.', $data['note']['summary']);
        $this->assertFalse($data['needs_ai'], 'Không gọi AI lại ngay; thử lại sau vài phút.');
    }
}
