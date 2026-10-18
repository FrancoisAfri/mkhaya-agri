<?php

use App\Core\UserApproval\Controllers\UserApprovalController;
use App\Core\UserManagement\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/profile', [UserManagementController::class, 'profile'])->name('profile');
Route::get('/user-profile/{uuid}', [UserManagementController::class, 'profileUser'])->name('profile.user');
Route::get('/user/edit/{uuid}',        [UserApprovalController::class, 'edit'])->name('user.edit');
Route::post('/user/{id}/update', [UserManagementController::class, 'updateUser'])->name('user.update');
Route::post('/user/updated/{uuid}', [UserApprovalController::class, 'update'])->name('user.updated');
// NOTE: /user/locale (name: user.locale) is defined in routes/web.php as a
// public route so guests on the support/portal pages can switch language too.

