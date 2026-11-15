<?php

use Illuminate\Support\Facades\Route;

/*
 * The Vue app (apps/web) is built into public/spa. Every path that is not the API, Sanctum or the health check
 * returns its index.html and the client-side router takes over.
 */
Route::get('/{path?}', function () {
    $index = public_path('spa/index.html');

    abort_unless(is_file($index), 503, 'The web app is not built yet: run "npm run build" in apps/web.');

    return response(file_get_contents($index), 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache',
    ]);
})->where('path', '^(?!api/|sanctum/|up$|spa/).*$');
