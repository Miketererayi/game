<?php

namespace App\Livewire;

use App\Enums\GameStatus;
use App\Enums\PlayerRole;
use App\Events\GameStarted;
use App\Events\LobbyUpdated;
use App\Game\GameLoopLauncher;
use App\Game\GameStateRepository;
use App\Game\MazeGenerator;
use App\Game\PlayerDeparture;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Str;
use Livewire\Component;

class Lobby extends Component
{
    public Game $game;

    public int $playerId;

    /** 1 Pac-Man + 2 ghosts, any mix of people and AI. */
    public const MIN_PLAYERS = 3;

    /** Arcade ghost names, so AI players read as characters not slots. */
    private const BOT_NAMES = ['Blinky', 'Pinky', 'Inky', 'Clyde', 'Sue', 'Funky', 'Orson', 'Tim', 'Kinky'];

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

    /**
     * Picking a ghost is also picking a colour: pass the slot to claim a
     * specific one, or leave it null to take the lowest free slot.
     */
    public function pickRole(string $role, ?int $slot = null): void
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

            if ($slot === null) {
                $slot = 0;
                while (in_array($slot, $taken, true)) {
                    $slot++;
                }
            } elseif (in_array($slot, $taken, true)) {
                return; // that colour belongs to someone else
            }

            if ($slot < 0 || $slot >= $this->ghostSlotCount()) {
                return;
            }

            $this->player->update(['role' => PlayerRole::Ghost, 'ghost_slot' => $slot]);
        }

        broadcast(new LobbyUpdated($this->game->id));
    }

    /** Ghost colours available: one per non-Pac-Man seat, capped by the art. */
    public function ghostSlotCount(): int
    {
        return min($this->game->max_players - 1, count(config('sprites.ghosts')));
    }

    public function addBot(): void
    {
        $this->game->refresh();

        if (! $this->player->is_host || ! $this->game->isJoinable()) {
            return;
        }

        $used = $this->game->players()->pluck('guest_name')->all();
        $name = collect(self::BOT_NAMES)->first(fn ($n) => ! in_array('CPU '.$n, $used, true));

        $this->game->players()->create([
            'guest_name' => 'CPU '.($name ?? Str::random(4)),
            'session_token' => 'bot-'.Str::random(32),
            'is_bot' => true,
            'is_ready' => true,
        ]);

        broadcast(new LobbyUpdated($this->game->id));
    }

    /**
     * Send a finished team back to the lobby for another round: same code,
     * same players, same AI. Scores from the round just played are already
     * recorded as a game_ended event, so wiping the per-match counters here
     * doesn't lose the series history.
     */
    public function rematch(): void
    {
        $this->game->refresh();

        if (! $this->player->is_host || $this->game->status !== GameStatus::Finished) {
            return;
        }

        $this->game->update([
            'status' => GameStatus::Lobby,
            'started_at' => null,
            'ended_at' => null,
            'winner_role' => null,
        ]);

        // Roles rotate during play, so end-of-match roles and colours are
        // arbitrary — clear them and let the team pick or roll again.
        $this->game->players()->update([
            'is_ready' => false,
            'role' => null,
            'ghost_slot' => null,
            'score' => 0,
            'caught_count' => 0,
        ]);

        $this->game->players()->where('is_bot', true)->update(['is_ready' => true]);

        broadcast(new LobbyUpdated($this->game->id));
    }

    public function removeBot(int $playerId): void
    {
        if (! $this->player->is_host || $this->game->status !== GameStatus::Lobby) {
            return;
        }

        $this->game->players()->where('id', $playerId)->where('is_bot', true)->delete();

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

    /**
     * Give up the seat and go home. Hosting passes to whoever is left; if
     * nobody is, the room closes rather than lingering with only AI in it.
     */
    public function leave(PlayerDeparture $departure): void
    {
        $departure->depart($this->game, $this->player);

        $this->redirect(route('home'));
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
            $this->addError('start', 'Need at least '.self::MIN_PLAYERS.' ready players (1 Pac-Man + 2 ghosts). Add AI players to fill the gaps.');

            return;
        }

        if ($ready->count() < $players->count()) {
            $this->addError('start', 'Everyone must be ready.');

            return;
        }

        $this->game->update([
            // A fresh maze per round, sized for the lobby.
            'maze_layout' => MazeGenerator::forPlayers($players->count())->layout(),
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
            'ghosts' => array_slice(config('sprites.ghosts'), 0, $this->ghostSlotCount()),
            'lastResult' => $this->game->lastResult(),
            'standings' => $this->game->standings(),
            'round' => $this->game->roundNumber(),
        ]);
    }
}
