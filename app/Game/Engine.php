<?php

namespace App\Game;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use App\Events\GameEnded;
use App\Events\GameStateUpdated;
use App\Events\PlayerCaught;
use App\Events\PowerPelletActivated;
use App\Events\RoleRotated;
use App\Models\Game;

/**
 * Server-authoritative simulation for one match. Advanced exclusively by
 * the game:tick command; clients only ever send direction intents.
 */
class Engine
{
    private const PACMAN_SPEED = 4.2;          // tiles per second

    private const PACMAN_POWER_SPEED = 5.0;

    private const GHOST_SPEED = 4.0;

    private const TURN_TOLERANCE = 0.35;       // how close to a tile center a turn may snap

    private const EAT_RADIUS = 0.45;

    private const CATCH_RADIUS = 0.7;

    private const POWER_PELLET_SECONDS = 8.0;

    private const ROTATION_IMMUNITY_SECONDS = 2.0;

    private const GHOST_RESPAWN_SECONDS = 3.0;

    private const WALL_TOGGLE_INTERVAL = 20.0;

    private const FULL_SYNC_EVERY_TICKS = 15;

    private const ABILITIES_BY_SLOT = [0 => 'speed_burst', 1 => 'blink', 2 => 'wall_drop', 3 => 'speed_burst'];

    private const SPEED_BURST_SECONDS = 1.5;

    private const SPEED_BURST_COOLDOWN = 15.0;

    private const BLINK_DISTANCE = 3;

    private const BLINK_COOLDOWN = 20.0;

    private const WALL_DROP_SECONDS = 4.0;

    private const WALL_DROP_COOLDOWN = 25.0;

    private array $layout;

    public function __construct(
        private Game $game,
        private GameStateRepository $repo,
    ) {
        $this->layout = $game->maze_layout;
    }

    /**
     * Advances the simulation by $dt seconds. Returns true while the match
     * is still running.
     */
    public function tick(float $dt): bool
    {
        $gameId = $this->game->id;
        $now = microtime(true);

        $state = $this->repo->getState($gameId);
        if (($state['status'] ?? 'finished') !== 'active') {
            return false;
        }

        $tick = (int) $state['tick'] + 1;
        $players = $this->repo->getPlayers($gameId);
        $inputs = $this->repo->getInputs($gameId);
        $closedWalls = $this->repo->closedWalls($gameId);
        $powerActive = (float) $state['power_pellet_until'] > $now;
        $pacmanId = (int) $state['pacman_player_id'];

        $eaten = [];
        $powerEaten = [];
        $stateChanges = ['tick' => $tick];

        // ghost abilities (stretch): consume queued requests, expire temp walls
        $this->processAbilities($players, $now, $closedWalls);

        $tempWalls = [];
        foreach ($this->repo->tempWalls($gameId) as $coord => $expiresAt) {
            if ($expiresAt > $now) {
                $tempWalls[$coord] = true;
            } else {
                $this->repo->clearTempWall($gameId, $coord);
            }
        }

        // temp walls block only pacman
        $pacmanBlocked = $closedWalls + $tempWalls;

        // 1-3. movement, server-authoritative
        foreach ($players as $pid => &$player) {
            if ((float) $player['respawn_until'] > $now) {
                continue;
            }

            $walls = $player['role'] === 'pacman' ? $pacmanBlocked : $closedWalls;
            $this->movePlayer($player, $inputs[$pid] ?? null, $dt, $player['role'] === 'pacman' && $powerActive, $walls, $now);
        }
        unset($player);

        // 4-5. pellets (pacman only)
        if (isset($players[$pacmanId])) {
            $pacman = &$players[$pacmanId];
            $tx = (int) round((float) $pacman['x']);
            $ty = (int) round((float) $pacman['y']);

            if (abs((float) $pacman['x'] - $tx) < self::EAT_RADIUS && abs((float) $pacman['y'] - $ty) < self::EAT_RADIUS) {
                if ($this->repo->eatPellet($gameId, $tx, $ty)) {
                    $eaten[] = [$tx, $ty];
                    $pacman['score'] = (int) ($pacman['score'] ?? 0) + 10;
                    $stateChanges['pellets_remaining'] = $this->repo->pelletsRemaining($gameId);
                }

                if ($this->repo->eatPowerPellet($gameId, $tx, $ty)) {
                    $powerEaten[] = [$tx, $ty];
                    $pacman['score'] = (int) ($pacman['score'] ?? 0) + 50;
                    $until = $now + self::POWER_PELLET_SECONDS;
                    $stateChanges['power_pellet_until'] = $until;
                    $powerActive = true;

                    broadcast(new PowerPelletActivated($gameId, $pacmanId, $until));
                    $this->game->events()->create(['type' => 'power_pellet', 'payload' => ['player_id' => $pacmanId]]);
                }
            }
            unset($pacman);
        }

        // 6. catches
        $immunityUntil = (float) ($state['rotation_immunity_until'] ?? 0);
        if (isset($players[$pacmanId]) && $immunityUntil < $now) {
            $px = (float) $players[$pacmanId]['x'];
            $py = (float) $players[$pacmanId]['y'];

            foreach ($players as $pid => &$ghost) {
                if ($ghost['role'] !== 'ghost' || (float) $ghost['respawn_until'] > $now) {
                    continue;
                }

                $dist = hypot((float) $ghost['x'] - $px, (float) $ghost['y'] - $py);
                if ($dist > self::CATCH_RADIUS) {
                    continue;
                }

                if ($powerActive) {
                    $this->respawnGhost($ghost, $now);
                    $players[$pacmanId]['score'] = (int) $players[$pacmanId]['score'] + 200;

                    broadcast(new PlayerCaught($gameId, $pacmanId, $pid, 'ghost_respawn'));
                    $this->game->events()->create(['type' => 'caught', 'payload' => ['catcher' => $pacmanId, 'caught' => $pid, 'outcome' => 'ghost_respawn']]);
                } else {
                    $this->rotateRoles($players, $pacmanId, $pid, $stateChanges, $now);
                    $stateChanges['rotation_immunity_until'] = $now + self::ROTATION_IMMUNITY_SECONDS;
                    $pacmanId = $pid;
                    break; // one rotation per tick
                }
            }
            unset($ghost);
        }

        // 7. win conditions
        $pelletsLeft = $stateChanges['pellets_remaining'] ?? (int) $state['pellets_remaining'];
        $ended = null;
        if ($pelletsLeft === 0) {
            $ended = ['winner' => 'pacman', 'reason' => 'pellets_cleared'];
        } elseif ($now >= (float) $state['ends_at']) {
            $ended = ['winner' => 'ghost', 'reason' => 'time_up'];
        }

        // dynamic maze (stretch): flip toggleable walls on a timer
        $wallEvents = $this->toggleWalls($state, $players, $now, $stateChanges, $closedWalls);

        // persist tick results to redis
        foreach ($players as $pid => $player) {
            $this->repo->updatePlayer($gameId, $pid, $player);
        }
        if ($ended) {
            $stateChanges['status'] = 'finished';
        }
        $this->repo->updateState($gameId, $stateChanges);

        // 8. broadcast
        $broadcastState = [
            'tick' => $tick,
            'now' => $now,
            'ends_at' => (float) $state['ends_at'],
            'power_pellet_until' => (float) ($stateChanges['power_pellet_until'] ?? $state['power_pellet_until']),
            'pacman_player_id' => $pacmanId,
            'pellets_remaining' => $pelletsLeft,
            'players' => $this->presentPlayers($players),
            'eaten' => $eaten,
            'power_eaten' => $powerEaten,
            'closed_walls' => array_keys($wallEvents ?? $closedWalls),
            'temp_walls' => array_keys($tempWalls),
        ];

        if ($tick % self::FULL_SYNC_EVERY_TICKS === 1) {
            $broadcastState['pellets'] = $this->repo->pellets($gameId);
            $broadcastState['power_pellets'] = $this->repo->powerPellets($gameId);
        }

        broadcast(new GameStateUpdated($gameId, $broadcastState));

        if ($ended) {
            $this->finish($players, $ended['winner'], $ended['reason']);

            return false;
        }

        return true;
    }

    /**
     * One ability per ghost slot; server validates role and cooldown, so a
     * spoofed request can't do anything a legit one couldn't.
     */
    private function processAbilities(array &$players, float $now, array $closedWalls): void
    {
        foreach ($this->repo->pullAbilityRequests($this->game->id) as $pid) {
            if (! isset($players[$pid])) {
                continue;
            }

            $player = &$players[$pid];

            if ($player['role'] !== 'ghost'
                || (float) $player['respawn_until'] > $now
                || (float) ($player['ability_ready_at'] ?? 0) > $now) {
                continue;
            }

            $slot = max(0, (int) $player['ghost_slot']);
            $ability = self::ABILITIES_BY_SLOT[$slot % count(self::ABILITIES_BY_SLOT)];

            switch ($ability) {
                case 'speed_burst':
                    $player['speed_until'] = $now + self::SPEED_BURST_SECONDS;
                    $player['ability_ready_at'] = $now + self::SPEED_BURST_COOLDOWN;
                    break;

                case 'blink':
                    [$dx, $dy] = $this->vector($player['dir']);
                    if ($dx === 0 && $dy === 0) {
                        continue 2; // not moving: nothing to blink toward, no cooldown
                    }

                    $x = (int) round((float) $player['x']);
                    $y = (int) round((float) $player['y']);
                    for ($step = 0; $step < self::BLINK_DISTANCE; $step++) {
                        if (! $this->walkable($x + $dx, $y + $dy, $closedWalls)) {
                            break;
                        }
                        $x += $dx;
                        $y += $dy;
                    }

                    $player['x'] = $x;
                    $player['y'] = $y;
                    $player['ability_ready_at'] = $now + self::BLINK_COOLDOWN;
                    break;

                case 'wall_drop':
                    // drop behind the ghost so it blocks a chasing pacman
                    [$dx, $dy] = $this->vector($player['dir']);
                    $x = (int) round((float) $player['x']) - $dx;
                    $y = (int) round((float) $player['y']) - $dy;

                    if (($dx === 0 && $dy === 0) || ! $this->walkable($x, $y, $closedWalls)) {
                        continue 2;
                    }

                    $this->repo->setTempWall($this->game->id, $x, $y, $now + self::WALL_DROP_SECONDS);
                    $player['ability_ready_at'] = $now + self::WALL_DROP_COOLDOWN;
                    break;
            }

            unset($player);
        }
    }

    private function movePlayer(array &$player, ?string $intent, float $dt, bool $powered, array $closedWalls, float $now): void
    {
        $x = (float) $player['x'];
        $y = (float) $player['y'];
        $dir = $player['dir'];

        // turning: reversals are always allowed, other turns snap at tile centers
        if ($intent && $intent !== $dir) {
            if ($this->isOpposite($intent, $dir)) {
                $dir = $intent;
            } else {
                $cx = (int) round($x);
                $cy = (int) round($y);
                [$ix, $iy] = $this->vector($intent);

                if (abs($x - $cx) <= self::TURN_TOLERANCE
                    && abs($y - $cy) <= self::TURN_TOLERANCE
                    && $this->walkable($cx + $ix, $cy + $iy, $closedWalls)) {
                    $x = (float) $cx;
                    $y = (float) $cy;
                    $dir = $intent;
                }
            }
        }

        $player['dir'] = $dir;

        if ($dir === 'none') {
            return;
        }

        $speed = $player['role'] === 'pacman'
            ? ($powered ? self::PACMAN_POWER_SPEED : self::PACMAN_SPEED)
            : (((float) ($player['speed_until'] ?? 0)) > $now ? self::GHOST_SPEED * 2 : self::GHOST_SPEED);

        [$dx, $dy] = $this->vector($dir);
        $x += $dx * $speed * $dt;
        $y += $dy * $speed * $dt;

        // clamp at the center of the current tile when the next tile is a wall
        if ($dx !== 0) {
            $cur = (int) round((float) $player['x']);
            if (! $this->walkable($cur + $dx, (int) round($y), $closedWalls)) {
                $x = $dx > 0 ? min($x, (float) $cur) : max($x, (float) $cur);
            }
        } else {
            $cur = (int) round((float) $player['y']);
            if (! $this->walkable((int) round($x), $cur + $dy, $closedWalls)) {
                $y = $dy > 0 ? min($y, (float) $cur) : max($y, (float) $cur);
            }
        }

        // horizontal tunnel wrap
        if ($x < -0.5) {
            $x += $this->layout['width'];
        } elseif ($x > $this->layout['width'] - 0.5) {
            $x -= $this->layout['width'];
        }

        $player['x'] = round($x, 3);
        $player['y'] = round($y, 3);
    }

    private function walkable(int $x, int $y, array $closedWalls): bool
    {
        // tunnel: stepping off the grid horizontally wraps to the far side
        if ($x < 0) {
            $x += $this->layout['width'];
        } elseif ($x >= $this->layout['width']) {
            $x -= $this->layout['width'];
        }

        return Maze::isWalkable($this->layout, $x, $y, $closedWalls);
    }

    private function respawnGhost(array &$ghost, float $now): void
    {
        $spawns = $this->layout['ghost_spawns'];
        $slot = max(0, (int) $ghost['ghost_slot']);
        [$sx, $sy] = $spawns[$slot % count($spawns)];

        $ghost['x'] = $sx;
        $ghost['y'] = $sy;
        $ghost['dir'] = 'none';
        $ghost['respawn_until'] = $now + self::GHOST_RESPAWN_SECONDS;
    }

    /**
     * The catcher becomes Pac-Man; the caught Pac-Man takes over the
     * catcher's ghost slot and respawns in the ghost house so the pair
     * can't just re-catch each other on the spot. Mirrored to MySQL so
     * reconnects see the truth.
     */
    private function rotateRoles(array &$players, int $pacmanId, int $catcherId, array &$stateChanges, float $now): void
    {
        $slot = (int) $players[$catcherId]['ghost_slot'];

        $players[$catcherId]['role'] = 'pacman';
        $players[$catcherId]['ghost_slot'] = -1;
        $players[$catcherId]['caught_count'] = (int) ($players[$catcherId]['caught_count'] ?? 0) + 1;
        $players[$catcherId]['score'] = (int) ($players[$catcherId]['score'] ?? 0) + 100;

        $players[$pacmanId]['role'] = 'ghost';
        $players[$pacmanId]['ghost_slot'] = $slot;
        $this->respawnGhost($players[$pacmanId], $now);

        $stateChanges['pacman_player_id'] = $catcherId;

        $this->game->players()->whereKey($catcherId)->update([
            'role' => PlayerRole::Pacman->value,
            'ghost_slot' => null,
        ]);
        $this->game->players()->whereKey($pacmanId)->update([
            'role' => PlayerRole::Ghost->value,
            'ghost_slot' => $slot,
        ]);

        broadcast(new PlayerCaught($this->game->id, $catcherId, $pacmanId, 'role_rotation'));
        broadcast(new RoleRotated($this->game->id, $catcherId, $pacmanId, $slot));
        $this->game->events()->create(['type' => 'role_rotated', 'payload' => ['new_pacman' => $catcherId, 'new_ghost' => $pacmanId]]);
    }

    /** @return array<string, bool>|null new closed set when it changed */
    private function toggleWalls(array $state, array $players, float $now, array &$stateChanges, array &$closedWalls): ?array
    {
        if (empty($this->layout['toggleable_walls'])) {
            return null;
        }

        $nextAt = (float) ($state['walls_next_toggle_at'] ?? 0);
        if ($nextAt === 0.0) {
            $stateChanges['walls_next_toggle_at'] = $now + self::WALL_TOGGLE_INTERVAL;

            return null;
        }

        if ($now < $nextAt) {
            return null;
        }

        $stateChanges['walls_next_toggle_at'] = $now + self::WALL_TOGGLE_INTERVAL;

        $occupied = [];
        foreach ($players as $player) {
            $occupied[(int) round((float) $player['x']).','.(int) round((float) $player['y'])] = true;
        }

        $newClosed = [];
        foreach ($this->layout['toggleable_walls'] as [$x, $y]) {
            $key = "{$x},{$y}";
            $shouldClose = ! isset($closedWalls[$key]);

            // never close a wall on top of a player
            if ($shouldClose && isset($occupied[$key])) {
                $shouldClose = false;
            }

            $this->repo->setWallClosed($this->game->id, $x, $y, $shouldClose);
            if ($shouldClose) {
                $newClosed[$key] = true;
            }
        }

        $closedWalls = $newClosed;

        return $newClosed;
    }

    private function presentPlayers(array $players): array
    {
        $out = [];
        foreach ($players as $pid => $p) {
            $out[$pid] = [
                'id' => $pid,
                'x' => (float) $p['x'],
                'y' => (float) $p['y'],
                'dir' => $p['dir'],
                'role' => $p['role'],
                'ghost_slot' => (int) $p['ghost_slot'],
                'respawn_until' => (float) $p['respawn_until'],
                'score' => (int) ($p['score'] ?? 0),
                'name' => $p['name'],
                'ability' => $p['role'] === 'ghost'
                    ? self::ABILITIES_BY_SLOT[max(0, (int) $p['ghost_slot']) % count(self::ABILITIES_BY_SLOT)]
                    : null,
                'ability_ready_at' => (float) ($p['ability_ready_at'] ?? 0),
            ];
        }

        return $out;
    }

    private function finish(array $players, string $winnerRole, string $reason): void
    {
        $scores = [];
        foreach ($players as $pid => $p) {
            $this->game->players()->whereKey($pid)->update([
                'score' => (int) ($p['score'] ?? 0),
                'caught_count' => (int) ($p['caught_count'] ?? 0),
            ]);

            $scores[] = [
                'id' => $pid,
                'name' => $p['name'],
                'role' => $p['role'],
                'score' => (int) ($p['score'] ?? 0),
                'caught_count' => (int) ($p['caught_count'] ?? 0),
            ];
        }

        $this->game->update([
            'status' => GameStatus::Finished,
            'ended_at' => now(),
            'winner_role' => $winnerRole,
        ]);

        $this->game->events()->create(['type' => 'game_ended', 'payload' => ['winner_role' => $winnerRole, 'reason' => $reason, 'scores' => $scores]]);

        broadcast(new GameEnded($this->game->id, $winnerRole, $reason, $scores));

        $this->repo->scheduleCleanup($this->game->id);
    }

    private function vector(string $dir): array
    {
        return match ($dir) {
            'up' => [0, -1],
            'down' => [0, 1],
            'left' => [-1, 0],
            'right' => [1, 0],
            default => [0, 0],
        };
    }

    private function isOpposite(string $a, string $b): bool
    {
        return match ($a) {
            'up' => $b === 'down',
            'down' => $b === 'up',
            'left' => $b === 'right',
            'right' => $b === 'left',
            default => false,
        };
    }
}
