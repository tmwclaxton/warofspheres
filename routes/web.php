<?php

use App\Game\GameSpecs;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Games\GameController;
use App\Http\Controllers\Maps\MapController;
use App\Http\Controllers\MatchmakingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickStartController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

Route::get('/wiki', fn () => Inertia::render('Wiki', GameSpecs::forWiki()))->name('wiki');

Route::get('maps/explore', [MapController::class, 'explore'])->name('maps.explore');

// Published maps are viewable in the builder without auth (see MapPolicy::view). Bare /map-builder requires login.
Route::get('map-builder/{map?}', [MapController::class, 'builder'])->name('map-builder');

Route::get('leaderboard', [ProfileController::class, 'leaderboard'])->name('leaderboard.index');
Route::get('profiles/{profile:profile_uuid}', [ProfileController::class, 'show'])->name('profiles.show');

Route::middleware(['auth', 'admin'])->get('admin', OverviewController::class)->name('admin.overview');

Route::middleware(['guest.game'])->group(function () {
    Route::post('quick-start', [QuickStartController::class, 'join'])->name('quick-start.join');
    Route::delete('quick-start', [QuickStartController::class, 'leave'])->name('quick-start.leave');
    Route::get('quick-start/status', [QuickStartController::class, 'status'])->name('quick-start.status');

    Route::get('lobbies', [GameController::class, 'lobbies'])->name('lobbies.index');
    Route::get('matches/ongoing', [GameController::class, 'ongoing'])->name('matches.ongoing');
    Route::post('games/join', [GameController::class, 'joinByCode'])->name('games.join-code');
    Route::get('games/{game}', [GameController::class, 'show'])->name('games.show');
    Route::post('games/{game}/join', [GameController::class, 'join'])->name('games.join');
    Route::delete('games/{game}/leave', [GameController::class, 'leave'])->name('games.leave');
    Route::get('games/{game}/spectate', [GameController::class, 'spectate'])->name('games.spectate');
    Route::get('games/{game}/spectate-snapshot', [GameController::class, 'spectateSnapshot'])->name('games.spectate-snapshot');
    Route::get('games/{game}/play', [GameController::class, 'play'])->name('games.play');
    Route::get('games/{game}/snapshot', [GameController::class, 'snapshot'])->name('games.snapshot');
    Route::post('games/{game}/orders', [GameController::class, 'submitOrders'])->name('games.orders');
    Route::post('games/{game}/recruit', [GameController::class, 'recruit'])->name('games.recruit');
    Route::post('games/{game}/recruit-tank', [GameController::class, 'recruitTank'])->name('games.recruit-tank');
    Route::post('games/{game}/city-production', [GameController::class, 'setCityProduction'])->name('games.city-production');
    Route::post('games/{game}/chat', [GameController::class, 'sendChat'])->name('games.chat');
    Route::get('games/{game}/replay', [GameController::class, 'replay'])->name('games.replay');
});

Route::middleware(['auth'])->group(function () {
    Route::get('matchmaking', [MatchmakingController::class, 'show'])->name('matchmaking.show');
    Route::post('matchmaking/queue', [MatchmakingController::class, 'join'])->name('matchmaking.join');
    Route::delete('matchmaking/queue', [MatchmakingController::class, 'leave'])->name('matchmaking.leave');
    Route::get('matchmaking/status', [MatchmakingController::class, 'status'])->name('matchmaking.status');

    Route::get('maps', [MapController::class, 'index'])->name('maps.index');
    Route::post('maps', [MapController::class, 'store'])->name('maps.store');
    Route::post('maps/{map}/publish', [MapController::class, 'publish'])->name('maps.publish');
    Route::post('maps/{map}/fork', [MapController::class, 'fork'])->name('maps.fork');
    Route::post('maps/{map}/vote', [MapController::class, 'vote'])->name('maps.vote');
    Route::get('maps/{map}', [MapController::class, 'show'])->name('maps.show');
    Route::patch('maps/{map}', [MapController::class, 'update'])->name('maps.update');
    Route::delete('maps/{map}', [MapController::class, 'destroy'])->name('maps.destroy');

    Route::get('matches/past', [GameController::class, 'past'])->name('matches.past');
    Route::post('games', [GameController::class, 'store'])->name('games.store');
    Route::post('games/{game}/start', [GameController::class, 'start'])->name('games.start');
    Route::patch('games/{game}/player-profile', [GameController::class, 'updatePlayerProfile'])->name('games.player-profile');
    Route::patch('player-tag', [GameController::class, 'updatePlayerTag'])->name('player-tag.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
