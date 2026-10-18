<?php

use App\Core\Category\Controller\CategoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('platform.admin')->group(function () {
    Route::get('/settings/category', [CategoryController::class, 'index'])->name('module.category');
    Route::post('/category/store', [CategoryController::class, 'store'])->name('category.store');
    Route::put('/category/edit/{id}', [CategoryController::class, 'update'])->name('category.edit');
});
