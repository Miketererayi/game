<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\PlayerInputController;
use App\Http\Middleware\EnsurePlayerToken;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsurePlayerToken::class)->group(function () {
    Route::get('/', [GameController::class, 'home'])->name('home');
    Route::post('/games', [GameController::class, 'store'])->name('games.store');
    Route::post('/games/join', [GameController::class, 'join'])->name('games.join');
    Route::get('/g/{game:code}/lobby', [GameController::class, 'lobby'])->name('games.lobby');
    Route::get('/g/{game:code}/play', [GameController::class, 'play'])->name('games.play');
    Route::post('/g/{game:code}/input', [PlayerInputController::class, 'store'])->name('games.input');
    Route::post('/g/{game:code}/ability', [PlayerInputController::class, 'ability'])->name('games.ability');
    Route::post('/g/{game:code}/leave', [GameController::class, 'leave'])->name('games.leave');
    Route::post('/g/{game:code}/end', [GameController::class, 'endMatch'])->name('games.end');
});
