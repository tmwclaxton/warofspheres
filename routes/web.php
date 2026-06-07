<?php

use App\Game\GameSpecs;
use App\Http\Controllers\Games\GameController;
use App\Http\Controllers\Maps\MapController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

Route::get('/wiki', fn () => Inertia::render('Wiki', GameSpecs::forWiki()))->name('wiki');

Route::middleware(['auth'])->group(function () {
    Route::get('map-builder/{map?}', [MapController::class, 'builder'])->name('map-builder');
    Route::get('maps', [MapController::class, 'index'])->name('maps.index');
    Route::post('maps', [MapController::class, 'store'])->name('maps.store');
    Route::get('maps/{map}', [MapController::class, 'show'])->name('maps.show');
    Route::patch('maps/{map}', [MapController::class, 'update'])->name('maps.update');
    Route::delete('maps/{map}', [MapController::class, 'destroy'])->name('maps.destroy');

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

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
