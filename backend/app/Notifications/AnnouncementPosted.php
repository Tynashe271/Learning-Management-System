<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AnnouncementPosted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $announcementId, public int $offeringId, public string $title) {}

    public static function fromAnnouncement(Announcement $announcement): self
    {
        return new self($announcement->id, $announcement->course_offering_id, $announcement->title);
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['announcement_id' => $this->announcementId, 'offering_id' => $this->offeringId, 'title' => $this->title];
    }
}
