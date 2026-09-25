<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\NotifyAudience;
use App\Models\SystemAnnouncement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Messages for the whole institution, shown at the top of every screen while they are current ("Registration closes Friday",
 * "The system will be down on Saturday night"). Administrators with the settings permission write them; everyone reads them.
 */
class SystemAnnouncementController extends Controller
{
    /** Everything, for the administrator's list (past and future ones too). */
    public function index(Request $request): JsonResponse
    {
        $this->allow($request);

        return response()->json(SystemAnnouncement::latest('id')->paginate(30));
    }

    /** The announcements that are showing right now to the person asking (their role is considered). */
    public function active(Request $request): JsonResponse
    {
        $roles = $request->user()->getRoleNames()->all();

        return response()->json(SystemAnnouncement::current()->latest('id')->get(['id', 'title', 'body', 'severity', 'audience', 'starts_at', 'ends_at'])
            ->filter(fn (SystemAnnouncement $a) => $a->isFor($roles))->map(fn ($a) => $a->only(['id', 'title', 'body', 'severity', 'ends_at']))->values());
    }

    /** `notify` also puts it in each recipient's notifications (in the background). */
    public function store(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate($this->rules() + ['notify' => ['sometimes', 'boolean']]);
        $announcement = SystemAnnouncement::create(collect($data)->except('notify')->all() + ['created_by' => $request->user()->id]);
        if ($request->boolean('notify')) {
            NotifyAudience::dispatch($announcement->id);
        }
        activity()->causedBy($request->user())->performedOn($announcement)->withProperties(['title' => $announcement->title, 'audience' => $announcement->audience, 'notified' => $request->boolean('notify')])->log('system announcement posted');

        return response()->json($announcement, 201);
    }

    public function update(Request $request, SystemAnnouncement $announcement): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate($this->rules(false));
        $announcement->update($data);
        activity()->causedBy($request->user())->performedOn($announcement)->log('system announcement changed');

        return response()->json($announcement);
    }

    public function destroy(Request $request, SystemAnnouncement $announcement): JsonResponse
    {
        $this->allow($request);
        $announcement->delete();
        activity()->causedBy($request->user())->withProperties(['title' => $announcement->title])->log('system announcement removed');

        return response()->json(['message' => 'Announcement removed.']);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'body' => [$required, 'string', 'max:5000'],
            'severity' => ['sometimes', Rule::in(SystemAnnouncement::SEVERITIES)],
            'audience' => ['sometimes', 'nullable', 'array'],
            'audience.*' => ['string', Rule::in(User::ROLES)],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
        ];
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('manage-settings'), 403);
    }
}
