<?php

namespace App\Models;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Game extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'maze_layout' => 'array',
            'players_pick_roles' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function pacman(): ?GamePlayer
    {
        return $this->players->firstWhere('role', PlayerRole::Pacman);
    }

    public function host(): ?GamePlayer
    {
        return $this->players->firstWhere('is_host', true);
    }

    public static function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /**
     * Randomly assign one pacman and distinct ghost slots to everyone else.
     * Players who already hold a role (host allowed picking) keep it.
     */
    public function assignRoles(): void
    {
        $players = $this->players()->get()->shuffle();

        $pacman = $players->firstWhere('role', PlayerRole::Pacman)
            ?? $players->firstWhere('role', null)
            ?? $players->first();

        $usedSlots = $players
            ->where('role', PlayerRole::Ghost)
            ->where('id', '!=', $pacman->id)
            ->pluck('ghost_slot')
            ->filter(fn ($slot) => $slot !== null)
            ->all();

        $pacman->update(['role' => PlayerRole::Pacman, 'ghost_slot' => null]);

        foreach ($players as $player) {
            if ($player->id === $pacman->id) {
                continue;
            }

            if ($player->role !== PlayerRole::Ghost || $player->ghost_slot === null) {
                $slot = 0;
                while (in_array($slot, $usedSlots, true)) {
                    $slot++;
                }
                $usedSlots[] = $slot;

                $player->update(['role' => PlayerRole::Ghost, 'ghost_slot' => $slot]);
            }
        }

        $this->load('players');
    }

    public function isJoinable(): bool
    {
        return $this->status === GameStatus::Lobby
            && $this->players()->count() < $this->max_players;
    }
}
