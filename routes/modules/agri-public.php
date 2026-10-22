<?php

use App\Modules\Agri\Http\Controllers\PublicTraceController;
use Illuminate\Support\Facades\Route;

/*
| Mkhaya Agri's public consumer trace page: the address a pack's QR code opens. No login, on purpose;
| the token is unguessable and revocable, the page shows only what the organisation allowed, and the
| route is rate-limited per visitor.
*/
Route::get('/t/{token}', [PublicTraceController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{24,64}')
    ->middleware('throttle:60,1')
    ->name('public.trace');   // not agri.*: that prefix is for signed-in, permission-checked routes
