<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Patient;
use App\Models\PatientRoutine;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Services\Document\DocumentEncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SettingsController extends Controller
{
    public function show(Patient $patient): JsonResponse
    {
        Gate::authorize('view', $patient);

        $routine = $patient->routines()->orderByDesc('effective_from')->first();

        return response()->json([
            'routines' => $routine ? [
                'wake_time' => $routine->wake_time,
                'breakfast_time' => $routine->breakfast_time,
                'lunch_time' => $routine->lunch_time,
                'dinner_time' => $routine->dinner_time,
                'sleep_time' => $routine->sleep_time,
            ] : null,
            'patient' => [
                'full_name' => $patient->full_name,
                'birth_year' => $patient->birth_year,
                'gender' => $patient->gender,
                'allergies' => $patient->allergies,
            ],
        ]);
    }

    public function updateRoutines(Request $request, Patient $patient): JsonResponse
    {
        Gate::authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'wake_time' => ['required', 'date_format:H:i'],
            'breakfast_time' => ['required', 'date_format:H:i'],
            'lunch_time' => ['required', 'date_format:H:i'],
            'dinner_time' => ['required', 'date_format:H:i'],
            'sleep_time' => ['required', 'date_format:H:i'],
        ]);

        // Giờ mới áp dụng từ ngày mai; lịch hôm nay và trước đó giữ nguyên.
        $tomorrow = \Carbon\CarbonImmutable::tomorrow();
        PatientRoutine::updateOrCreate(
            ['patient_id' => $patient->id, 'effective_from' => $tomorrow->toDateString()],
            [
                'wake_time' => $validated['wake_time'],
                'breakfast_time' => $validated['breakfast_time'],
                'lunch_time' => $validated['lunch_time'],
                'dinner_time' => $validated['dinner_time'],
                'sleep_time' => $validated['sleep_time'],
            ],
        );
        $result = app(\App\Services\Schedule\PatientScheduleOrchestrator::class)->regenerateSchedule($patient->id, $tomorrow, $patient->tenant_id);

        return response()->json(['status' => 'updated', 'effective_from' => $tomorrow->toDateString(), 'schedule_items' => $result['created']]);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Sổ sức khỏe chỉ mình người này sở hữu → xoá hẳn (CSDL xoá dây chuyền) kèm file ảnh mã hoá.
        $ownedTenantIds = TenantMember::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)->where('role', 'owner')->pluck('tenant_id');
        $soleTenantIds = $ownedTenantIds->filter(fn ($tenantId) => ! TenantMember::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)->where('user_id', '!=', $user->id)->exists());

        $paths = Document::withoutGlobalScope('tenant')->whereIn('tenant_id', $soleTenantIds)->pluck('encrypted_path');

        DB::transaction(function () use ($user, $soleTenantIds): void {
            Tenant::query()->whereKey($soleTenantIds)->delete();
            $user->tokens()->delete();
            $user->delete();
        });

        $storage = app(DocumentEncryptionService::class);
        $paths->each(fn ($path) => $storage->delete($path));

        return response()->json(['status' => 'deleted']);
    }
}
