<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DemoStagingController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Webhook nhận lead từ landing page — không auth, xác thực bằng token, miễn CSRF (bootstrap/app.php)
Route::post('/webhook/lead/{token}', [WebhookController::class, 'store'])->name('webhook.lead');

// Demo staging — công cụ standalone, độc lập pipeline/phân quyền. Dễ reset.
Route::prefix('demo')->group(function () {
    Route::get('/login', [DemoStagingController::class, 'loginPage'])->name('demo.login');
    Route::get('/login/{who}', [DemoStagingController::class, 'loginAs'])->name('demo.loginAs');
    Route::post('/logout', [DemoStagingController::class, 'logout'])->name('demo.logout');
    Route::get('/rules', [DemoStagingController::class, 'rules'])->name('demo.rules');
    Route::post('/rules', [DemoStagingController::class, 'ruleStore'])->name('demo.ruleStore');
    Route::delete('/rules/{id}', [DemoStagingController::class, 'ruleDelete'])->name('demo.ruleDelete');
    Route::get('/', [DemoStagingController::class, 'upload'])->name('demo.upload');
    Route::post('/preview', [DemoStagingController::class, 'preview'])->name('demo.preview');
    Route::post('/import', [DemoStagingController::class, 'import'])->name('demo.import');
    Route::get('/leads', [DemoStagingController::class, 'leads'])->name('demo.leads');
    Route::get('/report', [DemoStagingController::class, 'report'])->name('demo.report');
    Route::post('/reset', [DemoStagingController::class, 'reset'])->name('demo.reset');
});

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
        Route::get('/{lead}', fn (\App\Models\Lead $lead) => view('leads.show', ['lead' => $lead]))->name('leads.show');
        Route::get('/{lead}/edit', fn (\App\Models\Lead $lead) => view('leads.edit', ['lead' => $lead]))
            ->middleware('permission:lead.update')->name('leads.edit');
    });
});
