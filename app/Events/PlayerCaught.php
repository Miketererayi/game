<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class PlayerCaught implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $outcome  'role_rotation' (ghost caught pacman) or
     *                           'ghost_respawn' (pacman ate a ghost during power pellet)
     */
    public function __construct(
        public int $gameId,
        public int $catcherId,
        public int $caughtId,
        public string $outcome,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('game.'.$this->gameId);
    }
}
