<?php

namespace App\Game;

/**
 * Builds a fresh maze per match.
 *
 * The shape starts as a corridor lattice with a ring just inside the border,
 * which is connected by construction, then whole corridor *segments* are
 * walled off to break up the regularity. Removing a segment between two
 * junctions takes an edge out of the maze graph and leaves every remaining
 * tile its other exits — walling single tiles instead severs a corridor and
 * strands both halves as dead ends.
 *
 * Two rules are enforced on every candidate, so a bad roll can never reach
 * a match:
 *  - fully connected: every open tile reachable from every other
 *  - no dead ends: every open tile has at least two ways out, because a
 *    cul-de-sac in a chase game is a catch with no counterplay
 *
 * Generation is seeded, so any maze can be reproduced from its seed.
 */
class MazeGenerator
{
    /** Corridor spacing; every third row and column stays open. */
    private const SPACING = 3;

    /** Longer runs than this are trunk routes and never walled off. */
    private const MAX_SEGMENT = 4;

    /** Floor for any board: one Pac-Man plus nine ghosts. */
    private const GHOST_SPAWNS = 9;

    private const DIRECTIONS = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    /** @var array<int, array<int, string>> */
    private array $grid = [];

    /** @var array<string, bool> */
    private array $protected = [];

    private int $centreX;

    private int $centreY;

    public function __construct(
        private int $width = 25,
        private int $height = 23,
        private int $mutations = 14,
        private int $ghostSpawns = self::GHOST_SPAWNS,
    ) {
        // Centre the ghost house on a corridor column so its door lines up.
        $this->centreX = intdiv($this->width, 2 * self::SPACING) * self::SPACING;
        $this->centreY = intdiv($this->height, 2);

        // The house only has so many interior tiles; asking for more spawns
        // than exist would fail validation on every seed and silently drop the
        // match onto the fixed maze.
        $this->ghostSpawns = max(self::GHOST_SPAWNS, min($ghostSpawns, count($this->houseCells())));
    }

    /**
     * Maze sized for the lobby. Small games keep the tighter board; big ones
     * need the room, or fifteen people share a maze built for ten and Pac-Man
     * is caught the moment the match starts.
     */
    public static function forPlayers(int $players): self
    {
        // One Pac-Man, everyone else a ghost, each wanting its own spawn tile.
        $spawns = max(self::GHOST_SPAWNS, $players - 1);

        if ($players >= Maze::HUGE_MAZE_FROM_PLAYERS) {
            return new self(31, 27, 14, $spawns);
        }

        return $players >= Maze::LARGE_MAZE_FROM_PLAYERS
            ? new self(25, 23, 14, $spawns)
            : new self(19, 21, 14, $spawns);
    }

    /**
     * A parsed layout, ready for Game::maze_layout. Rerolls the seed if a
     * draw fails validation, and falls back to the hand-authored maze if the
     * generator somehow can't produce a valid board at all — a match start
     * must never fail over scenery.
     */
    public function layout(?int $seed = null): array
    {
        $seed ??= random_int(1, PHP_INT_MAX - 32);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $rows = $this->build($seed + $attempt);

            if ($this->isValid($rows)) {
                return Maze::parse($rows);
            }
        }

        report(new \RuntimeException("Maze generation failed for seed {$seed}; using the fixed maze."));

        return $this->width >= 25 ? Maze::large() : Maze::classic();
    }

    /** @return string[] ASCII rows in Maze::parse() format */
    public function build(int $seed): array
    {
        mt_srand($seed);

        $this->lattice();
        $this->ghostHouse();
        $this->reserveFeatures();
        $this->fillDeadEnds();
        $this->carveSegments();
        $this->placeFeatures();

        return array_map(fn ($row) => implode('', $row), $this->grid);
    }

    /** Corridors every third row/column, plus a ring inside the border. */
    private function lattice(): void
    {
        $this->grid = [];

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $border = $x === 0 || $y === 0 || $x === $this->width - 1 || $y === $this->height - 1;
                // Without the ring, every lattice column would end in a stub
                // against the border — a dead end apiece.
                $ring = $x === 1 || $y === 1 || $x === $this->width - 2 || $y === $this->height - 2;
                $corridor = $ring || $y % self::SPACING === 0 || $x % self::SPACING === 0;

                $this->grid[$y][$x] = ($border || ! $corridor) ? '#' : '.';
            }
        }
    }

    /** A walled box in the middle, open top and bottom, holding the spawns. */
    private function ghostHouse(): void
    {
        [$cx, $cy] = [$this->centreX, $this->centreY];

        for ($y = $cy - 2; $y <= $cy + 2; $y++) {
            for ($x = $cx - 4; $x <= $cx + 4; $x++) {
                $edge = $y === $cy - 2 || $y === $cy + 2 || $x === $cx - 4 || $x === $cx + 4;
                $this->grid[$y][$x] = $edge ? '#' : '.';
            }
        }

        // Doors stay permanently open: a toggleable wall here would seal the
        // ghosts in for a whole cycle.
        foreach ([$cy - 3, $cy - 2, $cy + 2, $cy + 3] as $y) {
            $this->grid[$y][$cx] = '.';
        }

        foreach ($this->spawnCells() as [$x, $y]) {
            $this->grid[$y][$x] = 'G';
        }
    }

    /** As many spawn tiles as this lobby needs. */
    private function spawnCells(): array
    {
        return array_slice($this->houseCells(), 0, $this->ghostSpawns);
    }

    /**
     * Every tile inside the ghost house, spread-out ones first.
     *
     * Order matters: the first nine are the original spawn pattern, so a
     * normal lobby gets exactly the board it always did, and only bigger
     * lobbies reach into the tighter gaps between them.
     *
     * @return array<int, array{int, int}>
     */
    private function houseCells(): array
    {
        [$cx, $cy] = [$this->centreX, $this->centreY];

        return [
            [$cx - 2, $cy - 1], [$cx, $cy - 1], [$cx + 2, $cy - 1],
            [$cx - 2, $cy], [$cx - 1, $cy], [$cx + 1, $cy], [$cx + 2, $cy],
            [$cx - 2, $cy + 1], [$cx + 2, $cy + 1],

            [$cx - 1, $cy - 1], [$cx + 1, $cy - 1], [$cx - 3, $cy - 1], [$cx + 3, $cy - 1],
            [$cx - 3, $cy], [$cx, $cy], [$cx + 3, $cy],
            [$cx - 1, $cy + 1], [$cx, $cy + 1], [$cx + 1, $cy + 1], [$cx - 3, $cy + 1], [$cx + 3, $cy + 1],
        ];
    }

    private function tunnelRows(): array
    {
        $above = intdiv($this->centreY - 4, self::SPACING) * self::SPACING;
        $below = (int) (ceil(($this->centreY + 4) / self::SPACING) * self::SPACING);

        return [$above, min($below, $this->height - 3)];
    }

    private function pacmanCell(): array
    {
        return [$this->centreX, $this->height - 4];
    }

    private function powerPelletCells(): array
    {
        return [
            [1, 1], [$this->width - 2, 1],
            [1, $this->height - 2], [$this->width - 2, $this->height - 2],
        ];
    }

    /**
     * Anything placed after the carve has to survive it — otherwise a feature
     * could land on a tile that has since been walled off, opening an isolated
     * pocket when it is reopened.
     */
    private function reserveFeatures(): void
    {
        $this->protected = [];

        [$cx, $cy] = [$this->centreX, $this->centreY];
        for ($y = $cy - 3; $y <= $cy + 3; $y++) {
            for ($x = $cx - 4; $x <= $cx + 4; $x++) {
                $this->protected["{$x},{$y}"] = true;
            }
        }

        foreach ($this->tunnelRows() as $y) {
            for ($x = 0; $x < $this->width; $x++) {
                $this->protected["{$x},{$y}"] = true;
            }
        }

        foreach ([$this->pacmanCell(), ...$this->powerPelletCells()] as [$x, $y]) {
            $this->protected["{$x},{$y}"] = true;
        }
    }

    /** Walls up cul-de-sacs until only loops remain. */
    private function fillDeadEnds(): void
    {
        $keep = [];
        foreach ($this->spawnCells() as [$x, $y]) {
            $keep["{$x},{$y}"] = true;
        }
        foreach ([$this->centreY - 3, $this->centreY - 2, $this->centreY + 2, $this->centreY + 3] as $y) {
            $keep["{$this->centreX},{$y}"] = true;
        }

        do {
            $filled = 0;
            for ($y = 1; $y < $this->height - 1; $y++) {
                for ($x = 1; $x < $this->width - 1; $x++) {
                    if ($this->isWall($this->grid, $x, $y) || isset($keep["{$x},{$y}"])) {
                        continue;
                    }
                    if ($this->exits($this->grid, $x, $y) < 2) {
                        $this->grid[$y][$x] = '#';
                        $filled++;
                    }
                }
            }
        } while ($filled > 0);
    }

    /** Wall off random corridor segments, mirrored, keeping both invariants. */
    private function carveSegments(): void
    {
        $applied = 0;

        for ($attempt = 0; $attempt < 400 && $applied < $this->mutations; $attempt++) {
            $segment = $this->segmentAt(mt_rand(1, $this->width - 2), mt_rand(1, $this->height - 2));

            if (! $segment || count($segment) > self::MAX_SEGMENT) {
                continue;
            }

            $candidate = $this->grid;
            $blocked = false;

            foreach ($segment as [$sx, $sy]) {
                $mirror = $this->width - 1 - $sx;
                if (isset($this->protected["{$sx},{$sy}"]) || isset($this->protected["{$mirror},{$sy}"])) {
                    $blocked = true;
                    break;
                }
                $candidate[$sy][$sx] = '#';
                $candidate[$sy][$mirror] = '#';
            }

            if ($blocked || ! $this->connected($candidate) || $this->hasDeadEnd($candidate)) {
                continue;
            }

            $this->grid = $candidate;
            $applied++;
        }
    }

    /** Tunnels, Pac-Man's start and the power pellets. */
    private function placeFeatures(): void
    {
        foreach ($this->tunnelRows() as $y) {
            for ($x = 1; $x < $this->width - 1; $x++) {
                $this->grid[$y][$x] = '.';
            }
            $this->grid[$y][0] = 'T';
            $this->grid[$y][$this->width - 1] = 'T';
        }

        [$px, $py] = $this->pacmanCell();
        $this->grid[$py][$px] = 'P';

        foreach ($this->powerPelletCells() as [$x, $y]) {
            $this->grid[$y][$x] = 'o';
        }
    }

    /**
     * The run of two-exit tiles around ($x, $y), bounded by junctions.
     *
     * @return array<int, array{int, int}>|null
     */
    private function segmentAt(int $x, int $y): ?array
    {
        if ($this->isWall($this->grid, $x, $y) || $this->exits($this->grid, $x, $y) !== 2) {
            return null;
        }

        $segment = ["{$x},{$y}" => [$x, $y]];
        $frontier = [[$x, $y]];

        while ($frontier) {
            [$cx, $cy] = array_pop($frontier);

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $this->wrapX($cx + $dx);
                $ny = $cy + $dy;

                if ($ny < 0 || $ny >= $this->height || isset($segment["{$nx},{$ny}"])) {
                    continue;
                }
                if ($this->isWall($this->grid, $nx, $ny) || $this->exits($this->grid, $nx, $ny) !== 2) {
                    continue;
                }

                $segment["{$nx},{$ny}"] = [$nx, $ny];
                $frontier[] = [$nx, $ny];
            }
        }

        return array_values($segment);
    }

    /** @param  string[]  $rows */
    public function isValid(array $rows): bool
    {
        $grid = array_map('str_split', $rows);

        if (count(array_unique(array_map('strlen', $rows))) !== 1) {
            return false;
        }

        $text = implode('', $rows);

        return substr_count($text, 'P') === 1
            && substr_count($text, 'G') >= $this->ghostSpawns
            && substr_count($text, 'o') >= 2
            && $this->connected($grid)
            && ! $this->hasDeadEnd($grid);
    }

    private function isWall(array $grid, int $x, int $y): bool
    {
        return $grid[$y][$x] === '#';
    }

    private function exits(array $grid, int $x, int $y): int
    {
        $exits = 0;

        foreach (self::DIRECTIONS as [$dx, $dy]) {
            $ny = $y + $dy;
            if ($ny >= 0 && $ny < $this->height && ! $this->isWall($grid, $this->wrapX($x + $dx), $ny)) {
                $exits++;
            }
        }

        return $exits;
    }

    private function hasDeadEnd(array $grid): bool
    {
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if (! $this->isWall($grid, $x, $y) && $this->exits($grid, $x, $y) < 2) {
                    return true;
                }
            }
        }

        return false;
    }

    private function connected(array $grid): bool
    {
        $open = [];
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if (! $this->isWall($grid, $x, $y)) {
                    $open["{$x},{$y}"] = true;
                }
            }
        }

        if (! $open) {
            return false;
        }

        [$sx, $sy] = array_map('intval', explode(',', array_key_first($open)));
        $seen = ["{$sx},{$sy}" => true];
        $queue = [[$sx, $sy]];

        for ($i = 0; $i < count($queue); $i++) {
            [$x, $y] = $queue[$i];

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $this->wrapX($x + $dx);
                $ny = $y + $dy;

                if ($ny < 0 || $ny >= $this->height || isset($seen["{$nx},{$ny}"]) || $this->isWall($grid, $nx, $ny)) {
                    continue;
                }

                $seen["{$nx},{$ny}"] = true;
                $queue[] = [$nx, $ny];
            }
        }

        return count($seen) === count($open);
    }

    private function wrapX(int $x): int
    {
        return ($x + $this->width) % $this->width;
    }
}
