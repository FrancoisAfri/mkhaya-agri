<?php

use App\Core\Position\Controller\PositionController;
use Illuminate\Support\Facades\Route;


// Categories
Route::get('/positions',                         [PositionController::class, 'index'])->name('positions.index');
Route::post('/positions/categories',             [PositionController::class, 'storeCategory'])->name('positions.categories.store');
Route::put('/positions/categories/{id}',         [PositionController::class, 'updateCategory'])->name('positions.categories.update');
Route::delete('/positions/categories/{id}',      [PositionController::class, 'destroyCategory'])->name('positions.categories.destroy');

// Positions
Route::post('/positions/store',                        [PositionController::class, 'storePosition'])->name('positions.store');
Route::put('/positions/{id}',                    [PositionController::class, 'updatePosition'])->name('positions.update');
Route::delete('/positions/{id}',                 [PositionController::class, 'destroyPosition'])->name('positions.destroy');
