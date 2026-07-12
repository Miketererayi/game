<?php

namespace App\Providers;

use App\Models\GamePlayer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Resolves the requesting browser's GamePlayer for broadcast channel
        // auth. The game id comes from the channel being authorized, the
        // player from the session token — no user account required.
        Auth::viaRequest('game-player', function (Request $request) {
            $token = $request->session()->get('player_token');

            if (! $token || ! preg_match('/^(?:presence|private)-game(?:-play)?\.(\d+)$/', (string) $request->input('channel_name'), $matches)) {
                return null;
            }

            return GamePlayer::where('game_id', $matches[1])
                ->where('session_token', $token)
                ->first();
        });
    }
}
