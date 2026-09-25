<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('manage-users') || $actor->can('manage-enrolments'), 403);
        $data = $request->validate(['role' => ['sometimes', Rule::in(User::ROLES)], 'q' => ['sometimes', 'string', 'max:100'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])]]);

        $query = User::with('roles:id,name')->orderBy('name')->orderBy('id');
        if (isset($data['role'])) {
            $query->role($data['role']);
        }
        if (isset($data['status'])) {
            $query->where('is_active', $data['status'] === 'active');
        }
        if (isset($data['q'])) {
            // '!' is the LIKE escape character: a backslash breaks PDO's placeholder parsing on PostgreSQL.
            $like = '%'.strtolower(preg_replace('/[!%_]/', '!$0', $data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("lower(name) like ? escape '!'", [$like])->orWhereRaw("lower(email) like ? escape '!'", [$like]));
        }

        return response()->json($query->paginate(50));
    }

    /**
     * Rename, change the email or the role, or activate/deactivate an account. Guards: only a super administrator may grant or
     * remove that role, nobody changes their own role or deactivates themselves, and the last active super administrator can
     * be neither demoted nor deactivated (the system would be left with nobody to run it).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('manage-users'), 403);
        abort_if($user->hasRole('super-admin') && ! $actor->hasRole('super-admin'), 403);
        if ($user->anonymised_at) {
            throw ValidationException::withMessages(['user' => 'This account has been anonymised and can no longer be changed.']);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($user) {
                if (User::whereRaw('lower(email) = ?', [mb_strtolower((string) $value)])->where('id', '!=', $user->id)->exists()) {
                    $fail('The email has already been taken.');
                }
            }],
            'role' => ['sometimes', Rule::in(User::ROLES)],
        ]);

        $oldRole = $user->getRoleNames()->first();
        $changes = [];
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $request->boolean('is_active');
            if (! $data['is_active'] && $user->is($actor)) {
                throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
            }
            if (! $data['is_active'] && $this->isLastActiveSuperAdmin($user)) {
                throw ValidationException::withMessages(['is_active' => 'This is the only active super administrator, so it cannot be deactivated.']);
            }
        }
        if (isset($data['role']) && $data['role'] !== $oldRole) {
            if (($data['role'] === 'super-admin') && ! $actor->hasRole('super-admin')) {
                abort(403);
            }
            if ($user->is($actor)) {
                throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
            }
            if ($oldRole === 'super-admin' && $this->isLastActiveSuperAdmin($user)) {
                throw ValidationException::withMessages(['role' => 'This is the only active super administrator, so it cannot be demoted.']);
            }
            $changes['role'] = ['from' => $oldRole, 'to' => $data['role']];
        }
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
            if ($data['email'] !== mb_strtolower($user->email)) {
                $changes['email'] = ['from' => $user->email, 'to' => $data['email']];
            }
        }

        $role = $data['role'] ?? null;
        unset($data['role']);
        $user->forceFill($data)->save();
        if ($role !== null && $role !== $oldRole) {
            $user->syncRoles([$role]);
        }
        if (isset($changes['email'])) {
            // Any reset link that was sent to the old address must not work for the new owner of the account.
            DB::table('password_reset_tokens')->where('email', $changes['email']['from'])->delete();
            DB::table('account_invitation_tokens')->where('email', $changes['email']['from'])->delete();
        }
        if (($data['is_active'] ?? true) === false) {
            $user->tokens()->delete();
        }
        activity()->causedBy($actor)->performedOn($user)->withProperties($data + ['role' => $changes['role'] ?? null])->log('user updated');
        if (isset($changes['role'])) {
            SecurityLog::event('account.role_changed', ['target_user_id' => $user->id, 'from' => $changes['role']['from'], 'to' => $changes['role']['to']], 'warning');
        }
        if (array_key_exists('is_active', $data)) {
            SecurityLog::event($data['is_active'] ? 'account.activated' : 'account.deactivated', ['target_user_id' => $user->id]);
        }

        return response()->json($user->load('roles'));
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return $user->hasRole('super-admin') && $user->is_active
            && User::role('super-admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist();
    }
}
