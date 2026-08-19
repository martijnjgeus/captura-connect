<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\IntegrationLogController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/logs', [IntegrationLogController::class, 'index'])->name('logs.index');
    Route::get('/logs/{integrationLog}', [IntegrationLogController::class, 'show'])->name('logs.show');
});
