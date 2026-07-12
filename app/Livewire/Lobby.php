<?php

namespace App\Livewire;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use App\Events\GameStarted;
use App\Events\LobbyUpdated;
use App\Game\GameLoopLauncher;
use App\Game\GameStateRepository;
use App\Game\Maze;
use App\Models\Game;
use App\Models\GamePlayer;
use Livewire\Component;

class Lobby extends Component
{
    public Game $game;

    public int $playerId;

    public const MIN_PLAYERS = 3;

    public function mount(Game $game, GamePlayer $player): void
    {
        $this->game = $game;
        $this->playerId = $player->id;
    }

    public function getPlayerProperty(): GamePlayer
    {
        return GamePlayer::findOrFail($this->playerId);
    }

    public function toggleReady(): void
    {
        $player = $this->player;
        $player->update(['is_ready' => ! $player->is_ready]);

        broadcast(new LobbyUpdated($this->game->id));
    }

    public function pickRole(string $role): void
    {
        if (! $this->game->players_pick_roles || $this->game->status !== GameStatus::Lobby) {
            return;
        }

        $role = PlayerRole::from($role);
        $players = $this->game->players()->get();

        if ($role === PlayerRole::Pacman) {
            if ($players->firstWhere('role', PlayerRole::Pacman)?->id !== $this->playerId
                && $players->contains(fn ($p) => $p->role === PlayerRole::Pacman)) {
                return; // someone else got there first
            }
            $this->player->update(['role' => PlayerRole::Pacman, 'ghost_slot' => null]);
        } else {
            $taken = $players->where('role', PlayerRole::Ghost)
                ->where('id', '!=', $this->playerId)
                ->pluck('ghost_slot')->all();

            $slot = 0;
            while (in_array($slot, $taken, true)) {
                $slot++;
            }

            if ($slot >= 4) {
                return; // all ghost slots taken
            }

            $this->player->update(['role' => PlayerRole::Ghost, 'ghost_slot' => $slot]);
        }

        broadcast(new LobbyUpdated($this->game->id));
    }

    public function togglePickRoles(): void
    {
        if (! $this->player->is_host) {
            return;
        }

        $this->game->update(['players_pick_roles' => ! $this->game->players_pick_roles]);

        if (! $this->game->players_pick_roles) {
            $this->game->players()->update(['role' => null, 'ghost_slot' => null]);
        }

        broadcast(new LobbyUpdated($this->game->id));
    }

    public function start(GameStateRepository $stateRepo, GameLoopLauncher $launcher): void
    {
        $this->game->refresh();

        if (! $this->player->is_host || $this->game->status !== GameStatus::Lobby) {
            return;
        }

        $players = $this->game->players()->get();
        $ready = $players->where('is_ready', true);

        if ($ready->count() < self::MIN_PLAYERS) {
            $this->addError('start', 'Need at least '.self::MIN_PLAYERS.' ready players (1 Pac-Man + 2 ghosts).');

            return;
        }

        if ($ready->count() < $players->count()) {
            $this->addError('start', 'Everyone must be ready.');

            return;
        }

        $this->game->update([
            'maze_layout' => Maze::classic(),
            'status' => GameStatus::Active,
            'started_at' => now(),
        ]);

        $this->game->assignRoles();
        $stateRepo->initialize($this->game->refresh());
        $launcher->launch($this->game);

        $playUrl = route('games.play', $this->game);
        broadcast(new GameStarted($this->game->id, $playUrl));

        $this->redirect($playUrl);
    }

    public function render()
    {
        $this->game->refresh();

        return view('livewire.lobby', [
            'players' => $this->game->players()->orderBy('id')->get(),
            'me' => $this->player,
        ]);
    }
}
