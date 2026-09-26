<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends TenantModel
{
    protected function casts(): array
    {
        return [
            'birth_year' => 'integer',
            'birth_date' => 'date',
        ];
    }

    public function access(): HasMany
    {
        return $this->hasMany(PatientAccess::class);
    }

    public function thresholds(): HasMany
    {
        return $this->hasMany(PatientThreshold::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function labResults(): HasMany
    {
        return $this->hasMany(LabResult::class);
    }

    public function aiPrescriptionDrafts(): HasMany
    {
        return $this->hasMany(AiPrescriptionDraft::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(Log::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(ScheduleItem::class);
    }

    public function monitoringPlans(): HasMany
    {
        return $this->hasMany(MonitoringPlan::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function routines(): HasMany
    {
        return $this->hasMany(PatientRoutine::class);
    }

    public function carePlans(): HasMany
    {
        return $this->hasMany(CarePlan::class);
    }

    public function prescriptionItems(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }
}
