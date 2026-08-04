<?php

namespace Database\Factories;

use App\Enums\PlayerRole;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GamePlayerFactory extends Factory
{
    protected $model = GamePlayer::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'user_id' => null,
            'guest_name' => fake()->firstName(),
            'session_token' => Str::random(40),
            'is_ready' => false,
            'is_connected' => true,
            'score' => 0,
            'caught_count' => 0,
        ];
    }

    public function pacman(): static
    {
        return $this->state(['role' => PlayerRole::Pacman]);
    }

    public function ghost(int $slot = 0): static
    {
        return $this->state(['role' => PlayerRole::Ghost, 'ghost_slot' => $slot]);
    }

    public function host(): static
    {
        return $this->state(['is_host' => true]);
    }

    public function bot(): static
    {
        return $this->state(['is_bot' => true, 'is_ready' => true, 'guest_name' => 'CPU '.fake()->firstName()]);
    }
}
