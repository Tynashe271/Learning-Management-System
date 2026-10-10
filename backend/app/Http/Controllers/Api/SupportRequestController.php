<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportRequest;
use App\Models\User;
use App\Notifications\SupportRequestResolved;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Tracks a person's own support requests - a sign-in problem, a submission failure, or anything else - for an administrator to triage, separate from the per-person lookup an administrator already has on a person's page. */
class SupportRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportRequest::STATUSES)],
            'category' => ['sometimes', Rule::in(SupportRequest::CATEGORIES)],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = SupportRequest::with('user:id,name,email', 'assignee:id,name')->orderByRaw("case when status = 'resolved' then 1 else 0 end")->latest('id');
        if (! $request->user()->can('manage-users')) {
            $query->where('user_id', $request->user()->id);
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['category'])) {
            $query->where('category', $data['category']);
        }
        if (isset($data['q'])) {
            $like = '%'.strtolower(preg_replace('/[!%_]/', '!$0', $data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("lower(subject) like ? escape '!'", [$like])->orWhereRaw("lower(description) like ? escape '!'", [$like]));
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(SupportRequest::CATEGORIES)],
            'subject' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:4000'],
        ]);
        $support = SupportRequest::create($data + ['user_id' => $request->user()->id, 'status' => 'open']);
        activity()->causedBy($request->user())->performedOn($support)->log('support request filed');

        return response()->json($support->load('user:id,name,email'), 201);
    }

    public function show(Request $request, SupportRequest $supportRequest): JsonResponse
    {
        abort_unless($supportRequest->user_id === $request->user()->id || $request->user()->can('manage-users'), 403);

        return response()->json($supportRequest->load('user:id,name,email', 'assignee:id,name'));
    }

    public function update(Request $request, SupportRequest $supportRequest): JsonResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportRequest::STATUSES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'resolution_note' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);
        if (isset($data['assigned_to']) && ! User::find($data['assigned_to'])?->can('manage-users')) {
            throw ValidationException::withMessages(['assigned_to' => 'This person is not a user administrator.']);
        }
        if (isset($data['status']) && $data['status'] === 'resolved') {
            $data['resolved_at'] = now();
        } elseif (array_key_exists('status', $data) && $data['status'] !== 'resolved') {
            $data['resolved_at'] = null;
        }
        $wasResolved = $supportRequest->status === 'resolved';
        $supportRequest->update($data);
        activity()->causedBy($request->user())->performedOn($supportRequest)->withProperties(['status' => $supportRequest->status])->log('support request updated');
        if ($supportRequest->status === 'resolved' && ! $wasResolved) {
            $supportRequest->user->notify(SupportRequestResolved::for($supportRequest));
        }

        return response()->json($supportRequest->load('user:id,name,email', 'assignee:id,name'));
    }
}
