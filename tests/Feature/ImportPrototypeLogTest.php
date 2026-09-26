<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportPrototypeLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_imports_old_log_idempotently_without_old_evaluations(): void
    {
        [$tenantId, $patientId] = $this->createTarget();
        $path = storage_path('framework/testing/prototype-log.json');
        file_put_contents($path, json_encode([
            '2026-09-27' => [
                'chk' => ['ins_am' => true, 'walk' => false],
                'glu' => ['fast' => '6,5', 'post_dinner' => '8.2'],
                'bp' => ['am' => ['sys' => '125', 'dia' => '78', 'hr' => '82']],
                'water' => 7,
                'sym' => ['dizzy'],
                'note' => 'Ghi chú thử nghiệm',
            ],
            '_qa' => ['0-0' => true],
        ], JSON_THROW_ON_ERROR));

        try {
            $arguments = [
                'tenant' => $tenantId,
                'patient' => $patientId,
                '--path' => $path,
            ];
            $this->artisan('prototype:import-log', $arguments)
                ->expectsOutputToContain('Đã nhập 1 ngày và 3 chỉ số')
                ->assertSuccessful();
            $this->artisan('prototype:import-log', $arguments)->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseCount('logs', 1);
        $this->assertDatabaseCount('readings', 3);
        $this->assertSame(0, DB::table('readings')->whereNotNull('evaluation')->count());

        $log = DB::table('logs')->first();
        $meta = json_decode($log->meta, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['ins_am'], $meta['checks']);
        $this->assertSame(7, $meta['water_cups']);
        $this->assertSame(['dizzy'], $meta['symptoms']);
        $this->assertSame('Ghi chú thử nghiệm', $meta['note']);

        $glucose = DB::table('readings')->where('type', 'glucose')->where('context', 'fast')->first();
        $this->assertSame(6.5, json_decode($glucose->values, true, flags: JSON_THROW_ON_ERROR)['value']);
    }

    public function test_command_fails_cleanly_when_source_file_does_not_exist(): void
    {
        [$tenantId, $patientId] = $this->createTarget();

        $this->artisan('prototype:import-log', [
            'tenant' => $tenantId,
            'patient' => $patientId,
            '--path' => storage_path('framework/testing/missing-log.json'),
        ])->assertFailed();

        $this->assertDatabaseCount('logs', 0);
        $this->assertDatabaseCount('readings', 0);
    }

    private function createTarget(): array
    {
        $tenantId = (string) Str::ulid();
        $patientId = (string) Str::ulid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Gia đình nhập liệu',
            'type' => 'family',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('patients')->insert([
            'id' => $patientId,
            'tenant_id' => $tenantId,
            'full_name' => 'Bệnh nhân nhập liệu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenantId, $patientId];
    }
}
