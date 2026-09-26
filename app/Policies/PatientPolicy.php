<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\PatientAccess;
use App\Models\User;

final class PatientPolicy
{
    public function view(User $user, Patient $patient): bool
    {
        return $this->hasRole($user, $patient, ['caregiver', 'patient', 'doctor', 'viewer']);
    }

    public function updateFamilyLog(User $user, Patient $patient): bool
    {
        return $this->hasRole($user, $patient, ['caregiver', 'patient']);
    }

    public function manageClinicalPlan(User $user, Patient $patient): bool
    {
        return $this->hasRole($user, $patient, ['doctor']);
    }

    private function hasRole(User $user, Patient $patient, array $roles): bool
    {
        return PatientAccess::query()
            ->where('patient_id', $patient->getKey())
            ->where('user_id', $user->getKey())
            ->whereIn('role', $roles)
            ->exists();
    }
}
