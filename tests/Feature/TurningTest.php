<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Game\Engine;
use App\Game\GameStateRepository;
use App\Game\Maze;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Turning perpendicular to travel, exercised through the whole path: HTTP
 * intent -> Redis -> engine. The suite previously only ever submitted 'left'
 * and 'up', so a direction-specific fault could not have been caught.
 */
class TurningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Redis::connection()->flushdb();
    }

    #[DataProvider('directions')]
    public function test_a_turn_is_taken_from_a_horizontal_corridor(string $dir): void
    {
        $layout = Maze::classic();
        [$x, $y] = $this->openingIn($layout, $dir);

        [$game, $player] = $this->match($layout);

        // Running right, sitting exactly on the tile that has the opening.
        $this->repo()->updatePlayer($game->id, $player->id, [
            'x' => $x, 'y' => $y, 'dir' => 'right', 'respawn_until' => 0,
        ]);

        $this->withSession(['player_token' => $player->session_token])
            ->post(route('games.input', $game), ['direction' => $dir])
            ->assertNoContent();

        $this->assertSame($dir, Redis::connection()->hget("game:{$game->id}:inputs", (string) $player->id));

        (new Engine($game->refresh(), $this->repo()))->tick(1 / 15);

        $this->assertSame(
            $dir,
            $this->repo()->getPlayer($game->id, $player->id)['dir'],
            "a '{$dir}' intent at an opening should have been taken",
        );
    }

    public static function directions(): array
    {
        return ['up' => ['up'], 'down' => ['down'], 'left' => ['left'], 'right' => ['right']];
    }

    public function test_a_turn_is_still_taken_when_approaching_off_centre(): void
    {
        $layout = Maze::classic();
        [$x, $y] = $this->openingIn($layout, 'down');
        [$game, $player] = $this->match($layout);

        // Arrive from the left rather than landing exactly on the tile: the
        // real case, since positions advance by ~0.28 tiles a tick.
        $this->repo()->updatePlayer($game->id, $player->id, [
            'x' => $x - 0.7, 'y' => $y, 'dir' => 'right', 'respawn_until' => 0,
        ]);
        $this->repo()->setInput($game->id, $player->id, 'down');

        $engine = new Engine($game->refresh(), $this->repo());
        $turned = false;
        for ($i = 0; $i < 10 && ! $turned; $i++) {
            $engine->tick(1 / 15);
            $turned = $this->repo()->getPlayer($game->id, $player->id)['dir'] === 'down';
        }

        $this->assertTrue($turned, 'a queued down intent was never taken while crossing the opening');
    }

    private function repo(): GameStateRepository
    {
        return app(GameStateRepository::class);
    }

    /** @return array{0: Game, 1: GamePlayer} */
    private function match(array $layout): array
    {
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'maze_layout' => $layout,
            'started_at' => now(),
        ]);
        $player = GamePlayer::factory()->host()->pacman()->for($game)->create();
        GamePlayer::factory()->bot()->ghost()->for($game)->create();

        $this->repo()->initialize($game->refresh());
        $this->repo()->updateState($game->id, [
            'pacman_player_id' => $player->id,
            'ends_at' => microtime(true) + 300,
        ]);

        return [$game, $player];
    }

    /** A cell in a horizontal corridor that has an opening in $dir. */
    private function openingIn(array $layout, string $dir): array
    {
        [$dx, $dy] = match ($dir) {
            'up' => [0, -1], 'down' => [0, 1], 'left' => [-1, 0], 'right' => [1, 0],
        };

        for ($y = 1; $y < $layout['height'] - 1; $y++) {
            for ($x = 1; $x < $layout['width'] - 1; $x++) {
                if (Maze::isWalkable($layout, $x, $y, [])
                    && Maze::isWalkable($layout, $x - 1, $y, [])
                    && Maze::isWalkable($layout, $x + 1, $y, [])
                    && Maze::isWalkable($layout, $x + $dx, $y + $dy, [])) {
                    return [$x, $y];
                }
            }
        }

        $this->fail("no corridor cell with a '{$dir}' opening in this maze");
    }
}
