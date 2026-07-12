<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nudges lobby clients to re-render (ready toggles, role picks, settings).
 */
class LobbyUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $gameId) {}

    public function broadcastOn(): Channel
    {
        return new PresenceChannel('game.'.$this->gameId);
    }
}
