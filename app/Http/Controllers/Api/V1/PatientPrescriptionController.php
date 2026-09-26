<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MonitoringPlan;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\ScheduleItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PatientPrescriptionController extends Controller
{
    /**
     * GET /patients/{patient}/prescriptions?status=active|all&date=YYYY-MM-DD
     * Đơn thuốc kèm giờ dùng, số lượng kê / đã mua và ngày dự kiến hết.
     * Ngày hết = số lượng (ưu tiên số đã mua) ÷ số đơn vị dùng mỗi ngày ghi trong đơn — chỉ là phép tính tồn kho.
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $validated = $request->validate([
            'status' => ['nullable', 'in:active,all'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $onDate = CarbonImmutable::parse($validated['date'] ?? now()->toDateString());

        $prescriptions = Prescription::where('patient_id', $patient->getKey())
            ->when(($validated['status'] ?? 'active') === 'active', fn ($q) => $q->where('status', 'active'))
            ->with(['items', 'items.drug'])
            ->orderByDesc('prescribed_at')
            ->orderBy('doctor_name')
            ->get();

        $times = ScheduleItem::where('patient_id', $patient->getKey())
            ->whereDate('starts_at', '<=', $onDate->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $onDate->toDateString()))
            ->orderBy('scheduled_time')
            ->get(['prescription_item_id', 'scheduled_time', 'amount_text', 'type'])
            ->groupBy('prescription_item_id');

        return response()->json([
            'data' => $prescriptions->map(fn (Prescription $p) => [
                'id' => $p->getKey(),
                'doctor_name' => $p->doctor_name,
                'prescribed_at' => $this->date($p->prescribed_at),
                'starts_at' => $this->date($p->starts_at),
                'ends_at' => $this->date($p->ends_at),
                'status' => $p->status,
                'items' => $p->items->map(fn (PrescriptionItem $item) => $this->presentItem($p, $item, $times->get($item->getKey(), collect()), $onDate))->values(),
            ])->values(),
        ]);
    }

    /**
     * PATCH /patients/{patient}/prescription-items/{prescriptionItem}
     * Cập nhật số lượng đã mua (khi mua thêm). Không cho sửa liều hay cách dùng ở đây.
     */
    public function updateItem(Request $request, Patient $patient, PrescriptionItem $prescriptionItem): JsonResponse
    {
        $this->authorize('updateFamilyLog', $patient);

        $validated = $request->validate([
            'purchased_quantity' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'add_quantity' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        if (isset($validated['add_quantity'])) {
            $base = (float) ($prescriptionItem->purchased_quantity ?? $prescriptionItem->prescribed_quantity ?? 0);
            $prescriptionItem->purchased_quantity = $base + (float) $validated['add_quantity'];
        } elseif (array_key_exists('purchased_quantity', $validated)) {
            $prescriptionItem->purchased_quantity = $validated['purchased_quantity'];
        }
        $prescriptionItem->save();

        $prescription = $prescriptionItem->prescription;

        return response()->json(['data' => $this->presentItem($prescription, $prescriptionItem, collect(), CarbonImmutable::today())]);
    }

    /**
     * GET /patients/{patient}/monitoring-plans — các giai đoạn đo.
     */
    public function monitoringPlans(Patient $patient): JsonResponse
    {
        $this->authorize('view', $patient);

        $plans = MonitoringPlan::where('patient_id', $patient->getKey())->orderBy('metric')->orderBy('starts_at')->get();

        return response()->json([
            'data' => $plans->map(function (MonitoringPlan $plan) {
                $schedule = is_array($plan->schedule) ? $plan->schedule : (json_decode((string) $plan->schedule, true) ?? []);

                return [
                    'id' => $plan->id,
                    'metric' => $plan->metric,
                    'phase' => $plan->phase,
                    'label' => $schedule['label'] ?? $plan->phase,
                    'description' => $schedule['description'] ?? null,
                    'starts_at' => $plan->starts_at?->toDateString(),
                    'ends_at' => $plan->ends_at?->toDateString(),
                    'schedule' => $schedule,
                ];
            })->values(),
        ]);
    }

    private function presentItem(Prescription $prescription, PrescriptionItem $item, $times, CarbonImmutable $onDate): array
    {
        $rule = is_string($item->usage_rule) ? json_decode($item->usage_rule, true) : $item->usage_rule;
        $unitsPerDay = isset($rule['units_per_day']) && is_numeric($rule['units_per_day']) && $rule['units_per_day'] > 0 ? (float) $rule['units_per_day'] : null;
        $stock = $item->purchased_quantity ?? $item->prescribed_quantity;
        $startsAt = $this->date($prescription->starts_at) ?? $this->date($prescription->prescribed_at);

        $supplyDays = null;
        $runsOutOn = null;
        $daysLeft = null;
        if ($unitsPerDay !== null && $stock !== null && $startsAt !== null) {
            $supplyDays = (int) floor((float) $stock / $unitsPerDay);
            $runsOutOn = CarbonImmutable::parse($startsAt)->addDays(max(0, $supplyDays - 1))->toDateString();
            $daysLeft = max(0, (int) $onDate->startOfDay()->diffInDays(CarbonImmutable::parse($runsOutOn), false) + 1);
        }

        return [
            'id' => $item->getKey(),
            'drug_id' => $item->drug_id,
            'drug_name' => $item->drug_name_snapshot,
            'active_ingredient' => $item->drug?->active_ingredient,
            'dose_text' => $item->dose_text,
            'type' => $rule['type'] ?? null,
            'purpose' => $rule['purpose'] ?? null,
            'warning' => $rule['warning'] ?? $item->drug?->general_warning,
            'times' => $times->map(fn ($t) => ['time' => substr((string) $t->scheduled_time, 0, 5), 'amount_text' => $t->amount_text])->values(),
            'requires_manual_time' => (bool) $item->requires_manual_time,
            'is_long_term' => (bool) $item->is_long_term,
            'prescribed_quantity' => $item->prescribed_quantity === null ? null : (float) $item->prescribed_quantity,
            'purchased_quantity' => $item->purchased_quantity === null ? null : (float) $item->purchased_quantity,
            'quantity_unit' => $item->quantity_unit,
            'units_per_day' => $unitsPerDay,
            'supply_days' => $supplyDays,
            'runs_out_on' => $runsOutOn,
            'days_left' => $daysLeft,
            'course_ends_at' => $this->date($prescription->ends_at),
        ];
    }

    private function date($value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
