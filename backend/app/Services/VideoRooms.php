<?php

namespace App\Services;

use App\Models\ClassSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** In-app video rooms for class sessions through Daily.co, off by default like this app's other optional integrations. */
class VideoRooms
{
    public function enabled(): bool
    {
        return (bool) config('lms.video.enabled') && filled(config('lms.video.api_key'));
    }

    /**
     * The room to embed for this session: the one already created for it, or a freshly created one. The room expires
     * shortly after the session ends, so nobody can join it long after class is over.
     *
     * @throws VideoUnavailable when video is not configured or the room could not be created
     */
    public function roomUrlFor(ClassSession $session): string
    {
        if ($session->video_room_url) {
            return $session->video_room_url;
        }
        if (! $this->enabled()) {
            throw new VideoUnavailable('In-app video is not configured for this institution.');
        }
        try {
            $response = Http::withToken(config('lms.video.api_key'))->timeout(15)->post(config('lms.video.base_url').'/rooms', [
                'name' => 'session-'.$session->id.'-'.Str::lower(Str::random(8)),
                'privacy' => 'public',
                'properties' => [
                    'exp' => $session->ends_at->addMinutes((int) config('lms.video.room_grace_minutes'))->timestamp,
                    'eject_at_room_exp' => true,
                    'enable_chat' => true,
                    'enable_screenshare' => true,
                ],
            ]);
        } catch (Throwable $e) {
            throw new VideoUnavailable('The video service could not be reached: '.$e->getMessage());
        }
        if ($response->failed()) {
            throw new VideoUnavailable('The video service returned an error: '.Str::limit($response->body(), 200));
        }
        $url = $response->json('url');
        if (! $url) {
            throw new VideoUnavailable('The video service returned no room address.');
        }
        $session->update(['video_room_url' => $url]);

        return $url;
    }
}
