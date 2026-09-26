<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationSweepTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;
    private string $patientId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::ulid();
        $this->patientId = (string) Str::ulid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Phòng khám thử nghiệm',
            'type' => 'clinic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('patients')->insert([
            'id' => $this->patientId,
            'tenant_id' => $this->tenantId,
            'full_name' => 'Bệnh nhân thử nghiệm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create();
        DB::table('tenant_members')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'user_id' => $this->user->getKey(),
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('patient_access')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenantId,
            'patient_id' => $this->patientId,
            'user_id' => $this->user->getKey(),
            'role' => 'caregiver',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Tự động tìm mọi route có tham số {patient} hoặc {patient?}.
     * Với mỗi route, tạo một patient thuộc tenant khác (không có quyền) và kiểm tra rằng
     * request trả về 404 (bị chặn bởi scopeBindings + TenantModel global scope).
     */
    public function test_all_patient_routes_hide_cross_tenant_patient(): void
    {
        $otherTenantId = (string) Str::ulid();
        $otherPatientId = (string) Str::ulid();

        DB::table('tenants')->insert([
            'id' => $otherTenantId,
            'name' => 'Tenant khác',
            'type' => 'clinic',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('patients')->insert([
            'id' => $otherPatientId,
            'tenant_id' => $otherTenantId,
            'full_name' => 'Bệnh nhân khác',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $routes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), '{patient'))
            ->filter(fn ($route) => in_array('api', $route->middleware()))
            ->map(fn ($route) => [
                'uri' => $route->uri(),
                'methods' => array_values(array_filter($route->methods(), fn ($m) => $m !== 'HEAD')),
            ])
            ->values();

        $this->assertNotEmpty($routes, 'Không tìm thấy route nào có tham số {patient}');

        foreach ($routes as $route) {
            $uri = $route['uri'];
            foreach ($route['methods'] as $method) {
                // Thay {patient} và {patient?}
                $testUri = str_replace(['{patient}', '{patient?}'], $otherPatientId, $uri);

                // Nếu còn tham số động khác, thay bằng ULID hợp lệ giả định
                if (preg_match('/\{[^}]+\}/', $testUri, $matches)) {
                    $testUri = preg_replace_callback('/\{[^}]+\}/', fn () => (string) Str::ulid(), $testUri);
                }

                $response = $this->actingAs($this->user)
                    ->withHeader('X-Tenant-ID', $this->tenantId)
                    ->json($method, $testUri, []);

                // Kỳ vọng 404 vì scopeBindings sẽ không tìm thấy patient thuộc tenant hiện tại
                $this->assertTrue(
                    $response->status() === 404,
                    "Route {$method} {$uri} trả về {$response->status()} thay vì 404 với patient cross-tenant."
                );
            }
        }
    }
}
