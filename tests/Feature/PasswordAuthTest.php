<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Nguyễn Văn Đức',
            'phone' => '0912 345 678',
            'birth_date' => '1958-03-14',
            'accepted' => true,
        ], $overrides));
    }

    public function test_register_creates_account_profile_and_default_password(): void
    {
        $response = $this->register()->assertCreated();

        $data = $response->json('data');
        $this->assertSame('nguyenvanduc5678', $data['default_password']);
        $this->assertSame('patient', $data['patient']['access_role']);
        $this->assertSame(1958, $data['patient']['birth_year']);
        $this->assertTrue($data['user']['uses_default_password']);

        $user = User::where('phone', '0912345678')->firstOrFail();
        $this->assertSame('1958-03-14', $user->birth_date->toDateString());
        $this->assertNotSame('nguyenvanduc5678', $user->password, 'Mật khẩu phải được băm.');

        // Token dùng được ngay với sổ vừa tạo.
        $this->withHeaders(['Authorization' => 'Bearer '.$data['token'], 'X-Tenant-ID' => $data['tenant']['id']])
            ->getJson('/api/v1/patients')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Nguyễn Văn Đức');

        app(TenantContext::class)->set(\App\Models\Tenant::findOrFail($data['tenant']['id']));
        $this->assertSame('1958-03-14', Patient::firstOrFail()->birth_date->toDateString());
    }

    public function test_register_requires_consent_and_valid_data(): void
    {
        $this->register(['accepted' => false])->assertStatus(422)->assertJsonValidationErrors('accepted');
        $this->register(['phone' => '12345'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->register(['birth_date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('birth_date');
        $this->assertSame(0, User::count());
    }

    public function test_existing_phone_must_log_in_instead(): void
    {
        $this->register()->assertCreated();
        $this->register(['name' => 'Người khác'])->assertStatus(409)->assertJsonPath('code', 'PHONE_EXISTS');
        $this->assertSame(1, User::count());
    }

    public function test_login_with_phone_and_default_password(): void
    {
        $this->register()->assertCreated();

        $this->postJson('/api/v1/auth/login', ['phone' => '+84912345678', 'password' => 'nguyenvanduc5678'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user_id', 'user']]);

        $this->postJson('/api/v1/auth/login', ['phone' => '0912345678', 'password' => 'sai-mat-khau'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/login', ['phone' => '0999999999', 'password' => 'nguyenvanduc5678'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_user_can_change_password(): void
    {
        $token = $this->register()->json('data.token');
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($auth)->postJson('/api/v1/auth/password', ['current_password' => 'sai', 'password' => 'matkhaumoi123', 'password_confirmation' => 'matkhaumoi123'])
            ->assertStatus(422);
        $this->withHeaders($auth)->postJson('/api/v1/auth/password', ['current_password' => 'nguyenvanduc5678', 'password' => 'matkhaumoi123', 'password_confirmation' => 'matkhaumoi123'])
            ->assertOk();
        $this->withHeaders($auth)->getJson('/api/v1/auth/me')->assertJsonPath('data.uses_default_password', false);

        $this->postJson('/api/v1/auth/login', ['phone' => '0912345678', 'password' => 'nguyenvanduc5678'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['phone' => '0912345678', 'password' => 'matkhaumoi123'])->assertOk();
    }
}
