<?php

namespace Tests\Feature;

use App\Enums\PlayerRole;
use App\Game\Engine;
use App\Game\GameStateRepository;
use App\Game\Maze;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private GameStateRepository $repo;

    private const DT = 1 / 15;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->flushdb();
        $this->repo = app(GameStateRepository::class);
        Event::fake();
    }

    private function startGame(): array
    {
        $game = Game::factory()->active()->create();
        GamePlayer::factory()->pacman()->for($game)->create();
        GamePlayer::factory()->ghost(0)->for($game)->create(); // speed_burst
        GamePlayer::factory()->ghost(1)->for($game)->create(); // blink
        GamePlayer::factory()->ghost(2)->for($game)->create(); // wall_drop

        $game->load('players');
        $this->repo->initialize($game);

        return [$game, new Engine($game, $this->repo)];
    }

    private function ghostBySlot(Game $game, int $slot): GamePlayer
    {
        return $game->players->first(
            fn ($p) => $p->role === PlayerRole::Ghost && $p->ghost_slot === $slot,
        );
    }

    public function test_speed_burst_sets_speed_window_and_cooldown(): void
    {
        [$game, $engine] = $this->startGame();
        $ghost = $this->ghostBySlot($game, 0);

        $this->repo->requestAbility($game->id, $ghost->id);
        $engine->tick(self::DT);

        $p = $this->repo->getPlayer($game->id, $ghost->id);
        $this->assertGreaterThan(microtime(true), (float) $p['speed_until']);
        $this->assertGreaterThan(microtime(true) + 10, (float) $p['ability_ready_at']);
    }

    public function test_ability_respects_cooldown(): void
    {
        [$game, $engine] = $this->startGame();
        $ghost = $this->ghostBySlot($game, 0);

        $this->repo->updatePlayer($game->id, $ghost->id, ['ability_ready_at' => microtime(true) + 60]);
        $this->repo->requestAbility($game->id, $ghost->id);
        $engine->tick(self::DT);

        $this->assertLessThan(microtime(true), (float) $this->repo->getPlayer($game->id, $ghost->id)['speed_until']);
    }

    public function test_pacman_cannot_use_ghost_abilities(): void
    {
        [$game, $engine] = $this->startGame();
        $pacman = $game->players->firstWhere('role', PlayerRole::Pacman);

        $this->repo->requestAbility($game->id, $pacman->id);
        $engine->tick(self::DT);

        $this->assertSame(0.0, (float) ($this->repo->getPlayer($game->id, $pacman->id)['ability_ready_at'] ?? 0));
    }

    public function test_blink_teleports_along_facing_direction_without_crossing_walls(): void
    {
        [$game, $engine] = $this->startGame();
        $ghost = $this->ghostBySlot($game, 1);

        // stand pacman-spawn row, facing left down an open corridor
        [$sx, $sy] = $game->maze_layout['pacman_spawn'];
        $this->repo->updatePlayer($game->id, $ghost->id, ['x' => $sx, 'y' => $sy, 'dir' => 'left']);
        $this->repo->requestAbility($game->id, $ghost->id);

        $engine->tick(self::DT);

        $p = $this->repo->getPlayer($game->id, $ghost->id);
        $this->assertLessThan($sx, (float) $p['x'], 'blinked left');
        $this->assertTrue(
            Maze::isWalkable($game->maze_layout, (int) round((float) $p['x']), (int) round((float) $p['y'])),
        );
    }

    public function test_wall_drop_places_temp_wall_behind_ghost(): void
    {
        [$game, $engine] = $this->startGame();
        $ghost = $this->ghostBySlot($game, 2);

        [$sx, $sy] = $game->maze_layout['pacman_spawn'];
        $this->repo->updatePlayer($game->id, $ghost->id, ['x' => $sx, 'y' => $sy, 'dir' => 'left']);
        $this->repo->requestAbility($game->id, $ghost->id);

        $engine->tick(self::DT);

        $walls = $this->repo->tempWalls($game->id);
        $behind = ($sx + 1).','.$sy;
        $this->assertArrayHasKey($behind, $walls);
        $this->assertGreaterThan(microtime(true), $walls[$behind]);
    }

    public function test_toggleable_walls_flip_after_interval(): void
    {
        [$game, $engine] = $this->startGame();

        // first tick schedules, force timer into the past, next tick flips
        $engine->tick(self::DT);
        $this->repo->updateState($game->id, ['walls_next_toggle_at' => microtime(true) - 1]);
        $engine->tick(self::DT);

        $this->assertNotEmpty($this->repo->closedWalls($game->id), 'toggleable walls closed');

        $this->repo->updateState($game->id, ['walls_next_toggle_at' => microtime(true) - 1]);
        $engine->tick(self::DT);

        $this->assertEmpty($this->repo->closedWalls($game->id), 'walls opened again');
    }
}
