<?php

namespace App\Notifications;

use App\Models\SystemAnnouncement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** The in-app copy of a system announcement, for people who were told about it when it was posted. */
class SystemAnnouncementPosted extends Notification
{
    use Queueable;

    public function __construct(public int $announcementId, public string $title, public string $severity) {}

    public static function for(SystemAnnouncement $announcement): self
    {
        return new self($announcement->id, mb_substr($announcement->title, 0, 120), $announcement->severity);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['announcement_id' => $this->announcementId, 'title' => $this->title, 'severity' => $this->severity];
    }
}
