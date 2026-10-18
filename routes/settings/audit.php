<?php

use App\Modules\Audit\Controllers\AuditsController;
use Illuminate\Support\Facades\Route;

Route::get('/audits', [AuditsController::class, 'index'])->name('audit');
Route::get('/audits/export-pdf', [AuditsController::class, 'exportPdf'])->name('audits.export');

/*
 * Which emails this system tried to send, and what came of each.
 *
 * Sits beside the audit trail because it answers the same kind of question — what did the system
 * do, and can I prove it — and belongs to whoever is accountable for the installation rather than
 * to any one module that happens to send mail.
 */
Route::get('/email-delivery', [\App\Modules\Audit\Controllers\EmailDeliveryController::class, 'index'])
    ->name('email-delivery');
