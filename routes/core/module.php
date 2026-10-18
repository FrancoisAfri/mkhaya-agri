<?php

use App\Core\Module\Controller\ModulesController;
use Illuminate\Support\Facades\Route;

Route::middleware('platform.admin')->group(function () {
    Route::get('/module/{id}', [ModulesController::class, 'index'])->name('module.list');
    Route::post('/module/store', [ModulesController::class, 'store'])->name('module.store');
    Route::put('/module/edit/{id}', [ModulesController::class, 'update'])->name('module.edit');
});
