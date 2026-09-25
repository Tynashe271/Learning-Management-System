<?php

namespace App\Policies;

use App\Models\CourseOffering;
use App\Models\User;

class CourseOfferingPolicy
{
    public function view(User $user, CourseOffering $offering): bool
    {
        return $user->can('manage-courses')
            || $offering->teachers()->where('user_id', $user->id)->exists()
            || ($offering->published && $offering->enrolments()->where('user_id', $user->id)->where('status', 'active')->exists());
    }

    public function manage(User $user, CourseOffering $offering): bool
    {
        return $user->can('manage-courses')
            || ($user->can('teach-courses') && $offering->teachers()->where('user_id', $user->id)->exists());
    }
}
