<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use App\Events\GameEnded;
use App\Events\GameStateUpdated;
use App\Events\PlayerCaught;
use App\Events\PowerPelletActivated;
use App\Events\RoleRotated;
use App\Game\Engine;
use App\Game\GameStateRepository;
use App\Game\Maze;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class EngineTest extends TestCase
{
    use RefreshDatabase;

    private GameStateRepository $repo;

    private const DT = 1 / 15;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->flushdb();
        $this->repo = app(GameStateRepository::class);
    }

    private function startGame(int $ghosts = 2): array
    {
        $game = Game::factory()->active()->create();
        $pacman = GamePlayer::factory()->pacman()->for($game)->create();
        for ($i = 0; $i < $ghosts; $i++) {
            GamePlayer::factory()->ghost($i)->for($game)->create();
        }

        $game->load('players');
        $this->repo->initialize($game);

        return [$game, new Engine($game, $this->repo), $pacman];
    }

    private function runTicks(Engine $engine, int $ticks): void
    {
        for ($i = 0; $i < $ticks; $i++) {
            $engine->tick(self::DT);
        }
    }

    public function test_movement_is_wall_clamped(): void
    {
        Event::fake();
        [$game, $engine, $pacman] = $this->startGame();
        $layout = $game->maze_layout;

        // pacman spawn (9,15): left is open corridor, up is a wall (9,14 is wall? verify via layout)
        $this->repo->setInput($game->id, $pacman->id, 'left');
        $this->runTicks($engine, 30); // 2 seconds, plenty to hit something

        $state = $this->repo->getPlayer($game->id, $pacman->id);
        $x = (float) $state['x'];
        $y = (float) $state['y'];

        // never standing inside a wall tile
        $this->assertTrue(Maze::isWalkable($layout, (int) round($x), (int) round($y)));
        // actually moved
        $this->assertNotEquals($layout['pacman_spawn'], [(int) round($x), (int) round($y)]);
    }

    public function test_wall_stops_forward_progress(): void
    {
        Event::fake();
        [$game, $engine, $pacman] = $this->startGame();

        // drive pacman down into the wall below spawn row
        $this->repo->setInput($game->id, $pacman->id, 'down');
        $this->runTicks($engine, 45);

        $p = $this->repo->getPlayer($game->id, $pacman->id);
        [$sx, $sy] = $game->maze_layout['pacman_spawn'];

        // wall below spawn (9,16 is wall in classic layout) — must clamp on spawn row center
        $this->assertSame((float) $sy, (float) $p['y']);
    }

    public function test_pellets_are_eaten_and_score_counted(): void
    {
        Event::fake();
        [$game, $engine, $pacman] = $this->startGame();
        $before = $this->repo->pelletsRemaining($game->id);

        $this->repo->setInput($game->id, $pacman->id, 'left');
        $this->runTicks($engine, 30);

        $this->assertLessThan($before, $this->repo->pelletsRemaining($game->id));
        $this->assertGreaterThan(0, (int) $this->repo->getPlayer($game->id, $pacman->id)['score']);
        Event::assertDispatched(GameStateUpdated::class);
    }

    public function test_catch_without_power_pellet_rotates_roles(): void
    {
        Event::fake();
        [$game, $engine, $pacman] = $this->startGame();
        $ghost = $game->players->firstWhere('role', PlayerRole::Ghost);

        // teleport ghost onto pacman
        $spawn = $game->maze_layout['pacman_spawn'];
        $this->repo->updatePlayer($game->id, $ghost->id, ['x' => $spawn[0], 'y' => $spawn[1]]);

        $engine->tick(self::DT);

        $this->assertSame('pacman', $this->repo->getPlayer($game->id, $ghost->id)['role']);
        $this->assertSame('ghost', $this->repo->getPlayer($game->id, $pacman->id)['role']);
        $this->assertSame((string) $ghost->id, $this->repo->getState($game->id)['pacman_player_id']);

        // mirrored to MySQL
        $this->assertSame(PlayerRole::Pacman, $ghost->fresh()->role);
        $this->assertSame(PlayerRole::Ghost, $pacman->fresh()->role);
        $this->assertNotNull($pacman->fresh()->ghost_slot);

        // caught player respawns in the ghost house, briefly frozen, so the
        // pair can't just re-catch each other where they stand
        $caught = $this->repo->getPlayer($game->id, $pacman->id);
        $this->assertContains(
            [(int) $caught['x'], (int) $caught['y']],
            $game->maze_layout['ghost_spawns'],
        );
        $this->assertGreaterThan(microtime(true), (float) $caught['respawn_until']);

        Event::assertDispatched(RoleRotated::class);
        Event::assertDispatched(PlayerCaught::class, fn ($e) => $e->outcome === 'role_rotation');

        // immunity: ticking again immediately must not rotate back
        $engine->tick(self::DT);
        $this->assertSame('pacman', $this->repo->getPlayer($game->id, $ghost->id)['role']);
    }

    public function test_catch_with_power_pellet_respawns_ghost(): void
    {
        Event::fake();
        [$game, $engine, $pacman] = $this->startGame();
        $ghost = $game->players->firstWhere('role', PlayerRole::Ghost);

        $this->repo->updateState($game->id, ['power_pellet_until' => microtime(true) + 5]);
        $spawn = $game->maze_layout['pacman_spawn'];
        $this->repo->updatePlayer($game->id, $ghost->id, ['x' => $spawn[0], 'y' => $spawn[1]]);

        $engine->tick(self::DT);

        $g = $this->repo->getPlayer($game->id, $ghost->id);
        $this->assertSame('ghost', $g['role'], 'no rotation during power pellet');
        $this->assertGreaterThan(microtime(true), (float) $g['respawn_until']);
        $this->assertSame(
            $game->maze_layout['ghost_spawns'][$ghost->ghost_slot],
            [(int) $g['x'], (int) $g['y']],
        );

        Event::assertDispatched(PlayerCaught::class, fn ($e) => $e->outcome === 'ghost_respawn');
        Event::assertNotDispatched(RoleRotated::class);
    }

    public function test_eating_power_pellet_activates_power(): void
    {
        Event::fake();
        [$game, $engine, $pacman] = $this->startGame();

        [$px, $py] = $game->maze_layout['power_pellets'][0];
        $this->repo->updatePlayer($game->id, $pacman->id, ['x' => $px, 'y' => $py]);

        $engine->tick(self::DT);

        $this->assertGreaterThan(microtime(true), (float) $this->repo->getState($game->id)['power_pellet_until']);
        Event::assertDispatched(PowerPelletActivated::class);
    }

    public function test_clearing_all_pellets_ends_game_with_pacman_win(): void
    {
        Event::fake();
        [$game, $engine, $pacman] = $this->startGame();

        // leave exactly one pellet, on pacman's tile
        $spawn = $game->maze_layout['pacman_spawn'];
        Redis::connection()->del("game:{$game->id}:pellets");
        Redis::connection()->sadd("game:{$game->id}:pellets", "{$spawn[0]},{$spawn[1]}");
        $this->repo->updateState($game->id, ['pellets_remaining' => 1]);

        $this->assertFalse($engine->tick(self::DT), 'engine reports match over');

        $game->refresh();
        $this->assertSame(GameStatus::Finished, $game->status);
        $this->assertSame('pacman', $game->winner_role);
        $this->assertNotNull($game->ended_at);
        $this->assertTrue($game->events()->where('type', 'game_ended')->exists());

        Event::assertDispatched(GameEnded::class, fn ($e) => $e->reason === 'pellets_cleared');
    }

    public function test_time_expiry_ends_game_with_ghost_win(): void
    {
        Event::fake();
        [$game, $engine] = $this->startGame();

        $this->repo->updateState($game->id, ['ends_at' => microtime(true) - 1]);

        $this->assertFalse($engine->tick(self::DT));
        $this->assertSame('ghost', $game->fresh()->winner_role);

        Event::assertDispatched(GameEnded::class, fn ($e) => $e->reason === 'time_up');
    }

    public function test_two_simulated_players_produce_consistent_ticks(): void
    {
        Event::fake();
        [$game, $engine, $pacman] = $this->startGame();
        $ghost = $game->players->firstWhere('role', PlayerRole::Ghost);
        $layout = $game->maze_layout;

        $this->repo->setInput($game->id, $pacman->id, 'left');
        $this->repo->setInput($game->id, $ghost->id, 'up');

        for ($i = 0; $i < 60; $i++) {
            $engine->tick(self::DT);

            foreach ([$pacman->id, $ghost->id] as $pid) {
                $p = $this->repo->getPlayer($game->id, $pid);
                $this->assertTrue(
                    Maze::isWalkable($layout, (int) round((float) $p['x']), (int) round((float) $p['y'])),
                    "player {$pid} inside a wall on tick {$i}",
                );
            }
        }

        $this->assertSame(60, (int) $this->repo->getState($game->id)['tick']);
    }
}
