<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Game\Maze;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GameFactory extends Factory
{
    protected $model = Game::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(6)),
            'status' => GameStatus::Lobby,
            'maze_layout' => Maze::classic(),
            'mode' => 'classic',
            'max_players' => 5,
            'match_duration_seconds' => 300,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status' => GameStatus::Active,
            'started_at' => now(),
        ]);
    }
}
