<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /** What each permission means, shown on the roles screen. */
    public const PERMISSIONS = [
        'manage-users' => 'Create, edit, deactivate and support accounts; import accounts; read the audit log and security events.',
        'manage-courses' => 'Run the catalogue (terms, courses, departments, offerings), assign teachers, and manage every course.',
        'manage-enrolments' => 'Enrol and withdraw students, and look up people and offerings.',
        'teach-courses' => 'Be assigned to courses as a teacher.',
        'submit-assignments' => 'Submit assignments and take quizzes as a student.',
        'grade-submissions' => 'Grade submissions in courses they manage.',
        'resolve-appeals' => 'Decide grade appeals in courses they manage.',
        'manage-settings' => 'Change institution settings, send system announcements, and see integrations.',
        'manage-system' => 'Roles and permissions, backups and restores, maintenance, and technical monitoring.',
    ];

    /** The starting point for each role. Administrators can change it afterwards (Administration > Roles and permissions). */
    public const DEFAULTS = [
        'super-admin' => ['manage-users', 'manage-courses', 'manage-enrolments', 'teach-courses', 'submit-assignments', 'grade-submissions', 'resolve-appeals', 'manage-settings', 'manage-system'],
        'university-admin' => ['manage-users', 'manage-courses', 'manage-enrolments', 'resolve-appeals', 'manage-settings'],
        'registrar' => ['manage-users', 'manage-courses', 'manage-enrolments'],
        'department-admin' => ['manage-courses', 'manage-enrolments', 'resolve-appeals'],
        'lecturer' => ['teach-courses', 'grade-submissions', 'resolve-appeals'],
        'teaching-assistant' => ['teach-courses', 'grade-submissions', 'resolve-appeals'],
        'student' => ['submit-assignments'],
    ];

    /**
     * Safe to run again after an update. A role's permissions are set only when the role is first created, or when a new
     * permission first appears (then its default holders receive it), so changes an administrator has made are never
     * undone. The super administrator always holds every permission.
     */
    public function run(): void
    {
        $created = [];
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permission = Permission::findOrCreate($name, 'web');
            if ($permission->wasRecentlyCreated) {
                $created[] = $name;
            }
        }
        foreach (self::DEFAULTS as $name => $grants) {
            $role = Role::findOrCreate($name, 'web');
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($grants);
            } else {
                $role->givePermissionTo(array_values(array_intersect($grants, $created)));
            }
        }
        Role::findByName('super-admin', 'web')->syncPermissions(array_keys(self::PERMISSIONS));

        if (env('LMS_ADMIN_EMAIL') && env('LMS_ADMIN_PASSWORD')) {
            $user = User::firstOrCreate(['email' => env('LMS_ADMIN_EMAIL')], [
                'name' => 'System Administrator', 'password' => env('LMS_ADMIN_PASSWORD'),
            ]);
            $user->assignRole('super-admin');
        }
    }
}
