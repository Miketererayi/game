<?php

namespace App\Game;

use App\Models\Game;
use Symfony\Component\Process\Process;

/**
 * Spawns the detached game:tick process for a match. In production this
 * would be a supervisor-managed pool; a detached artisan process keeps
 * local/dev friction-free.
 */
class GameLoopLauncher
{
    public function launch(Game $game): void
    {
        $log = storage_path("logs/game-{$game->id}.log");

        Process::fromShellCommandline(
            sprintf('nohup %s artisan game:tick %d >> %s 2>&1 &', config('game.php_binary'), $game->id, escapeshellarg($log)),
            base_path(),
        )->run();
    }
}
