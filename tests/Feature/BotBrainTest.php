<?php

namespace Tests\Feature;

use App\Game\BotBrain;
use App\Game\Maze;
use PHPUnit\Framework\TestCase;

/**
 * The brain is pure — maze in, direction intents out — so it can be driven
 * without Redis or a running match.
 */
class BotBrainTest extends TestCase
{
    private array $layout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->layout = Maze::classic();
    }

    private function player(array $overrides = []): array
    {
        return array_merge([
            'x' => 9,
            'y' => 15,
            'dir' => 'none',
            'role' => 'ghost',
            'ghost_slot' => 0,
            'respawn_until' => 0,
            'is_bot' => 1,
        ], $overrides);
    }

    /** Runs the brain repeatedly, moving bots a tile at a time. */
    private function simulate(array $players, int $steps, array $pellets = [], bool $powered = false): array
    {
        $brain = new BotBrain($this->layout);
        $vectors = ['up' => [0, -1], 'down' => [0, 1], 'left' => [-1, 0], 'right' => [1, 0]];

        $pacmanId = (int) array_key_first(array_filter($players, fn ($p) => $p['role'] === 'pacman'));

        for ($i = 0; $i < $steps; $i++) {
            $intents = $brain->intents($players, $pacmanId, $powered, $pellets, [], microtime(true));

            foreach ($intents as $pid => $dir) {
                [$dx, $dy] = $vectors[$dir];
                $nx = $players[$pid]['x'] + $dx;
                $ny = $players[$pid]['y'] + $dy;

                if (Maze::isWalkable($this->layout, ($nx + $this->layout['width']) % $this->layout['width'], $ny)) {
                    $players[$pid]['x'] = ($nx + $this->layout['width']) % $this->layout['width'];
                    $players[$pid]['y'] = $ny;
                    $players[$pid]['dir'] = $dir;
                }
            }
        }

        return $players;
    }

    public function test_a_ghost_bot_closes_in_on_pacman(): void
    {
        $players = [
            1 => $this->player(['role' => 'pacman', 'x' => 9, 'y' => 15, 'is_bot' => 0]),
            2 => $this->player(['x' => 1, 'y' => 1, 'ghost_slot' => 0]),
        ];

        $before = hypot($players[2]['x'] - 9, $players[2]['y'] - 15);
        $after = $this->simulate($players, 10);
        $distance = hypot($after[2]['x'] - 9, $after[2]['y'] - 15);

        $this->assertLessThan($before, $distance, 'ghost bot should be closer to Pac-Man after 10 steps');
    }

    public function test_a_ghost_bot_hunts_pacman_down_from_across_the_maze(): void
    {
        $players = [
            1 => $this->player(['role' => 'pacman', 'x' => 9, 'y' => 15, 'is_bot' => 0]),
            2 => $this->player(['x' => 1, 'y' => 1]),
        ];

        // Landing on Pac-Man's tile is a catch in a live match (radius 0.7),
        // so arrival is what matters, not where the bot drifts afterwards.
        $caught = false;
        for ($step = 0; $step < 60 && ! $caught; $step++) {
            $players = $this->simulate($players, 1);
            $caught = $players[2]['x'] === 9 && $players[2]['y'] === 15;
        }

        $this->assertTrue($caught, 'ghost bot never reached Pac-Man');
    }

    public function test_ghost_bots_flee_while_pacman_is_powered(): void
    {
        $players = [
            1 => $this->player(['role' => 'pacman', 'x' => 9, 'y' => 13, 'is_bot' => 0]),
            2 => $this->player(['x' => 9, 'y' => 15, 'dir' => 'up']),
        ];

        $before = hypot($players[2]['x'] - 9, $players[2]['y'] - 13);
        $after = $this->simulate($players, 8, powered: true);

        $this->assertGreaterThan($before, hypot($after[2]['x'] - 9, $after[2]['y'] - 13));
    }

    public function test_a_pacman_bot_walks_toward_the_nearest_pellet(): void
    {
        $players = [
            1 => $this->player(['role' => 'pacman', 'x' => 9, 'y' => 15]),
        ];

        // Two pellets: one three tiles west, one across the maze.
        $after = $this->simulate($players, 3, ['6,15', '1,19']);

        $this->assertSame(6, $after[1]['x']);
        $this->assertSame(15, $after[1]['y']);
    }

    public function test_only_bots_get_intents(): void
    {
        $brain = new BotBrain($this->layout);

        $players = [
            1 => $this->player(['role' => 'pacman', 'x' => 9, 'y' => 15, 'is_bot' => 0]),
            2 => $this->player(['x' => 9, 'y' => 7, 'is_bot' => 0]),
            3 => $this->player(['x' => 8, 'y' => 7, 'is_bot' => 1]),
        ];

        $intents = $brain->intents($players, 1, false, [], [], microtime(true));

        $this->assertSame([3], array_keys($intents));
    }

    public function test_respawning_bots_stay_put(): void
    {
        $brain = new BotBrain($this->layout);

        $players = [
            1 => $this->player(['role' => 'pacman', 'x' => 9, 'y' => 15, 'is_bot' => 0]),
            2 => $this->player(['x' => 9, 'y' => 7, 'respawn_until' => microtime(true) + 5]),
        ];

        $this->assertSame([], $brain->intents($players, 1, false, [], [], microtime(true)));
    }

    public function test_bots_navigate_the_large_maze_too(): void
    {
        $this->layout = Maze::large();
        [$px, $py] = $this->layout['pacman_spawn'];
        [$gx, $gy] = $this->layout['ghost_spawns'][0];

        $players = [
            1 => $this->player(['role' => 'pacman', 'x' => $px, 'y' => $py, 'is_bot' => 0]),
            2 => $this->player(['x' => $gx, 'y' => $gy]),
        ];

        $before = hypot($gx - $px, $gy - $py);
        $after = $this->simulate($players, 12);

        $this->assertLessThan($before, hypot($after[2]['x'] - $px, $after[2]['y'] - $py));
    }

    public function test_every_ghost_personality_produces_a_legal_move(): void
    {
        $brain = new BotBrain($this->layout);

        $players = [1 => $this->player(['role' => 'pacman', 'x' => 9, 'y' => 15, 'dir' => 'left', 'is_bot' => 0])];

        foreach (range(0, 8) as $slot) {
            $players[10 + $slot] = $this->player(['x' => 9, 'y' => 7, 'ghost_slot' => $slot]);
        }

        $intents = $brain->intents($players, 1, false, [], [], microtime(true));

        $this->assertCount(9, $intents);
        foreach ($intents as $pid => $dir) {
            $this->assertContains($dir, ['up', 'down', 'left', 'right'], "slot for player {$pid} produced '{$dir}'");
        }
    }
}
