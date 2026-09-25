<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SecurityLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** The seven roles, what each may do, and (for super administrators) changing that. */
class RoleController extends Controller
{
    /** The permission catalogue and each role's permissions, with how many people hold the role. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-users') || $request->user()->can('manage-system'), 403);
        $counts = DB::table('model_has_roles')->where('model_type', User::class)->select('role_id', DB::raw('count(*) as total'))->groupBy('role_id')->pluck('total', 'role_id');
        $roles = Role::with('permissions:id,name')->get()->keyBy('name');

        return response()->json([
            'permissions' => collect(DatabaseSeeder::PERMISSIONS)->map(fn ($description, $name) => ['name' => $name, 'description' => $description])->values(),
            'roles' => collect(User::ROLES)->map(fn ($name) => [
                'name' => $name,
                'users' => (int) ($counts[$roles[$name]->id] ?? 0),
                'permissions' => $roles[$name]->permissions->pluck('name')->sort()->values(),
                'defaults' => DatabaseSeeder::DEFAULTS[$name],
                'locked' => $name === 'super-admin', // always holds everything
            ])->values(),
        ]);
    }

    /** Sets exactly which permissions a role holds. The super administrator role cannot be edited. */
    public function update(Request $request, string $role): JsonResponse
    {
        abort_unless($request->user()->can('manage-system'), 403);
        $model = $this->find($role);
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', Rule::in(array_keys(DatabaseSeeder::PERMISSIONS))]]);

        return $this->apply($request, $model, array_values(array_unique($data['permissions'])));
    }

    /** Puts a role back to the permissions it started with. */
    public function reset(Request $request, string $role): JsonResponse
    {
        abort_unless($request->user()->can('manage-system'), 403);
        $model = $this->find($role);

        return $this->apply($request, $model, DatabaseSeeder::DEFAULTS[$model->name]);
    }

    private function find(string $name): Role
    {
        if ($name === 'super-admin') {
            throw ValidationException::withMessages(['role' => 'The super administrator role always holds every permission and cannot be edited.']);
        }
        abort_unless(in_array($name, User::ROLES, true), 404);

        return Role::findByName($name, 'web');
    }

    /** @param  list<string>  $permissions */
    private function apply(Request $request, Role $role, array $permissions): JsonResponse
    {
        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $after = $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
        if ($before !== $after) {
            activity()->causedBy($request->user())->withProperties(['role' => $role->name, 'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))])->log('role permissions changed');
            SecurityLog::event('role.permissions_changed', ['role' => $role->name, 'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))], 'warning');
        }

        return response()->json(['name' => $role->name, 'permissions' => $after]);
    }
}
