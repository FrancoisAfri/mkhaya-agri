<?php

use Illuminate\Support\Facades\Route;
use App\Core\HowTo\Controllers\HowToController;



Route::prefix('how-to')->middleware('platform.admin')->group(function (){

    // ── Inertia Pages ────────────────────────────────────────────────────────
    Route::get('/',                          [HowToController::class, 'index'])->name('howto.index');
    Route::get('/view/{moduleId}/{ribbonId}', [HowToController::class, 'view'])->name('howto.view');

    // ── JSON API — consumed by store via axios ───────────────────────────────
    Route::get('/{moduleId}',                [HowToController::class, 'byModule'])->name('howto.byModule');

    // ── Mutations ────────────────────────────────────────────────────────────
    Route::post('/store',                    [HowToController::class, 'store'])->name('howto.store');
    Route::put('/edit/{id}',                 [HowToController::class, 'update'])->name('howto.update');
    Route::delete('/delete/{id}',            [HowToController::class, 'destroy'])->name('howto.destroy');
    Route::patch('/toggle/{id}',             [HowToController::class, 'toggle'])->name('howto.toggle');
});
