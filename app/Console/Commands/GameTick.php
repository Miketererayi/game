<?php

namespace App\Console\Commands;

use App\Enums\GameStatus;
use App\Game\Engine;
use App\Game\GameStateRepository;
use App\Models\Game;
use Illuminate\Console\Command;

class GameTick extends Command
{
    protected $signature = 'game:tick {gameId} {--rate= : ticks per second, defaults to config game.movement.tick_rate}';

    protected $description = 'Run the authoritative simulation loop for a match';

    public function handle(GameStateRepository $repo): int
    {
        $game = Game::find($this->argument('gameId'));

        if (! $game || $game->status !== GameStatus::Active) {
            $this->error('Game not found or not active.');

            return self::FAILURE;
        }

        $rate = max(1, (int) ($this->option('rate') ?: config('game.movement.tick_rate')));
        $interval = 1.0 / $rate;
        $engine = new Engine($game, $repo);

        $this->info("Tick loop started for game {$game->id} at {$rate} tps.");

        $running = true;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, function () use (&$running) {
                    $running = false;
                });
            }
        }

        $last = microtime(true);

        while ($running) {
            $start = microtime(true);
            $dt = min($start - $last, 4 * $interval); // clamp huge gaps (debugger, stalls)
            $last = $start;

            try {
                if (! $engine->tick($dt)) {
                    break;
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error('tick failed: '.$e->getMessage());
            }

            $elapsed = microtime(true) - $start;
            $sleep = $interval - $elapsed;
            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }

        $this->info("Tick loop for game {$game->id} stopped.");

        return self::SUCCESS;
    }
}
