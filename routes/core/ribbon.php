<?php

use App\Core\Ribbon\Controller\RibbonController;
use Illuminate\Support\Facades\Route;

Route::middleware('platform.admin')->group(function () {
    Route::get('/ribbon/{id}', [RibbonController::class, 'index'])->name('ribbon.list');
    Route::post('/ribbon/store', [RibbonController::class, 'store'])->name('ribbon.store');
    Route::put('/ribbon/edit/{id}', [RibbonController::class, 'update'])->name('ribbon.update');
    Route::delete('/ribbon/delete/{id}', [RibbonController::class, 'delete'])->name('ribbon.delete');
});
