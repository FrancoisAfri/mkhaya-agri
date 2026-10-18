<?php

use App\Core\DocumentType\Controller\DocumentTypesController;
use App\Core\DocumentType\Controller\EmployeeDocumnetController;
use Illuminate\Support\Facades\Route;

Route::get('/document-types', [DocumentTypesController::class, 'index'])->name('document-types');
Route::post('/document-types',        [DocumentTypesController::class, 'store'])->name('doc-types.store');
Route::put('/document-types/{id}',    [DocumentTypesController::class, 'update'])->name('doc-types.update');
Route::delete('/document-types/{id}', [DocumentTypesController::class, 'destroy'])->name('doc-types.destroy');

//** Employee Documents */
Route::post('/documents',              [EmployeeDocumnetController::class, 'store']);
Route::put('/documents/{id}',         [EmployeeDocumnetController::class, 'update']);
Route::delete('/documents/{id}',         [EmployeeDocumnetController::class, 'destroy']);
Route::get('/documents/{id}/download', [EmployeeDocumnetController::class, 'download']);
