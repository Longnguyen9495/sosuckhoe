<?php

namespace Tests\Feature;

use App\Contracts\MeterAiClient;
use App\Models\Reading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Chụp máy đo: AI tự nhận loại máy, code kiểm tra số (mg/dL, LO/HI, số vô lý, bộ nhớ), không lưu gì cho tới khi xác nhận. */
class MeterReadTest extends TestCase
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
    }

    private function fakeMeter(array|RuntimeException $result): void
    {
        $this->app->bind(MeterAiClient::class, fn () => new class($result) implements MeterAiClient
        {
            public function __construct(private readonly array|RuntimeException $result) {}

            public function readMeter(string $binary, string $mime): array
            {
                if ($this->result instanceof RuntimeException) {
                    throw $this->result;
                }

                return $this->result;
            }
        });
    }

    private function read(): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('may.png', base64_decode(self::PNG));

        return $this->withHeaders($this->auth)->post("/api/v1/patients/{$this->patientId}/meter-read", ['image' => $file], ['Accept' => 'application/json']);
    }

    public function test_glucose_meter_is_read_but_nothing_is_saved(): void
    {
        $data = $this->read()->assertOk()->json('data');

        $this->assertSame('blood_glucose', $data['device']);
        $this->assertEquals(7.2, $data['glucose']['value']);
        $this->assertFalse($data['glucose']['converted']);
        $this->assertSame([], $data['warnings']);
        $this->assertSame(0, Reading::count(), 'Chỉ lưu khi người bệnh bấm xác nhận.');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Không giữ ảnh máy đo.');
    }

    public function test_mg_dl_is_converted_even_when_unit_is_missing(): void
    {
        $this->fakeMeter(['device' => 'blood_glucose', 'readable' => true, 'glucose' => ['value' => 126, 'unit' => null, 'flag' => null], 'memory_view' => true]);
        $data = $this->read()->assertOk()->json('data');

        $this->assertEquals(7.0, $data['glucose']['value']);
        $this->assertTrue($data['glucose']['converted']);
        $this->assertSame('126 mg/dL', $data['glucose']['seen']);
        $this->assertCount(2, $data['warnings'], 'Cảnh báo đổi đơn vị + số cũ trong bộ nhớ.');
    }

    public function test_lo_flag_is_passed_through_without_a_value(): void
    {
        $this->fakeMeter(['device' => 'blood_glucose', 'readable' => true, 'glucose' => ['value' => null, 'unit' => null, 'flag' => 'Lo']]);
        $data = $this->read()->assertOk()->json('data');

        $this->assertSame('blood_glucose', $data['device']);
        $this->assertSame('LO', $data['flag']);
        $this->assertNull($data['glucose']);
    }

    public function test_blood_pressure_monitor_is_recognised_and_swapped_numbers_fixed(): void
    {
        $this->fakeMeter(['device' => 'blood_pressure', 'readable' => true, 'glucose' => null, 'blood_pressure' => ['systolic' => '82', 'diastolic' => 135, 'pulse' => 300]]);
        $data = $this->read()->assertOk()->json('data');

        $this->assertSame('blood_pressure', $data['device']);
        $this->assertSame(['systolic' => 135, 'diastolic' => 82, 'heart_rate' => null], $data['blood_pressure']);
    }

    public function test_implausible_or_unknown_readings_ask_to_retake(): void
    {
        $this->fakeMeter(['device' => 'blood_glucose', 'readable' => true, 'glucose' => ['value' => 7.2, 'unit' => 'mg/dL', 'flag' => null]]);
        $data = $this->read()->assertOk()->json('data');
        $this->assertNull($data['device'], '7,2 mg/dL = 0,4 mmol/L: đọc nhầm, không đưa lên xác nhận.');
        $this->assertStringContainsString('Chưa đọc rõ', $data['message']);

        $this->fakeMeter(['device' => 'unknown', 'readable' => false]);
        $this->assertStringContainsString('Không thấy màn hình máy đo', $this->read()->assertOk()->json('data.message'));
    }

    public function test_ai_failure_returns_friendly_error(): void
    {
        $this->fakeMeter(new RuntimeException('down'));

        $this->read()->assertStatus(503)->assertJsonPath('message', 'Chưa đọc được ảnh lúc này. Bạn gõ số giúp nhé.');
    }

    public function test_image_is_required(): void
    {
        $this->withHeaders($this->auth)->postJson("/api/v1/patients/{$this->patientId}/meter-read", [])->assertStatus(422);
    }
}
