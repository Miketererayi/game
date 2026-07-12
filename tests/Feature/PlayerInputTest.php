<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class PlayerInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
    }

    public function test_member_can_submit_direction_intent(): void
    {
        $game = Game::factory()->active()->create();
        $player = GamePlayer::factory()->pacman()->for($game)->create();

        $this->withSession(['player_token' => $player->session_token])
            ->post(route('games.input', $game), ['direction' => 'left'])
            ->assertNoContent();

        $this->assertSame('left', Redis::connection()->hget("game:{$game->id}:inputs", (string) $player->id));
    }

    public function test_invalid_direction_is_rejected(): void
    {
        $game = Game::factory()->active()->create();
        $player = GamePlayer::factory()->pacman()->for($game)->create();

        $this->withSession(['player_token' => $player->session_token])
            ->post(route('games.input', $game), ['direction' => 'diagonal'])
            ->assertSessionHasErrors('direction');
    }

    public function test_non_member_cannot_submit_input(): void
    {
        $game = Game::factory()->active()->create();
        GamePlayer::factory()->pacman()->for($game)->create();

        $this->withSession(['player_token' => 'not-a-member-token'])
            ->post(route('games.input', $game), ['direction' => 'up'])
            ->assertForbidden();
    }

    public function test_input_rejected_when_game_not_active(): void
    {
        $game = Game::factory()->create(); // lobby
        $player = GamePlayer::factory()->for($game)->create();

        $this->withSession(['player_token' => $player->session_token])
            ->post(route('games.input', $game), ['direction' => 'up'])
            ->assertStatus(409);
    }
}
