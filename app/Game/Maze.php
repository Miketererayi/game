<?php

namespace App\Game;

/**
 * Authors and parses maze layouts.
 *
 * A layout is stored on games.maze_layout as JSON:
 *  - width, height
 *  - tiles: 2D array [y][x] of ints (0 = path, 1 = wall)
 *  - pellets: list of [x, y]
 *  - power_pellets: list of [x, y]
 *  - pacman_spawn: [x, y]
 *  - ghost_spawns: list of [x, y]
 *  - toggleable_walls: list of [x, y] (dynamic maze stretch goal; start closed)
 *
 * ASCII legend: '#' wall, '.' pellet, 'o' power pellet, ' ' bare path,
 * 'P' pacman spawn, 'G' ghost spawn, 'T' toggleable wall.
 */
class Maze
{
    public const TILE_PATH = 0;

    public const TILE_WALL = 1;

    private const CLASSIC = [
        '###################',
        '#........#........#',
        '#o##.###.#.###.##o#',
        '#.................#',
        '#.##.#.#####.#.##.#',
        '#....#...#...#....#',
        '####.###T#T###.####',
        '   #.#   G   #.#   ',
        '####.# ## ## #.####',
        'T....  #GGG#  ....T',
        '####.# ##### #.####',
        '   #.#       #.#   ',
        '####.# ##### #.####',
        '#........#........#',
        '#.##.###.#.###.##.#',
        '#o.#.....P.....#.o#',
        '##.#.#.#####.#.#.##',
        '#....#...#...#....#',
        '#.######.#.######.#',
        '#.................#',
        '###################',
    ];

    public static function classic(): array
    {
        return self::parse(self::CLASSIC);
    }

    public static function parse(array $rows): array
    {
        $height = count($rows);
        $width = max(array_map('strlen', $rows));

        $tiles = [];
        $pellets = [];
        $powerPellets = [];
        $ghostSpawns = [];
        $toggleable = [];
        $pacmanSpawn = null;

        foreach ($rows as $y => $row) {
            $row = str_pad($row, $width);
            for ($x = 0; $x < $width; $x++) {
                $char = $row[$x];
                $tiles[$y][$x] = $char === '#' ? self::TILE_WALL : self::TILE_PATH;

                match ($char) {
                    '.' => $pellets[] = [$x, $y],
                    'o' => $powerPellets[] = [$x, $y],
                    'P' => $pacmanSpawn = [$x, $y],
                    'G' => $ghostSpawns[] = [$x, $y],
                    'T' => $toggleable[] = [$x, $y],
                    default => null,
                };
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'tiles' => $tiles,
            'pellets' => $pellets,
            'power_pellets' => $powerPellets,
            'pacman_spawn' => $pacmanSpawn,
            'ghost_spawns' => $ghostSpawns,
            'toggleable_walls' => $toggleable,
        ];
    }

    /**
     * Whether a tile can be walked on, honoring dynamic wall state.
     *
     * @param  array<string, bool>  $closedToggleables  keyed "x,y" => closed
     */
    public static function isWalkable(array $layout, int $x, int $y, array $closedToggleables = []): bool
    {
        if ($x < 0 || $y < 0 || $x >= $layout['width'] || $y >= $layout['height']) {
            return false;
        }

        if ($layout['tiles'][$y][$x] === self::TILE_WALL) {
            return false;
        }

        return ! ($closedToggleables["{$x},{$y}"] ?? false);
    }
}
