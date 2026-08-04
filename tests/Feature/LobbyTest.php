<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use App\Events\GameStarted;
use App\Game\GameLoopLauncher;
use App\Livewire\Lobby;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class LobbyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Never spawn real tick processes from tests.
        $this->mock(GameLoopLauncher::class)->shouldReceive('launch')->byDefault();
    }

    public function test_creating_a_game_makes_the_creator_host(): void
    {
        $response = $this->post(route('games.store'), ['name' => 'Michael']);

        $game = Game::sole();
        $response->assertRedirect(route('games.lobby', $game));

        $host = $game->players()->sole();
        $this->assertTrue($host->is_host);
        $this->assertSame('Michael', $host->guest_name);
    }

    public function test_joining_by_code_adds_a_player(): void
    {
        $game = Game::factory()->create();
        GamePlayer::factory()->host()->for($game)->create();

        $this->post(route('games.join'), ['name' => 'Ghost1', 'code' => strtolower($game->code)])
            ->assertRedirect(route('games.lobby', $game));

        $this->assertSame(2, $game->players()->count());
    }

    public function test_joining_a_started_game_is_rejected_for_new_players(): void
    {
        $game = Game::factory()->active()->create();

        $this->post(route('games.join'), ['name' => 'Late', 'code' => $game->code])
            ->assertSessionHasErrors('code');
    }

    public function test_lobby_page_requires_membership(): void
    {
        $game = Game::factory()->create();

        $this->get(route('games.lobby', $game))->assertRedirect(route('home'));
    }

    public function test_host_can_start_when_three_players_ready(): void
    {
        Event::fake([GameStarted::class]);
        Redis::connection()->flushdb();

        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->count(2)->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('start')
            ->assertRedirect(route('games.play', $game));

        $game->refresh();
        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertNotNull($game->started_at);
        $this->assertCount(1, $game->players->where('role', PlayerRole::Pacman));
        $this->assertCount(2, $game->players->where('role', PlayerRole::Ghost));

        // Redis state was initialized
        $state = Redis::connection()->hgetall("game:{$game->id}:state");
        $this->assertSame('active', $state['status']);
        $this->assertGreaterThan(0, (int) $state['pellets_remaining']);

        Event::assertDispatched(GameStarted::class);
    }

    public function test_start_requires_three_ready_players(): void
    {
        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('start')
            ->assertHasErrors('start');

        $this->assertSame(GameStatus::Lobby, $game->fresh()->status);
    }

    public function test_host_can_add_and_remove_ai_players(): void
    {
        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create();

        $component = Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('addBot')
            ->call('addBot');

        $bots = $game->players()->where('is_bot', true)->get();
        $this->assertCount(2, $bots);
        $this->assertTrue($bots->every->is_ready, 'AI players are always ready');
        $this->assertSame(2, $bots->pluck('guest_name')->unique()->count(), 'AI players get distinct names');

        $component->call('removeBot', $bots->first()->id);

        $this->assertSame(1, $game->players()->where('is_bot', true)->count());
    }

    public function test_only_the_host_manages_ai_players(): void
    {
        $game = Game::factory()->create();
        GamePlayer::factory()->host()->for($game)->create();
        $guest = GamePlayer::factory()->for($game)->create();
        $bot = GamePlayer::factory()->bot()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $guest])
            ->call('addBot')
            ->call('removeBot', $bot->id);

        $this->assertSame(1, $game->players()->where('is_bot', true)->count());
    }

    public function test_ai_players_cannot_exceed_the_lobby_size(): void
    {
        $game = Game::factory()->create(['max_players' => 3]);
        $host = GamePlayer::factory()->host()->for($game)->create();

        $component = Livewire::test(Lobby::class, ['game' => $game, 'player' => $host]);
        foreach (range(1, 5) as $ignored) {
            $component->call('addBot');
        }

        $this->assertSame(3, $game->players()->count());
    }

    public function test_one_human_plus_two_ai_players_can_start_a_solo_match(): void
    {
        Event::fake([GameStarted::class]);
        Redis::connection()->flushdb();

        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->bot()->count(2)->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('start')
            ->assertRedirect(route('games.play', $game));

        $this->assertSame(GameStatus::Active, $game->fresh()->status);

        // Bots must be flagged in redis or the engine won't drive them.
        $botId = $game->players()->where('is_bot', true)->value('id');
        $this->assertSame('1', Redis::connection()->hget("game:{$game->id}:player:{$botId}", 'is_bot'));
    }

    public function test_a_ten_player_lobby_gets_a_spawn_and_colour_each(): void
    {
        Event::fake([GameStarted::class]);
        Redis::connection()->flushdb();

        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->count(9)->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('start');

        $game->refresh();
        $ghosts = $game->players->where('role', PlayerRole::Ghost);

        $this->assertCount(9, $ghosts);
        $this->assertCount(9, $ghosts->pluck('ghost_slot')->unique(), 'every ghost needs its own colour');
        $this->assertLessThan(count(config('sprites.ghosts')), $ghosts->max('ghost_slot'));
    }

    public function test_a_six_player_match_starts_on_the_bigger_maze(): void
    {
        Event::fake([GameStarted::class]);
        Redis::connection()->flushdb();

        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->count(5)->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('start');

        $this->assertSame(\App\Game\Maze::large()['width'], $game->fresh()->maze_layout['width']);
    }

    public function test_a_five_player_match_stays_on_the_classic_maze(): void
    {
        Event::fake([GameStarted::class]);
        Redis::connection()->flushdb();

        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->count(4)->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('start');

        $this->assertSame(\App\Game\Maze::classic()['width'], $game->fresh()->maze_layout['width']);
    }

    public function test_players_can_claim_a_specific_ghost_colour(): void
    {
        $game = Game::factory()->create(['players_pick_roles' => true]);
        $host = GamePlayer::factory()->host()->for($game)->create();
        $guest = GamePlayer::factory()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('pickRole', 'ghost', 5);
        $this->assertSame(5, $host->fresh()->ghost_slot);

        // Taken colours can't be stolen; free ones can be claimed.
        Livewire::test(Lobby::class, ['game' => $game, 'player' => $guest])->call('pickRole', 'ghost', 5);
        $this->assertNull($guest->fresh()->ghost_slot);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $guest])->call('pickRole', 'ghost', 6);
        $this->assertSame(6, $guest->fresh()->ghost_slot);
    }

    public function test_ghost_colours_outside_the_palette_are_rejected(): void
    {
        $game = Game::factory()->create(['players_pick_roles' => true]);
        $host = GamePlayer::factory()->host()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('pickRole', 'ghost', 99)
            ->call('pickRole', 'ghost', -1);

        $this->assertNull($host->fresh()->ghost_slot);
    }

    /** Records a finished round the way Engine::finish() does. */
    private function recordRound(Game $game, string $winnerRole, array $scores, string $reason = 'time_up'): void
    {
        $game->events()->create([
            'type' => 'game_ended',
            'payload' => ['winner_role' => $winnerRole, 'reason' => $reason, 'scores' => $scores],
        ]);
    }

    public function test_a_finished_game_reports_the_last_round_and_session_standings(): void
    {
        $game = Game::factory()->create(['status' => GameStatus::Finished]);

        $this->recordRound($game, 'ghost', [
            ['id' => 1, 'name' => 'Ama', 'role' => 'pacman', 'score' => 300, 'caught_count' => 0],
            ['id' => 2, 'name' => 'Ben', 'role' => 'ghost', 'score' => 100, 'caught_count' => 1],
        ]);
        $this->recordRound($game, 'pacman', [
            ['id' => 1, 'name' => 'Ama', 'role' => 'ghost', 'score' => 50, 'caught_count' => 0],
            ['id' => 2, 'name' => 'Ben', 'role' => 'pacman', 'score' => 700, 'caught_count' => 2],
        ], 'pellets_cleared');

        $this->assertSame(2, $game->roundNumber());
        $this->assertSame('pacman', $game->lastResult()['winner_role']);
        $this->assertSame('pellets_cleared', $game->lastResult()['reason']);

        // Ben won both rounds: as a ghost, then as Pac-Man.
        $standings = $game->standings();
        $this->assertSame('Ben', $standings[0]['name']);
        $this->assertSame(2, $standings[0]['wins']);
        $this->assertSame(800, $standings[0]['score']);
        $this->assertSame(3, $standings[0]['caught']);
        $this->assertSame(0, $standings[1]['wins']);
    }

    public function test_host_can_start_another_round_with_the_same_team(): void
    {
        $game = Game::factory()->create(['status' => GameStatus::Finished, 'winner_role' => 'ghost', 'ended_at' => now()]);
        $host = GamePlayer::factory()->host()->pacman()->for($game)->create(['is_ready' => true, 'score' => 420]);
        $guest = GamePlayer::factory()->ghost(2)->for($game)->create(['is_ready' => true, 'score' => 130]);
        $bot = GamePlayer::factory()->bot()->ghost(1)->for($game)->create();
        $this->recordRound($game, 'ghost', [['id' => $host->id, 'name' => 'Host', 'role' => 'pacman', 'score' => 420, 'caught_count' => 0]]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('rematch');

        $game->refresh();
        $this->assertSame(GameStatus::Lobby, $game->status);
        $this->assertNull($game->winner_role);
        $this->assertNull($game->ended_at);

        // Same code, same team — nobody is booted out.
        $this->assertSame(3, $game->players()->count());

        $this->assertFalse($host->fresh()->is_ready);
        $this->assertNull($guest->fresh()->role);
        $this->assertNull($guest->fresh()->ghost_slot);
        $this->assertSame(0, $guest->fresh()->score);
        $this->assertTrue($bot->fresh()->is_ready, 'AI players stay ready for the next round');

        // The round just played is still on record for the standings.
        $this->assertCount(1, $game->rounds());
        $this->assertSame(2, $game->roundNumber());
    }

    public function test_only_the_host_can_start_another_round(): void
    {
        $game = Game::factory()->create(['status' => GameStatus::Finished]);
        GamePlayer::factory()->host()->for($game)->create();
        $guest = GamePlayer::factory()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $guest])->call('rematch');

        $this->assertSame(GameStatus::Finished, $game->fresh()->status);
    }

    public function test_rematch_does_nothing_to_a_game_still_being_played(): void
    {
        $game = Game::factory()->active()->create();
        $host = GamePlayer::factory()->host()->pacman()->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('rematch');

        $game->refresh();
        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertTrue($host->fresh()->is_ready);
    }

    public function test_finished_matches_send_players_to_the_team_room_not_the_front_page(): void
    {
        $game = Game::factory()->create(['status' => GameStatus::Finished]);
        $token = 'session-token-for-play';
        GamePlayer::factory()->for($game)->create(['session_token' => $token]);

        $this->withSession(['player_token' => $token])
            ->get(route('games.play', $game))
            ->assertRedirect(route('games.lobby', $game));
    }

    public function test_latecomers_can_join_between_rounds(): void
    {
        $game = Game::factory()->create(['status' => GameStatus::Finished]);
        GamePlayer::factory()->host()->for($game)->create();

        $this->post(route('games.join'), ['name' => 'Latecomer', 'code' => $game->code])
            ->assertRedirect(route('games.lobby', $game));

        $this->assertSame(2, $game->players()->count());
    }

    public function test_non_host_cannot_start(): void
    {
        $game = Game::factory()->create();
        GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        $guest = GamePlayer::factory()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->for($game)->create(['is_ready' => true]);

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $guest])
            ->call('start');

        $this->assertSame(GameStatus::Lobby, $game->fresh()->status);
    }
}
