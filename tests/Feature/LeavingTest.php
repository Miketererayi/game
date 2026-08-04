<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Events\GameEnded;
use App\Game\GameLoopLauncher;
use App\Game\GameStateRepository;
use App\Livewire\Lobby;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class LeavingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(GameLoopLauncher::class)->shouldReceive('launch')->byDefault();
        Redis::connection()->flushdb();
    }

    // ---- leaving the lobby -------------------------------------------------

    public function test_leaving_the_lobby_frees_the_seat(): void
    {
        $game = Game::factory()->create();
        GamePlayer::factory()->host()->for($game)->create();
        $leaver = GamePlayer::factory()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $leaver])
            ->call('leave')
            ->assertRedirect(route('home'));

        $this->assertDatabaseMissing('game_players', ['id' => $leaver->id]);
        $this->assertSame(1, $game->players()->count());
    }

    public function test_a_departing_host_hands_over_to_someone_still_here(): void
    {
        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create();
        $other = GamePlayer::factory()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('leave');

        $this->assertTrue($other->refresh()->is_host);
    }

    public function test_hosting_never_passes_to_an_ai_player(): void
    {
        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create();
        $human = GamePlayer::factory()->for($game)->create();
        $bot = GamePlayer::factory()->bot()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('leave');

        $this->assertTrue($human->refresh()->is_host);
        $this->assertFalse($bot->refresh()->is_host);
    }

    public function test_the_last_human_leaving_closes_the_room(): void
    {
        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create();
        GamePlayer::factory()->bot()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])->call('leave');

        // Otherwise a room with only AI in it lingers in the join list with
        // nobody able to start it.
        $this->assertSame(GameStatus::Finished, $game->refresh()->status);
        $this->assertSame(0, $game->players()->count());
    }

    // ---- leaving a live match ---------------------------------------------

    public function test_leaving_mid_match_hands_the_character_to_the_ai(): void
    {
        [$game, $players] = $this->liveMatch();
        $leaver = $players[1];

        $this->withSession(['player_token' => $leaver->session_token])
            ->post(route('games.leave', $game))
            ->assertRedirect(route('home'));

        $leaver->refresh();
        $this->assertTrue($leaver->is_bot);
        // The engine reads this from Redis each tick, so it is what actually
        // puts the AI in control.
        $this->assertSame('1', Redis::connection()->hget("game:{$game->id}:player:{$leaver->id}", 'is_bot'));

        // Everyone else plays on.
        $this->assertSame(GameStatus::Active, $game->refresh()->status);
    }

    public function test_a_leaver_cannot_walk_back_into_the_character_they_left(): void
    {
        [$game, $players] = $this->liveMatch();
        $leaver = $players[1];
        $token = $leaver->session_token;

        $this->withSession(['player_token' => $token])->post(route('games.leave', $game));

        $this->assertNotSame($token, $leaver->refresh()->session_token);
    }

    public function test_the_last_human_leaving_a_match_ends_it(): void
    {
        Event::fake([GameEnded::class]);
        [$game, $players] = $this->liveMatch(humans: 1);

        $this->withSession(['player_token' => $players[0]->session_token])
            ->post(route('games.leave', $game));

        $this->assertSame(GameStatus::Finished, $game->refresh()->status);
        Event::assertDispatched(GameEnded::class);
    }

    // ---- the host ending a match ------------------------------------------

    public function test_host_can_end_a_match_early(): void
    {
        Event::fake([GameEnded::class]);
        [$game, $players] = $this->liveMatch();

        $this->withSession(['player_token' => $players[0]->session_token])
            ->post(route('games.end', $game))
            ->assertRedirect(route('games.lobby', $game));

        $game->refresh();
        $this->assertSame(GameStatus::Finished, $game->status);
        $this->assertNull($game->winner_role);

        // Flipping this is what stops the tick loop.
        $this->assertSame('finished', Redis::connection()->hget("game:{$game->id}:state", 'status'));

        Event::assertDispatched(GameEnded::class, fn ($e) => $e->reason === 'host_ended' && $e->winnerRole === 'none');
    }

    public function test_only_the_host_can_end_a_match(): void
    {
        [$game, $players] = $this->liveMatch();

        $this->withSession(['player_token' => $players[1]->session_token])
            ->post(route('games.end', $game))
            ->assertRedirect(route('games.play', $game));

        $this->assertSame(GameStatus::Active, $game->refresh()->status);
    }

    public function test_a_match_whose_tick_loop_never_ran_can_still_be_ended(): void
    {
        // The case that stranded players in production: the match is active in
        // the database but has no Redis state at all, so there is no loop to
        // notice anything and no timer to run out.
        Event::fake([GameEnded::class]);
        $game = Game::factory()->active()->create();
        $host = GamePlayer::factory()->host()->pacman()->for($game)->create();
        GamePlayer::factory()->ghost()->for($game)->create();

        $this->assertEmpty(Redis::connection()->hgetall("game:{$game->id}:state"));

        $this->withSession(['player_token' => $host->session_token])
            ->post(route('games.end', $game))
            ->assertRedirect(route('games.lobby', $game));

        $this->assertSame(GameStatus::Finished, $game->refresh()->status);
        Event::assertDispatched(GameEnded::class);

        // No state existed, so none should have been conjured up for a match
        // that is already over.
        $this->assertEmpty(Redis::connection()->hgetall("game:{$game->id}:state"));
    }

    public function test_ending_an_already_finished_match_does_nothing(): void
    {
        Event::fake([GameEnded::class]);
        $game = Game::factory()->create(['status' => GameStatus::Finished]);
        $host = GamePlayer::factory()->host()->for($game)->create();

        $this->withSession(['player_token' => $host->session_token])
            ->post(route('games.end', $game));

        Event::assertNotDispatched(GameEnded::class);
    }

    /**
     * A match that has actually been initialised in Redis.
     *
     * @return array{0: Game, 1: array<int, GamePlayer>}
     */
    private function liveMatch(int $humans = 2): array
    {
        $game = Game::factory()->active()->create();

        $players = [GamePlayer::factory()->host()->pacman()->for($game)->create()];
        for ($i = 1; $i < $humans; $i++) {
            $players[] = GamePlayer::factory()->ghost($i - 1)->for($game)->create();
        }
        $players[] = GamePlayer::factory()->bot()->ghost($humans)->for($game)->create();

        app(GameStateRepository::class)->initialize($game->refresh());

        return [$game, $players];
    }
}
