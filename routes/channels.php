<?php

use App\Models\GamePlayer;
use Illuminate\Support\Facades\Broadcast;

// Lobby presence + live match channel. The game-player guard has already
// verified the session token belongs to a player of this exact game; the
// returned array is the presence member info shared with other players.
Broadcast::channel('game.{gameId}', function (GamePlayer $player, int $gameId) {
    if ($player->game_id !== $gameId) {
        return false;
    }

    return [
        'id' => $player->id,
        'name' => $player->display_name,
        'role' => $player->role?->value,
        'ghost_slot' => $player->ghost_slot,
        'is_host' => $player->is_host,
    ];
}, ['guards' => ['game-player']]);
