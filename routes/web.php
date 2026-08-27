<?php

use App\Http\Controllers\Api\BundleController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\EntryController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\WeeklyResultController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\PoolController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/pool'));

/*
 * Auth — single operator. These routes live in web.php (not api.php) on
 * purpose: the frontend authenticates with the session cookie + CSRF token
 * that the web middleware group provides, and api-sync.js sends the
 * X-CSRF-TOKEN header on every write.
 */
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/pool', [PoolController::class, 'index']);

    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    Route::prefix('api')->group(function () {
        Route::get('seasons/{season}/bundle', [BundleController::class, 'bundle']);
        Route::get('seasons/{season}/stats', [BundleController::class, 'stats']);

        Route::post('players', [PlayerController::class, 'store']);
        Route::patch('players/{player}', [PlayerController::class, 'update']);

        Route::put('entries', [EntryController::class, 'upsert']);
        Route::delete('entries/{player}/{week}', [EntryController::class, 'destroy']);

        Route::put('weekly-results/{week}', [WeeklyResultController::class, 'upsert']);

        Route::patch('config', [ConfigController::class, 'update']);
    });
});
