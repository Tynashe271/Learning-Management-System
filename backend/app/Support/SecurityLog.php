<?php

namespace App\Support;

use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Security-relevant events (sign-ins, lockouts, password changes, denied access, throttling) go to their own log channel
 * so they can be watched and kept separately from ordinary errors, and to a table an administrator can search. Email addresses
 * are logged as short hashes, not in clear, so the log can be shared with a monitoring service without spreading personal data.
 */
class SecurityLog
{
    /** Kept out of the table: a flood of refused requests must not become a flood of database writes. */
    private const FILE_ONLY = ['throttle.hit'];

    /** @param  array<string, mixed>  $context */
    public static function event(string $event, array $context = [], string $level = 'info'): void
    {
        $request = request();
        $full = $context + [
            'ip' => $request?->ip(),
            'user_id' => $request?->user()?->id,
            'agent' => Str::limit((string) $request?->userAgent(), 120, ''),
        ];
        Log::channel('security')->{$level}($event, $full);

        if (in_array($event, self::FILE_ONLY, true)) {
            return;
        }

        // The searchable copy must never be able to break the request it is describing.
        try {
            $extra = array_diff_key($full, array_flip(['ip', 'user_id', 'email', 'agent']));
            SecurityEvent::create([
                'event' => $event,
                'user_id' => $full['user_id'] ?? null,
                'email_hash' => $full['email'] ?? null,
                'ip' => $full['ip'] ?? null,
                'level' => $level,
                'context' => $extra === [] ? null : $extra,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function emailFingerprint(string $email): string
    {
        return substr(hash('sha256', mb_strtolower(trim($email))), 0, 12);
    }
}
