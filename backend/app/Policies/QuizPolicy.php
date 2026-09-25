<?php

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;

class QuizPolicy
{
    public function view(User $user, Quiz $quiz): bool
    {
        if ($user->can('manage', $quiz->offering)) {
            return $user->can('view', $quiz->offering);
        }

        return $user->can('view', $quiz->offering) && $quiz->published && $quiz->isVisibleTo($user);
    }

    public function take(User $user, Quiz $quiz): bool
    {
        return $user->can('submit-assignments')
            && $quiz->published
            && $quiz->offering->published
            && $quiz->offering->archived_at === null
            && $quiz->offering->enrolments()->where('user_id', $user->id)->where('status', 'active')->exists()
            && $quiz->isVisibleTo($user)
            && ($quiz->opens_at === null || now()->greaterThanOrEqualTo($quiz->opens_at))
            && now()->lessThanOrEqualTo($quiz->due_at);
    }
}
