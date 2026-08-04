<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // 1 Pac-Man + up to 9 ghosts; the maze now has a spawn for each.
            $table->unsignedTinyInteger('max_players')->default(10)->change();
        });

        Schema::table('game_players', function (Blueprint $table) {
            $table->boolean('is_bot')->default(false)->after('is_host');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_players')->default(5)->change();
        });

        Schema::table('game_players', function (Blueprint $table) {
            $table->dropColumn('is_bot');
        });
    }
};
