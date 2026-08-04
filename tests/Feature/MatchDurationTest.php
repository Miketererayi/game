<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Game\GameLoopLauncher;
use App\Livewire\Lobby;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class MatchDurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(GameLoopLauncher::class)->shouldReceive('launch')->byDefault();
        Event::fake();
        Redis::connection()->flushdb();
    }

    public function test_host_can_choose_the_match_length(): void
    {
        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('setDuration', 600);

        $this->assertSame(600, (int) $game->refresh()->match_duration_seconds);
    }

    public function test_only_the_host_can_change_it(): void
    {
        $game = Game::factory()->create(['match_duration_seconds' => 300]);
        GamePlayer::factory()->host()->for($game)->create();
        $other = GamePlayer::factory()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $other])
            ->call('setDuration', 600);

        $this->assertSame(300, (int) $game->refresh()->match_duration_seconds);
    }

    public function test_lengths_outside_the_offered_set_are_refused(): void
    {
        $game = Game::factory()->create(['match_duration_seconds' => 300]);
        $host = GamePlayer::factory()->host()->for($game)->create();

        // The column is an unsigned smallint, so an out-of-range value would
        // otherwise be a way to break the lobby rather than a way to play.
        foreach ([0, 7, 99999, -60] as $bogus) {
            Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
                ->call('setDuration', $bogus);
        }

        $this->assertSame(300, (int) $game->refresh()->match_duration_seconds);
    }

    public function test_it_cannot_be_changed_once_the_match_is_running(): void
    {
        $game = Game::factory()->active()->create(['match_duration_seconds' => 300]);
        $host = GamePlayer::factory()->host()->for($game)->create();

        Livewire::test(Lobby::class, ['game' => $game, 'player' => $host])
            ->call('setDuration', 600);

        // The countdown is an absolute end time in Redis by then, so allowing
        // this would change a number that moves nothing.
        $this->assertSame(300, (int) $game->refresh()->match_duration_seconds);
    }

    public function test_the_chosen_length_drives_the_match_clock(): void
    {
        $game = Game::factory()->create();
        $host = GamePlayer::factory()->host()->for($game)->create(['is_ready' => true]);
        GamePlayer::factory()->count(2)->for($game)->create(['is_ready' => true]);

        $component = Livewire::test(Lobby::class, ['game' => $game, 'player' => $host]);
        $component->call('setDuration', 60);

        $before = microtime(true);
        $component->call('start');

        $this->assertSame(GameStatus::Active, $game->refresh()->status);

        $endsAt = (float) Redis::connection()->hget("game:{$game->id}:state", 'ends_at');

        // A one minute match should end about a minute out, not the 300s the
        // column defaults to.
        $this->assertEqualsWithDelta($before + 60, $endsAt, 5.0);
    }
}
