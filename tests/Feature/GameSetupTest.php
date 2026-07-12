<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use App\Game\Maze;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertCount(4, $game->maze_layout['ghost_spawns']);
    }

    public function test_maze_is_fully_connected(): void
    {
        $layout = Maze::classic();
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
}
