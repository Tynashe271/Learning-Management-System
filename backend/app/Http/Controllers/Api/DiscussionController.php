<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\DiscussionPost;
use App\Models\DiscussionThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscussionController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);

        return response()->json($offering->threads()->with('author:id,name')->withCount('posts')->orderByDesc('updated_at')->orderByDesc('id')->paginate(20));
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:10000']]);
        $thread = $offering->threads()->create($data + ['user_id' => $request->user()->id]);

        return response()->json($thread->load('author:id,name'), 201);
    }

    public function show(Request $request, DiscussionThread $thread): JsonResponse
    {
        $this->authorize('view', $thread->offering);

        return response()->json([
            'thread' => $thread->load('author:id,name'),
            'posts' => $thread->posts()->with('author:id,name')->orderBy('id')->paginate(50),
        ]);
    }

    public function reply(Request $request, DiscussionThread $thread): JsonResponse
    {
        $this->authorize('view', $thread->offering);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $post = $thread->posts()->create($data + ['user_id' => $request->user()->id]);
        $thread->touch();

        return response()->json($post->load('author:id,name'), 201);
    }

    public function destroyThread(Request $request, DiscussionThread $thread): JsonResponse
    {
        $this->authorizeRemoval($request, $thread->offering, $thread->user_id);
        $thread->delete();
        activity()->causedBy($request->user())->performedOn($thread)->log('discussion thread removed');

        return response()->json(['message' => 'Thread deleted.']);
    }

    public function destroyPost(Request $request, DiscussionPost $post): JsonResponse
    {
        $offering = $post->thread->offering;
        $this->authorizeRemoval($request, $offering, $post->user_id);
        $post->delete();
        activity()->causedBy($request->user())->performedOn($post)->log('discussion post removed');

        return response()->json(['message' => 'Post deleted.']);
    }

    /** Authors may remove their own contributions; offering managers may moderate anything. */
    private function authorizeRemoval(Request $request, CourseOffering $offering, int $authorId): void
    {
        $this->authorize('view', $offering);
        abort_unless($authorId === $request->user()->id || $request->user()->can('manage', $offering), 403);
    }
}
