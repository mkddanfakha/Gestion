<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Modules\NotificationCenter\Http\Controllers\NotificationApiController;
use App\Modules\NotificationCenter\Http\Controllers\NotificationClientLogController;
use App\Modules\NotificationCenter\Http\Controllers\NotificationHealthApiController;
use App\Modules\NotificationCenter\Http\Controllers\NotificationMonitoringApiController;
use App\Modules\NotificationCenter\Http\Controllers\NotificationSettingsApiController;
use App\Modules\NotificationCenter\Http\Controllers\UserNotificationPreferencesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('api/notifications')->name('notification-center.')->group(function () {
    Route::get('/', [NotificationApiController::class, 'index'])->name('index');
    Route::get('/counts', [NotificationApiController::class, 'counts'])->name('counts');
    Route::get('/search', [NotificationApiController::class, 'search'])->name('search');
    Route::post('/read', [NotificationApiController::class, 'markAsRead'])->name('mark-as-read');
    Route::post('/read/{id}', [NotificationApiController::class, 'markAsReadById'])->name('mark-as-read-id');
    Route::post('/read-all', [NotificationApiController::class, 'markAllAsRead'])->name('mark-all-as-read');
    Route::post('/archive/{id}', [NotificationApiController::class, 'archive'])->name('archive');
    Route::delete('/read', [NotificationApiController::class, 'destroyRead'])->name('delete-read');
    Route::delete('/{id}', [NotificationApiController::class, 'destroy'])->name('destroy');
    Route::post('/test', [NotificationApiController::class, 'test'])->name('test');
    Route::post('/client-log', [NotificationClientLogController::class, 'store'])->name('client-log');
});

Route::middleware(['web', 'auth', EnsureUserIsAdmin::class])->prefix('api/notifications')->name('notification-center.settings.')->group(function () {
    Route::get('/settings', [NotificationSettingsApiController::class, 'show'])->name('show');
    Route::put('/settings', [NotificationSettingsApiController::class, 'update'])->name('update');
    Route::post('/settings/maintenance/archive', [NotificationSettingsApiController::class, 'maintenanceArchive'])->name('maintenance-archive');
    Route::post('/settings/maintenance/delete-archived', [NotificationSettingsApiController::class, 'maintenanceDeleteArchived'])->name('maintenance-delete-archived');
    Route::post('/settings/maintenance/cleanup', [NotificationSettingsApiController::class, 'maintenanceCleanup'])->name('maintenance-cleanup');
    Route::get('/settings/monitoring', [NotificationMonitoringApiController::class, 'dashboard'])->name('monitoring');
    Route::get('/settings/performance', [NotificationMonitoringApiController::class, 'performance'])->name('performance');
    Route::get('/settings/health', [NotificationHealthApiController::class, 'show'])->name('health');
});

Route::middleware(['web', 'auth'])->prefix('api/user')->name('notification-center.preferences.')->group(function () {
    Route::get('/notification-preferences', [UserNotificationPreferencesController::class, 'show'])->name('show');
    Route::put('/notification-preferences', [UserNotificationPreferencesController::class, 'update'])->name('update');
});

// Routes legacy (compatibilité frontend existant)
Route::middleware(['web', 'auth'])->prefix('notifications')->name('notifications.')->group(function () {
    Route::post('/mark-as-read', [NotificationApiController::class, 'markAsRead'])->name('mark-as-read');
    Route::post('/mark-all-as-read', [NotificationApiController::class, 'markAllAsRead'])->name('mark-all-as-read');
    Route::post('/test', [NotificationApiController::class, 'test'])->name('test');
});
