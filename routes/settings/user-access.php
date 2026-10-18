<?php

use App\Core\ManagerUser\Controllers\ManagerUserController;
use App\Modules\Admin\UserAccess\Controllers\UserAccessController;
use App\Core\UserApproval\Controllers\UserApprovalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/user-rights', '/settings/user-access')->name('user-rights.redirect');
Route::get('/user-access', [UserAccessController::class, 'index'])->name('user-access.list');
Route::get('/user-access/{userId}', [UserAccessController::class, 'getUserAccess'])
    ->name('user-access.get');

Route::post('/user-access/{userId}/category/{categoryId}', [UserAccessController::class, 'saveCategoryAccess'])
    ->name('user-access.category');
Route::post('/user-access/{userId}/module/{moduleId}', [UserAccessController::class, 'saveModuleAccess'])
    ->name('user-access.module');
Route::post('/user-access/{userId}/ribbon/{ribbonId}', [UserAccessController::class, 'saveRibbonAccess'])
    ->name('user-access.ribbon');


/**
 * MANAGER USER
 */

Route::get('/manage-users', [ManagerUserController::class, 'index'])->name('manager-user.list');
Route::get('/manage-users/report', [ManagerUserController::class, 'report'])->name('manager-user.report');
Route::post('/storeUser', [ManagerUserController::class, 'storeUser'])->name('manager-user.store');
Route::post('/users/{id}/toggle-status', [ManagerUserController::class, 'toggleStatus'])->name('manager-user.toggle');
Route::delete('/delete/{id}', [ManagerUserController::class, 'deleteUser'])->name('manager-user.delete');
Route::post('/employees/{id}/enable-login', [ManagerUserController::class, 'enableLogin'])->name('manager-user.enable-login');
Route::post('/users/bulk-delete', [ManagerUserController::class, 'bulkDelete'])->name('manager-user.bulk-delete');
Route::get('/user/{uuid}', [ManagerUserController::class, 'editUser'])->name('manager-user.edit');
Route::post('/userUpdate/{id}', [ManagerUserController::class, 'updateUser'])->name('manager-user.update');
Route::post('/users-import', [ManagerUserController::class, 'userImport'])->name('manager-user.import');

/**
 * User approval
 */
Route::get('/profile-updates-approval',              [UserApprovalController::class, 'index'])->name('approvals.index');
Route::get('/approvals/{id}',         [UserApprovalController::class, 'show'])->name('approvals.show');
Route::post('/approvals/{id}/approve', [UserApprovalController::class, 'approve'])->name('approvals.approve');
Route::post('/approvals/{id}/reject', [UserApprovalController::class, 'reject'])->name('approvals.reject');
