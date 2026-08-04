<?php

namespace App\Game;

use App\Enums\PlayerRole;
use App\Models\Game;
use Illuminate\Contracts\Redis\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Single gateway to hot per-tick game state in Redis. Nothing else in the
 * app touches these keys.
 *
 * Keys (all expire GC_TTL after the match ends):
 *   game:{id}:state         hash  tick, status, ends_at, power_pellet_until,
 *                                 pacman_player_id, pellets_remaining
 *   game:{id}:player:{pid}  hash  x, y, dir, role, ghost_slot, respawn_until, name
 *   game:{id}:players       set   player ids
 *   game:{id}:pellets       set   "x,y"
 *   game:{id}:power_pellets set   "x,y"
 *   game:{id}:inputs        hash  pid => intended direction
 *   game:{id}:closed_walls  set   "x,y" of toggleable walls currently closed
 */
class GameStateRepository
{
    private const GC_TTL = 3600;

    public function connection(): Connection
    {
        return Redis::connection();
    }

    private function key(int $gameId, string $suffix): string
    {
        return "game:{$gameId}:{$suffix}";
    }

    /**
     * Seeds all Redis state for a match from the persisted Game row.
     */
    public function initialize(Game $game): void
    {
        $redis = $this->connection();
        $layout = $game->maze_layout;
        $gameId = $game->id;

        $this->clear($gameId, $game->players->pluck('id')->all());

        $pellets = array_map(fn ($p) => "{$p[0]},{$p[1]}", $layout['pellets']);
        $powerPellets = array_map(fn ($p) => "{$p[0]},{$p[1]}", $layout['power_pellets']);

        $redis->sadd($this->key($gameId, 'pellets'), ...$pellets);
        $redis->sadd($this->key($gameId, 'power_pellets'), ...$powerPellets);

        $redis->hmset($this->key($gameId, 'state'), [
            'tick' => 0,
            'status' => 'active',
            'ends_at' => microtime(true) + ($game->match_duration_seconds ?? 300),
            'power_pellet_until' => 0,
            'pacman_player_id' => $game->pacman()?->id ?? 0,
            'pellets_remaining' => count($pellets),
        ]);

        $ghostSpawns = $layout['ghost_spawns'];
        $spawnIndex = 0;

        foreach ($game->players as $player) {
            if ($player->role === PlayerRole::Pacman) {
                [$x, $y] = $layout['pacman_spawn'];
            } else {
                [$x, $y] = $ghostSpawns[$spawnIndex % count($ghostSpawns)];
                $spawnIndex++;
            }

            $redis->sadd($this->key($gameId, 'players'), $player->id);
            $redis->hmset($this->key($gameId, "player:{$player->id}"), [
                'x' => $x,
                'y' => $y,
                'dir' => 'none',
                'role' => $player->role->value,
                'ghost_slot' => $player->ghost_slot ?? -1,
                'respawn_until' => 0,
                'score' => 0,
                'caught_count' => 0,
                'speed_until' => 0,
                'ability_ready_at' => 0,
                'is_bot' => (int) $player->is_bot,
                'name' => $player->display_name,
            ]);
        }
    }

    public function getState(int $gameId): array
    {
        return $this->connection()->hgetall($this->key($gameId, 'state'));
    }

    public function updateState(int $gameId, array $fields): void
    {
        $this->connection()->hmset($this->key($gameId, 'state'), $fields);
    }

    public function playerIds(int $gameId): array
    {
        return array_map('intval', $this->connection()->smembers($this->key($gameId, 'players')));
    }

    public function getPlayer(int $gameId, int $playerId): array
    {
        return $this->connection()->hgetall($this->key($gameId, "player:{$playerId}"));
    }

    public function updatePlayer(int $gameId, int $playerId, array $fields): void
    {
        $this->connection()->hmset($this->key($gameId, "player:{$playerId}"), $fields);
    }

    /** @return array<int, array> keyed by player id */
    public function getPlayers(int $gameId): array
    {
        $players = [];
        foreach ($this->playerIds($gameId) as $pid) {
            $players[$pid] = $this->getPlayer($gameId, $pid);
        }

        return $players;
    }

    public function setInput(int $gameId, int $playerId, string $direction): void
    {
        $this->connection()->hset($this->key($gameId, 'inputs'), (string) $playerId, $direction);
    }

    /** @return array<int, string> pid => direction */
    public function getInputs(int $gameId): array
    {
        $raw = $this->connection()->hgetall($this->key($gameId, 'inputs'));

        $inputs = [];
        foreach ($raw as $pid => $dir) {
            $inputs[(int) $pid] = $dir;
        }

        return $inputs;
    }

    /** Marks that a player wants to use their ability next tick. */
    public function requestAbility(int $gameId, int $playerId): void
    {
        $this->connection()->sadd($this->key($gameId, 'ability_requests'), $playerId);
    }

    /** @return int[] player ids, atomically consumed */
    public function pullAbilityRequests(int $gameId): array
    {
        $key = $this->key($gameId, 'ability_requests');
        $redis = $this->connection();

        $members = $redis->smembers($key);
        if ($members) {
            $redis->del($key);
        }

        return array_map('intval', $members);
    }

    /** Ghost wall-drop: a temporary 1-cell wall with an expiry timestamp. */
    public function setTempWall(int $gameId, int $x, int $y, float $expiresAt): void
    {
        $this->connection()->hset($this->key($gameId, 'temp_walls'), "{$x},{$y}", $expiresAt);
    }

    public function clearTempWall(int $gameId, string $coord): void
    {
        $this->connection()->hdel($this->key($gameId, 'temp_walls'), $coord);
    }

    /** @return array<string, float> "x,y" => expires_at */
    public function tempWalls(int $gameId): array
    {
        return array_map('floatval', $this->connection()->hgetall($this->key($gameId, 'temp_walls')));
    }

    /** Atomically removes and returns a pellet; false if none there. */
    public function eatPellet(int $gameId, int $x, int $y): bool
    {
        return (bool) $this->connection()->srem($this->key($gameId, 'pellets'), "{$x},{$y}");
    }

    public function eatPowerPellet(int $gameId, int $x, int $y): bool
    {
        return (bool) $this->connection()->srem($this->key($gameId, 'power_pellets'), "{$x},{$y}");
    }

    public function pelletsRemaining(int $gameId): int
    {
        return (int) $this->connection()->scard($this->key($gameId, 'pellets'));
    }

    /** @return string[] "x,y" strings */
    public function pellets(int $gameId): array
    {
        return $this->connection()->smembers($this->key($gameId, 'pellets'));
    }

    /** @return string[] "x,y" strings */
    public function powerPellets(int $gameId): array
    {
        return $this->connection()->smembers($this->key($gameId, 'power_pellets'));
    }

    public function setWallClosed(int $gameId, int $x, int $y, bool $closed): void
    {
        $key = $this->key($gameId, 'closed_walls');

        $closed
            ? $this->connection()->sadd($key, "{$x},{$y}")
            : $this->connection()->srem($key, "{$x},{$y}");
    }

    /** @return array<string, bool> "x,y" => true */
    public function closedWalls(int $gameId): array
    {
        return array_fill_keys($this->connection()->smembers($this->key($gameId, 'closed_walls')), true);
    }

    /**
     * Deletes state immediately (on re-init) or after finish let TTLs reap it.
     */
    public function clear(int $gameId, array $playerIds = []): void
    {
        $redis = $this->connection();

        $keys = [
            $this->key($gameId, 'state'),
            $this->key($gameId, 'players'),
            $this->key($gameId, 'pellets'),
            $this->key($gameId, 'power_pellets'),
            $this->key($gameId, 'inputs'),
            $this->key($gameId, 'closed_walls'),
            $this->key($gameId, 'ability_requests'),
            $this->key($gameId, 'temp_walls'),
        ];

        foreach ($playerIds as $pid) {
            $keys[] = $this->key($gameId, "player:{$pid}");
        }

        $redis->del(...$keys);
    }

    /** Marks every key for garbage collection once the match is over. */
    public function scheduleCleanup(int $gameId): void
    {
        $redis = $this->connection();

        $keys = [
            $this->key($gameId, 'state'),
            $this->key($gameId, 'players'),
            $this->key($gameId, 'pellets'),
            $this->key($gameId, 'power_pellets'),
            $this->key($gameId, 'inputs'),
            $this->key($gameId, 'closed_walls'),
            $this->key($gameId, 'ability_requests'),
            $this->key($gameId, 'temp_walls'),
        ];

        foreach ($this->playerIds($gameId) as $pid) {
            $keys[] = $this->key($gameId, "player:{$pid}");
        }

        foreach ($keys as $key) {
            $redis->expire($key, self::GC_TTL);
        }
    }
}
