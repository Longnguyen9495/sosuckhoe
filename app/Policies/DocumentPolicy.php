<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\PatientAccess;
use App\Models\User;

final class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return PatientAccess::query()
            ->where('patient_id', $document->patient_id)
            ->where('user_id', $user->getKey())
            ->whereIn('role', ['caregiver', 'patient', 'doctor', 'viewer'])
            ->exists();
    }

    public function delete(User $user, Document $document): bool
    {
        return PatientAccess::query()
            ->where('patient_id', $document->patient_id)
            ->where('user_id', $user->getKey())
            ->whereIn('role', ['caregiver', 'patient', 'doctor'])
            ->exists();
    }
}
