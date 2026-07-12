<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('status')->default('lobby')->index();
            $table->json('maze_layout')->nullable();
            $table->string('mode')->default('classic');
            $table->unsignedTinyInteger('max_players')->default(5);
            $table->unsignedSmallInteger('match_duration_seconds')->default(300);
            $table->boolean('players_pick_roles')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('winner_role')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
