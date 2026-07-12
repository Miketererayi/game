<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class GameEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $winnerRole  'pacman' or 'ghost'
     * @param  string  $reason  'pellets_cleared', 'pacman_caught' or 'time_up'
     * @param  array  $scores  [{id, name, role, score, caught_count}]
     */
    public function __construct(
        public int $gameId,
        public string $winnerRole,
        public string $reason,
        public array $scores,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('game.'.$this->gameId);
    }
}
