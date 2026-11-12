<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RoutingController;
use App\Http\Controllers\Auth\LoginController;

use App\Http\Controllers\ApplicantController;
use App\Http\Controllers\HeadOfficeController;
use App\Http\Controllers\ModuleNotesController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\IPAddressController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\PostcodeController;
use App\Http\Controllers\CommunicationController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\FreePBXController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ApplicantCvController;
use App\Http\Controllers\DialLockController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ScrapController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

require __DIR__ . '/auth.php';

// Login and logout: LoginController only (IP allowlist, active accounts, logout_at). "/" is the
// dashboard root below (guests are sent to /login); auth.php no longer has Breeze copies of these.
Route::get('login', [LoginController::class, 'showLoginForm'])->middleware('guest')->name('login');
Route::post('login', [LoginController::class, 'login'])->middleware('throttle:5,1');
// POST only: a GET logout can be triggered by any link or prefetch (R-06).
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');
// Where PermissionHelper::firstAllowedRoute sends a signed-in user with no permission at all
// (e.g. an approved sign-up without a role yet); the route was missing, so that sign-in was a 500.
Route::view('no-access', 'auth.no-access')->middleware('auth')->name('no-permission');

Route::get('message_receive', [CommunicationController::class, 'messageReceive']);
/**This route is using to retrieve messages from openVox */

// Route group with authentication middleware
Route::group(['prefix' => '/', 'middleware' => 'auth'], function () {
    // Click-to-dial collision control — lock a number while an agent calls it
    // Xplogent "Agent call" page (permission: xplogent-agent-call)
    Route::get('xplogent/agent-call', [\App\Http\Controllers\XplogentAgentCallController::class, 'index'])->name('xplogent.agent-call');
    Route::get('xplogent/agent-call/sales', [\App\Http\Controllers\XplogentAgentCallController::class, 'sales'])->name('xplogent.agent-call.sales');
    Route::get('xplogent/agent-call/queue', [\App\Http\Controllers\XplogentAgentCallController::class, 'queue'])->name('xplogent.agent-call.queue');
    Route::post('xplogent/agent-call/dial', [\App\Http\Controllers\XplogentAgentCallController::class, 'dial'])->name('xplogent.agent-call.dial');
    Route::get('xplogent/agent-call/attempts', [\App\Http\Controllers\XplogentAgentCallController::class, 'attempts'])->name('xplogent.agent-call.attempts');
    Route::post('xplogent/agent-call/check', [\App\Http\Controllers\XplogentAgentCallController::class, 'check'])->name('xplogent.agent-call.check');

    Route::get('dialing/info',          [DialLockController::class, 'info'])->name('dialing.info');
    Route::post('dialing/acquire',      [DialLockController::class, 'acquire'])->name('dialing.acquire');
    Route::post('dialing/release',      [DialLockController::class, 'release'])->name('dialing.release');
    Route::get('dialing/active-locks',  [DialLockController::class, 'activeList'])->name('dialing.active-locks');
    Route::post('dialing/clear-lock',   [DialLockController::class, 'clearLock'])->name('dialing.clear-lock');
    Route::post('dialing/clear-all-locks', [DialLockController::class, 'clearAllLocks'])->name('dialing.clear-all-locks');

    Route::group(['prefix' => 'dashboard'], function () {
        Route::get('', [DashboardController::class, 'index'])->name('dashboard.index');
    });
    Route::get('getUsersForDashboard', [DashboardController::class, 'getUsersForDashboard'])->name('getUsersForDashboard');
    Route::POST('getUserStatistics', [DashboardController::class, 'getUserStatistics'])->name('getUserStatistics');
    Route::post('/get-user-statistics-detail', [DashboardController::class, 'getUserStatisticsDetail'])->name('getUserStatisticsDetail');
    Route::get('/get-weekly-sales', [DashboardController::class, 'getWeeklySales']);
    Route::get('/get-sales-analytic', [DashboardController::class, 'getSalesAnalytic']);
    Route::get('/dashboard/counts', [DashboardController::class, 'getCounts'])->name('dashboard.counts');
    Route::get('/dashboard/statistics-data', [DashboardController::class, 'getStats']);
    Route::get('/dashboard/statistics-details', [DashboardController::class, 'getStatisticsDetails']);
    Route::get('/statistics/status-details', [DashboardController::class, 'getStatusDetails']);
    Route::post('/dashboard/statistics-report', [DashboardController::class, 'statisticsReportIndex'])->name('dashboard.report-applicants');
    Route::get('getStatisticsApplicants', [DashboardController::class, 'getStatisticsApplicants'])->name('getStatisticsApplicants');
    Route::get('/statistics/chart-data', [DashboardController::class, 'getChartData']);

    Route::get('/notifications', [DashboardController::class, 'notificationsIndex'])->name('notifications.index');
    Route::post('/notifications/mark-as-read', [DashboardController::class, 'markNotificationsAsRead']);
    Route::get('getUserNotifications', [DashboardController::class, 'getUserNotifications'])->name('getUserNotifications');

    Route::get('/unread-notifications', [DashboardController::class, 'getUnreadNotifications'])->name('unread-notifications');
    Route::get('/unread-messages', [DashboardController::class, 'getUnreadMessages'])->name('unread-messages');

    Route::group(['prefix' => 'applicants'], function () {
        Route::get('', [ApplicantController::class, 'index'])->name('applicants.list');
        Route::get('create', [ApplicantController::class, 'create'])->name('applicants.create');
        Route::post('store', [ApplicantController::class, 'store'])->name('applicants.store');
        Route::get('{id}/edit', [ApplicantController::class, 'edit'])->name('applicants.edit');
        Route::get('available-no-job/{id}/{radius?}', [ApplicantController::class, 'availableNoJobsIndex'])->name('applicants.available_no_job');
        Route::get('available-jobs/{id}/{radius?}', [ApplicantController::class, 'availableJobsIndex'])->name('applicants.available_job');
        Route::post('update', [ApplicantController::class, 'update'])->name('applicants.update');
        Route::post('uploadCv', [ApplicantController::class, 'uploadCv'])->name('applicants.uploadCv');
        Route::post('crmuploadCv', [ApplicantController::class, 'crmuploadCv'])->name('applicants.crmuploadCv');
        Route::get('draft-files/{synopsisForm}/download', [ApplicantController::class, 'downloadApplicantDraftFile'])
            ->whereNumber('synopsisForm')
            ->name('applicants.draft-file.download');
        Route::get('draft-files/{synopsisForm}/auto-download', [ApplicantController::class, 'autoDownloadApplicantDraftFile'])
            ->whereNumber('synopsisForm')
            ->middleware('signed')
            ->name('applicants.draft-file.auto-download');
        Route::get('{id}/history', [ApplicantController::class, 'history'])->name('applicants.history');
        // CVs are not public files: this checks the login, the permission and the blocked rule.
        Route::get('{id}/cv/{kind}', [ApplicantCvController::class, 'download'])
            ->whereNumber('id')->whereIn('kind', ['original', 'updated'])->name('applicants.cv.download');
    });

    Route::get('getAvailableJobs', [ApplicantController::class, 'getAvailableJobs'])->name('getAvailableJobs');
    Route::get('getAvailableNoJobs', [ApplicantController::class, 'getAvailableNoJobs'])->name('getAvailableNoJobs');
    Route::get('applicantsExport', [ApplicantController::class, 'export'])->name('applicantsExport');
    Route::get('regionApplicantsExport', [ApplicantController::class, 'regionalApplicatsExport'])->name('regionApplicantsExport');
    Route::post('changeStatus', [ApplicantController::class, 'changeStatus'])->name('changeStatus');
    Route::post('getApplicantsAjaxRequest', [ApplicantController::class, 'getApplicantsAjaxRequest'])->name('getApplicantsAjaxRequest');
    Route::get('getJobTitlesByCategory', [ApplicantController::class, 'getJobTitlesByCategory'])->name('getJobTitlesByCategory');
    Route::post('storeShortNotes', [ApplicantController::class, 'storeShortNotes'])->name('storeShortNotes');
    Route::post('markApplicantNoNursingHome', [ApplicantController::class, 'markApplicantNoNursingHome'])->name('markApplicantNoNursingHome');
    Route::post('sendCVtoQuality', [ApplicantController::class, 'sendCVtoQuality'])->name('sendCVtoQuality');
    Route::post('saveSendCvHangupNote', [ApplicantController::class, 'saveSendCvHangupNote'])->name('saveSendCvHangupNote');
    Route::get('applicants/cv-questionnaire/{cvNoteId}/download', [ApplicantController::class, 'downloadCvQuestionnaire'])
        ->name('applicants.cv-questionnaire.download');
    Route::get('getApplicantHistoryAjaxRequest', [ApplicantController::class, 'getApplicantHistoryAjaxRequest'])
        ->name('getApplicantHistoryAjaxRequest');
    Route::get('getApplicantNoNursingHomeNotes', [ApplicantController::class, 'getApplicantNoNursingHomeNotes'])
        ->name('getApplicantNoNursingHomeNotes');
    Route::get('getApplicanCallbackNotes', [ApplicantController::class, 'getApplicanCallbackNotes'])
        ->name('getApplicanCallbackNotes');

    Route::group(['prefix' => 'postcode-finder'], function () {
        Route::get('', [PostcodeController::class, 'index'])->name('postcode-finder.index');
    });
    Route::post('getPostcodeResults', [PostcodeController::class, 'getPostcodeResults'])->name('getPostcodeResults');

    Route::group(['prefix' => 'head-offices'], function () {
        Route::get('', [HeadOfficeController::class, 'index'])->name('head-offices.list');
        Route::get('create', [HeadOfficeController::class, 'create'])->name('head-offices.create');
        Route::post('store', [HeadOfficeController::class, 'store'])->name('head-offices.store');
        Route::get('{id}/edit', [HeadOfficeController::class, 'edit'])->name('head-offices.edit');
        Route::post('update', [HeadOfficeController::class, 'update'])->name('head-offices.update');
        Route::get('{id}/documents', [HeadOfficeController::class, 'documents'])->name('head-offices.documents.index');
        Route::get('{id}/documents/{documentId}', [HeadOfficeController::class, 'showDocument'])->name('head-offices.documents.show');
        Route::delete('{id}/documents/{documentId}', [HeadOfficeController::class, 'destroyDocument'])->name('head-offices.documents.destroy');
        Route::post('{id}/documents/{documentId}/restore', [HeadOfficeController::class, 'restoreDocument'])->name('head-offices.documents.restore');
        Route::post('{id}/toggle-blacklist', [HeadOfficeController::class, 'toggleBlacklist'])->name('head-offices.toggle-blacklist');
        Route::get('{id}', [HeadOfficeController::class, 'officeDetails'])->name('head-offices.details');
    });
    Route::get('officesExport', [HeadOfficeController::class, 'export'])->name('officesExport');
    Route::get('getHeadOffices', [HeadOfficeController::class, 'getHeadOffices'])->name('getHeadOffices');
    Route::post('storeHeadOfficeShortNotes', [HeadOfficeController::class, 'storeHeadOfficeShortNotes'])->name('storeHeadOfficeShortNotes');
    Route::get('getModuleContacts', [HeadOfficeController::class, 'getModuleContacts'])->name('getModuleContacts');

    Route::group(['prefix' => 'units'], function () {
        Route::get('', [UnitController::class, 'index'])->name('units.list');
        Route::get('create', [UnitController::class, 'create'])->name('units.create');
        Route::post('store', [UnitController::class, 'store'])->name('units.store');
        Route::get('{id}/edit', [UnitController::class, 'edit'])->name('units.edit');
        Route::post('update', [UnitController::class, 'update'])->name('units.update');
        Route::get('{id}/documents', [UnitController::class, 'documents'])->name('units.documents.index');
        Route::get('{id}/documents/{documentId}', [UnitController::class, 'showDocument'])->name('units.documents.show');
        Route::delete('{id}/documents/{documentId}', [UnitController::class, 'destroyDocument'])->name('units.documents.destroy');
        Route::post('{id}/documents/{documentId}/restore', [UnitController::class, 'restoreDocument'])->name('units.documents.restore');
        Route::post('{id}/change-status', [UnitController::class, 'changeUnitStatus'])->name('units.change-status');
        Route::post('{id}/toggle-blacklist', [UnitController::class, 'toggleBlacklist'])->name('units.toggle-blacklist');
        Route::get('{id}', [UnitController::class, 'unitDetails'])->name('units.details');
    });
    Route::get('unitsExport', [UnitController::class, 'export'])->name('unitsExport');
    Route::get('getUnits', [UnitController::class, 'getUnits'])->name('getUnits');
    Route::post('storeUnitShortNotes', [UnitController::class, 'storeUnitShortNotes'])->name('storeUnitShortNotes');

    Route::group(['prefix' => 'sales'], function () {
        Route::get('', [SaleController::class, 'index'])->name('sales.list');
        Route::get('create', [SaleController::class, 'create'])->name('sales.create');
        Route::post('store', [SaleController::class, 'store'])->name('sales.store');
        Route::get('{id}/edit', [SaleController::class, 'edit'])->name('sales.edit');
        Route::post('update', [SaleController::class, 'update'])->name('sales.update');
        Route::post('{id}/toggle-blacklist', [SaleController::class, 'toggleBlacklist'])->name('sales.toggle-blacklist');
        Route::get('{id}/history', [SaleController::class, 'saleHistoryIndex'])->name('sales.history');
        Route::get('direct', [SaleController::class, 'directSaleIndex'])->name('sales.direct');
        Route::get('open', [SaleController::class, 'openSaleIndex'])->name('sales.open');
        Route::get('closed', [SaleController::class, 'closeSaleIndex'])->name('sales.closed');
        Route::get('rejected', [SaleController::class, 'rejectedSaleIndex'])->name('sales.rejected');
        Route::get('on-hold', [SaleController::class, 'onHoldSaleIndex'])->name('sales.on-hold');
        Route::get('pending-on-hold', [SaleController::class, 'pendingOnHoldSaleIndex'])->name('sales.pending-on-hold');
    });
    Route::get('salesExport', [SaleController::class, 'export'])->name('salesExport');
    Route::get('/sales/fetch-applicants-by-radius/{id}/{radius?}', [SaleController::class, 'fetchApplicantsWithinSaleRadiusIndex'])
        ->name('fetchApplicantsWithinSaleRadiusIndex');
    Route::get('getSales', [SaleController::class, 'getSales'])->name('getSales');
    Route::get('getDirectSales', [SaleController::class, 'getDirectSales'])->name('getDirectSales');
    Route::get('getRejectedSales', [SaleController::class, 'getRejectedSales'])->name('getRejectedSales');
    Route::get('getClosedSales', [SaleController::class, 'getClosedSales'])->name('getClosedSales');
    Route::get('getOpenSales', [SaleController::class, 'getOpenSales'])->name('getOpenSales');
    Route::get('pendingOnHoldSales', [SaleController::class, 'pendingOnHoldSales'])->name('pendingOnHoldSales');
    Route::get('getOnHoldSales', [SaleController::class, 'getOnHoldSales'])->name('getOnHoldSales');
    Route::delete('delete-document', [SaleController::class, 'removeDocument'])->name('sales.remove_document');
    Route::get('getOfficeUnits', [SaleController::class, 'getOfficeUnits'])->name('getOfficeUnits');
    Route::post('storeSaleNotes', [SaleController::class, 'storeSaleNotes'])->name('storeSaleNotes');
    Route::post('changeSaleStatus', [SaleController::class, 'changeSaleStatus'])->name('changeSaleStatus');
    Route::get('getApplicantsBySaleRadius', [SaleController::class, 'getApplicantsBySaleRadius'])->name('getApplicantsBySaleRadius');
    Route::post('changeSaleHoldStatus', [SaleController::class, 'changeSaleHoldStatus'])->name('changeSaleHoldStatus');   // was GET (R-06)
    // web.php
    Route::post('updatePendingOnHoldStatus', [SaleController::class, 'updatePendingOnHoldStatus'])->name('updatePendingOnHoldStatus');

    Route::get('getSaleDocuments', [SaleController::class, 'getSaleDocuments'])->name('getSaleDocuments');
    Route::get('getSaleHistoryAjaxRequest', [SaleController::class, 'getSaleHistoryAjaxRequest'])->name('getSaleHistoryAjaxRequest');

    Route::group(['prefix' => 'resources'], function () {
        Route::get('direct', [ResourceController::class, 'directIndex'])->name('resources.directIndex');
        Route::get('indirect', [ResourceController::class, 'indirectIndex'])->name('resources.indirectIndex');
        Route::get('rejected-applicants', [ResourceController::class, 'rejectedApplicantsIndex'])->name('resources.rejectedIndex');
        Route::get('blocked-applicants', [ResourceController::class, 'blockedApplicantsIndex'])->name('resources.blockedApplicantsIndex');
        Route::get('crm-paid-applicants', [ResourceController::class, 'crmPaidIndex'])->name('resources.crmPaidIndex');
        Route::get('no-job-applicants', [ResourceController::class, 'noJobIndex'])->name('resources.noJobIndex');
        Route::get('not-interested-applicants', [ResourceController::class, 'notInterestedIndex'])->name('resources.notInterestedIndex');
        Route::get('category-wise-applicants', [ResourceController::class, 'categoryWiseApplicantIndex'])->name('resources.categoryWiseApplicantIndex');
        Route::post('revertBlockedApplicant', [ResourceController::class, 'revertBlockedApplicant'])->name('resources.revertBlockedApplicant');
        Route::post('revertNoJobApplicant', [ResourceController::class, 'revertNoJobApplicant'])->name('resources.revertNoJobApplicant');
        Route::post('revertNotInterestedApplicant', [ResourceController::class, 'revertNotInterestedApplicant'])->name('resources.revertNotInterestedApplicant');
    });
    Route::post('markAsNursingHomeExp', [ResourceController::class, 'markAsNursingHomeExp'])->name('markAsNursingHomeExp');
    Route::post('markAsNoNursingHomeExp', [ResourceController::class, 'markAsNoNursingHomeExp'])->name('markAsNoNursingHomeExp');
    Route::get('getResourcesDirectSales', [ResourceController::class, 'getResourcesDirectSales'])->name('getResourcesDirectSales');
    Route::get('getResourcesIndirectApplicants', [ResourceController::class, 'getResourcesIndirectApplicants'])->name('getResourcesIndirectApplicants');
    Route::get('getResourcesRejectedApplicants', [ResourceController::class, 'getResourcesRejectedApplicants'])->name('getResourcesRejectedApplicants');
    Route::get('getApplicantHistorybyStatus', [ResourceController::class, 'getApplicantHistorybyStatus'])->name('getApplicantHistorybyStatus');
    Route::get('getResourcesBlockedApplicants', [ResourceController::class, 'getResourcesBlockedApplicants'])->name('getResourcesBlockedApplicants');
    Route::get('getResourcesPaidApplicants', [ResourceController::class, 'getResourcesPaidApplicants'])->name('getResourcesPaidApplicants');
    Route::get('getResourcesNoJobApplicants', [ResourceController::class, 'getResourcesNoJobApplicants'])->name('getResourcesNoJobApplicants');
    Route::get('getResourcesNotInterestedApplicants', [ResourceController::class, 'getResourcesNotInterestedApplicants'])->name('getResourcesNotInterestedApplicants');
    Route::get('getResourcesCategoryWised', [ResourceController::class, 'getResourcesCategoryWised'])->name('getResourcesCategoryWised');
    Route::post('markApplicantNotInterestedOnSale', [ResourceController::class, 'markApplicantNotInterestedOnSale'])->name('markApplicantNotInterestedOnSale');
    Route::post('markApplicantCallback', [ResourceController::class, 'markApplicantCallback'])->name('markApplicantCallback');
    Route::post('exportDirectApplicantsEmails', [ResourceController::class, 'exportDirectApplicantsEmails'])->name('exportDirectApplicantsEmails');

    Route::group(['prefix' => 'emails'], function () {
        Route::get('compose-email', [CommunicationController::class, 'index'])->name('emails.inbox');
        Route::get('sent-emails', [CommunicationController::class, 'sentEmailsIndex'])->name('emails.sent_emails');
    });
    Route::get('send-email-to-applicant', [CommunicationController::class, 'sendEmailsToApplicants'])->name('emails.sendemailstoapplicants');
    Route::post('saveEmailsForApplicants', [CommunicationController::class, 'saveEmailsForApplicants'])->name('emails.saveEmailsForApplicants');
    Route::post('saveComposedEmail', [CommunicationController::class, 'saveComposedEmail'])->name('emails.saveComposedEmail');
    Route::get('getSentEmailsAjaxRequest', [CommunicationController::class, 'getSentEmailsAjaxRequest'])->name('getSentEmailsAjaxRequest');
    Route::post('sendMessageToApplicant', [CommunicationController::class, 'sendMessageToApplicant'])->name('sendMessageToApplicant');

    Route::group(['prefix' => 'messages'], function () {
        Route::get('', [CommunicationController::class, 'Messagesindex'])->name('messages.index');
        Route::get('write-message', [CommunicationController::class, 'writeMessageindex'])->name('messages.write');
    });

    Route::post('/getChatBoxMessages', [CommunicationController::class, 'getChatBoxMessages'])->name('getChatBoxMessages');
    Route::post('/sendChatBoxMsg', [CommunicationController::class, 'sendChatBoxMsg'])->name('sendChatBoxMsg');
    Route::get('getUserChats', [CommunicationController::class, 'getUserChats'])->name('getUserChats');
    Route::get('getApplicantsForMessage', [CommunicationController::class, 'getApplicantsForMessage'])->name('getApplicantsForMessage');
    Route::get('getUnknownMessage', [CommunicationController::class, 'getUnknownMessage'])->name('getUnknownMessage');

    Route::group(['prefix' => 'users'], function () {
        Route::get('', [UserController::class, 'index'])->name('users.list');
        Route::get('create', [UserController::class, 'create'])->name('users.create');
        Route::post('store', [UserController::class, 'store'])->name('users.store');
        Route::get('edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('update', [UserController::class, 'update'])->name('users.update');
        Route::get('{id}', [UserController::class, 'userDetails'])->name('users.details');
        Route::get('activity-logs/{id}', [UserController::class, 'activityLogIndex'])->name('users.activity_log');
    });
    Route::get('usersExport', [UserController::class, 'export'])->name('usersExport');
    Route::get('getUsers', [UserController::class, 'getUsers'])->name('getUsers');
    Route::post('getUserActivityLogs', [UserController::class, 'getUserActivityLogs'])->name('getUserActivityLogs');

    Route::get('import', [ImportController::class, 'importIndex'])->name('import.index');
    Route::post('users/import', [ImportController::class, 'usersImport'])->name('users.import');
    Route::post('applicants/import', [ImportController::class, 'applicantsImport'])->name('applicants.import');
    Route::post('applicants/process-file', [ImportController::class, 'applicantsProcessFile'])->name('process.file');
    Route::post('offices/import', [ImportController::class, 'officesImport'])->name('offices.import');
    Route::post('units/import', [ImportController::class, 'unitsImport'])->name('units.import');
    Route::post('sales/import', [ImportController::class, 'salesImport'])->name('sales.import');
    Route::post('applicant-message/import', [ImportController::class, 'messagesImport'])->name('messages.import');
    Route::post('applicant-notes/import', [ImportController::class, 'applicantNotesImport'])->name('applicantNotes.import');
    Route::post('applicant-pivot-sales/import', [ImportController::class, 'applicantPivotSaleImport'])->name('applicantPivotSale.import');
    Route::post('notes-pivot-sales/import', [ImportController::class, 'notesRangeForPivotSaleImport'])->name('notesRangeForPivotSale.import');
    Route::post('audits/import', [ImportController::class, 'auditsImport'])->name('audits.import');
    Route::post('crm-notes/import', [ImportController::class, 'crmNotesImport'])->name('crmNotes.import');
    Route::post('crm-rejected-cv/import', [ImportController::class, 'crmRejectedCvImport'])->name('crmRejectedCv.import');
    Route::post('cv-notes/import', [ImportController::class, 'cvNotesImport'])->name('cvNotes.import');
    Route::post('history-data/import', [ImportController::class, 'historyImport'])->name('history.import'); //
    Route::post('interview/import', [ImportController::class, 'interviewImport'])->name('interview.import');
    Route::post('ipAddress/import', [ImportController::class, 'ipAddressImport'])->name('ipAddress.import');
    Route::post('module-notes-data/import', [ImportController::class, 'moduleNotesImport'])->name('moduleNotes.import');
    Route::post('quality-notes/import', [ImportController::class, 'qualityNotesImport'])->name('qualityNotes.import');
    Route::post('regions/import', [ImportController::class, 'regionsImport'])->name('regions.import');
    Route::post('revert-stage/import', [ImportController::class, 'revertStageImport'])->name('revertStage.import');
    Route::post('sale-documents/import', [ImportController::class, 'saleDocumentsImport'])->name('saleDocuments.import');
    Route::post('sale-notes/import', [ImportController::class, 'saleNotesImport'])->name('saleNotes.import');
    Route::post('sent-emails-data/import', [ImportController::class, 'sentEmailDataImport'])->name('sentEmailData.import');

    Route::group(['prefix' => 'reports'], function () {
        Route::get('users-login-report', [UserController::class, 'userLogin'])->name('reports.usersLoginReport');
        Route::get('login-history/{id}', [UserController::class, 'userLoginHistoryIndex'])->name('reports.userLoginHistory');
    });
    Route::get('getUsersLoginReport', [UserController::class, 'getUsersLoginReport'])->name('getUsersLoginReport');
    Route::get('getUserLoginHistory', [UserController::class, 'getUserLoginHistory'])->name('getUserLoginHistory');
    Route::post('changeUserStatus', [UserController::class, 'changeUserStatus'])->name('changeUserStatus');

    Route::group(['prefix' => 'roles'], function () {
        Route::get('', [RoleController::class, 'index'])->name('roles.list');
        Route::get('create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('store', [RoleController::class, 'store'])->name('roles.store');
        Route::get('edit/{id}', [RoleController::class, 'edit'])->name('roles.edit');
        Route::get('view/{id}', [RoleController::class, 'view'])->name('roles.view');
        Route::put('update', [RoleController::class, 'update'])->name('roles.update');
        Route::get('{id}', [RoleController::class, 'show'])->name('roles.details');     // there is no details()
    });
    Route::get('getRoles', [RoleController::class, 'getRoles'])->name('getRoles');
    Route::get('getPermissions', [RoleController::class, 'getPermissions'])->name('getPermissions');
    Route::post('permissions/list', [RoleController::class, 'permissionIndex'])->name('permissions.list');
    Route::post('permissions/store', [RoleController::class, 'permissionStore'])->name('permissions.store');
    Route::put('permissions/update', [RoleController::class, 'permissionUpdate'])->name('permissions.update');

    // job categories
    Route::get('getJobCategories', [SettingController::class, 'getJobCategories'])->name('getJobCategories');
    Route::post('job-categories/list', [SettingController::class, 'jobCategoriesIndex'])->name('job-categories.list');
    Route::post('job-categories/store', [SettingController::class, 'jobCategoriesStore'])->name('job-categories.store');
    Route::put('job-categories/update', [SettingController::class, 'jobCategoriesUpdate'])->name('job-categories.update');

    // job titles
    Route::get('getJobTitles', [SettingController::class, 'getJobTitles'])->name('getJobTitles');
    Route::get('getJobTitlesList', [SettingController::class, 'getJobTitlesList'])->name('getJobTitlesList');
    Route::get('job-titles/list', [SettingController::class, 'jobTitlesIndex'])->name('job-titles.list');
    Route::post('job-titles/store', [SettingController::class, 'jobTitlesStore'])->name('job-titles.store');
    Route::put('job-titles/update', [SettingController::class, 'jobTitlesUpdate'])->name('job-titles.update');

    // job sources
    Route::get('getJobSources', [SettingController::class, 'getJobSources'])->name('getJobSources');
    Route::post('job-sources/list', [SettingController::class, 'jobSourceIndex'])->name('job-sources.list');
    Route::post('job-sources/store', [SettingController::class, 'jobSourceStore'])->name('job-sources.store');
    Route::put('job-sources/update', [SettingController::class, 'jobSourceUpdate'])->name('job-sources.update');

    /**Email Templates */
    Route::get('settings/email-templates', [SettingController::class, 'emailTemplatesIndex'])->name('settings.email-templates');
    Route::get('getEmailTemplates', [SettingController::class, 'getEmailTemplates'])->name('getEmailTemplates');
    Route::post('emailEditTemplate', [SettingController::class, 'emailEditTemplate'])->name('emailEditTemplate');
    Route::post('email-templates/store', [SettingController::class, 'emailTemplatesStore'])->name('emailTemplates.store');
    Route::put('email-templates/update', [SettingController::class, 'emailTemplatesUpdate'])->name('emailTemplates.update');
    Route::put('settings/email-layout', [SettingController::class, 'saveEmailLayout'])->name('settings.email-layout.save');

    /**SMS Templates */
    Route::post('settings/sms-templates', [SettingController::class, 'smsTemplatesIndex'])->name('settings.sms-templates');
    Route::get('getSmsTemplates', [SettingController::class, 'getSmsTemplates'])->name('getSmsTemplates');
    Route::post('smsEditTemplate', [SettingController::class, 'smsEditTemplate'])->name('smsEditTemplate');
    Route::post('sms-templates/store', [SettingController::class, 'smsTemplatesStore'])->name('smsTemplates.store');
    Route::put('sms-templates/update', [SettingController::class, 'smsTemplatesUpdate'])->name('smsTemplates.update');

    /** crm */
    Route::group(['prefix' => 'crm'], function () {
        Route::get('', [CrmController::class, 'index'])->name('crm.list');
        // Route::get('{id}', [CrmController::class, 'changeStatus'])->name('crm.changeStatus');
        Route::get('notes-history/{applicant_id}/{sale_id}', [CrmController::class, 'crmNotesHistoryIndex'])->name('crmNotesHistoryIndex');
    });
    Route::get('getCrmApplicantsAjaxRequest', [CrmController::class, 'getCrmApplicantsAjaxRequest'])->name('getCrmApplicantsAjaxRequest');
    Route::post('getCrmUnreadMessageApplicants', [CrmController::class, 'getCrmUnreadMessageApplicants'])->name('getCrmUnreadMessageApplicants');
    Route::get('getApplicantCrmNotesHistoryAjaxRequest', [CrmController::class, 'getApplicantCrmNotesHistoryAjaxRequest'])->name('getApplicantCrmNotesHistoryAjaxRequest');
    Route::get('getApplicantCrmNotes', [CrmController::class, 'getApplicantCrmNotes'])->name('getApplicantCrmNotes');

    /** CRM Sent CV */
    Route::post('updateCrmNotes', [CrmController::class, 'updateCrmNotes'])->name('updateCrmNotes');
    Route::post('crmSendRequest', [CrmController::class, 'crmSendRequest'])->name('crmSendRequest');
    Route::post('crmSendRejectedCv', [CrmController::class, 'crmSendRejectedCv'])->name('crmSendRejectedCv');
    Route::post('crmRevertInQuality', [CrmController::class, 'crmRevertInQuality'])->name('crmRevertInQuality');

    /** CRM Sent No Job */
    Route::post('updateCrmNoJobNotes', [CrmController::class, 'updateCrmNoJobNotes'])->name('updateCrmNoJobNotes');
    Route::post('crmSendNoJobRequest', [CrmController::class, 'crmSendNoJobRequest'])->name('crmSendNoJobRequest');
    Route::post('crmSendNoJobToRejectedCv', [CrmController::class, 'crmSendNoJobToRejectedCv'])->name('crmSendNoJobToRejectedCv');
    Route::post('crmSentCvNoJobRevertInQuality', [CrmController::class, 'crmSentCvNoJobRevertInQuality'])->name('crmSentCvNoJobRevertInQuality');

    /** CRM Rejected CV */
    Route::post('crmRevertRejectedCvToSentCv', [CrmController::class, 'crmRevertRejectedCvToSentCv'])->name('crmRevertRejectedCvToSentCv');
    Route::post('crmRevertRejectedCvToQuality', [CrmController::class, 'crmRevertRejectedCvToQuality'])->name('crmRevertRejectedCvToQuality');

    /** CRM Request */
    Route::post('/crm/request-reject', [CrmController::class, 'crmRequestReject'])->name('crmRequestReject');
    Route::post('crmRequestNoResponseToReject', [CrmController::class, 'crmRequestNoResponseToReject'])->name('crmRequestNoResponseToReject');
    Route::post('crmRequestNoResponseToConfirmedRequest', [CrmController::class, 'crmRequestNoResponseToConfirmedRequest'])->name('crmRequestNoResponseToConfirmedRequest');
    Route::post('/crm/request-no-response', [CrmController::class, 'crmRequestNoResponse'])->name('crmRequestNoResponse');
    Route::post('crmRequestConfirm', [CrmController::class, 'crmRequestConfirm'])->name('crmRequestConfirm');
    Route::post('crmRequestSave', [CrmController::class, 'crmRequestSave'])->name('crmRequestSave');
    Route::post('crmScheduleInterview', [CrmController::class, 'crmScheduleInterview'])->name('crmScheduleInterview');
    Route::post('crmRevertRequestedCvToSentCv', [CrmController::class, 'crmRevertRequestedCvToSentCv'])->name('crmRevertRequestedCvToSentCv');
    Route::post('crmRevertRequestedCvToQuality', [CrmController::class, 'crmRevertRequestedCvToQuality'])->name('crmRevertRequestedCvToQuality');
    Route::post('crmRequestedInterviewEmailToApplicant', [CrmController::class, 'crmRequestedInterviewEmailToApplicant'])->name('crmRequestedInterviewEmailToApplicant');
    /** CRM Request No Job */

    /** CRM Request Reject */
    Route::post('crmRevertRequestRejectToSentCv', [CrmController::class, 'crmRevertRequestRejectToSentCv'])->name('crmRevertRequestRejectToSentCv');
    Route::post('crmRevertRequestRejectToRequest', [CrmController::class, 'crmRevertRequestRejectToRequest'])->name('crmRevertRequestRejectToRequest');
    Route::post('crmRequestRejectToQuality', [CrmController::class, 'crmRequestRejectToQuality'])->name('crmRequestRejectToQuality');

    /** CRM Confirmation */
    Route::post('crmRevertConfirmToRequest', [CrmController::class, 'crmRevertConfirmToRequest'])->name('crmRevertConfirmToRequest');
    Route::post('crmRevertConfirmToQuality', [CrmController::class, 'crmRevertConfirmToQuality'])->name('crmRevertConfirmToQuality');
    Route::post('crmConfirmInterviewToNotAttend', [CrmController::class, 'crmConfirmInterviewToNotAttend'])->name('crmConfirmInterviewToNotAttend');
    Route::post('crmConfirmInterviewToAttend', [CrmController::class, 'crmConfirmInterviewToAttend'])->name('crmConfirmInterviewToAttend');
    Route::post('crmConfirmInterviewToRebook', [CrmController::class, 'crmConfirmInterviewToRebook'])->name('crmConfirmInterviewToRebook');
    Route::post('crmConfirmSave', [CrmController::class, 'crmConfirmSave'])->name('crmConfirmSave');

    /** CRM Rebook */
    Route::post('crmRevertRebookToConfirmation', [CrmController::class, 'crmRevertRebookToConfirmation'])->name('crmRevertRebookToConfirmation');
    Route::post('crmRebookToNotAttended', [CrmController::class, 'crmRebookToNotAttended'])->name('crmRebookToNotAttended');
    Route::post('crmRevertRebookToQuality', [CrmController::class, 'crmRevertRebookToQuality'])->name('crmRevertRebookToQuality');
    Route::post('crmRebookToAttended', [CrmController::class, 'crmRebookToAttended'])->name('crmRebookToAttended');
    Route::post('crmRebookSave', [CrmController::class, 'crmRebookSave'])->name('crmRebookSave');

    /** CRM Attended */
    Route::post('crmRevertAttendedToRebook', [CrmController::class, 'crmRevertAttendedToRebook'])->name('crmRevertAttendedToRebook');
    Route::post('crmAttendedToDecline', [CrmController::class, 'crmAttendedToDecline'])->name('crmAttendedToDecline');
    Route::post('crmAttendedToStartDate', [CrmController::class, 'crmAttendedToStartDate'])->name('crmAttendedToStartDate');
    Route::post('crmRevertAttendedToQuality', [CrmController::class, 'crmRevertAttendedToQuality'])->name('crmRevertAttendedToQuality');
    Route::post('crmAttendedSave', [CrmController::class, 'crmAttendedSave'])->name('crmAttendedSave');

    /** CRM Not Attended */
    Route::post('crmNotAttendedToAttended', [CrmController::class, 'crmNotAttendedToAttended'])->name('crmNotAttendedToAttended');
    Route::post('crmNotAttendedToQuality', [CrmController::class, 'crmNotAttendedToQuality'])->name('crmNotAttendedToQuality');

    /** CRM Decline */
    Route::post('crmRevertDeclinedToAttended', [CrmController::class, 'crmRevertDeclinedToAttended'])->name('crmRevertDeclinedToAttended');
    Route::post('crmRevertDeclinedToQuality', [CrmController::class, 'crmRevertDeclinedToQuality'])->name('crmRevertDeclinedToQuality');

    /** CRM Start Date */
    Route::post('crmRevertStartDateToAttended', [CrmController::class, 'crmRevertStartDateToAttended'])->name('crmRevertStartDateToAttended');
    Route::post('crmStartDateToInvoice', [CrmController::class, 'crmStartDateToInvoice'])->name('crmStartDateToInvoice');
    Route::post('crmStartDateToHold', [CrmController::class, 'crmStartDateToHold'])->name('crmStartDateToHold');
    Route::post('crmStartDateSave', [CrmController::class, 'crmStartDateSave'])->name('crmStartDateSave');
    Route::post('crmStartDateToQuality', [CrmController::class, 'crmStartDateToQuality'])->name('crmStartDateToQuality');

    /** CRM Start Date Hold*/
    Route::post('crmRevertStartDateHoldToStartDate', [CrmController::class, 'crmRevertStartDateHoldToStartDate'])->name('crmRevertStartDateHoldToStartDate');
    Route::post('crmStartDateHoldSave', [CrmController::class, 'crmStartDateHoldSave'])->name('crmStartDateHoldSave');
    Route::post('crmStartDateHoldToQuality', [CrmController::class, 'crmStartDateHoldToQuality'])->name('crmStartDateHoldToQuality');

    /** CRM Invoice */
    Route::post('crmSendInvoiceToInvoiceSent', [CrmController::class, 'crmSendInvoiceToInvoiceSent'])->name('crmSendInvoiceToInvoiceSent');
    Route::post('crmRevertInvoiceToStartDate', [CrmController::class, 'crmRevertInvoiceToStartDate'])->name('crmRevertInvoiceToStartDate');
    Route::post('crmInvoiceToDispute', [CrmController::class, 'crmInvoiceToDispute'])->name('crmInvoiceToDispute');
    Route::post('crmInvoiceFinalSave', [CrmController::class, 'crmInvoiceFinalSave'])->name('crmInvoiceFinalSave');
    Route::post('crmInvoiceToQuality', [CrmController::class, 'crmInvoiceToQuality'])->name('crmInvoiceToQuality');

    /** CRM Invoice Sent*/
    Route::post('crmInvoiceSentToPaid', [CrmController::class, 'crmInvoiceSentToPaid'])->name('crmInvoiceSentToPaid');
    Route::post('crmInvoiceSentToDispute', [CrmController::class, 'crmInvoiceSentToDispute'])->name('crmInvoiceSentToDispute');
    Route::post('crmInvoiceSentToQuality', [CrmController::class, 'crmInvoiceSentToQuality'])->name('crmInvoiceSentToQuality');

    /** CRM Dispute */
    Route::post('crmRevertDisputeToInvoice', [CrmController::class, 'crmRevertDisputeToInvoice'])->name('crmRevertDisputeToInvoice');
    Route::post('crmDisputeToQuality', [CrmController::class, 'crmDisputeToQuality'])->name('crmDisputeToQuality');

    /** CRM Paid */
    Route::post('crmChangePaidStatus', [CrmController::class, 'crmChangePaidStatus'])->name('crmChangePaidStatus');
    Route::get('openToPaidApplicants', [CrmController::class, 'crmOpenToPaidApplicants'])->name('openToPaidApplicants');
    Route::post('crmPaidRevertToInvoiceSent', [CrmController::class, 'crmPaidRevertToInvoiceSent'])->name('crmPaidRevertToInvoiceSent');


    /** regions */
    Route::group(['prefix' => 'regions'], function () {
        Route::get('resources', [RegionController::class, 'resourcesIndex'])->name('regions.resources');
        Route::get('sales', [RegionController::class, 'salesIndex'])->name('regions.sales');
    });
    Route::get('getApplicantsByRegions', [RegionController::class, 'getApplicantsByRegions'])->name('getApplicantsByRegions');
    Route::get('getSalesByRegions', [RegionController::class, 'getSalesByRegions'])->name('getSalesByRegions');

    /** Quality */
    Route::group(['prefix' => 'quality'], function () {
        Route::get('resources', [QualityController::class, 'resourceIndex'])->name('quality.resources');
        Route::get('sales', [QualityController::class, 'saleIndex'])->name('quality.sales');
    });
    Route::get('getResourcesByTypeAjaxRequest', [QualityController::class, 'getResourcesByTypeAjaxRequest'])->name('getResourcesByTypeAjaxRequest');
    Route::get('getSalesByTypeAjaxRequest', [QualityController::class, 'getSalesByTypeAjaxRequest'])->name('getSalesByTypeAjaxRequest');
    Route::post('clear_reject_Sale', [QualityController::class, 'clearRejectSale'])->name('clear_reject_Sale');
    Route::post('updateApplicantStatusByQuality', [QualityController::class, 'updateApplicantStatusByQuality'])->name('updateApplicantStatusByQuality');
    Route::get('getQualityNotesHistory', [QualityController::class, 'getQualityNotesHistory'])->name('getQualityNotesHistory');


    Route::group(['prefix' => 'ip-address'], function () {
        Route::get('', [IPAddressController::class, 'index'])->name('ip-address.list');
        Route::post('store', [IPAddressController::class, 'store'])->name('ip-address.store');
        Route::put('update', [IPAddressController::class, 'update'])->name('ip-address.update');
        Route::post('delete', [IPAddressController::class, 'destroy'])->name('ip-address.destroy');
    });
    Route::get('ipaddressExport', [IPAddressController::class, 'export'])->name('ipaddressExport');
    Route::get('getIPs', [IPAddressController::class, 'getIPs'])->name('getIPs');

    Route::get('settings', [SettingController::class, 'index'])->name('settings.list');
    Route::get('getSettings', [SettingController::class, 'getSettings'])->name('settings.get');

    Route::post('save-general-settings', [SettingController::class, 'saveGeneralSettings'])->name('settings.general.save');
    Route::post('save-email-signature-settings', [SettingController::class, 'saveEmailSignatureSettings'])->name('settings.email-signature.save');
    Route::post('save-profile-settings', [SettingController::class, 'saveProfileSettings'])->name('settings.profile.save');
    Route::post('save-sms-settings', [SettingController::class, 'saveSmsSettings'])->name('settings.sms.save');
    Route::post('save-google-settings', [SettingController::class, 'saveGoogleSettings'])->name('settings.google.save');
    Route::post('save-notification-settings', [SettingController::class, 'saveNotificationSettings'])->name('settings.notification.save');
    Route::post('save-smtp-settings', [SettingController::class, 'saveSmtpSettings'])->name('settings.smtp.save');
    Route::post('delete-smtp-settings', [SettingController::class, 'deleteSmtp'])->name('settings.smtp.delete');
    Route::post('save-dialing-settings', [SettingController::class, 'saveDialingSettings'])->name('settings.dialing.save');
    Route::post('save-scraper-settings', [SettingController::class, 'saveScraperSettings'])->name('settings.scraper.save');
    Route::delete('delete-scraper-actor/{key}', [SettingController::class, 'deleteScraperActor'])->name('settings.scraper.delete');
    Route::post('run-scraper-actor/{key}', [SettingController::class, 'runScraperActor'])->name('settings.scraper.run');
    Route::post('settings/version', [SettingController::class, 'saveVersion'])->name('settings.version.save');

    Route::post('module-notes/store', [ModuleNotesController::class, 'store'])->name('moduleNotes.store');
    Route::get('getModuleNotesHistory', [ModuleNotesController::class, 'getModuleNotesHistory'])->name('getModuleNotesHistory');
    Route::get('getModuleUpdateHistory', [ModuleNotesController::class, 'getModuleUpdateHistory'])->name('getModuleUpdateHistory');

    Route::group(['prefix' => 'scrap'], function () {
        Route::get('office-list', [ScrapController::class, 'officeIndex'])->name('scrap.office.list');
        Route::get('unit-list', [ScrapController::class, 'unitIndex'])->name('scrap.unit.list');
        Route::get('sales-list', [ScrapController::class, 'salesIndex'])->name('scrap.sales.list');
    });
    Route::get('scrap/import', [ScrapController::class, 'importIndex'])->name('scrap.import.index');
    Route::post('scrap/import', [ScrapController::class, 'importJobs'])->name('scrap.import');
    Route::get('getScrappedOffices', [ScrapController::class, 'getScrappedOffices'])->name('getScrappedOffices');
    Route::get('getScrappedUnits', [ScrapController::class, 'getScrappedUnits'])->name('getScrappedUnits');
    Route::get('getScrappedSales', [ScrapController::class, 'getScrappedSales'])->name('getScrappedSales');
    Route::put('scrapped/office/restore', [ScrapController::class, 'scrappedOfficeRestore'])->name('scrapped.office.restore');
    Route::put('scrapped/unit/restore', [ScrapController::class, 'scrappedUnitRestore'])->name('scrapped.unit.restore');
    Route::put('scrapped/sale/restore', [ScrapController::class, 'scrappedSaleRestore'])->name('scrapped.sale.restore');
    Route::delete('scrapped/office/destroy', [ScrapController::class, 'scrappedOfficeDestroy'])->name('scrapped.office.destroy');
    Route::delete('scrapped/unit/destroy', [ScrapController::class, 'scrappedUnitDestroy'])->name('scrapped.unit.destroy');
    Route::delete('scrapped/sale/destroy', [ScrapController::class, 'scrappedSaleDestroy'])->name('scrapped.sale.destroy');
    Route::post('scrapped/sale/approve', [ScrapController::class, 'scrappedSaleApprove'])->name('scrapped.sale.approve');
    Route::post('scrapped/unit/approve', [ScrapController::class, 'scrappedUnitApprove'])->name('scrapped.unit.approve');
    Route::post('scrapped/office/approve', [ScrapController::class, 'scrappedOfficeApprove'])->name('scrapped.office.approve');
    Route::get('/get-sale-emails', [ScrapController::class, 'getSaleEmails']);
    Route::post('/send-email-to-offices', [ScrapController::class, 'sendEmailToOffices'])->name('send.email.to.offices');
    Route::post('scrap/bulk-email-template', [ScrapController::class, 'getBulkEmailTemplate'])->name('scrap.bulk.email.template');
    Route::post('scrap/bulk-offices-email-template', [ScrapController::class, 'getBulkOfficesEmailTemplate'])->name('scrap.bulk.offices.email.template');
    Route::post('/send-bulk-emails-to-offices', [ScrapController::class, 'sendBulkEmailsToOffices'])->name('send.bulk.emails.to.offices');


    Route::group(['prefix' => 'freepbx-cdrs'], function () {
        Route::get('', [FreePBXController::class, 'index'])->name('freepbx-cdrs.list');
    });
    Route::get('getFreepbxAjaxRequest', [FreePBXController::class, 'getFreepbxAjaxRequest'])->name('getFreepbxAjaxRequest');

    Route::get('', [RoutingController::class, 'index'])->name('root');

    // Dynamic routes (Root, second-level, and third-level)
    Route::get('{first}', [RoutingController::class, 'root'])->name('root.first');    // 'root' is the one above
    Route::get('{first}/{second}', [RoutingController::class, 'secondLevel'])->name('second');
    Route::get('{first}/{second}/{third}', [RoutingController::class, 'thirdLevel'])->name('third');

    // Handle .well-known paths specifically
    Route::get('.well-known/{any}', function () {
        // Return 404 for all .well-known requests
        abort(404, 'File not found');

        // Or serve a specific file
        // $filePath = public_path('.well-known/' . request()->segment(2));
        // if (file_exists($filePath)) {
        //     return response()->file($filePath);
        // }
        // abort(404, 'File not found');
    })->where('any', '.*');
});
