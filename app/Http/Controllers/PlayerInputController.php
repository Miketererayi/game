<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Game\GameStateRepository;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives direction intents. The tick loop is the only thing that moves
 * players — this endpoint just records what the player wants to do next.
 */
class PlayerInputController extends Controller
{
    public function store(Request $request, Game $game, GameStateRepository $stateRepo): Response
    {
        $validated = $request->validate([
            'direction' => ['required', 'in:up,down,left,right'],
        ]);

        if ($game->status !== GameStatus::Active) {
            abort(409, 'Game is not active.');
        }

        $player = $game->players()
            ->where('session_token', $request->session()->get('player_token'))
            ->first();

        abort_unless($player, 403);

        $stateRepo->setInput($game->id, $player->id, $validated['direction']);

        return response()->noContent();
    }

    public function ability(Request $request, Game $game, GameStateRepository $stateRepo): Response
    {
        if ($game->status !== GameStatus::Active) {
            abort(409, 'Game is not active.');
        }

        $player = $game->players()
            ->where('session_token', $request->session()->get('player_token'))
            ->first();

        abort_unless($player, 403);

        // The engine validates role + cooldown; this just queues the request.
        $stateRepo->requestAbility($game->id, $player->id);

        return response()->noContent();
    }
}
