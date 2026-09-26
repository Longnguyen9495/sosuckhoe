<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineQueueCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_sw_js_contains_clear_queue_for_user_logic(): void
    {
        $sw = file_get_contents(base_path('public/sw.js'));
        $this->assertStringContainsString('clearQueueForUser', $sw);
        $this->assertStringContainsString("action === 'clear-queue'", $sw);
        $this->assertStringContainsString('userId', $sw);
        $this->assertStringContainsString('createdAt', $sw);
    }

    public function test_app_js_sends_clear_queue_message_on_logout(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('postMessageToSw', $js);
        $this->assertStringContainsString('clear-queue', $js);
        $this->assertStringContainsString('getUserIdFromToken', $js);
    }

    public function test_otp_verify_returns_user_id(): void
    {
        $phone = '0987654321';
        $code = '123456';
        OtpCode::create([
            'recipient' => $phone,
            'code_hash' => hash_hmac('sha256', $phone . '|' . $code, (string) config('app.key')),
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => $code,
            'device_name' => 'test',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.user_id', fn ($id) => (bool) $id);
    }
}
