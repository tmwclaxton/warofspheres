<?php

use App\Http\Controllers\Games\GameController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('games', [GameController::class, 'index'])->name('games.index');
    Route::post('games', [GameController::class, 'store'])->name('games.store');
    Route::post('games/join', [GameController::class, 'joinByCode'])->name('games.join-code');
    Route::get('games/{game}', [GameController::class, 'show'])->name('games.show');
    Route::post('games/{game}/join', [GameController::class, 'join'])->name('games.join');
    Route::post('games/{game}/start', [GameController::class, 'start'])->name('games.start');
    Route::get('games/{game}/play', [GameController::class, 'play'])->name('games.play');
    Route::post('games/{game}/orders', [GameController::class, 'submitOrders'])->name('games.orders');
    Route::post('games/{game}/pause', [GameController::class, 'togglePause'])->name('games.pause');
});

Route::prefix('{current_team}')
    ->middleware(['auth', ValidateSessionWithWorkOS::class, EnsureTeamMembership::class])
    ->group(function () {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
