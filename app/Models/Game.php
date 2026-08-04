<?php

namespace App\Models;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
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

    /**
     * A finished game is still joinable: the team stays together between
     * rounds, so latecomers can slot in while the results are on screen.
     */
    public function isJoinable(): bool
    {
        return in_array($this->status, [GameStatus::Lobby, GameStatus::Finished], true)
            && $this->players()->count() < $this->max_players;
    }

    /**
     * Every round this session has played, oldest first. The engine writes
     * one 'game_ended' event per match, so the series history is already on
     * disk — nothing extra to track.
     *
     * @return \Illuminate\Support\Collection<int, array> {winner_role, reason, scores}
     */
    public function rounds(): Collection
    {
        return $this->events()
            ->where('type', 'game_ended')
            ->orderBy('id')
            ->pluck('payload');
    }

    /** Results of the most recently finished round, if any. */
    public function lastResult(): ?array
    {
        return $this->rounds()->last();
    }

    /** Which round the team is on — the one being played or about to start. */
    public function roundNumber(): int
    {
        $played = $this->rounds()->count();

        return $this->status === GameStatus::Finished ? $played : $played + 1;
    }

    /**
     * Session standings across every round played, best first. Ranked on
     * rounds won, then total score — a player wins a round when the winning
     * role is the one they held when the whistle blew.
     *
     * @return array<int, array{id: int, name: string, score: int, caught: int, wins: int, rounds: int}>
     */
    public function standings(): array
    {
        $standings = [];

        foreach ($this->rounds() as $round) {
            foreach ($round['scores'] ?? [] as $entry) {
                $id = (int) $entry['id'];

                $standings[$id] ??= ['id' => $id, 'name' => $entry['name'], 'score' => 0, 'caught' => 0, 'wins' => 0, 'rounds' => 0];

                $standings[$id]['name'] = $entry['name'];
                $standings[$id]['score'] += (int) ($entry['score'] ?? 0);
                $standings[$id]['caught'] += (int) ($entry['caught_count'] ?? 0);
                $standings[$id]['rounds']++;
                $standings[$id]['wins'] += ($entry['role'] ?? null) === ($round['winner_role'] ?? null) ? 1 : 0;
            }
        }

        usort($standings, fn ($a, $b) => [$b['wins'], $b['score']] <=> [$a['wins'], $a['score']]);

        return $standings;
    }
}
