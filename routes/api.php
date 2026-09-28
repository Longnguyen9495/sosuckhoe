<?php

use App\Http\Controllers\Api\V1\AiPrescriptionDraftController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\CarePlanController;
use App\Http\Controllers\Api\V1\PasswordAuthController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\DoctorMonitoringPlanController;
use App\Http\Controllers\Api\V1\DoctorNoteController;
use App\Http\Controllers\Api\V1\DoctorPatientListController;
use App\Http\Controllers\Api\V1\DoctorQuestionController;
use App\Http\Controllers\Api\V1\DoctorThresholdConfirmController;
use App\Http\Controllers\Api\V1\DocumentDownloadController;
use App\Http\Controllers\Api\V1\DocumentUploadController;
use App\Http\Controllers\Api\V1\DrugController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\ShareLinkController;
use App\Http\Controllers\Api\V1\SharedViewController;
use App\Http\Controllers\Api\V1\LabResultController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\OtpAuthController;
use App\Http\Controllers\Api\V1\TwoFactorAuthController;
use App\Http\Controllers\Api\V1\PatientAlertController;
use App\Http\Controllers\Api\V1\PatientCalendarController;
use App\Http\Controllers\Api\V1\PatientChartController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PatientCsvExportController;
use App\Http\Controllers\Api\V1\PatientDayController;
use App\Http\Controllers\Api\V1\PatientOverviewController;
use App\Http\Controllers\Api\V1\PatientPrescriptionController;
use App\Http\Controllers\Api\V1\PatientReportController;
use App\Http\Controllers\Api\V1\PrescriptionDraftController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantMemberController;
use App\Http\Controllers\Api\V1\ThresholdController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Đăng ký nhanh (tên + SĐT + ngày sinh) và đăng nhập SĐT + mật khẩu.
    Route::post('/auth/register', [PasswordAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/login', [PasswordAuthController::class, 'login'])->middleware('throttle:8,1');
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/auth/me', [PasswordAuthController::class, 'me']);
        Route::post('/auth/password', [PasswordAuthController::class, 'changePassword'])->middleware('throttle:6,1');
    });

    // OTP — tạm không dùng trên giao diện, giữ để bật lại sau.
    Route::post('/auth/otp/request', [OtpAuthController::class, 'request'])->middleware('throttle:5,1');
    Route::post('/auth/otp/verify', [OtpAuthController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('/auth/logout', [OtpAuthController::class, 'logout'])->middleware('auth:sanctum');

    // Two-Factor (TOTP) setup & challenge
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::post('/auth/2fa/setup', [TwoFactorAuthController::class, 'setup']);
        Route::post('/auth/2fa/confirm', [TwoFactorAuthController::class, 'confirm']);
        Route::post('/auth/2fa/challenge', [TwoFactorAuthController::class, 'challenge']);
        Route::post('/auth/2fa/recovery-codes', [TwoFactorAuthController::class, 'regenerateRecoveryCodes']);
    });
    Route::get('/tenants', [TenantController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/invitations/accept', [InvitationController::class, 'accept'])->middleware('auth:sanctum');

    // Onboarding (luồng đăng ký 7 bước — chưa có tenant)
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/onboarding', [OnboardingController::class, 'show']);
        Route::post('/onboarding/step/{step}', [OnboardingController::class, 'saveStep']);
        Route::post('/onboarding/back', [OnboardingController::class, 'goBack']);
        Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);
    });

    // Xem hồ sơ qua link chia sẻ — KHÔNG đăng nhập; giới hạn số lần gọi theo IP (chống dò mã / dò PIN).
    Route::get('/shared/{token}', [SharedViewController::class, 'show'])->middleware('throttle:30,1')->name('shared.show');
    Route::post('/shared/{token}/open', [SharedViewController::class, 'open'])->middleware('throttle:10,1')->name('shared.open');
    Route::get('/shared-documents/{document}', [SharedViewController::class, 'document'])->middleware('throttle:60,1')->name('shared.document');

    // Nội dung điều khoản — công khai để hiện ở màn đăng ký trước khi có tài khoản.
    Route::get('/consents/version', [ConsentController::class, 'currentVersion'])->middleware('throttle:30,1');
    // Ghi / rút đồng ý trong phạm vi tài khoản đang chọn (tenant lấy từ ngữ cảnh, không lấy từ client).
    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::post('/consents', [ConsentController::class, 'store']);
        Route::patch('/consents/{consent}/revoke', [ConsentController::class, 'revoke']);
    });

    // Danh mục thuốc, mẫu bệnh, bài viết (public sau khi đăng nhập)
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/drugs', [DrugController::class, 'index']);
        Route::get('/drugs/{drug}', [DrugController::class, 'show']);
        Route::get('/templates', [TemplateController::class, 'index']);
        Route::get('/articles', [ArticleController::class, 'index']);
        Route::get('/articles/{article}', [ArticleController::class, 'show']);
    });

    // Thành viên trong tenant
    Route::get('/tenants/{tenant}/members', [TenantMemberController::class, 'index'])
        ->middleware(['auth:sanctum', 'tenant'])
        ->name('tenants.members.index');

    // Push subscription
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
        Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    });

    // Route cơ bản danh sách/xem bệnh nhân
    Route::middleware(['auth:sanctum', 'tenant', 'doctor.2fa', 'audit.patient'])->scopeBindings()->group(function (): void {
        Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
        Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
    });

    // Các route theo dõi hàng ngày: caregiver có thể truy cập
    Route::middleware(['auth:sanctum', 'tenant', 'doctor.2fa', 'audit.patient'])->scopeBindings()->group(function (): void {
        // Link chia sẻ hồ sơ chỉ xem (người bệnh tự tạo / thu hồi).
        Route::get('/patients/{patient}/share-links', [ShareLinkController::class, 'index'])->name('patients.share-links.index');
        Route::get('/patients/{patient}/share-links/pin', [ShareLinkController::class, 'suggestPin'])->name('patients.share-links.pin');
        Route::post('/patients/{patient}/share-links', [ShareLinkController::class, 'store'])->middleware('throttle:10,1')->name('patients.share-links.store');
        Route::delete('/patients/{patient}/share-links/{shareLink}', [ShareLinkController::class, 'destroy'])->name('patients.share-links.destroy');

        Route::get('/patients/{patient}/day/{date}', [PatientDayController::class, 'day'])->name('patients.day');
        Route::post('/patients/{patient}/logs', [PatientDayController::class, 'storeLog'])->name('patients.logs.store');

        Route::get('/patients/{patient}/readings', [PatientDayController::class, 'readingsIndex'])->name('patients.readings.index');
        Route::post('/patients/{patient}/readings', [PatientDayController::class, 'storeReading'])->name('patients.readings.store');

        Route::get('/patients/{patient}/events', [PatientDayController::class, 'eventsIndex'])->name('patients.events.index');
        Route::post('/patients/{patient}/events', [PatientDayController::class, 'storeEvent'])->name('patients.events.store');
        Route::patch('/patients/{patient}/events/{event}', [PatientDayController::class, 'updateEvent'])->name('patients.events.update');

        Route::get('/patients/{patient}/chart-data', [PatientChartController::class, 'chartData'])->name('patients.chart');
        Route::get('/patients/{patient}/csv-export', [PatientCsvExportController::class, 'csvExport'])->name('patients.csv');

        Route::get('/patients/{patient}/alerts', [PatientAlertController::class, 'index'])->name('patients.alerts.index');
        Route::patch('/patients/{patient}/alerts/{alert}/seen', [PatientAlertController::class, 'markSeen'])->name('patients.alerts.seen');

        // Tổng quan / overview
        Route::get('/patients/{patient}/overview', [PatientOverviewController::class, 'show'])->name('patients.overview');

        // Danh sách đơn thuốc đang hoạt động
        Route::get('/patients/{patient}/prescriptions', [PatientPrescriptionController::class, 'index'])->name('patients.prescriptions.index');
        Route::patch('/patients/{patient}/prescription-items/{prescriptionItem}', [PatientPrescriptionController::class, 'updateItem'])->name('patients.prescription-items.update');
        Route::get('/patients/{patient}/monitoring-plans', [PatientPrescriptionController::class, 'monitoringPlans'])->name('patients.monitoring-plans.index');

        // Lịch tháng
        Route::get('/patients/{patient}/calendar', [PatientCalendarController::class, 'index'])->name('patients.calendar');

        // Prescription lifecycle + preview
        Route::post('/patients/{patient}/prescriptions/preview', [PrescriptionDraftController::class, 'preview'])->name('patients.prescriptions.preview');
        Route::post('/patients/{patient}/prescriptions', [PrescriptionDraftController::class, 'store'])->name('patients.prescriptions.store');
        Route::post('/patients/{patient}/prescriptions/{prescription}/close', [PrescriptionDraftController::class, 'close'])->name('patients.prescriptions.close');

        // Templates / conditions
        Route::post('/patients/{patient}/conditions', [TemplateController::class, 'assign'])->name('patients.conditions.assign');

        // Thresholds
        Route::get('/patients/{patient}/thresholds', [ThresholdController::class, 'index'])->name('patients.thresholds.index');
        Route::patch('/patients/{patient}/thresholds/{threshold}', [ThresholdController::class, 'update'])->name('patients.thresholds.update');
        Route::get('/patients/{patient}/thresholds/history', [ThresholdController::class, 'history'])->name('patients.thresholds.history');

        // Documents
        Route::get('/patients/{patient}/documents', [DocumentUploadController::class, 'index'])->name('patients.documents.index');
        Route::post('/patients/{patient}/documents', [DocumentUploadController::class, 'store'])->name('patients.documents.store');
        Route::get('/patients/{patient}/documents/{document}/file', [DocumentDownloadController::class, 'showForPatient'])->name('patients.documents.file');
        Route::delete('/patients/{patient}/documents/{document}', [DocumentUploadController::class, 'destroy'])->name('patients.documents.destroy');
        Route::post('/patients/{patient}/documents/{document}/dismiss-medications', [DocumentUploadController::class, 'dismissMedications'])->name('patients.documents.dismiss');
        Route::get('/patients/{patient}/pending-medications', [DocumentUploadController::class, 'pendingMedications'])->name('patients.pending-medications');

        // Kế hoạch chăm sóc: chế độ ăn uống, sinh hoạt, theo dõi (AI lập, để tham khảo)
        Route::get('/patients/{patient}/care-plan', [CarePlanController::class, 'show'])->name('patients.care-plan.show');
        Route::post('/patients/{patient}/care-plan', [CarePlanController::class, 'store'])->middleware('throttle:6,1')->name('patients.care-plan.store');

        // Lab results
        Route::get('/patients/{patient}/lab-results', [LabResultController::class, 'index'])->name('patients.lab-results.index');
        Route::post('/patients/{patient}/lab-results', [LabResultController::class, 'store'])->name('patients.lab-results.store');

        // Questions
        Route::get('/patients/{patient}/questions', [QuestionController::class, 'index'])->name('patients.questions.index');
        Route::post('/patients/{patient}/questions', [QuestionController::class, 'store'])->name('patients.questions.store');
        Route::patch('/patients/{patient}/questions/{question}/asked', [QuestionController::class, 'markAsked'])->name('patients.questions.asked');

        // Notes — gia đình xem, bác sĩ tạo mới (I3)
        Route::get('/patients/{patient}/notes', [DoctorNoteController::class, 'index'])
            ->name('patients.notes.index');

        // Settings
        Route::get('/patients/{patient}/settings', [SettingsController::class, 'show'])->name('patients.settings.show');
        Route::patch('/patients/{patient}/settings/routines', [SettingsController::class, 'updateRoutines'])->name('patients.settings.routines');
    });

    // Xoá tài khoản (global)
    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::post('/account/delete', [SettingsController::class, 'deleteAccount']);
    });

    // Tải ảnh phiếu đã mã hóa — Policy gate "view" trên Document
    Route::get('/documents/{document}', [DocumentDownloadController::class, 'show'])
        ->name('documents.show')
        ->middleware('auth:sanctum');

    // Route cần 2FA bác sĩ (quản lý lâm sàng)
    Route::middleware(['auth:sanctum', 'tenant', 'doctor.2fa', 'audit.patient'])->scopeBindings()->group(function (): void {
        Route::post('/patients/{patient}/invitations', [InvitationController::class, 'store'])
            ->name('patients.invitations.store');

        // Danh sách bệnh nhân của phòng khám (cổng bác sĩ)
        Route::get('/clinic/patients', [DoctorPatientListController::class, 'index'])
            ->name('clinic.patients.index');

        // Ghi chú bác sĩ — chỉ bác sĩ tạo mới
        Route::post('/patients/{patient}/notes', [DoctorNoteController::class, 'store'])
            ->name('patients.notes.store');

        // Trả lời câu hỏi — chỉ bác sĩ
        Route::post('/patients/{patient}/questions/{question}/answer', [DoctorQuestionController::class, 'answer'])
            ->name('patients.questions.answer');


        // Xác nhận ngưỡng
        Route::post('/patients/{patient}/thresholds/{threshold}/confirm', [DoctorThresholdConfirmController::class, 'confirm'])
            ->name('patients.thresholds.confirm');

        // Điều chỉnh kế hoạch theo dõi
        Route::put('/patients/{patient}/monitoring-plans/{monitoringPlan}', [DoctorMonitoringPlanController::class, 'update'])
            ->name('patients.monitoring-plans.update');

        // Báo cáo tái khám
        Route::get('/patients/{patient}/report', [PatientReportController::class, 'show'])
            ->name('patients.report.show');
        Route::get('/patients/{patient}/report/pdf', [PatientReportController::class, 'pdf'])
            ->name('patients.report.pdf');
    });

    // AI đọc đơn thuốc — mở cho caregiver/owner; doctor.2fa chỉ áp dụng với tài khoản bác sĩ
    Route::middleware(['auth:sanctum', 'tenant', 'doctor.2fa', 'audit.patient'])->scopeBindings()->group(function (): void {
        Route::get('/patients/{patient}/ai-prescription-drafts', [AiPrescriptionDraftController::class, 'index'])
            ->name('patients.ai-drafts.index');
        Route::post('/patients/{patient}/ai-prescription-drafts', [AiPrescriptionDraftController::class, 'store'])
            ->name('patients.ai-drafts.store');
        Route::post('/patients/{patient}/ai-prescription-drafts/{aiPrescriptionDraft}/confirm', [AiPrescriptionDraftController::class, 'confirm'])
            ->name('patients.ai-drafts.confirm');
        Route::post('/patients/{patient}/ai-prescription-drafts/{aiPrescriptionDraft}/match-drugs', [AiPrescriptionDraftController::class, 'matchDrugs'])
            ->name('patients.ai-drafts.match');
    });
});
