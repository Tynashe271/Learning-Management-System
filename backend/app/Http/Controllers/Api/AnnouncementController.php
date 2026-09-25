<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CourseOffering;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class AnnouncementController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);

        return response()->json($offering->announcements()->with('author:id,name')->latest()->latest('id')->paginate(20));
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string']]);
        $announcement = $offering->announcements()->create($data + ['user_id' => $request->user()->id]);

        if ($offering->published) {
            $offering->enrolments()->where('status', 'active')->pluck('user_id')->chunk(200)->each(
                fn ($ids) => Notification::send(User::whereIn('id', $ids)->get(), AnnouncementPosted::fromAnnouncement($announcement))
            );
        }

        return response()->json($announcement->load('author:id,name'), 201);
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $this->authorize('manage', $announcement->offering);
        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }
}
