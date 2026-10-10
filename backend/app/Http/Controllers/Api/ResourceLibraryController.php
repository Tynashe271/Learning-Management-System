<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseModule;
use App\Models\CourseOffering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A cross-course library of topics teaching staff have chosen to share, so another lecturer can reuse one instead of
 * rebuilding it from scratch. Browsing is read-only; importing deep-copies the topic and its items into a course the
 * importer already manages, the same way CourseCopyController duplicates a whole offering into a new term.
 */
class ResourceLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('teach-courses') || $request->user()->can('manage-courses'), 403);

        $modules = CourseModule::shared()
            ->with(['offering.course:id,code,title', 'offering.term:id,name', 'offering.teachers.user:id,name'])
            ->withCount('items')
            ->orderByDesc('id')
            ->get();

        return response()->json($modules->map(fn (CourseModule $module) => [
            'id' => $module->id,
            'title' => $module->title,
            'items_count' => $module->items_count,
            'offering_id' => $module->course_offering_id,
            'course' => ['code' => $module->offering->course->code, 'title' => $module->offering->course->title],
            'term' => $module->offering->term->name,
            'section' => $module->offering->section,
            'teachers' => $module->offering->teachers->pluck('user.name')->values(),
        ]));
    }

    public function import(Request $request, CourseModule $module): JsonResponse
    {
        if (! $module->is_shared) {
            throw ValidationException::withMessages(['module' => 'This topic is not shared.']);
        }
        $data = $request->validate(['destination_offering_id' => ['required', 'integer', 'exists:course_offerings,id']]);
        $destination = CourseOffering::findOrFail($data['destination_offering_id']);
        $this->authorize('manage', $destination);

        $module->load('items');
        $copiedFiles = [];

        try {
            $newModule = DB::transaction(function () use ($module, $destination, $request, &$copiedFiles) {
                $newModule = $destination->modules()->create($module->only(['title', 'position']) + ['published' => false, 'is_shared' => false]);
                foreach ($module->items as $item) {
                    $attributes = $item->only(['title', 'type', 'body', 'position', 'published']);
                    if ($item->storage_path) {
                        $target = 'course-files/'.$destination->id.'/'.Str::random(40).(($ext = pathinfo($item->storage_path, PATHINFO_EXTENSION)) ? '.'.$ext : '');
                        try {
                            $copied = Storage::disk('s3')->copy($item->storage_path, $target);
                        } catch (Throwable) {
                            $copied = false; // depending on the disk's settings a failed copy returns false or throws
                        }
                        if (! $copied) {
                            throw ValidationException::withMessages(['module' => "The file for \"{$item->title}\" is missing from storage, so this topic cannot be imported."]);
                        }
                        $copiedFiles[] = $target;
                        $attributes['storage_path'] = $target;
                    }
                    $newModule->items()->create($attributes);
                }
                activity()->causedBy($request->user())->performedOn($newModule)
                    ->withProperties(['imported_from_module_id' => $module->id, 'source_offering_id' => $module->course_offering_id])
                    ->log('resource imported');

                return $newModule;
            });
        } catch (Throwable $e) {
            if ($copiedFiles !== []) {
                Storage::disk('s3')->delete($copiedFiles);
            }
            throw $e;
        }

        return response()->json($newModule->load('items'), 201);
    }
}
