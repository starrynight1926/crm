<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Webhook nhận lead từ landing page — không auth, xác thực bằng token, miễn CSRF (bootstrap/app.php)
Route::post('/webhook/lead/{token}', [WebhookController::class, 'store'])->name('webhook.lead');

// Hướng dẫn sử dụng — trang tĩnh, không cần đăng nhập
Route::view('/huong-dan', 'guide')->name('guide');
// 2026-08-04: QA checklist page (public — mở được không cần login để tester bên ngoài truy cập).
Route::view('/qa', 'qa-checklist')->name('qa');
Route::view('/changelog', 'changelog')->name('changelog');

// Hỗ trợ / phản hồi — list + detail cần login (dùng layouts.app). Bubble gửi ticket
// khả dụng mọi trang qua base layout (guest submit qua bubble → hiển thị toast).
Route::middleware('auth')->group(function () {
    Route::view('/ho-tro', 'support.index')->name('support.index');
    Route::get('/ho-tro/{ticketId}', fn (int $ticketId) => view('support.show', ['ticketId' => $ticketId]))->whereNumber('ticketId')->name('support.show');
});

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Dev tool 2026-08-15 — Impersonate + quick-login panel.
    Route::post('/impersonate/{user}', [\App\Http\Controllers\ImpersonateController::class, 'start'])->name('impersonate.start');
    Route::post('/impersonate-leave', [\App\Http\Controllers\ImpersonateController::class, 'leave'])->name('impersonate.leave');
    Route::get('/dev/quick-login', [\App\Http\Controllers\ImpersonateController::class, 'quickLogin'])->name('dev.quick-login');

    // 2026-08-12 — AI-Coop: phòng chat 3 bên (user + 2 Claude API riêng key).
    Route::view('/ai-coop', 'ai-coop.index')->name('ai-coop');

    // 2026-08-11 — Super admin chọn "Cơ sở đang xem" tạm thời (scope các widget dashboard).
    Route::post('/admin-scope', function (\Illuminate\Http\Request $r) {
        abort_unless(\App\Support\AdminScope::isSuperAdmin(), 403);
        $val = $r->input('org_unit_id');
        if ($val === '' || $val === null) {
            session()->forget(\App\Support\AdminScope::SESSION_KEY);
        } else {
            $exists = \App\Models\OrgUnit::where('id', (int) $val)->where('depth', 1)->exists();
            abort_unless($exists, 422);
            session([\App\Support\AdminScope::SESSION_KEY => (int) $val]);
        }
        return back();
    })->name('admin.scope');
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::view('/settings/sessions', 'settings.sessions')->name('sessions.index');
    Route::view('/settings/password', 'settings.password')->name('settings.password');
    Route::view('/settings', 'settings.index')->name('settings.index');

    // Thông báo (in-app)
    Route::view('/thong-bao', 'notifications.index')->name('notifications.index');
    Route::view('/settings/notifications', 'settings.notifications')
        ->middleware('permission:role.manage')->name('settings.notifications');
    Route::view('/settings/notification-log', 'settings.notification-log')
        ->middleware('permission:role.manage')->name('settings.notification-log');
    Route::view('/settings/booking-connection', 'settings.booking-connection')->name('settings.booking-connection');
    Route::view('/settings/fields', 'settings.fields')->middleware('permission:field.manage')->name('settings.fields');
    Route::view('/settings/field-approvals', 'settings.field-approvals')->middleware('permission:field.approve')->name('settings.field-approvals');
    Route::view('/settings/staff', 'settings.staff')->middleware('permission:staff.manage')->name('settings.staff');
    Route::post('/settings/staff/export', [\App\Http\Controllers\StaffExportController::class, 'export'])
        ->middleware('permission:staff.manage')->name('settings.staff.export');

    Route::view('/org/users', 'org.users')->middleware('permission:user.manage')->name('org.users');
    Route::get('/org/users/export', [\App\Http\Controllers\OrgUsersExportController::class, 'export'])
        ->middleware('permission:user.manage')->name('org.users.export');
    Route::view('/org/roles', 'org.roles')->middleware('permission:role.manage')->name('org.roles');
    Route::view('/org/chart', 'org.chart')->middleware('permission:org.manage')->name('org.chart');
    Route::view('/org/fields', 'org.fields')->middleware('permission:field.manage')->name('org.fields');

    Route::view('/distribution/rules', 'distribution.rules')->middleware('permission:rule.manage')->name('distribution.rules');
    Route::view('/distribution/pools', 'distribution.pools')->middleware('permission:lead.view')->name('distribution.pools');

    // UPS check-in đầu ngày (Phase 6.22)
    Route::view('/ups-list', 'ups.index')->middleware('permission:ups.view')->name('ups.list');
    Route::view('/ups-today', 'ups.today')->name('ups.today'); // read-only, cho mọi user có scope
    // 2026-08-26: Import/Export DailyAttendance — chỉ admin@longevity.com.vn (guard trong controller).
    Route::get('/ups-today/export',  [\App\Http\Controllers\UpsAttendanceImportExportController::class, 'export'])->name('ups.today.export');
    Route::post('/ups-today/import', [\App\Http\Controllers\UpsAttendanceImportExportController::class, 'import'])->name('ups.today.import');
    // B3 (2026-08-14): sale toggle "Không tiếp nhận" / "Tiếp tục nhận" từ avatar dropdown.
    Route::post('/me/receive-toggle', [\App\Http\Controllers\MeStatusController::class, 'toggleReceive'])
        ->name('me.receive-toggle');
    Route::get('/me/activity', [\App\Http\Controllers\MyActivityController::class, 'index'])
        ->name('me.activity');

    Route::view('/services', 'services.catalog')->middleware('permission:service.manage')->name('services.catalog');
    Route::view('/payments', 'services.payments')->middleware('permission:payment.record')->name('payments.index');
    Route::view('/reports', 'reports.index')->middleware('permission:report.view,report.view_all')->name('reports.index');
    Route::view('/sources', 'sources.connections')->middleware('permission:connection.manage')->name('sources.index');

    // /leads/create chỉ cần lead.create (Team nhập lead dùng để up lead nhưng không xem danh sách)
    Route::view('/leads/create', 'leads.create')->middleware('permission:lead.create')->name('leads.create');

    Route::prefix('leads')->middleware('permission:lead.view,lead.import')->group(function () {
        Route::view('/', 'leads.index')->name('leads.index');
        Route::view('/import', 'leads.import')->middleware('permission:lead.import')->name('leads.import');
        Route::view('/failed', 'leads.failed')->middleware('permission:lead.import')->name('leads.failed');
        Route::view('/trash', 'leads.trash')->middleware('permission:phase.rollback')->name('leads.trash');
        Route::view('/approvals', 'leads.approvals')->middleware('permission:lead.approve_source')->name('leads.approvals');
        // 2026-08-09: shortcut cho sbooking link về SCRM theo lead code (VD KH-014-MKT).
        Route::get('/by-code/{code}', function (string $code) {
            $lead = \App\Models\Lead::where('code', $code)->firstOrFail();
            abort_unless($lead->canOpenEditForm(auth()->user()), 403);
            return redirect()->route('leads.edit', $lead);
        })->name('leads.by-code');
        Route::get('/{lead}', fn (\App\Models\Lead $lead) => view('leads.show', ['lead' => $lead]))->name('leads.show');
        Route::get('/{lead}/booking-callback', \App\Http\Controllers\BookingCallbackController::class)->name('leads.booking-callback');
        Route::get('/{lead}/edit', function (\App\Models\Lead $lead) {
            // 2026-08-05: đổi gate canEditPersonalInfo → canOpenEditForm (owner Sale/Tele mở
            // được form để ghi call/booking log, dù không có perm sửa info personal).
            // Form tự readonly các field ngoài quyền.
            abort_unless($lead->canOpenEditForm(auth()->user()), 403,
                'Bạn không có quyền truy cập lead này ở phase ' . ($lead->pipeline_phase ?? 'sale') . '.');
            return view('leads.edit', ['lead' => $lead]);
        })->name('leads.edit');
    });

    // Phase 6.6 — Quy tắc vận hành (chỉ admin hệ thống)
    Route::view('/ops/rules', 'ops.rules')->middleware('permission:ops.manage')->name('ops.rules');

    // 2026-09-02 — Danh sách API v1 (dev doc cho super-admin).
    Route::view('/admin/api-list', 'admin.api-list')
        ->middleware('permission:user.manage')
        ->name('admin.api-list');

    // 2026-09-02 — Lịch sử UPS (DailyAttendance) + import/export CSV.
    Route::prefix('admin/ups-history')->middleware('permission:user.manage')->group(function () {
        Route::get('/',       [\App\Http\Controllers\Admin\UpsHistoryController::class, 'index'])->name('admin.ups-history');
        Route::get('/export', [\App\Http\Controllers\Admin\UpsHistoryController::class, 'export'])->name('admin.ups-history.export');
        Route::post('/import',[\App\Http\Controllers\Admin\UpsHistoryController::class, 'import'])->name('admin.ups-history.import');
    });

    // 2026-09-02 — Nhật ký hệ thống (public/logs.md).
    Route::get('/admin/logs', [\App\Http\Controllers\Admin\PublicLogController::class, 'index'])
        ->middleware('permission:user.manage')
        ->name('admin.logs');

    // 2026-08-04 (Task 3) — Danh mục hệ thống: xem + nhập + xuất core catalog (chỉ Admin hệ thống)
    Route::prefix('admin/catalog')->middleware('permission:user.manage')->group(function () {
        Route::view('/', 'admin.catalog')->name('admin.catalog');
        Route::get('/export/{tab}', [\App\Http\Controllers\SystemCatalogController::class, 'export'])->name('admin.catalog.export');
        // 2026-08-05: Export tất cả — 1 xlsx multi-sheet đầy đủ danh mục hệ thống + mirror sbooking.
        Route::get('/export-all', fn () => app(\App\Services\CatalogExporter::class)->stream())->name('admin.catalog.export-all');
        Route::get('/template/{tab}', [\App\Http\Controllers\SystemCatalogController::class, 'template'])->name('admin.catalog.template');
    });

    // Sao lưu & khôi phục cấu hình / dữ liệu hệ thống
    Route::view('/settings/backup', 'settings.backup')
        ->middleware('permission:system.backup')->name('settings.backup');
});
