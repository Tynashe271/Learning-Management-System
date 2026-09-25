<?php

namespace App\Console\Commands;

use App\Services\PruneService;
use Illuminate\Console\Command;

class Prune extends Command
{
    protected $signature = 'lms:prune {--dry-run : Only count what would be removed}';

    protected $description = 'Remove expired sign-ins, old security events, read notifications, used reset links and old failed jobs';

    public function handle(PruneService $prune): int
    {
        $counts = $prune->run((bool) $this->option('dry-run'));
        $this->line(json_encode($counts));

        return self::SUCCESS;
    }
}
