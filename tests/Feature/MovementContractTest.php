<?php

namespace Tests\Feature;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The browser predicts the local player using the same movement rule the
 * engine runs. If the two ever disagree the character rubber-bands on every
 * correction — so the rule has one home, and the page must hand it over.
 */
class MovementContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_play_page_hands_the_movement_rule_to_the_client(): void
    {
        $game = Game::factory()->active()->create();
        $player = GamePlayer::factory()->host()->pacman()->for($game)->create();

        $html = $this->withSession(['player_token' => $player->session_token])
            ->get(route('games.play', $game))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-movement=', $html);

        preg_match('/data-movement="([^"]*)"/', $html, $m);
        $movement = json_decode(html_entity_decode($m[1] ?? ''), true);

        foreach (['tick_rate', 'pacman_speed', 'pacman_power_speed', 'ghost_speed', 'ghost_boost_multiplier', 'turn_tolerance'] as $key) {
            $this->assertArrayHasKey($key, $movement ?? [], "the client cannot predict without '{$key}'");
            $this->assertGreaterThan(0, $movement[$key]);
        }
    }

    public function test_the_config_carries_every_value_the_engine_needs(): void
    {
        // Engine reads these on construction; a missing key would only surface
        // as a broken match, not a failed deploy.
        foreach (['tick_rate', 'pacman_speed', 'pacman_power_speed', 'ghost_speed', 'ghost_boost_multiplier', 'turn_tolerance'] as $key) {
            $this->assertIsNumeric(config("game.movement.{$key}"), "config game.movement.{$key} is missing");
        }
    }

    public function test_a_ghost_speed_burst_is_visible_to_the_client(): void
    {
        // Prediction has to know about a burst to predict it at the right pace.
        $game = Game::factory()->active()->create();
        $player = GamePlayer::factory()->host()->ghost()->for($game)->create();

        $this->assertSame(GameStatus::Active, $game->status);

        $reflection = new \ReflectionMethod(\App\Game\Engine::class, 'presentPlayers');
        $engine = new \App\Game\Engine($game, app(\App\Game\GameStateRepository::class));

        $presented = $reflection->invoke($engine, [
            $player->id => [
                'x' => 1, 'y' => 1, 'dir' => 'up', 'role' => 'ghost', 'ghost_slot' => 0,
                'respawn_until' => 0, 'speed_until' => 1234.5, 'name' => 'G',
            ],
        ]);

        $this->assertSame(1234.5, $presented[$player->id]['speed_until']);
    }
}
