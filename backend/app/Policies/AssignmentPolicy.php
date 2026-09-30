<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;

class AssignmentPolicy
{
    public function view(User $user, Assignment $assignment): bool
    {
        if ($user->can('manage', $assignment->offering)) {
            return $user->can('view', $assignment->offering);
        }

        return $user->can('view', $assignment->offering) && $assignment->published && $assignment->isVisibleTo($user);
    }

    public function submit(User $user, Assignment $assignment): bool
    {
        return $user->can('submit-assignments')
            && $assignment->published
            && $assignment->offering->published
            && $assignment->offering->archived_at === null
            && $assignment->offering->enrolments()->where('user_id', $user->id)->where('status', 'active')->exists()
            && $assignment->isVisibleTo($user)
            && (now()->lessThanOrEqualTo($assignment->due_at) || $assignment->allow_late_submissions);
    }

    public function grade(User $user, Assignment $assignment): bool
    {
        return $user->can('grade-submissions') && $user->can('manage', $assignment->offering);
    }
}
