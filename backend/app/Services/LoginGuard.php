<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Locks sign-in for an email address after repeated failures, whichever IP the attempts come from. It counts failures for
 * addresses that do not exist too, so the lockout cannot be used to discover which accounts are real.
 */
class LoginGuard
{
    /** Seconds until the address may try again, or 0 when it is not locked. */
    public function retryAfter(string $email): int
    {
        $until = (int) Cache::get($this->lockKey($email), 0);

        return max(0, $until - time());
    }

    /** Records a failed attempt. Returns true when this failure just locked the address. */
    public function fail(string $email): bool
    {
        $attempts = max(1, (int) config('lms.security.lockout_attempts'));
        $minutes = max(1, (int) config('lms.security.lockout_minutes'));
        $key = $this->failKey($email);
        Cache::add($key, 0, now()->addMinutes($minutes));
        if ((int) Cache::increment($key) < $attempts) {
            return false;
        }
        Cache::put($this->lockKey($email), time() + $minutes * 60, now()->addMinutes($minutes));
        Cache::forget($key);

        return true;
    }

    public function clear(string $email): void
    {
        Cache::forget($this->failKey($email));
        Cache::forget($this->lockKey($email));
    }

    private function failKey(string $email): string
    {
        return 'login:fails:'.hash('sha256', mb_strtolower(trim($email)));
    }

    private function lockKey(string $email): string
    {
        return 'login:lock:'.hash('sha256', mb_strtolower(trim($email)));
    }
}
