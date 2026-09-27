<?php

namespace App\Services\Onboarding;

use App\Models\Consent;
use App\Models\ConsentVersion;
use App\Models\Patient;
use App\Models\PatientAccess;
use App\Models\PatientRoutine;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\DefaultPassword;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Đăng ký nhanh: một lần nhập tên + SĐT + ngày sinh tạo luôn tài khoản,
 * sổ sức khỏe cá nhân (tenant), hồ sơ người bệnh và giờ sinh hoạt mặc định.
 */
final class QuickProfileService
{
    /** @return array{user: User, tenant: Tenant, patient: Patient, default_password: string} */
    public function register(string $name, string $phone, CarbonImmutable $birthDate, ?string $ip = null): array
    {
        $password = DefaultPassword::for($name, $phone);

        return DB::transaction(function () use ($name, $phone, $birthDate, $password, $ip) {
            $user = User::query()->create([
                'name' => $name,
                'phone' => $phone,
                'birth_date' => $birthDate->toDateString(),
                'password' => $password,
            ]);

            $tenant = Tenant::create(['name' => 'Sổ sức khỏe của '.$name, 'type' => 'family']);
            $context = app(TenantContext::class);
            $context->set($tenant);

            TenantMember::create(['user_id' => $user->id, 'role' => 'owner']);

            $version = $this->currentConsentVersion();
            if ($version !== null) {
                Consent::create([
                    'user_id' => $user->id,
                    'consent_version_id' => $version->id,
                    'consented_at' => now()->toDateTimeString(),
                    'ip_address' => $ip,
                ]);
            }

            $patient = Patient::create([
                'full_name' => $name,
                'birth_year' => $birthDate->year,
                'birth_date' => $birthDate->toDateString(),
                'gender' => 'unknown',
            ]);

            PatientAccess::create(['patient_id' => $patient->id, 'user_id' => $user->id, 'role' => 'patient']);

            PatientRoutine::create([
                'patient_id' => $patient->id,
                'wake_time' => '06:00',
                'breakfast_time' => '07:00',
                'lunch_time' => '12:00',
                'dinner_time' => '18:30',
                'sleep_time' => '22:00',
                'effective_from' => CarbonImmutable::today()->toDateString(),
            ]);

            return ['user' => $user, 'tenant' => $tenant, 'patient' => $patient, 'default_password' => $password];
        });
    }

    private function currentConsentVersion(): ?ConsentVersion
    {
        return ConsentVersion::query()
            ->when(app()->isProduction(), fn ($q) => $q->where('is_draft', false))
            ->where('effective_date', '<=', now()->toDateString())
            ->orderBy('is_draft')
            ->orderByDesc('effective_date')
            ->first();
    }
}
