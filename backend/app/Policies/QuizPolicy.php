<?php

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;

class QuizPolicy
{
    public function view(User $user, Quiz $quiz): bool
    {
        return $user->can('view', $quiz->offering)
            && ($quiz->published || $user->can('manage', $quiz->offering));
    }

    public function take(User $user, Quiz $quiz): bool
    {
        return $user->can('submit-assignments')
            && $quiz->published
            && $quiz->offering->published
            && $quiz->offering->archived_at === null
            && $quiz->offering->enrolments()->where('user_id', $user->id)->where('status', 'active')->exists()
            && ($quiz->opens_at === null || now()->greaterThanOrEqualTo($quiz->opens_at))
            && now()->lessThanOrEqualTo($quiz->due_at);
    }
}
