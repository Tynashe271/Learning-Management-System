<?php

use App\Http\Controllers\Api\AcademicStructureController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AppealController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CheckinController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CourseCopyController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\DiscussionController;
use App\Http\Controllers\Api\GradebookController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\LmsController;
use App\Http\Controllers\Api\ManagementController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\QuizAttemptController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RubricController;
use App\Http\Controllers\Api\SecurityEventController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SimilarityController;
use App\Http\Controllers\Api\SsoController;
use App\Http\Controllers\Api\SystemAnnouncementController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserSupportController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'show'])->middleware('throttle:health');
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'status' => 'ok',
    'message' => 'POST /api/login with an email and password to get a token. See README.md for every endpoint.',
]))->middleware('throttle:public');
Route::post('/login', [LmsController::class, 'login'])->middleware('throttle:login');
Route::get('/auth/config', [SsoController::class, 'config'])->middleware('throttle:public');
Route::get('/auth/sso', [SsoController::class, 'redirect'])->middleware('throttle:sso');
Route::post('/auth/sso/callback', [SsoController::class, 'callback'])->middleware('throttle:sso');
Route::post('/forgot-password', [AccountController::class, 'forgotPassword'])->middleware('throttle:password');
Route::post('/reset-password', [AccountController::class, 'resetPassword'])->middleware('throttle:password');

Route::middleware(['auth:sanctum', 'throttle:api', 'idempotent', 'conditional.get'])->group(function () {
    // Account
    Route::get('/me', [AccountController::class, 'me']);
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::patch('/me', [AccountController::class, 'updateProfile']);
    Route::post('/me/password', [AccountController::class, 'changePassword']);
    Route::get('/me/preferences', [DigestController::class, 'show']);
    Route::patch('/me/preferences', [DigestController::class, 'update']);
    Route::get('/me/digest-preview', [DigestController::class, 'preview'])->middleware('throttle:heavy');
    Route::post('/logout', [LmsController::class, 'logout']);
    Route::get('/notifications', [LmsController::class, 'notifications']);
    Route::post('/notifications/{notification}/read', [LmsController::class, 'readNotification']);

    // Administration
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [LmsController::class, 'createUser']);
    Route::post('/users/import', [ImportController::class, 'users'])->middleware('throttle:heavy');
    Route::get('/users/{user}', [UserSupportController::class, 'show']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserSupportController::class, 'destroy']);
    Route::post('/users/{user}/unlock', [UserSupportController::class, 'unlock']);
    Route::post('/users/{user}/reset-link', [UserSupportController::class, 'resetLink'])->middleware('throttle:heavy');
    Route::post('/users/{user}/sessions/revoke', [UserSupportController::class, 'revokeSessions']);
    Route::get('/users/{user}/export', [UserSupportController::class, 'export'])->middleware('throttle:heavy');
    Route::post('/users/{user}/anonymise', [UserSupportController::class, 'anonymise']);
    Route::get('/security/events', [SecurityEventController::class, 'index']);
    Route::get('/security/summary', [SecurityEventController::class, 'summary']);
    Route::get('/roles', [RoleController::class, 'index']);
    Route::put('/roles/{role}/permissions', [RoleController::class, 'update']);
    Route::post('/roles/{role}/reset', [RoleController::class, 'reset']);
    Route::get('/audit-log', [AdminReportController::class, 'audit'])->middleware('throttle:heavy');
    Route::get('/reports/overview', [AdminReportController::class, 'overview'])->middleware('throttle:heavy');
    Route::get('/reports/usage', [AdminReportController::class, 'usage'])->middleware('throttle:heavy');
    Route::get('/reports/enrolments', [AdminReportController::class, 'enrolments'])->middleware('throttle:heavy');

    // System: monitoring, housekeeping, backups, integrations
    Route::get('/system', [SystemController::class, 'info'])->middleware('throttle:heavy');
    Route::get('/system/failed-jobs', [SystemController::class, 'failedJobs']);
    Route::post('/system/failed-jobs/retry', [SystemController::class, 'retryAllFailedJobs']);
    Route::post('/system/failed-jobs/{uuid}/retry', [SystemController::class, 'retryFailedJob']);
    Route::delete('/system/failed-jobs', [SystemController::class, 'flushFailedJobs']);
    Route::delete('/system/failed-jobs/{uuid}', [SystemController::class, 'forgetFailedJob']);
    Route::post('/system/refresh', [SystemController::class, 'refreshCaches']);
    Route::post('/system/prune', [SystemController::class, 'prune'])->middleware('throttle:heavy');
    Route::get('/backups', [BackupController::class, 'index']);
    Route::post('/backups', [BackupController::class, 'store'])->middleware('throttle:heavy');
    Route::post('/backups/{name}/verify', [BackupController::class, 'verify'])->middleware('throttle:heavy');
    Route::get('/backups/{name}/download', [BackupController::class, 'download'])->middleware('throttle:heavy');
    Route::delete('/backups/{name}', [BackupController::class, 'destroy']);
    Route::get('/integrations', [IntegrationController::class, 'index']);
    Route::post('/integrations/{key}/test', [IntegrationController::class, 'test'])->middleware('throttle:heavy');
    Route::get('/system-announcements', [SystemAnnouncementController::class, 'index']);
    Route::get('/system-announcements/active', [SystemAnnouncementController::class, 'active']);
    Route::post('/system-announcements', [SystemAnnouncementController::class, 'store']);
    Route::patch('/system-announcements/{announcement}', [SystemAnnouncementController::class, 'update']);
    Route::delete('/system-announcements/{announcement}', [SystemAnnouncementController::class, 'destroy']);

    // Institution settings
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings', [SettingsController::class, 'update']);

    // Academic catalogue
    Route::get('/terms', [CatalogController::class, 'terms']);
    Route::post('/terms', [AcademicStructureController::class, 'storeTerm']);
    Route::patch('/terms/{term}', [AcademicStructureController::class, 'updateTerm']);
    Route::post('/terms/{term}/archive', [AcademicStructureController::class, 'archiveTerm']);
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
    Route::get('/courses', [CatalogController::class, 'courses']);
    Route::post('/courses', [AcademicStructureController::class, 'storeCourse']);
    Route::patch('/courses/{course}', [AcademicStructureController::class, 'updateCourse']);

    // Course registration by students
    Route::get('/registration', [RegistrationController::class, 'index']);
    Route::post('/offerings/{offering}/register', [RegistrationController::class, 'register']);
    Route::delete('/offerings/{offering}/register', [RegistrationController::class, 'drop']);

    // Offerings, people, and reports
    Route::get('/offerings', [LmsController::class, 'offerings']);
    Route::post('/offerings', [AcademicStructureController::class, 'storeOffering']);
    Route::get('/offerings/{offering}', [LmsController::class, 'offering']);
    Route::patch('/offerings/{offering}', [AcademicStructureController::class, 'updateOffering']);
    Route::post('/offerings/{offering}/copy', [CourseCopyController::class, 'copy'])->middleware('throttle:heavy');
    Route::get('/offerings/{offering}/roster', [CatalogController::class, 'roster']);
    Route::get('/offerings/{offering}/summary', [CatalogController::class, 'summary']);
    Route::get('/offerings/{offering}/gradebook', [GradebookController::class, 'gradebook'])->middleware('throttle:heavy');
    Route::get('/offerings/{offering}/my-grades', [GradebookController::class, 'mine']);
    Route::get('/offerings/{offering}/progress', [ProgressController::class, 'progress']);
    Route::post('/offerings/{offering}/enrolments', [LmsController::class, 'enrol']);
    Route::post('/offerings/{offering}/enrolments/import', [ManagementController::class, 'importEnrolments'])->middleware('throttle:heavy');
    Route::post('/offerings/{offering}/teachers', [LmsController::class, 'teacher']);
    Route::delete('/offerings/{offering}/teachers/{user}', [ManagementController::class, 'removeTeacher']);

    // Class sessions, online meetings, and attendance
    Route::get('/offerings/{offering}/sessions', [SessionController::class, 'index']);
    Route::post('/offerings/{offering}/sessions', [SessionController::class, 'store']);
    Route::patch('/sessions/{session}', [SessionController::class, 'update']);
    Route::delete('/sessions/{session}', [SessionController::class, 'destroy']);
    Route::get('/sessions/{session}/attendance', [SessionController::class, 'roll']);
    Route::put('/sessions/{session}/attendance', [SessionController::class, 'mark']);
    Route::get('/offerings/{offering}/attendance', [SessionController::class, 'summary']);
    Route::get('/sessions/{session}/checkin', [CheckinController::class, 'show']);
    Route::post('/sessions/{session}/checkin/open', [CheckinController::class, 'open']);
    Route::post('/sessions/{session}/checkin/close', [CheckinController::class, 'close']);
    Route::post('/sessions/{session}/checkin', [CheckinController::class, 'checkin'])->middleware('throttle:checkin');

    // Communication
    Route::get('/messages', [MessageController::class, 'index']);
    Route::get('/messages/{user}', [MessageController::class, 'thread']);
    Route::post('/messages/{user}', [MessageController::class, 'send'])->middleware('throttle:messages');
    Route::get('/message-attachments/{attachment}/download', [MessageController::class, 'download']);
    Route::get('/offerings/{offering}/announcements', [AnnouncementController::class, 'index']);
    Route::post('/offerings/{offering}/announcements', [AnnouncementController::class, 'store']);
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
    Route::get('/offerings/{offering}/discussions', [DiscussionController::class, 'index']);
    Route::post('/offerings/{offering}/discussions', [DiscussionController::class, 'store']);
    Route::get('/discussions/{thread}', [DiscussionController::class, 'show']);
    Route::delete('/discussions/{thread}', [DiscussionController::class, 'destroyThread']);
    Route::post('/discussions/{thread}/posts', [DiscussionController::class, 'reply']);
    Route::delete('/posts/{post}', [DiscussionController::class, 'destroyPost']);

    // Learning content and progress
    Route::post('/offerings/{offering}/modules', [LmsController::class, 'module']);
    Route::patch('/modules/{module}', [LmsController::class, 'updateModule']);
    Route::delete('/modules/{module}', [ManagementController::class, 'deleteModule']);
    Route::post('/modules/{module}/items', [LmsController::class, 'item']);
    Route::patch('/items/{item}', [LmsController::class, 'updateItem']);
    Route::delete('/items/{item}', [ManagementController::class, 'deleteItem']);
    Route::get('/items/{item}/download', [LmsController::class, 'downloadItem']);
    Route::post('/items/{item}/complete', [ProgressController::class, 'complete']);
    Route::delete('/items/{item}/complete', [ProgressController::class, 'uncomplete']);

    // Assignments and grading
    Route::post('/offerings/{offering}/assignments', [LmsController::class, 'assignment']);
    Route::get('/assignments/{assignment}', [LmsController::class, 'showAssignment']);
    Route::patch('/assignments/{assignment}', [LmsController::class, 'updateAssignment']);
    Route::delete('/assignments/{assignment}', [ManagementController::class, 'deleteAssignment']);
    Route::get('/assignments/{assignment}/rubric', [RubricController::class, 'show']);
    Route::put('/assignments/{assignment}/rubric', [RubricController::class, 'replace']);
    Route::delete('/assignments/{assignment}/rubric', [RubricController::class, 'destroy']);
    Route::get('/assignments/{assignment}/similarity', [SimilarityController::class, 'show'])->middleware('throttle:heavy');

    // Grade appeals
    Route::post('/submissions/{submission}/appeal', [AppealController::class, 'store']);
    Route::get('/my-appeals', [AppealController::class, 'mine']);
    Route::get('/offerings/{offering}/appeals', [AppealController::class, 'forOffering']);
    Route::get('/appeals/{appeal}', [AppealController::class, 'show']);
    Route::post('/appeals/{appeal}/resolve', [AppealController::class, 'resolve']);
    Route::delete('/appeals/{appeal}', [AppealController::class, 'withdraw']);
    Route::post('/assignments/{assignment}/submissions', [LmsController::class, 'submit'])->middleware('throttle:submissions');
    Route::get('/assignments/{assignment}/submissions', [LmsController::class, 'submissions']);
    Route::get('/assignments/{assignment}/my-grade', [LmsController::class, 'myGrade']);
    Route::post('/submissions/{submission}/grades', [LmsController::class, 'grade']);
    Route::get('/submissions/{submission}/download', [LmsController::class, 'downloadSubmission']);

    // Quizzes
    Route::post('/offerings/{offering}/quizzes', [QuizController::class, 'store']);
    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::patch('/quizzes/{quiz}', [QuizController::class, 'update']);
    Route::delete('/quizzes/{quiz}', [QuizController::class, 'destroy']);
    Route::post('/quizzes/{quiz}/questions', [QuizController::class, 'addQuestion']);
    Route::patch('/questions/{question}', [QuizController::class, 'updateQuestion']);
    Route::delete('/questions/{question}', [QuizController::class, 'deleteQuestion']);
    Route::get('/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'index']);
    Route::post('/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'start'])->middleware('throttle:submissions');
    Route::get('/quizzes/{quiz}/my-attempts', [QuizAttemptController::class, 'mine']);
    Route::get('/attempts/{attempt}', [QuizAttemptController::class, 'show']);
    Route::post('/attempts/{attempt}/submit', [QuizAttemptController::class, 'submit'])->middleware('throttle:submissions');
});
