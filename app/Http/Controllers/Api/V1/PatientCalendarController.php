<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Patient;
use App\Services\Schedule\DayPlanBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientCalendarController extends Controller
{
    public function __construct(private readonly DayPlanBuilder $dayPlan)
    {
    }

    /**
     * GET /patients/{patient}/calendar?month=YYYY-MM&today=YYYY-MM-DD
     * Mỗi ngày: số việc, số đã làm, % (chỉ tính tới hôm nay) và các mốc lịch.
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'today' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $start = CarbonImmutable::parse(($validated['month'] ?? now()->format('Y-m')).'-01');
        $end = $start->endOfMonth()->startOfDay();
        $today = CarbonImmutable::parse($validated['today'] ?? now()->toDateString());

        $events = Event::where('patient_id', $patient->getKey())
            ->whereDate('event_date', '>=', $start->toDateString())
            ->whereDate('event_date', '<=', $end->toDateString())
            ->orderBy('event_date')
            ->get();

        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $summary = $this->dayPlan->build($patient, $day)['summary'];
            $date = $day->toDateString();
            $days[] = [
                'date' => $date,
                'total' => $summary['total'],
                'done' => $summary['done'],
                // Ngày tương lai chưa tính %.
                'percent' => $day->gt($today) ? null : $summary['percent'],
                'events' => $events->filter(fn (Event $e) => substr((string) $e->event_date, 0, 10) === $date)
                    ->map(fn (Event $e) => ['id' => $e->getKey(), 'type' => $e->type, 'title' => $e->title, 'status' => $e->status])
                    ->values(),
            ];
        }

        return response()->json([
            'data' => [
                'month' => $start->format('Y-m'),
                'days' => $days,
                'events' => $events->map(fn (Event $e) => [
                    'id' => $e->getKey(),
                    'date' => substr((string) $e->event_date, 0, 10),
                    'due_date' => $e->due_date === null ? null : substr((string) $e->due_date, 0, 10),
                    'type' => $e->type,
                    'title' => $e->title,
                    'description' => $e->description,
                    'status' => $e->status,
                ])->values(),
            ],
        ]);
    }
}
