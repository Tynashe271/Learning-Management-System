<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\Enrolment;
use App\Models\MessageAttachment;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Rules\CleanFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** One-to-one messages. People can write to those they share a course with; administrators can write to anyone. */
class MessageController extends Controller
{
    /** Conversations, most recent first, with the last message and how many are unread. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user()->id;
        $sent = DB::table('direct_messages')->where('sender_id', $me)->selectRaw('recipient_id as other_id, id, read_at, 0 as incoming');
        $received = DB::table('direct_messages')->where('recipient_id', $me)->selectRaw('sender_id as other_id, id, read_at, 1 as incoming');
        $threads = DB::query()->fromSub($sent->unionAll($received), 'm')
            ->selectRaw('other_id, max(id) as last_id, sum(case when incoming = 1 and read_at is null then 1 else 0 end) as unread')
            ->groupBy('other_id')->orderByDesc('last_id')->limit(50)->get();

        $people = User::whereIn('id', $threads->pluck('other_id'))->get(['id', 'name', 'email'])->keyBy('id');
        $last = DirectMessage::with('attachments')->whereIn('id', $threads->pluck('last_id'))->get()->keyBy('id');

        return response()->json([
            'unread_total' => (int) $threads->sum('unread'),
            'conversations' => $threads->map(fn ($t) => [
                'user' => $people[$t->other_id] ?? null,
                'unread' => (int) $t->unread,
                'last_message' => $last[$t->last_id] ?? null,
            ])->values(),
        ]);
    }

    /** The conversation with one person, newest first. Opening it marks their messages to you as read. */
    public function thread(Request $request, User $user): JsonResponse
    {
        $me = $request->user()->id;
        $messages = DirectMessage::with('attachments')
            ->where(fn ($q) => $q->where('sender_id', $me)->where('recipient_id', $user->id))
            ->orWhere(fn ($q) => $q->where('sender_id', $user->id)->where('recipient_id', $me))
            ->latest('id')->paginate(50);
        DirectMessage::where('sender_id', $user->id)->where('recipient_id', $me)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json($messages);
    }

    /** Text, files, or both. Recipients are checked before any upload is scanned or stored. */
    public function send(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        if ($user->id === $me->id) {
            throw ValidationException::withMessages(['recipient' => 'You cannot message yourself.']);
        }
        if (! $user->is_active || ! $this->mayMessage($me, $user)) {
            // Same answer whether the person exists elsewhere or not, so the endpoint cannot be used to look people up.
            abort(403, 'You cannot message this person.');
        }
        $this->enforceDailyUploadBudget($request, $me);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:attachments'],
            'attachments' => ['sometimes', 'array', 'max:'.config('lms.messages.max_attachments')],
            'attachments.*' => ['bail', 'file', 'max:'.config('lms.messages.max_attachment_kb'), 'mimes:'.config('lms.upload_mimes'), new CleanFile],
        ]);

        $stored = [];
        try {
            $message = DB::transaction(function () use ($request, $me, $user, $data, &$stored) {
                $message = DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $user->id, 'body' => $data['body'] ?? '']);
                foreach ($request->file('attachments', []) as $file) {
                    $path = $file->store('message-attachments/'.$message->id, 's3');
                    $stored[] = $path;
                    $message->attachments()->create([
                        'original_name' => $this->safeName($file->getClientOriginalName()),
                        'storage_path' => $path,
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }

                return $message;
            });
        } catch (Throwable $e) {
            // The database rolled back; do not leave the files that were already written.
            if ($stored !== []) {
                Storage::disk('s3')->delete($stored);
            }
            throw $e;
        }

        return response()->json($message->load('attachments'), 201);
    }

    /** Only the two people in the conversation can download its files. */
    public function download(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $message = $attachment->message;
        abort_unless(in_array($request->user()->id, [$message->sender_id, $message->recipient_id], true), 403);

        return Storage::disk('s3')->download($attachment->storage_path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Caps how much one person can upload through messages in a rolling 24 hours (LMS_DAILY_UPLOAD_MB), a limit on both
     * storage cost and abuse. Checked before any file is scanned or stored.
     */
    private function enforceDailyUploadBudget(Request $request, User $sender): void
    {
        $incoming = collect($request->file('attachments', []))->sum(fn ($file) => (int) $file->getSize());
        if ($incoming === 0) {
            return;
        }
        $used = (int) MessageAttachment::whereHas('message', fn ($q) => $q->where('sender_id', $sender->id)->where('created_at', '>=', now()->subDay()))->sum('size');
        if ($used + $incoming > (int) config('lms.limits.daily_upload_mb') * 1024 * 1024) {
            throw ValidationException::withMessages(['attachments' => 'You have reached the daily limit for files sent in messages. Try again tomorrow.']);
        }
    }

    private function mayMessage(User $from, User $to): bool
    {
        if ($from->can('manage-users') || $from->can('manage-enrolments')) {
            return true;
        }
        // Replying to someone who has already written to you.
        if (DirectMessage::where('sender_id', $to->id)->where('recipient_id', $from->id)->exists()) {
            return true;
        }

        return $this->offeringIds($from)->intersect($this->offeringIds($to))->isNotEmpty();
    }

    /** Offerings a person teaches or is actively enrolled in. */
    private function offeringIds(User $user)
    {
        return TeachingAssignment::where('user_id', $user->id)->pluck('course_offering_id')
            ->merge(Enrolment::where('user_id', $user->id)->where('status', 'active')->pluck('course_offering_id'))->unique();
    }

    /** The name a file is shown and downloaded under: no path parts or control characters, and a sensible length. */
    private function safeName(string $name): string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F\\\\\/]+/', '_', basename(str_replace('\\', '/', $name))));

        return mb_substr($clean !== '' ? $clean : 'attachment', 0, 255);
    }
}
