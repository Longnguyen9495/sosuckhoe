<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\CarePlan\GlucoseInsightService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Nhận xét đường huyết của một ngày (màn Hôm nay).
 * GET trả ngay số liệu + nhận xét đã lưu; POST mới gọi AI (chậm) khi có số đo mới — màn hình gọi sau khi đã hiện số liệu.
 */
final class GlucoseNoteController extends Controller
{
    public function __construct(private readonly GlucoseInsightService $service) {}

    public function show(Patient $patient, string $date): JsonResponse
    {
        Gate::authorize('view', $patient);

        return response()->json(['data' => $this->service->show($patient, $this->day($date))]);
    }

    public function store(Patient $patient, string $date): JsonResponse
    {
        Gate::authorize('view', $patient);

        return response()->json(['data' => $this->service->generate($patient, $this->day($date))]);
    }

    private function day(string $date): CarbonImmutable
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 422, 'Ngày không hợp lệ.');

        return CarbonImmutable::parse($date);
    }
}
