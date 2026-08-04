<?php

namespace App\Game;

/**
 * Direction intents for AI-controlled players.
 *
 * Deliberately produces nothing but the same 'up'|'down'|'left'|'right'
 * intents a human sends through PlayerInputController, so bots are subject
 * to identical movement, catching and rotation rules — the Engine never
 * needs to know whether a player is a person.
 *
 * Navigation is a breadth-first distance map over the maze grid: flood from
 * the target, then step to whichever neighbouring tile is closest to it
 * (or furthest, when running away). Maps are cached per target within a
 * tick, so a pack of ghosts chasing the same Pac-Man costs one flood.
 */
class BotBrain
{
    /** Tiles a ghost looks ahead of Pac-Man when cutting him off. */
    private const AMBUSH_LOOKAHEAD = 4;

    /** How close a ghost has to be before a Pac-Man bot treats it as danger. */
    private const DANGER_RADIUS = 3;

    private const DIRECTIONS = [
        'up' => [0, -1],
        'down' => [0, 1],
        'left' => [-1, 0],
        'right' => [1, 0],
    ];

    /** @var array<string, array<string, int>> distance maps, keyed by target */
    private array $mapCache = [];

    public function __construct(private array $layout) {}

    /**
     * @param  array<int, array>  $players  redis player hashes, keyed by id
     * @param  string[]  $pellets  "x,y" strings still on the board
     * @param  array<string, bool>  $blocked  walls closed this tick
     * @return array<int, string> player id => direction intent
     */
    public function intents(
        array $players,
        int $pacmanId,
        bool $powerActive,
        array $pellets,
        array $blocked,
        float $now,
        ?array $pacmanBlocked = null,
    ): array {
        $this->mapCache = [];

        $intents = [];

        foreach ($players as $pid => $player) {
            if (empty($player['is_bot']) || (float) ($player['respawn_until'] ?? 0) > $now) {
                continue;
            }

            $intent = $player['role'] === 'pacman'
                ? $this->pacmanIntent($player, $players, $pellets, $powerActive, $pacmanBlocked ?? $blocked)
                : $this->ghostIntent($player, $players[$pacmanId] ?? null, $powerActive, $blocked);

            if ($intent !== null) {
                $intents[$pid] = $intent;
            }
        }

        return $intents;
    }

    /**
     * Ghosts hunt Pac-Man, with a personality per slot so a pack spreads out
     * instead of forming a conga line, and scatter while he is powered up.
     */
    private function ghostIntent(array $ghost, ?array $pacman, bool $powerActive, array $blocked): ?string
    {
        if (! $pacman) {
            return null;
        }

        $from = $this->tileOf($ghost);
        $pacTile = $this->tileOf($pacman);

        if ($powerActive) {
            // Edible: put distance between us and him.
            return $this->stepTowards($from, $pacTile, $blocked, $ghost['dir'] ?? 'none', away: true);
        }

        $slot = max(0, (int) ($ghost['ghost_slot'] ?? 0));

        $target = match ($slot % 4) {
            // Blinky: straight at him.
            0 => $pacTile,
            // Pinky: cut him off ahead of where he's facing.
            1 => $this->ahead($pacTile, $pacman['dir'] ?? 'none', self::AMBUSH_LOOKAHEAD, $blocked),
            // Inky: shadow him just ahead, covering the nearer escape.
            2 => $this->ahead($pacTile, $pacman['dir'] ?? 'none', 2, $blocked),
            // Clyde: chases, but backs off to a corner when he gets close.
            default => $this->distance($from, $pacTile) > 6 ? $pacTile : $this->nearestCorner($from, $blocked),
        };

        return $this->stepTowards($from, $target, $blocked, $ghost['dir'] ?? 'none');
    }

    /**
     * Pac-Man eats the closest pellet, hunts ghosts while powered, and treats
     * tiles near a dangerous ghost as walls so he routes around them.
     */
    private function pacmanIntent(array $pacman, array $players, array $pellets, bool $powerActive, array $blocked): ?string
    {
        $from = $this->tileOf($pacman);

        $ghosts = array_filter($players, fn ($p) => $p['role'] === 'ghost');

        if ($powerActive && $ghosts) {
            $prey = $this->nearest($from, array_map(fn ($g) => $this->tileOf($g), $ghosts));

            return $this->stepTowards($from, $prey, $blocked, $pacman['dir'] ?? 'none');
        }

        // Give ghosts a wide berth by pretending their surroundings are walls.
        $danger = $blocked;
        foreach ($ghosts as $ghost) {
            [$gx, $gy] = $this->tileOf($ghost);
            for ($dx = -self::DANGER_RADIUS; $dx <= self::DANGER_RADIUS; $dx++) {
                for ($dy = -self::DANGER_RADIUS; $dy <= self::DANGER_RADIUS; $dy++) {
                    if (abs($dx) + abs($dy) <= self::DANGER_RADIUS) {
                        $danger[($gx + $dx).','.($gy + $dy)] = true;
                    }
                }
            }
        }

        $targets = array_map(
            fn ($p) => array_map('intval', explode(',', $p)),
            $pellets,
        );

        if (! $targets) {
            return null;
        }

        // Prefer a route that keeps clear of ghosts; if boxed in, take any
        // pellet rather than freezing, and let the chase play out.
        $safe = $this->nearest($from, $targets, $danger);

        if ($safe !== null) {
            $step = $this->stepTowards($from, $safe, $danger, $pacman['dir'] ?? 'none');
            if ($step !== null) {
                return $step;
            }
        }

        $any = $this->nearest($from, $targets, $blocked);

        return $any === null ? null : $this->stepTowards($from, $any, $blocked, $pacman['dir'] ?? 'none');
    }

    /**
     * Picks the neighbouring tile that moves closest to (or furthest from)
     * the target. Reversing is a last resort so bots don't jitter in place.
     */
    private function stepTowards(array $from, ?array $target, array $blocked, string $currentDir, bool $away = false): ?string
    {
        if ($target === null) {
            return null;
        }

        $map = $this->distanceMap($target, $blocked);

        $best = null;
        $bestScore = null;
        $fallback = null;

        foreach (self::DIRECTIONS as $dir => [$dx, $dy]) {
            $nx = $this->wrapX($from[0] + $dx);
            $ny = $from[1] + $dy;

            if (! $this->walkable($nx, $ny, $blocked)) {
                continue;
            }

            $reversing = $this->isOpposite($dir, $currentDir);
            $fallback ??= $dir;
            if ($reversing) {
                continue;
            }

            $score = $map["{$nx},{$ny}"] ?? null;
            if ($score === null) {
                continue; // unreachable from the target
            }

            $better = $bestScore === null || ($away ? $score > $bestScore : $score < $bestScore);
            if ($better) {
                $bestScore = $score;
                $best = $dir;
            }
        }

        // Dead end (or every forward tile unreachable): turning back is fine.
        return $best ?? $fallback;
    }

    /** Flood fill outwards from $target; "x,y" => steps away. */
    private function distanceMap(array $target, array $blocked): array
    {
        $key = $target[0].','.$target[1].'|'.crc32(implode(';', array_keys($blocked)));

        if (isset($this->mapCache[$key])) {
            return $this->mapCache[$key];
        }

        $start = $this->wrapX($target[0]).','.$target[1];
        $dist = [$start => 0];
        $queue = [[$this->wrapX($target[0]), $target[1]]];

        for ($i = 0; $i < count($queue); $i++) {
            [$x, $y] = $queue[$i];
            $d = $dist["{$x},{$y}"];

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $this->wrapX($x + $dx);
                $ny = $y + $dy;

                if (isset($dist["{$nx},{$ny}"]) || ! $this->walkable($nx, $ny, $blocked)) {
                    continue;
                }

                $dist["{$nx},{$ny}"] = $d + 1;
                $queue[] = [$nx, $ny];
            }
        }

        return $this->mapCache[$key] = $dist;
    }

    /** Closest of $candidates by maze distance, not straight line. */
    private function nearest(array $from, array $candidates, array $blocked = []): ?array
    {
        $map = $this->distanceMap($from, $blocked);

        $best = null;
        $bestDist = null;

        foreach ($candidates as $candidate) {
            $d = $map[$this->wrapX($candidate[0]).','.$candidate[1]] ?? null;

            if ($d !== null && ($bestDist === null || $d < $bestDist)) {
                $bestDist = $d;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** The tile $steps in front of $tile, backing off if that lands in a wall. */
    private function ahead(array $tile, string $dir, int $steps, array $blocked): array
    {
        [$dx, $dy] = self::DIRECTIONS[$dir] ?? [0, 0];

        for ($i = $steps; $i > 0; $i--) {
            $x = $this->wrapX($tile[0] + $dx * $i);
            $y = $tile[1] + $dy * $i;

            if ($this->walkable($x, $y, $blocked)) {
                return [$x, $y];
            }
        }

        return $tile;
    }

    private function nearestCorner(array $from, array $blocked): array
    {
        $corners = [
            [1, 1],
            [$this->layout['width'] - 2, 1],
            [1, $this->layout['height'] - 2],
            [$this->layout['width'] - 2, $this->layout['height'] - 2],
        ];

        $walkable = array_values(array_filter($corners, fn ($c) => $this->walkable($c[0], $c[1], $blocked)));

        return $this->nearest($from, $walkable, $blocked) ?? $from;
    }

    private function distance(array $a, array $b): float
    {
        return hypot($a[0] - $b[0], $a[1] - $b[1]);
    }

    private function tileOf(array $player): array
    {
        return [
            $this->wrapX((int) round((float) $player['x'])),
            (int) round((float) $player['y']),
        ];
    }

    private function wrapX(int $x): int
    {
        $width = $this->layout['width'];

        if ($x < 0) {
            return $x + $width;
        }

        return $x >= $width ? $x - $width : $x;
    }

    private function walkable(int $x, int $y, array $blocked): bool
    {
        return Maze::isWalkable($this->layout, $this->wrapX($x), $y, $blocked);
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
