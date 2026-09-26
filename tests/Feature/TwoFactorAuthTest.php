<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactor\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OTPHP\TOTP;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private function createDoctorWithPatient(): array
    {
        $tenantId = (string) Str::ulid();
        $patientId = (string) Str::ulid();
        $user = User::factory()->create();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'PK thử nghiệm',
            'type' => 'clinic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('patients')->insert([
            'id' => $patientId,
            'tenant_id' => $tenantId,
            'full_name' => 'BN thử nghiệm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenant_members')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'user_id' => $user->getKey(),
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'patient_id' => $patientId,
            'user_id' => $user->getKey(),
            'role' => 'doctor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $tenantId, $patientId];
    }

    public function test_user_can_setup_totp_and_get_secret_and_qr(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/setup');

        $response->assertOk()
            ->assertJsonStructure(['secret', 'qr_svg']);

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    public function test_user_can_confirm_totp_with_valid_code(): void
    {
        $user = User::factory()->create();
        $totp = new TotpService();
        $secret = $totp->generateSecret();
        $totp->storeSecret($user, $secret);

        $code = TOTP::create($secret)->now();

        $response = $this->actingAs($user->fresh())
            ->postJson('/api/v1/auth/2fa/confirm', ['code' => $code]);

        $response->assertOk()
            ->assertJsonPath('message', '2FA đã được kích hoạt.')
            ->assertJsonCount(8, 'recovery_codes');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_confirm_fails_with_invalid_code(): void
    {
        $user = User::factory()->create();
        $totp = new TotpService();
        $totp->storeSecret($user, $totp->generateSecret());

        $this->actingAs($user->fresh())
            ->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_doctor_without_2fa_token_is_denied_patient_access(): void
    {
        [$user, $tenantId, $patientId] = $this->createDoctorWithPatient();
        $totp = new TotpService();
        $secret = $totp->generateSecret();
        $totp->storeSecret($user, $secret);
        $totp->confirm($user, TOTP::create($secret)->now());
        $user->refresh();

        // Tạo token KHÔNG có ability 2fa-passed
        $token = $user->createToken('test', ['basic'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant-ID', $tenantId)
            ->getJson("/api/v1/patients/{$patientId}")
            ->assertForbidden()
            ->assertJsonPath('code', 'DOCTOR_TWO_FACTOR_CHALLENGE_REQUIRED');
    }

    public function test_doctor_can_pass_challenge_and_get_2fa_token(): void
    {
        $user = User::factory()->create();
        $totp = new TotpService();
        $secret = $totp->generateSecret();
        $totp->storeSecret($user, $secret);
        $totp->confirm($user, TOTP::create($secret)->now());
        $user->refresh();

        $code = TOTP::create($totp->decryptSecret($user))->now();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/challenge', ['code' => $code]);

        $response->assertOk()
            ->assertJsonStructure(['token']);

        $this->assertTrue(
            in_array('2fa-passed', $user->tokens()->first()->abilities ?? [], true)
        );
    }

    public function test_recovery_code_works_as_challenge(): void
    {
        $user = User::factory()->create();
        $totp = new TotpService();
        $secret = $totp->generateSecret();
        $totp->storeSecret($user, $secret);
        $totp->confirm($user, TOTP::create($secret)->now());
        $user->refresh();

        $codes = $totp->decryptRecoveryCodes($user->two_factor_recovery_codes);
        $recoveryCode = $codes[0];

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/challenge', ['code' => $recoveryCode]);

        $response->assertOk()
            ->assertJsonPath('message', 'Xác thực bằng mã khôi phục thành công.');

        // Recovery code bị tiêu thụ
        $remaining = $totp->decryptRecoveryCodes($user->fresh()->two_factor_recovery_codes);
        $this->assertCount(7, $remaining);
    }

    public function test_user_can_regenerate_recovery_codes(): void
    {
        $user = User::factory()->create();
        $totp = new TotpService();
        $secret = $totp->generateSecret();
        $totp->storeSecret($user, $secret);
        $totp->confirm($user, TOTP::create($secret)->now());
        $user->refresh();

        $code = TOTP::create($totp->decryptSecret($user))->now();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/recovery-codes', ['code' => $code]);

        $response->assertOk()
            ->assertJsonCount(8, 'recovery_codes');
    }
}
