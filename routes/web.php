<?php

use App\Http\Controllers\Games\GameController;
use App\Http\Controllers\Teams\TeamInvitationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::inertia('map-builder', 'MapBuilder')->name('map-builder');
    Route::get('lobbies', [GameController::class, 'lobbies'])->name('lobbies.index');
    Route::get('matches/ongoing', [GameController::class, 'ongoing'])->name('matches.ongoing');
    Route::get('matches/past', [GameController::class, 'past'])->name('matches.past');
    Route::post('games', [GameController::class, 'store'])->name('games.store');
    Route::post('games/join', [GameController::class, 'joinByCode'])->name('games.join-code');
    Route::get('games/{game}', [GameController::class, 'show'])->name('games.show');
    Route::post('games/{game}/join', [GameController::class, 'join'])->name('games.join');
    Route::post('games/{game}/start', [GameController::class, 'start'])->name('games.start');
    Route::get('games/{game}/play', [GameController::class, 'play'])->name('games.play');
    Route::post('games/{game}/orders', [GameController::class, 'submitOrders'])->name('games.orders');
    Route::post('games/{game}/pause', [GameController::class, 'togglePause'])->name('games.pause');
});

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
