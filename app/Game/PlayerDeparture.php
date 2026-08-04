<?php

namespace App\Game;

use App\Enums\GameStatus;
use App\Events\LobbyUpdated;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Str;

/**
 * Takes a player out of a game they no longer want to be in.
 *
 * What that means depends on where they are. In the lobby the seat is simply
 * given back. Mid-match it cannot be: the engine holds live state keyed by
 * player id, and deleting it would strand the maze. There the character is
 * handed to the AI instead, so the match keeps running for everyone else.
 */
class PlayerDeparture
{
    public function __construct(
        private GameStateRepository $repo,
        private MatchEnder $ender,
    ) {}

    public function depart(Game $game, GamePlayer $player): void
    {
        $game->status === GameStatus::Active
            ? $this->leaveMatch($game, $player)
            : $this->leaveLobby($game, $player);
    }

    private function leaveLobby(Game $game, GamePlayer $player): void
    {
        $player->delete();

        if ($player->is_host) {
            $this->promoteHost($game);
        }

        // A room with nothing but AI in it has no one left to start it, and
        // would otherwise sit in the join list forever.
        if ($this->humans($game)->isEmpty()) {
            $game->players()->delete();
            $game->update(['status' => GameStatus::Finished, 'ended_at' => now()]);

            return;
        }

        broadcast(new LobbyUpdated($game->id));
    }

    private function leaveMatch(Game $game, GamePlayer $player): void
    {
        // Rotate the token as well as flipping the flag: without it, the
        // leaver's session still matches this row and rejoining would drop
        // them back into the character they just walked away from.
        $player->update([
            'is_bot' => true,
            'is_ready' => true,
            'session_token' => 'bot-'.Str::random(32),
        ]);

        // The engine reads is_bot from Redis every tick, so this is what
        // actually puts the AI in the driving seat.
        if ($this->repo->getState($game->id)) {
            $this->repo->updatePlayer($game->id, $player->id, ['is_bot' => 1]);
        }

        if ($player->is_host) {
            $this->promoteHost($game);
        }

        if ($this->humans($game)->isEmpty()) {
            $this->ender->end($game->refresh(), 'abandoned');

            return;
        }

        broadcast(new LobbyUpdated($game->id));
    }

    /** Hosting passes to whoever is still here; AI cannot hold the room. */
    private function promoteHost(Game $game): void
    {
        $heir = $this->humans($game)->first();

        if ($heir) {
            $game->players()->whereKey($heir->id)->update(['is_host' => true]);
        }
    }

    private function humans(Game $game): \Illuminate\Support\Collection
    {
        return $game->players()->where('is_bot', false)->get();
    }
}
