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
