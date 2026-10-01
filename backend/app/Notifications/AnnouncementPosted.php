<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AnnouncementPosted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $announcementId, public int $offeringId, public string $title, private bool $urgent = false, private ?string $courseName = null, private ?string $body = null) {}

    public static function fromAnnouncement(Announcement $announcement): self
    {
        return new self($announcement->id, $announcement->course_offering_id, $announcement->title, $announcement->urgent, $announcement->offering?->course?->code, $announcement->body);
    }

    /** An urgent announcement (an emergency class notice) is also emailed right away, not left to wait for the student's next digest. */
    public function via(object $notifiable): array
    {
        return $this->urgent ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['announcement_id' => $this->announcementId, 'offering_id' => $this->offeringId, 'title' => $this->title, 'urgent' => $this->urgent];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Urgent: '.$this->title.($this->courseName ? ' ('.$this->courseName.')' : ''))
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An urgent notice was just posted'.($this->courseName ? ' for '.$this->courseName : '').':')
            ->line($this->body ?? '')
            ->line('This was sent immediately because it was marked urgent, rather than waiting for your next summary email.');
    }
}
