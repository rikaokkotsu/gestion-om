<?php

use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [SessionController::class, 'store'])->middleware('throttle:login');
Route::post('/auth/logout', [SessionController::class, 'destroy'])->middleware('auth');

// Toutes les autres URL sont servies par l'application Vue.
Route::view('/{any?}', 'app')->where('any', '^(?!api|auth|sanctum|up).*$');
