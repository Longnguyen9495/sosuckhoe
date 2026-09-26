<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_stores_only_a_hash(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0912345678'])
            ->assertOk()
            ->assertJsonMissing(['code' => '123456']);

        $otp = OtpCode::query()->firstOrFail();

        $this->assertNotSame('123456', $otp->getRawOriginal('code_hash'));
        $this->assertSame('0912345678', $otp->recipient);
    }

    public function test_wrong_code_increments_attempt_counter(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '0912345678'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0912345678',
            'code' => '000000',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('otp_codes', ['recipient' => '0912345678', 'attempts' => 1]);
    }

    public function test_fake_code_issues_token_and_cannot_be_reused(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '+84912345678'])->assertOk();

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0912345678',
            'code' => '123456',
            'device_name' => 'Trình duyệt kiểm thử',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'phone']]])
            ->assertJsonPath('data.user.phone', '0912345678');

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => '0912345678',
            'code' => '123456',
        ])->assertUnprocessable();
    }
}
