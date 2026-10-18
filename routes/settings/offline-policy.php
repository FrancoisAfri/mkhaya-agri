<?php

use App\Http\Controllers\Settings\OfflinePolicyController;
use Illuminate\Support\Facades\Route;

Route::get('/offline-policy',  [OfflinePolicyController::class, 'index'])->name('offline-policy.index');
Route::post('/offline-policy', [OfflinePolicyController::class, 'update'])->name('offline-policy.update');
