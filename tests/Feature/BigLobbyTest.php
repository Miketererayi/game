<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Game\GameLoopLauncher;
use App\Game\GameStateRepository;
use App\Game\Maze;
use App\Game\MazeGenerator;
use App\Livewire\Lobby;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class BigLobbyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(GameLoopLauncher::class)->shouldReceive('launch')->byDefault();
        Event::fake();
        Redis::connection()->flushdb();
    }

    // ---- the host control --------------------------------------------------

    public function test_host_can_open_the_room_up(): void
    {
        $game = Game::factory()->create(['max_players' => 10]);
        $host = GamePlayer::factory()->host()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('setLobbySize', 15);

        $this->assertSame(15, (int) $game->refresh()->max_players);
    }

    public function test_only_the_host_can_resize_the_room(): void
    {
        $game = Game::factory()->create(['max_players' => 10]);
        GamePlayer::factory()->host()->for($game)->create();
        $other = GamePlayer::factory()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $other])
            ->call('setLobbySize', 15);

        $this->assertSame(10, (int) $game->refresh()->max_players);
    }

    public function test_sizes_outside_the_offered_set_are_refused(): void
    {
        $game = Game::factory()->create(['max_players' => 10]);
        $host = GamePlayer::factory()->host()->for($game)->create();

        foreach ([0, 1, 7, 200, -5] as $bogus) {
            Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
                ->call('setLobbySize', $bogus);
        }

        $this->assertSame(10, (int) $game->refresh()->max_players);
    }

    public function test_the_room_cannot_shrink_below_the_people_in_it(): void
    {
        $game = Game::factory()->create(['max_players' => 15]);
        $host = GamePlayer::factory()->host()->for($game)->create();
        GamePlayer::factory()->count(11)->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('setLobbySize', 8)
            ->assertHasErrors('start');

        // Otherwise the lobby sits over capacity with no rule for who leaves.
        $this->assertSame(15, (int) $game->refresh()->max_players);
    }

    // ---- the board -------------------------------------------------------

    public function test_a_big_lobby_gets_a_bigger_maze(): void
    {
        $small = MazeGenerator::forPlayers(4)->layout();
        $large = MazeGenerator::forPlayers(8)->layout();
        $huge = MazeGenerator::forPlayers(15)->layout();

        $this->assertSame(19, $small['width']);
        $this->assertSame(25, $large['width']);

        // 31 rather than 25 also proves generation did not quietly fall back
        // to the hand-authored board, which is 25 wide.
        $this->assertSame(31, $huge['width']);
        $this->assertSame(27, $huge['height']);
    }

    public function test_fifteen_players_each_get_their_own_spawn_tile(): void
    {
        $layout = MazeGenerator::forPlayers(15)->layout();
        $spawns = $layout['ghost_spawns'];

        // Fourteen ghosts. Fewer tiles than that and players start stacked.
        $this->assertGreaterThanOrEqual(14, count($spawns));
        $this->assertSame(count($spawns), count(array_unique(array_map('json_encode', $spawns))));
    }

    public function test_a_normal_lobby_still_gets_the_board_it_always_did(): void
    {
        $layout = MazeGenerator::forPlayers(10)->layout();

        $this->assertSame(25, $layout['width']);
        $this->assertSame(23, $layout['height']);
        $this->assertGreaterThanOrEqual(9, count($layout['ghost_spawns']));
    }

    public function test_the_big_board_is_still_fair_to_walk(): void
    {
        // The generator's own rules — fully connected, no dead ends — matter
        // more on a board this size, where a cul-de-sac is a free catch.
        $generator = MazeGenerator::forPlayers(15);

        for ($seed = 1; $seed <= 5; $seed++) {
            $this->assertTrue(
                $generator->isValid($generator->build($seed)),
                "the 15-player board failed its own validation on seed {$seed}",
            );
        }
    }

    // ---- a real fifteen-player match ---------------------------------------

    public function test_a_fifteen_player_match_starts_and_seats_everyone(): void
    {
        $game = Game::factory()->create(['max_players' => 15]);
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->count(14)->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('start')
            ->assertRedirect(route('games.play', $game));

        $game->refresh();
        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(31, $game->maze_layout['width']);

        // Exactly one Pac-Man, everyone else a ghost with a slot of their own.
        $this->assertCount(1, $game->players->where('role', \App\Enums\PlayerRole::Pacman));
        $ghosts = $game->players->where('role', \App\Enums\PlayerRole::Ghost);
        $this->assertCount(14, $ghosts);
        $this->assertCount(14, $ghosts->pluck('ghost_slot')->unique());

        // And all fifteen made it into the live state.
        $this->assertCount(15, app(GameStateRepository::class)->getPlayers($game->id));
    }

    public function test_nobody_starts_stacked_on_anybody_else(): void
    {
        $game = Game::factory()->create(['max_players' => 15]);
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->count(14)->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('start');

        $positions = collect(app(GameStateRepository::class)->getPlayers($game->refresh()->id))
            ->map(fn ($p) => $p['x'].','.$p['y']);

        $this->assertSame(
            $positions->count(),
            $positions->unique()->count(),
            'two players were spawned on the same tile',
        );
    }

    public function test_the_huge_tier_only_kicks_in_when_it_is_needed(): void
    {
        $this->assertSame(11, Maze::HUGE_MAZE_FROM_PLAYERS);
        $this->assertSame(25, MazeGenerator::forPlayers(10)->layout()['width']);
        $this->assertSame(31, MazeGenerator::forPlayers(11)->layout()['width']);
    }
}
