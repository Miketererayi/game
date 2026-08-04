<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Game\MatchEnder;
use App\Game\PlayerDeparture;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GameController extends Controller
{
    public function home(): View
    {
        return view('home');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:20'],
        ]);

        $game = Game::create([
            'code' => Game::generateCode(),
            'status' => GameStatus::Lobby,
        ]);

        $game->players()->create([
            'guest_name' => $validated['name'],
            'session_token' => $request->session()->get('player_token'),
            'is_host' => true,
        ]);

        return redirect()->route('games.lobby', $game);
    }

    public function join(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string'],
        ]);

        $game = Game::where('code', strtoupper(trim($validated['code'])))->first();

        if (! $game) {
            return back()->withErrors(['code' => 'No game found with that code.'])->withInput();
        }

        $token = $request->session()->get('player_token');
        $existing = $game->players()->where('session_token', $token)->first();

        if ($existing) {
            return redirect()->route(
                $game->status === GameStatus::Active ? 'games.play' : 'games.lobby',
                $game,
            );
        }

        if (! $game->isJoinable()) {
            return back()->withErrors(['code' => 'That game is full or already started.'])->withInput();
        }

        $game->players()->create([
            'guest_name' => $validated['name'],
            'session_token' => $token,
        ]);

        return redirect()->route('games.lobby', $game);
    }

    public function lobby(Request $request, Game $game): View|RedirectResponse
    {
        $player = $this->currentPlayer($request, $game);

        if (! $player) {
            return redirect()->route('home')->withErrors(['code' => 'Join the game first.']);
        }

        if ($game->status === GameStatus::Active) {
            return redirect()->route('games.play', $game);
        }

        return view('lobby', ['game' => $game, 'player' => $player]);
    }

    public function play(Request $request, Game $game): View|RedirectResponse
    {
        $player = $this->currentPlayer($request, $game);

        if (! $player) {
            return redirect()->route('home')->withErrors(['code' => 'Join the game first.']);
        }

        // The lobby doubles as the team room between rounds: it's where the
        // results and the rematch button live.
        if ($game->status !== GameStatus::Active) {
            return redirect()->route('games.lobby', $game);
        }

        return view('play', ['game' => $game, 'player' => $player]);
    }

    /**
     * Walk away from a game. In the lobby that frees the seat; mid-match the
     * character is handed to the AI so the others can play on.
     */
    public function leave(Request $request, Game $game, PlayerDeparture $departure): RedirectResponse
    {
        $player = $this->currentPlayer($request, $game);

        if ($player) {
            $departure->depart($game, $player);
        }

        return redirect()->route('home')->with('status', 'You left the game.');
    }

    /**
     * Host-only early finish. Deliberately does not require a live tick loop:
     * the matches most in need of ending are the ones whose loop is gone.
     */
    public function endMatch(Request $request, Game $game, MatchEnder $ender): RedirectResponse
    {
        $player = $this->currentPlayer($request, $game);

        if (! $player) {
            return redirect()->route('home')->withErrors(['code' => 'Join the game first.']);
        }

        if (! $player->is_host) {
            return redirect()->route('games.play', $game)
                ->withErrors(['end' => 'Only the host can end the match.']);
        }

        $ender->end($game, 'host_ended');

        return redirect()->route('games.lobby', $game);
    }

    private function currentPlayer(Request $request, Game $game)
    {
        return $game->players()
            ->where('session_token', $request->session()->get('player_token'))
            ->first();
    }
}
