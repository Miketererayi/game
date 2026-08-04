<?php

namespace App\Game;

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Models\Game;
use App\Models\GamePlayer;

/**
 * Ends a match that is still marked active but has no winner: the host
 * closing it early, or the last human walking away.
 *
 * Engine::finish() already covers matches that end on their own terms, but it
 * is private to a running tick loop and needs live Redis state. A match most
 * needs ending precisely when that loop is gone — a crashed loop, or one that
 * never started — so scores are read from Redis when present and from the
 * database when not.
 */
class MatchEnder
{
    public function __construct(private GameStateRepository $repo) {}

    public function end(Game $game, string $reason = 'abandoned'): void
    {
        if ($game->status !== GameStatus::Active) {
            return;
        }

        $scores = $this->scores($game);

        // This is what actually stops the tick loop: Engine::tick() bails as
        // soon as status is no longer 'active'. Guarded because hmset on a
        // missing key would resurrect a state hash for a match with none.
        if ($this->repo->getState($game->id)) {
            $this->repo->updateState($game->id, ['status' => 'finished']);
        }

        $game->update([
            'status' => GameStatus::Finished,
            'ended_at' => now(),
            // No winner. standings() treats a null winner as a round nobody
            // won, which is what an abandoned match is.
            'winner_role' => null,
        ]);

        $game->events()->create([
            'type' => 'game_ended',
            'payload' => ['winner_role' => null, 'reason' => $reason, 'scores' => $scores],
        ]);

        broadcast(new GameEnded($game->id, 'none', $reason, $scores));

        $this->repo->scheduleCleanup($game->id);
    }

    /**
     * Live Redis scores if the match got that far, database rows otherwise.
     *
     * @return array<int, array{id:int,name:string,role:?string,score:int,caught_count:int}>
     */
    private function scores(Game $game): array
    {
        $live = $this->repo->getPlayers($game->id);

        return $game->players()->get()->map(function (GamePlayer $player) use ($live) {
            $state = $live[$player->id] ?? [];

            $score = (int) ($state['score'] ?? $player->score);
            $caught = (int) ($state['caught_count'] ?? $player->caught_count);

            // Keep the persisted row in step, so the series standings on the
            // lobby match what the end screen just showed.
            $player->update(['score' => $score, 'caught_count' => $caught]);

            return [
                'id' => $player->id,
                'name' => $state['name'] ?? $player->display_name,
                'role' => $state['role'] ?? $player->role?->value,
                'score' => $score,
                'caught_count' => $caught,
            ];
        })->all();
    }
}
