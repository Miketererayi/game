<div class="flex min-h-screen items-center justify-center p-6" data-game-id="{{ $game->id }}">
    {{-- Between rounds the results panel owns the controls, so the setup
         UI only shows once the team is back in the lobby proper. --}}
    @php
        $inLobby = $game->status === \App\Enums\GameStatus::Lobby;
    @endphp

    <div class="w-full max-w-lg space-y-6">
        <header class="text-center">
            <p class="text-xs uppercase tracking-widest text-slate-500">Game code</p>
            <h1 class="font-mono text-5xl font-black tracking-[0.3em] text-yellow-400">{{ $game->code }}</h1>
            <p class="mt-2 text-sm text-slate-400">
                Share this code. {{ \App\Livewire\Lobby::MIN_PLAYERS }}–{{ $game->max_players }} players.
                @if ($round > 1 || $lastResult)
                    <span class="text-slate-500">· Round {{ $round }}</span>
                @endif
            </p>
        </header>

        @if ($lastResult)
            @php
                // A match can also end with nobody winning — the host closing
                // it, or the last human leaving.
                $winnerRole = $lastResult['winner_role'] ?? null;
                $pacmanWon = $winnerRole === 'pacman';
                $noWinner = $winnerRole === null;
                $reasons = [
                    'pellets_cleared' => 'every pellet eaten',
                    'time_up' => 'time ran out',
                    'pacman_caught' => 'Pac-Man was caught',
                    'host_ended' => 'the host ended the match',
                    'abandoned' => 'everyone left',
                ];
                $accent = $noWinner ? 'slate-500' : ($pacmanWon ? 'yellow-400' : 'cyan-400');
                $scores = collect($lastResult['scores'] ?? [])->sortByDesc('score')->values();
            @endphp

            <section class="rounded-2xl border {{ $noWinner ? 'border-slate-600/40' : ($pacmanWon ? 'border-yellow-400/40' : 'border-cyan-400/40') }} bg-slate-900 p-6">
                <div class="text-center">
                    <h2 class="text-2xl font-black {{ $noWinner ? 'text-slate-300' : ($pacmanWon ? 'text-yellow-300' : 'text-cyan-300') }}">
                        {{ $noWinner ? 'MATCH ENDED' : ($pacmanWon ? 'PAC-MAN WINS' : 'GHOSTS WIN') }}
                    </h2>
                    <p class="mt-1 text-xs text-slate-500">
                        Round {{ $round }} · {{ $reasons[$lastResult['reason'] ?? ''] ?? $lastResult['reason'] ?? '' }}
                    </p>
                </div>

                <table class="mt-4 w-full text-sm">
                    <thead>
                        <tr class="text-left text-[10px] uppercase tracking-wider text-slate-500">
                            <th class="pb-1 font-medium">#</th>
                            <th class="pb-1 font-medium">Player</th>
                            <th class="pb-1 font-medium">Ended as</th>
                            <th class="pb-1 text-right font-medium">Catches</th>
                            <th class="pb-1 text-right font-medium">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scores as $i => $entry)
                            <tr class="border-t border-slate-800">
                                <td class="py-1.5 text-slate-500">{{ $i + 1 }}</td>
                                <td class="py-1.5 font-semibold">{{ $entry['name'] }}</td>
                                <td class="py-1.5 {{ $entry['role'] === 'pacman' ? 'text-yellow-300' : 'text-cyan-300' }}">
                                    {{ $entry['role'] === 'pacman' ? 'Pac-Man' : 'Ghost' }}
                                </td>
                                <td class="py-1.5 text-right text-slate-400">{{ $entry['caught_count'] ?? 0 }}</td>
                                <td class="py-1.5 text-right font-mono">{{ $entry['score'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if (count($standings) && $round > 1)
                    <details class="mt-4 border-t border-slate-800 pt-3">
                        <summary class="cursor-pointer text-xs text-slate-400">Session standings ({{ $round }} rounds)</summary>
                        <ul class="mt-2 space-y-1 text-xs">
                            @foreach ($standings as $i => $row)
                                <li class="flex justify-between text-slate-300">
                                    <span>{{ $i + 1 }}. {{ $row['name'] }}</span>
                                    <span class="font-mono text-slate-400">
                                        {{ $row['wins'] }} {{ \Illuminate\Support\Str::plural('win', $row['wins']) }} · {{ $row['score'] }} pts
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                {{-- Once the team is back in the lobby the scoreboard stays up
                     for reference, but starting the next round is the normal
                     ready/start flow again. --}}
                @if (! $inLobby)
                    @if ($me->is_host)
                        <button wire:click="rematch" wire:loading.attr="disabled"
                                class="mt-4 w-full rounded-xl bg-emerald-500 px-4 py-3 font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-50">
                            Play another round
                        </button>
                    @else
                        <p class="mt-4 text-center text-xs text-slate-500">Waiting for the host to start another round…</p>
                    @endif
                @endif
            </section>
        @endif

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="font-bold text-slate-200">Players ({{ $players->count() }}/{{ $game->max_players }})</h2>
                @if ($me->is_host && $inLobby)
                    <div class="flex items-center gap-2">
                        <button wire:click="addBot"
                                @disabled($players->count() >= $game->max_players)
                                class="rounded-full border border-fuchsia-400/50 px-3 py-1 text-xs text-fuchsia-300 hover:bg-fuchsia-400/10 disabled:opacity-40">
                            + AI player
                        </button>
                        <button wire:click="togglePickRoles"
                                class="rounded-full border px-3 py-1 text-xs {{ $game->players_pick_roles ? 'border-cyan-400 text-cyan-300' : 'border-slate-700 text-slate-400' }}">
                            {{ $game->players_pick_roles ? 'Players pick roles' : 'Random roles' }}
                        </button>
                    </div>
                @endif
            </div>

            {{-- Shown to everyone, not just the host: how long you are signing
                 up for is worth knowing before you ready up. --}}
            @if ($me->is_host && $inLobby)
                <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-slate-500">Room size</span>
                    @foreach (\App\Livewire\Lobby::LOBBY_SIZES as $size)
                        <button wire:click="setLobbySize({{ $size }})"
                                class="rounded-full border px-3 py-1 {{ (int) $game->max_players === $size ? 'border-emerald-400 text-emerald-300' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">
                            {{ $size }}
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
                <span class="text-slate-500">Match length</span>
                @if ($me->is_host && $inLobby)
                    @foreach (\App\Livewire\Lobby::DURATIONS as $seconds)
                        <button wire:click="setDuration({{ $seconds }})"
                                class="rounded-full border px-3 py-1 {{ (int) $game->match_duration_seconds === $seconds ? 'border-yellow-400 text-yellow-300' : 'border-slate-700 text-slate-400 hover:border-slate-500' }}">
                            {{ intdiv($seconds, 60) }} min
                        </button>
                    @endforeach
                @else
                    <span class="rounded-full border border-slate-700 px-3 py-1 text-slate-300">
                        {{ intdiv((int) $game->match_duration_seconds, 60) }} min
                    </span>
                @endif
            </div>

            <ul class="space-y-2">
                @foreach ($players as $player)
                    <li class="flex items-center justify-between rounded-lg bg-slate-800/60 px-4 py-2"
                        data-player-id="{{ $player->id }}">
                        <span class="flex items-center gap-2 text-sm">
                            @if ($player->is_bot)
                                <span class="inline-block h-2 w-2 rounded-full bg-fuchsia-400"></span>
                            @else
                                <span class="presence-dot inline-block h-2 w-2 rounded-full bg-slate-600"></span>
                            @endif
                            <span class="font-semibold">{{ $player->display_name }}</span>
                            @if ($player->is_bot)
                                <span class="rounded bg-fuchsia-400/20 px-1.5 text-[10px] font-bold uppercase text-fuchsia-300">AI</span>
                            @endif
                            @if ($player->is_host)
                                <span class="rounded bg-yellow-400/20 px-1.5 text-[10px] font-bold uppercase text-yellow-300">host</span>
                            @endif
                            @if ($player->id === $me->id)
                                <span class="text-[10px] text-slate-500">(you)</span>
                            @endif
                        </span>
                        <span class="flex items-center gap-3 text-xs">
                            @if ($game->players_pick_roles && $player->role)
                                <span class="flex items-center gap-1.5 {{ $player->role === \App\Enums\PlayerRole::Pacman ? 'text-yellow-300' : 'text-cyan-300' }}">
                                    @if ($player->role === \App\Enums\PlayerRole::Pacman)
                                        Pac-Man
                                    @else
                                        <x-ghost-sprite :ghost-slot="$player->ghost_slot" size="h-4 w-4" />
                                        {{ config('sprites.ghosts')[$player->ghost_slot]['label'] ?? 'Ghost' }}
                                    @endif
                                </span>
                            @endif
                            <span class="{{ $player->is_ready ? 'text-emerald-400' : 'text-slate-500' }}">
                                {{ $player->is_ready ? 'Ready' : 'Not ready' }}
                            </span>
                            @if ($player->is_bot && $me->is_host)
                                <button wire:click="removeBot({{ $player->id }})"
                                        class="rounded border border-slate-700 px-1.5 text-slate-400 hover:border-red-500/50 hover:text-red-300"
                                        title="Remove {{ $player->display_name }}">&times;</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            @if ($game->players_pick_roles && $inLobby)
                @php
                    // A colour is taken only if someone *else* holds it.
                    $taken = $players->where('role', \App\Enums\PlayerRole::Ghost)
                        ->where('id', '!=', $me->id)
                        ->pluck('ghost_slot')
                        ->all();
                    $pacmanTaken = $players->contains(fn ($p) => $p->role === \App\Enums\PlayerRole::Pacman && $p->id !== $me->id);
                @endphp

                <div class="mt-4 space-y-3">
                    <button wire:click="pickRole('pacman')"
                            @disabled($pacmanTaken)
                            class="w-full rounded-lg border px-3 py-2 text-xs font-bold {{ $me->role === \App\Enums\PlayerRole::Pacman ? 'border-yellow-400 bg-yellow-400/15 text-yellow-200' : 'border-yellow-400/40 text-yellow-300 hover:bg-yellow-400/10' }} disabled:opacity-30">
                        {{ $pacmanTaken ? 'Pac-Man taken' : 'Play Pac-Man' }}
                    </button>

                    <div>
                        <p class="mb-2 text-xs text-slate-400">Or pick your ghost colour</p>
                        <div class="grid grid-cols-5 gap-2">
                            @foreach ($ghosts as $slot => $ghost)
                                @php($mine = $me->role === \App\Enums\PlayerRole::Ghost && $me->ghost_slot === $slot)
                                <button wire:click="pickRole('ghost', {{ $slot }})"
                                        @disabled(in_array($slot, $taken, true))
                                        title="{{ $ghost['label'] ?? 'Ghost '.($slot + 1) }}"
                                        class="flex flex-col items-center gap-1 rounded-lg border p-2 transition {{ $mine ? 'border-cyan-400 bg-cyan-400/15' : 'border-slate-700 hover:border-cyan-400/60' }} disabled:opacity-25 disabled:hover:border-slate-700">
                                    <x-ghost-sprite :ghost-slot="$slot" size="h-6 w-6" />
                                    <span class="text-[10px] {{ $mine ? 'text-cyan-200' : 'text-slate-400' }}">{{ $ghost['label'] ?? $slot + 1 }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </section>

        @error('start')
            <div class="rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-300">{{ $message }}</div>
        @enderror

        {{-- Wrapped rather than toggled with a `hidden` class: that loses to
             `flex`, since both are display utilities of equal specificity. --}}
        @if ($inLobby)
        <div class="flex gap-3">
            <button wire:click="toggleReady"
                    class="flex-1 rounded-xl px-4 py-3 font-bold {{ $me->is_ready ? 'bg-slate-700 text-slate-300' : 'bg-emerald-500 text-slate-950 hover:bg-emerald-400' }}">
                {{ $me->is_ready ? 'Unready' : 'Ready up' }}
            </button>
            @if ($me->is_host)
                <button wire:click="start" wire:loading.attr="disabled"
                        class="flex-1 rounded-xl bg-yellow-400 px-4 py-3 font-bold text-slate-950 hover:bg-yellow-300 disabled:opacity-50">
                    Start match
                </button>
            @endif
        </div>
        @endif

        {{-- Outside the lobby check on purpose: between rounds is exactly when
             someone wants to drop out. --}}
        <button wire:click="leave"
                wire:confirm="Leave this game?"
                wire:loading.attr="disabled"
                class="rounded-xl border border-slate-700 px-4 py-2 text-sm text-slate-400 hover:border-red-500/50 hover:text-red-300 disabled:opacity-50">
            Leave game
        </button>
    </div>

    @script
    <script>
        const gameId = $wire.$el.dataset.gameId;

        const markPresence = (members) => {
            document.querySelectorAll('[data-player-id]').forEach((row) => {
                const dot = row.querySelector('.presence-dot');
                if (!dot) return; // AI players have no connection to report

                const online = members.some((m) => String(m.id) === row.dataset.playerId);
                dot.classList.toggle('bg-emerald-400', online);
                dot.classList.toggle('bg-slate-600', !online);
            });
        };

        let members = [];

        // Without a realtime transport the lobby still works, it just won't
        // update until the next interaction. echo.js explains why.
        if (window.Echo) {
            window.Echo.join(`game.${gameId}`)
                .here((users) => { members = users; markPresence(members); $wire.$refresh(); })
                .joining((user) => { members.push(user); markPresence(members); $wire.$refresh(); })
                .leaving((user) => { members = members.filter((m) => m.id !== user.id); markPresence(members); $wire.$refresh(); })
                .listen('LobbyUpdated', () => $wire.$refresh())
                .listen('GameStarted', (e) => { window.location = e.playUrl; });
        }
    </script>
    @endscript
</div>
