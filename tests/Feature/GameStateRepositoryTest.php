<?php

namespace Tests\Feature;

use App\Game\GameStateRepository;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class GameStateRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private GameStateRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        Redis::connection()->flushdb();
        $this->repo = app(GameStateRepository::class);
    }

    private function makeStartedGame(): Game
    {
        $game = Game::factory()->active()->create();
        GamePlayer::factory()->pacman()->for($game)->create();
        GamePlayer::factory()->ghost(0)->for($game)->create();
        GamePlayer::factory()->ghost(1)->for($game)->create();

        return $game->load('players');
    }

    public function test_initialize_seeds_full_state(): void
    {
        $game = $this->makeStartedGame();
        $this->repo->initialize($game);

        $state = $this->repo->getState($game->id);
        $this->assertSame('active', $state['status']);
        $this->assertSame(count($game->maze_layout['pellets']), (int) $state['pellets_remaining']);
        $this->assertSame($game->pacman()->id, (int) $state['pacman_player_id']);

        $players = $this->repo->getPlayers($game->id);
        $this->assertCount(3, $players);

        $pacman = $players[$game->pacman()->id];
        $this->assertSame($game->maze_layout['pacman_spawn'], [(int) $pacman['x'], (int) $pacman['y']]);
        $this->assertSame('pacman', $pacman['role']);
    }

    public function test_player_state_round_trips(): void
    {
        $game = $this->makeStartedGame();
        $this->repo->initialize($game);
        $pid = $game->players->first()->id;

        $this->repo->updatePlayer($game->id, $pid, ['x' => 3.25, 'y' => 7.0, 'dir' => 'left']);

        $player = $this->repo->getPlayer($game->id, $pid);
        $this->assertSame(3.25, (float) $player['x']);
        $this->assertSame(7.0, (float) $player['y']);
        $this->assertSame('left', $player['dir']);
    }

    public function test_inputs_round_trip(): void
    {
        $game = $this->makeStartedGame();
        $pid = $game->players->first()->id;

        $this->repo->setInput($game->id, $pid, 'up');

        $this->assertSame([$pid => 'up'], $this->repo->getInputs($game->id));
    }

    public function test_pellets_are_consumed_exactly_once(): void
    {
        $game = $this->makeStartedGame();
        $this->repo->initialize($game);
        [$x, $y] = $game->maze_layout['pellets'][0];

        $before = $this->repo->pelletsRemaining($game->id);
        $this->assertTrue($this->repo->eatPellet($game->id, $x, $y));
        $this->assertFalse($this->repo->eatPellet($game->id, $x, $y), 'second eat must fail');
        $this->assertSame($before - 1, $this->repo->pelletsRemaining($game->id));
    }

    public function test_toggleable_walls_round_trip(): void
    {
        $game = $this->makeStartedGame();

        $this->repo->setWallClosed($game->id, 9, 6, true);
        $this->assertSame(['9,6' => true], $this->repo->closedWalls($game->id));

        $this->repo->setWallClosed($game->id, 9, 6, false);
        $this->assertSame([], $this->repo->closedWalls($game->id));
    }
}
