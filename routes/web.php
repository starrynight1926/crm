<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Webhook nhận lead từ landing page — không auth, xác thực bằng token, miễn CSRF (bootstrap/app.php)
Route::post('/webhook/lead/{token}', [WebhookController::class, 'store'])->name('webhook.lead');

// Hướng dẫn sử dụng — trang tĩnh, không cần đăng nhập
Route::view('/huong-dan', 'guide')->name('guide');

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::view('/settings/sessions', 'settings.sessions')->name('sessions.index');
    Route::view('/settings', 'settings.index')->name('settings.index');
    Route::view('/settings/fields', 'settings.fields')->middleware('permission:field.manage')->name('settings.fields');
    Route::view('/settings/field-approvals', 'settings.field-approvals')->middleware('permission:field.approve')->name('settings.field-approvals');

    Route::view('/org/users', 'org.users')->middleware('permission:user.manage')->name('org.users');
    Route::view('/org/roles', 'org.roles')->middleware('permission:role.manage')->name('org.roles');
    Route::view('/org/chart', 'org.chart')->middleware('permission:org.manage')->name('org.chart');
    Route::view('/org/fields', 'org.fields')->middleware('permission:field.manage')->name('org.fields');

    Route::view('/distribution/rules', 'distribution.rules')->middleware('permission:rule.manage')->name('distribution.rules');
    Route::view('/distribution/pools', 'distribution.pools')->middleware('permission:lead.view')->name('distribution.pools');

    Route::view('/services', 'services.catalog')->middleware('permission:service.manage')->name('services.catalog');
    Route::view('/payments', 'services.payments')->middleware('permission:payment.record')->name('payments.index');
    Route::view('/reports', 'reports.index')->middleware('permission:report.view,report.view_all')->name('reports.index');
    Route::view('/sources', 'sources.connections')->middleware('permission:connection.manage')->name('sources.index');

    Route::prefix('leads')->middleware('permission:lead.view')->group(function () {
        Route::view('/', 'leads.index')->name('leads.index');
        Route::view('/create', 'leads.create')->middleware('permission:lead.create')->name('leads.create');
        Route::view('/import', 'leads.import')->middleware('permission:lead.import')->name('leads.import');
        Route::view('/failed', 'leads.failed')->middleware('permission:lead.import')->name('leads.failed');
        Route::view('/approvals', 'leads.approvals')->middleware('permission:lead.approve_source')->name('leads.approvals');
        Route::get('/{lead}', fn (\App\Models\Lead $lead) => view('leads.show', ['lead' => $lead]))->name('leads.show');
        Route::get('/{lead}/edit', fn (\App\Models\Lead $lead) => view('leads.edit', ['lead' => $lead]))
            ->middleware('permission:lead.update')->name('leads.edit');
    });

    // Phase 6.6 — Quy tắc vận hành (chỉ admin hệ thống)
    Route::view('/ops/rules', 'ops.rules')->middleware('permission:ops.manage')->name('ops.rules');
});
