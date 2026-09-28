<?php

namespace App\Services\Dedup;

use App\Models\PrescriptionItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Thuốc đang có trong các đơn còn hiệu lực — để không đưa cùng một thuốc vào lịch hai lần. */
final class ActiveMedications
{
    /** @return Collection<int, object{id: string, prescription_id: string, drug_name: string, prescribed_at: string}> */
    public function items(string $patientId, ?CarbonImmutable $on = null): Collection
    {
        $on ??= CarbonImmutable::today();

        return PrescriptionItem::query()
            ->join('prescriptions', 'prescriptions.id', '=', 'prescription_items.prescription_id')
            ->where('prescription_items.patient_id', $patientId)
            ->where('prescriptions.status', 'active')
            ->where(fn ($q) => $q->whereNull('prescriptions.ends_at')->orWhere('prescriptions.ends_at', '>=', $on->toDateString()))
            ->orderBy('prescriptions.prescribed_at')
            ->get(['prescription_items.id', 'prescription_items.prescription_id', 'prescription_items.drug_name_snapshot as drug_name', 'prescriptions.prescribed_at'])
            ->map(fn ($i) => (object) [
                'id' => $i->id,
                'prescription_id' => $i->prescription_id,
                'drug_name' => $i->drug_name,
                'prescribed_at' => substr((string) $i->prescribed_at, 0, 10),
            ]);
    }

    /** Thuốc đang dùng trùng với tên này, nếu có. */
    public function match(Collection $active, string $drugName): ?object
    {
        return $active->first(fn ($i) => DuplicateMatcher::sameDrug($i->drug_name, $drugName));
    }

    public static function label(object $item): string
    {
        return 'Đang dùng · đơn '.CarbonImmutable::parse($item->prescribed_at)->format('d/m');
    }
}
