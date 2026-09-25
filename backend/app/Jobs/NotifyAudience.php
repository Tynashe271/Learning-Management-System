<?php

namespace App\Jobs;

use App\Models\SystemAnnouncement;
use App\Models\User;
use App\Notifications\SystemAnnouncementPosted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/** Puts a system announcement in the notifications of everyone it is for, in batches, so posting it never makes anyone wait. */
class NotifyAudience implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $announcementId) {}

    public function handle(): void
    {
        $announcement = SystemAnnouncement::find($this->announcementId);
        if (! $announcement) {
            return;
        }
        User::where('is_active', true)->whereNull('anonymised_at')
            ->when(! empty($announcement->audience), fn ($q) => $q->whereHas('roles', fn ($r) => $r->whereIn('name', $announcement->audience)))
            ->chunkById(200, fn ($users) => Notification::send($users, SystemAnnouncementPosted::for($announcement)));
    }
}
