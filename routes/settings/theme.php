<?php

use App\Http\Controllers\Settings\ThemeSettingController;
use Illuminate\Support\Facades\Route;

Route::get('/theme', [ThemeSettingController::class, 'show'])->name('theme');
Route::patch('/theme', [ThemeSettingController::class, 'update'])->name('theme.update');
Route::patch('/theme/global', [ThemeSettingController::class, 'updateGlobal'])->name('theme.global');
