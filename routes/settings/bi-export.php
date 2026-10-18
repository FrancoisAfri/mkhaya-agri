<?php

use App\Modules\Analytics\Http\Controllers\BiExportController;
use Illuminate\Support\Facades\Route;

/*
 * Connecting a tenant's own warehouse, so their own BI tools can read their own data.
 *
 * Under settings rather than inside a module: the datasets span projects and finance, and the token
 * saved here can read and write a customer's warehouse. That is an administrator's decision, not a
 * project manager's, and the controller gates every action on it.
 */
Route::get('/bi-export', [BiExportController::class, 'index'])->name('bi-export');
Route::post('/bi-export', [BiExportController::class, 'save'])->name('bi-export.save');
Route::post('/bi-export/test', [BiExportController::class, 'test'])->name('bi-export.test');
Route::post('/bi-export/run', [BiExportController::class, 'export'])->name('bi-export.run');
Route::delete('/bi-export', [BiExportController::class, 'destroy'])->name('bi-export.destroy');
