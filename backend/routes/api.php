<?php

use App\Http\Controllers\Api\AcademicStructureController;
use App\Http\Controllers\Api\AccommodationController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AppealController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CheckinController;
use App\Http\Controllers\Api\CompetencyController;
use App\Http\Controllers\Api\CourseAnalyticsController;
use App\Http\Controllers\Api\CourseCopyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DigestController;
use App\Http\Controllers\Api\DiscussionController;
use App\Http\Controllers\Api\EngagementController;
use App\Http\Controllers\Api\GradebookController;
use App\Http\Controllers\Api\HandRaiseController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\IntegrityController;
use App\Http\Controllers\Api\InterventionController;
use App\Http\Controllers\Api\LearningGoalController;
use App\Http\Controllers\Api\LmsController;
use App\Http\Controllers\Api\ManagementController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\QuizAttemptController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RubricController;
use App\Http\Controllers\Api\SecurityEventController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SessionQuestionController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SimilarityController;
use App\Http\Controllers\Api\SsoController;
use App\Http\Controllers\Api\StudyGroupController;
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
Route::get('/me/calendar/{token}.ics', [DashboardController::class, 'calendarFeed'])->where('token', '[A-Za-z0-9]+')->middleware('throttle:public');
Route::get('/attachment-feedback/{token}', [AttachmentController::class, 'showFeedbackForm'])->middleware('throttle:password');
Route::post('/attachment-feedback/{token}', [AttachmentController::class, 'submitFeedback'])->middleware('throttle:password');

Route::middleware(['auth:sanctum', 'throttle:api', 'idempotent', 'conditional.get'])->group(function () {
    // Account
    Route::get('/me', [AccountController::class, 'me']);
    Route::patch('/me', [AccountController::class, 'updateProfile']);
    Route::post('/me/password', [AccountController::class, 'changePassword']);
    Route::get('/me/preferences', [DigestController::class, 'show']);
    Route::patch('/me/preferences', [DigestController::class, 'update']);
    Route::get('/me/digest-preview', [DigestController::class, 'preview'])->middleware('throttle:heavy');
    Route::get('/me/agenda', [DashboardController::class, 'agenda']);
    Route::get('/me/insights', [DashboardController::class, 'insights']);
    Route::post('/me/calendar-token', [DashboardController::class, 'calendarToken']);
    Route::get('/me/engagement', [EngagementController::class, 'me']);
    Route::get('/me/learning-goals', [LearningGoalController::class, 'index']);
    Route::post('/me/learning-goals', [LearningGoalController::class, 'store']);
    Route::patch('/learning-goals/{goal}', [LearningGoalController::class, 'update']);
    Route::delete('/learning-goals/{goal}', [LearningGoalController::class, 'destroy']);
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

    // System: monitoring, housekeeping, backups
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
    Route::post('/offerings/{offering}/join-code', [RegistrationController::class, 'generateJoinCode']);
    Route::post('/offerings/join', [RegistrationController::class, 'joinByCode'])->middleware('throttle:submissions');

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
    Route::get('/offerings/{offering}/analytics', [CourseAnalyticsController::class, 'show']);
    Route::post('/offerings/{offering}/enrolments', [LmsController::class, 'enrol']);
    Route::post('/offerings/{offering}/enrolments/import', [ManagementController::class, 'importEnrolments'])->middleware('throttle:heavy');
    Route::post('/offerings/{offering}/teachers', [LmsController::class, 'teacher']);
    Route::patch('/offerings/{offering}/teachers/{user}', [LmsController::class, 'updateTeacher']);
    Route::delete('/offerings/{offering}/teachers/{user}', [ManagementController::class, 'removeTeacher']);

    // Class sessions, online meetings, and attendance
    Route::get('/offerings/{offering}/sessions', [SessionController::class, 'index']);
    Route::post('/offerings/{offering}/sessions', [SessionController::class, 'store']);
    Route::patch('/sessions/{session}', [SessionController::class, 'update']);
    Route::post('/sessions/{session}/join', [SessionController::class, 'join'])->middleware('throttle:heavy');
    Route::delete('/sessions/{session}', [SessionController::class, 'destroy']);
    Route::get('/sessions/{session}/attendance', [SessionController::class, 'roll']);
    Route::put('/sessions/{session}/attendance', [SessionController::class, 'mark']);
    Route::get('/offerings/{offering}/attendance', [SessionController::class, 'summary']);
    Route::get('/sessions/{session}/checkin', [CheckinController::class, 'show']);
    Route::post('/sessions/{session}/checkin/open', [CheckinController::class, 'open']);
    Route::post('/sessions/{session}/checkin/close', [CheckinController::class, 'close']);
    Route::post('/sessions/{session}/checkin', [CheckinController::class, 'checkin'])->middleware('throttle:checkin');
    Route::post('/sessions/{session}/hand-raises', [HandRaiseController::class, 'raise']);
    Route::delete('/sessions/{session}/hand-raises', [HandRaiseController::class, 'lower']);
    Route::get('/sessions/{session}/hand-raises', [HandRaiseController::class, 'index']);
    Route::delete('/sessions/{session}/hand-raises/{user}', [HandRaiseController::class, 'clear']);
    Route::post('/sessions/{session}/questions', [SessionQuestionController::class, 'store']);
    Route::get('/sessions/{session}/questions', [SessionQuestionController::class, 'index']);
    Route::patch('/session-questions/{question}', [SessionQuestionController::class, 'markAnswered']);
    Route::delete('/session-questions/{question}', [SessionQuestionController::class, 'destroy']);

    // Communication
    Route::get('/offerings/{offering}/announcements', [AnnouncementController::class, 'index']);
    Route::post('/offerings/{offering}/announcements', [AnnouncementController::class, 'store']);
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
    Route::get('/offerings/{offering}/discussions', [DiscussionController::class, 'index']);
    Route::post('/offerings/{offering}/discussions', [DiscussionController::class, 'store']);
    Route::get('/discussions/{thread}', [DiscussionController::class, 'show']);
    Route::delete('/discussions/{thread}', [DiscussionController::class, 'destroyThread']);
    Route::post('/discussions/{thread}/posts', [DiscussionController::class, 'reply']);
    Route::delete('/posts/{post}', [DiscussionController::class, 'destroyPost']);

    // Practical Skills Passport
    Route::get('/offerings/{offering}/competencies', [CompetencyController::class, 'index']);
    Route::post('/offerings/{offering}/competencies', [CompetencyController::class, 'store']);
    Route::patch('/competencies/{competency}', [CompetencyController::class, 'update']);
    Route::delete('/competencies/{competency}', [CompetencyController::class, 'destroy']);
    Route::get('/competencies/{competency}/logbook', [CompetencyController::class, 'indexLogbookEntries']);
    Route::post('/competencies/{competency}/logbook', [CompetencyController::class, 'storeLogbookEntry']);
    Route::patch('/logbook-entries/{entry}/review', [CompetencyController::class, 'reviewLogbookEntry']);
    Route::get('/logbook-entries/{entry}/evidence', [CompetencyController::class, 'downloadEvidence']);

    // Industrial attachment workspace
    Route::get('/offerings/{offering}/attachments', [AttachmentController::class, 'index']);
    Route::post('/offerings/{offering}/attachments', [AttachmentController::class, 'store']);
    Route::get('/offerings/{offering}/attachments/mine', [AttachmentController::class, 'mine']);
    Route::get('/attachment-placements/{placement}/logbook', [AttachmentController::class, 'indexLogbookEntries']);
    Route::post('/attachment-placements/{placement}/logbook', [AttachmentController::class, 'storeLogbookEntry']);
    Route::post('/attachment-placements/{placement}/request-supervisor-feedback', [AttachmentController::class, 'requestSupervisorFeedback']);
    Route::get('/attachment-logbook-entries/{entry}/evidence', [AttachmentController::class, 'downloadEvidence']);

    // Research and project workspace
    Route::get('/offerings/{offering}/projects', [ProjectController::class, 'index']);
    Route::post('/offerings/{offering}/projects', [ProjectController::class, 'store']);
    Route::get('/offerings/{offering}/projects/mine', [ProjectController::class, 'mine']);
    Route::patch('/projects/{project}', [ProjectController::class, 'update']);
    Route::get('/projects/{project}/milestones', [ProjectController::class, 'indexMilestones']);
    Route::post('/projects/{project}/milestones', [ProjectController::class, 'storeMilestone']);
    Route::patch('/project-milestones/{milestone}', [ProjectController::class, 'updateMilestone']);
    Route::get('/projects/{project}/meetings', [ProjectController::class, 'indexMeetings']);
    Route::post('/projects/{project}/meetings', [ProjectController::class, 'storeMeeting']);

    // Learning intervention centre
    Route::get('/offerings/{offering}/at-risk', [InterventionController::class, 'atRisk']);
    Route::get('/offerings/{offering}/intervention-plans', [InterventionController::class, 'indexPlans']);
    Route::post('/offerings/{offering}/intervention-plans', [InterventionController::class, 'storePlan']);
    Route::patch('/intervention-plans/{plan}', [InterventionController::class, 'updatePlan']);

    // Collaborative learning community
    Route::get('/offerings/{offering}/study-groups', [StudyGroupController::class, 'index']);
    Route::post('/offerings/{offering}/study-groups', [StudyGroupController::class, 'store']);
    Route::delete('/study-groups/{group}', [StudyGroupController::class, 'destroy']);
    Route::post('/study-groups/{group}/join', [StudyGroupController::class, 'join']);
    Route::post('/study-groups/{group}/leave', [StudyGroupController::class, 'leave']);

    // Academic integrity centre
    Route::get('/offerings/{offering}/integrity-cases', [IntegrityController::class, 'index']);
    Route::post('/offerings/{offering}/integrity-cases', [IntegrityController::class, 'store']);
    Route::patch('/integrity-cases/{case}', [IntegrityController::class, 'update']);

    // AI learning assistant and lecturer teaching assistant
    Route::post('/offerings/{offering}/ai/assist', [AiAssistantController::class, 'assist'])->middleware('throttle:heavy');
    Route::post('/offerings/{offering}/ai/teaching-assist', [AiAssistantController::class, 'teachingAssist'])->middleware('throttle:heavy');

    // Accessibility: extended-time accommodations
    Route::get('/offerings/{offering}/accommodations', [AccommodationController::class, 'index']);
    Route::post('/offerings/{offering}/accommodations', [AccommodationController::class, 'store']);
    Route::delete('/accommodations/{accommodation}', [AccommodationController::class, 'destroy']);

    // Engagement and motivation: per-course participation points, for managers
    Route::get('/offerings/{offering}/engagement', [EngagementController::class, 'offering']);

    // Learning content and progress
    Route::post('/offerings/{offering}/modules', [LmsController::class, 'module']);
    Route::patch('/modules/{module}', [LmsController::class, 'updateModule']);
    Route::delete('/modules/{module}', [ManagementController::class, 'deleteModule']);
    Route::post('/modules/{module}/items', [LmsController::class, 'item']);
    Route::get('/modules/{module}/download', [LmsController::class, 'downloadModule'])->middleware('throttle:heavy');
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
    Route::get('/assignments/{assignment}/groups', [LmsController::class, 'assignmentGroups']);
    Route::post('/assignments/{assignment}/groups', [LmsController::class, 'storeAssignmentGroup']);
    Route::patch('/assignment-groups/{group}', [LmsController::class, 'updateAssignmentGroup']);
    Route::delete('/assignment-groups/{group}', [LmsController::class, 'destroyAssignmentGroup']);
    Route::post('/assignments/{assignment}/peer-reviews/assign', [LmsController::class, 'assignPeerReviews']);
    Route::get('/assignments/{assignment}/my-peer-reviews', [LmsController::class, 'myPeerReviews']);
    Route::patch('/peer-reviews/{review}', [LmsController::class, 'givePeerReview']);
    Route::get('/submissions/{submission}/peer-reviews', [LmsController::class, 'submissionPeerReviews']);

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
    Route::get('/assignments/{assignment}/my-group', [LmsController::class, 'myGroup']);
    Route::post('/submissions/{submission}/grades', [LmsController::class, 'grade']);
    Route::get('/submissions/{submission}/download', [LmsController::class, 'downloadSubmission']);
    Route::get('/grades/{grade}/recording', [LmsController::class, 'downloadGradeRecording']);
    Route::get('/submissions/{submission}/versions', [LmsController::class, 'submissionVersions']);
    Route::get('/submissions/{submission}/feedback', [LmsController::class, 'submissionFeedback']);
    Route::post('/submissions/{submission}/feedback', [LmsController::class, 'giveSubmissionFeedback']);

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
    Route::put('/attempts/{attempt}/draft', [QuizAttemptController::class, 'saveDraft']);
    Route::patch('/quiz-answers/{answer}/grade', [QuizAttemptController::class, 'gradeAnswer']);
});
