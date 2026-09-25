<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Removes records that have outlived their usefulness, so the database does not grow without limit: expired sign-in tokens, old
 * security events and read notifications (kept for the periods set in Administration > Settings), used-up reset links and old
 * failed jobs. Nothing academic (grades, submissions, messages, the audit log) is ever touched.
 */
class PruneService
{
    /** @return array<string, int> how many records were (or, with $dryRun, would be) removed, by kind */
    public function run(bool $dryRun = false): array
    {
        $sessionMinutes = max(15, (int) config('sanctum.expiration'));
        $targets = [
            'expired_sign_ins' => DB::table('personal_access_tokens')->where('created_at', '<', now()->subMinutes($sessionMinutes + 1440)),
            'security_events' => DB::table('security_events')->where('created_at', '<', now()->subDays((int) config('lms.retention.security_event_days'))),
            'read_notifications' => DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', now()->subDays((int) config('lms.retention.notification_days'))),
            'password_reset_links' => DB::table('password_reset_tokens')->where('created_at', '<', now()->subDay()),
            'welcome_links' => DB::table('account_invitation_tokens')->where('created_at', '<', now()->subDays(8)),
            'failed_jobs' => DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(30)),
        ];
        $out = [];
        foreach ($targets as $kind => $query) {
            $out[$kind] = $dryRun ? $query->count() : $query->delete();
        }

        return $out;
    }
}
