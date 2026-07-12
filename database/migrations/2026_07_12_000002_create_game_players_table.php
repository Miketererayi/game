<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('session_token', 64)->index();
            $table->string('role')->nullable();
            $table->unsignedTinyInteger('ghost_slot')->nullable();
            $table->boolean('is_host')->default(false);
            $table->boolean('is_ready')->default(false);
            $table->boolean('is_connected')->default(true);
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('caught_count')->default(0);
            $table->timestamps();

            $table->unique(['game_id', 'session_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
