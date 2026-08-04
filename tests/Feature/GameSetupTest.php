<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use App\Game\Maze;
use App\Game\MazeGenerator;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_with_three_players_gets_valid_roles_assigned(): void
    {
        $game = Game::factory()->create();
        GamePlayer::factory()->count(3)->for($game)->create();

        $game->assignRoles();

        $players = $game->players;
        $this->assertCount(3, $players);
        $this->assertCount(1, $players->where('role', PlayerRole::Pacman));

        $ghosts = $players->where('role', PlayerRole::Ghost);
        $this->assertCount(2, $ghosts);
        $this->assertSame(
            $ghosts->count(),
            $ghosts->pluck('ghost_slot')->unique()->count(),
            'ghost slots must be distinct',
        );
    }

    public function test_role_assignment_respects_pre_picked_roles(): void
    {
        $game = Game::factory()->create();
        $picked = GamePlayer::factory()->pacman()->for($game)->create();
        GamePlayer::factory()->count(2)->for($game)->create();

        $game->assignRoles();

        $this->assertSame(PlayerRole::Pacman, $picked->fresh()->role);
        $this->assertCount(2, $game->players->where('role', PlayerRole::Ghost));
    }

    public function test_game_has_relationships_and_maze_layout(): void
    {
        $game = Game::factory()->create();
        GamePlayer::factory()->for($game)->create();
        $game->events()->create(['type' => 'pellet_cleared', 'payload' => ['x' => 1, 'y' => 1]]);

        $this->assertSame(GameStatus::Lobby, $game->status);
        $this->assertCount(1, $game->players);
        $this->assertCount(1, $game->events);
        $this->assertSame(19, $game->maze_layout['width']);
        $this->assertNotEmpty($game->maze_layout['pellets']);
        $this->assertNotNull($game->maze_layout['pacman_spawn']);
        // One spawn per ghost seat, so a full 10-player lobby never doubles up.
        $this->assertCount($game->max_players - 1, $game->maze_layout['ghost_spawns']);
    }

    public function test_big_lobbies_get_a_bigger_maze(): void
    {
        $small = MazeGenerator::forPlayers(5)->layout();
        $big = MazeGenerator::forPlayers(6)->layout();

        $this->assertSame([19, 21], [$small['width'], $small['height']]);
        $this->assertSame([25, 23], [$big['width'], $big['height']]);
        $this->assertGreaterThan(count($small['pellets']), count($big['pellets']));

        // A full lobby needs a spawn each, plus a Pac-Man start.
        $this->assertGreaterThanOrEqual(9, count($big['ghost_spawns']));
        $this->assertNotNull($big['pacman_spawn']);
    }

    public function test_every_generated_maze_is_playable(): void
    {
        foreach ([[19, 21], [25, 23]] as [$w, $h]) {
            $generator = new MazeGenerator($w, $h);

            // Sweep seeds rather than trusting one lucky roll: a bad maze
            // reaching a match would strand players or trap them in a pocket.
            foreach (range(1, 30) as $seed) {
                $rows = $generator->build($seed);

                $this->assertTrue($generator->isValid($rows), "{$w}x{$h} seed {$seed} produced an invalid maze:\n".implode("\n", $rows));
            }
        }
    }

    public function test_each_match_gets_a_different_maze(): void
    {
        $generator = new MazeGenerator;

        $layouts = collect(range(1, 8))->map(fn () => json_encode($generator->layout()));

        $this->assertGreaterThan(1, $layouts->unique()->count(), 'mazes should vary between matches');
    }

    public function test_generation_is_reproducible_from_its_seed(): void
    {
        $generator = new MazeGenerator;

        $this->assertSame($generator->build(4242), $generator->build(4242));
        $this->assertNotSame($generator->build(4242), $generator->build(4243));
    }

    #[DataProvider('mazes')]
    public function test_maze_is_fully_connected(string $maze): void
    {
        $layout = Maze::$maze();
        [$sx, $sy] = $layout['pacman_spawn'];

        $seen = ["{$sx},{$sy}" => true];
        $queue = [[$sx, $sy]];
        while ($queue) {
            [$x, $y] = array_shift($queue);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if (! isset($seen["{$nx},{$ny}"]) && Maze::isWalkable($layout, $nx, $ny)) {
                    $seen["{$nx},{$ny}"] = true;
                    $queue[] = [$nx, $ny];
                }
            }
        }

        foreach (array_merge($layout['pellets'], $layout['power_pellets'], $layout['ghost_spawns']) as [$x, $y]) {
            $this->assertArrayHasKey("{$x},{$y}", $seen, "tile {$x},{$y} unreachable");
        }
    }

    /**
     * A dead end is a guaranteed catch with no counterplay — a ghost only has
     * to follow you in. Checked on pellet tiles, which is everywhere a player
     * has a reason to run: the classic maze's few one-exit tiles are corners
     * inside the ghost house and pockets sealed off from the board entirely,
     * neither of which anyone chases through.
     */
    #[DataProvider('mazes')]
    public function test_no_pellet_sits_in_a_dead_end(string $maze): void
    {
        $layout = Maze::$maze();
        $deadEnds = [];

        foreach (array_merge($layout['pellets'], $layout['power_pellets']) as [$x, $y]) {
            $exits = 0;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                // Wrap horizontally, the way the tunnels do.
                $nx = ($x + $dx + $layout['width']) % $layout['width'];
                if (Maze::isWalkable($layout, $nx, $y + $dy)) {
                    $exits++;
                }
            }

            if ($exits < 2) {
                $deadEnds[] = "{$x},{$y}";
            }
        }

        $this->assertSame([], $deadEnds, 'pellets in dead ends: '.implode(' ', $deadEnds));
    }

    public static function mazes(): array
    {
        return [
            'classic' => ['classic'],
            'large' => ['large'],
        ];
    }
}
