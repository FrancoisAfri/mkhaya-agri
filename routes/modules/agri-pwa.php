<?php

use Illuminate\Support\Facades\Route;

/*
| Mkhaya Agri installable phone app: the service worker and web manifest for "My Work". Static, and
| outside the authenticated group on purpose — a browser re-checks a service worker in the
| background, when the session may have lapsed, and must still get the script rather than a login
| redirect. Neither file carries any data.
*/
Route::get('/agri/sw.js', fn () => response(file_get_contents(resource_path('agri/sw.js')), 200, [
    'Content-Type' => 'application/javascript; charset=utf-8',
    'Cache-Control' => 'no-cache',
    'Service-Worker-Allowed' => '/agri/',
]))->name('agri-pwa.sw');

Route::get('/agri/manifest.webmanifest', fn () => response()->json([
    'name' => config('app.name', 'Mkhaya Agri').' — My Work',
    'short_name' => 'My Work',
    'description' => 'Farm tasks, field activities and scouting, online or offline.',
    'start_url' => '/agri/my-work',
    'scope' => '/agri/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#16a34a',
    'icons' => [
        ['src' => '/images/agri-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/images/agri-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], 200, ['Content-Type' => 'application/manifest+json']))->name('agri-pwa.manifest');
