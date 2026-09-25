<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Digest;
use App\Services\DigestBuilder;
use Illuminate\Console\Command;

class SendDigests extends Command
{
    protected $signature = 'lms:send-digests {frequency : daily or weekly}';

    protected $description = 'Email each opted-in person a summary of what needs their attention';

    public function handle(DigestBuilder $builder): int
    {
        $frequency = $this->argument('frequency');
        if (! in_array($frequency, ['daily', 'weekly'], true)) {
            $this->error('The frequency must be "daily" or "weekly".');

            return self::INVALID;
        }
        // Someone's first summary looks back one period; later ones look back to the previous summary.
        $firstLookBack = $frequency === 'daily' ? now()->subDay() : now()->subWeek();
        // Running the command twice in the same period must not send twice.
        $notBefore = $frequency === 'daily' ? now()->subHours(20) : now()->subHours(6 * 24 + 12);
        $sent = 0;
        $skipped = 0;

        User::where('is_active', true)->where('digest_frequency', $frequency)
            ->where(fn ($q) => $q->whereNull('digest_sent_at')->orWhere('digest_sent_at', '<', $notBefore))
            ->chunkById(200, function ($users) use ($builder, $frequency, $firstLookBack, &$sent, &$skipped) {
                foreach ($users as $user) {
                    $summary = $builder->build($user, $user->digest_sent_at ?? $firstLookBack);
                    // Advance the clock even when there was nothing to say, so the next summary starts from now.
                    $user->forceFill(['digest_sent_at' => now()])->save();
                    if ($summary === null) {
                        $skipped++;

                        continue;
                    }
                    $user->notify(new Digest($summary, $frequency));
                    $sent++;
                }
            });

        $this->info("Digests ({$frequency}): {$sent} sent, {$skipped} skipped because there was nothing to report.");

        return self::SUCCESS;
    }
}
