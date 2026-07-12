<div class="flex min-h-screen items-center justify-center p-6" data-game-id="{{ $game->id }}">
    <div class="w-full max-w-lg space-y-6">
        <header class="text-center">
            <p class="text-xs uppercase tracking-widest text-slate-500">Game code</p>
            <h1 class="font-mono text-5xl font-black tracking-[0.3em] text-yellow-400">{{ $game->code }}</h1>
            <p class="mt-2 text-sm text-slate-400">Share this code. {{ \App\Livewire\Lobby::MIN_PLAYERS }}–{{ $game->max_players }} players.</p>
        </header>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="font-bold text-slate-200">Players ({{ $players->count() }}/{{ $game->max_players }})</h2>
                @if ($me->is_host)
                    <button wire:click="togglePickRoles"
                            class="rounded-full border px-3 py-1 text-xs {{ $game->players_pick_roles ? 'border-cyan-400 text-cyan-300' : 'border-slate-700 text-slate-400' }}">
                        {{ $game->players_pick_roles ? 'Players pick roles' : 'Random roles' }}
                    </button>
                @endif
            </div>

            <ul class="space-y-2">
                @foreach ($players as $player)
                    <li class="flex items-center justify-between rounded-lg bg-slate-800/60 px-4 py-2"
                        data-player-id="{{ $player->id }}">
                        <span class="flex items-center gap-2 text-sm">
                            <span class="presence-dot inline-block h-2 w-2 rounded-full bg-slate-600"></span>
                            <span class="font-semibold">{{ $player->display_name }}</span>
                            @if ($player->is_host)
                                <span class="rounded bg-yellow-400/20 px-1.5 text-[10px] font-bold uppercase text-yellow-300">host</span>
                            @endif
                            @if ($player->id === $me->id)
                                <span class="text-[10px] text-slate-500">(you)</span>
                            @endif
                        </span>
                        <span class="flex items-center gap-3 text-xs">
                            @if ($game->players_pick_roles && $player->role)
                                <span class="{{ $player->role === \App\Enums\PlayerRole::Pacman ? 'text-yellow-300' : 'text-cyan-300' }}">
                                    {{ $player->role === \App\Enums\PlayerRole::Pacman ? 'Pac-Man' : 'Ghost '.($player->ghost_slot + 1) }}
                                </span>
                            @endif
                            <span class="{{ $player->is_ready ? 'text-emerald-400' : 'text-slate-500' }}">
                                {{ $player->is_ready ? 'Ready' : 'Not ready' }}
                            </span>
                        </span>
                    </li>
                @endforeach
            </ul>

            @if ($game->players_pick_roles)
                <div class="mt-4 flex gap-2">
                    <button wire:click="pickRole('pacman')"
                            class="flex-1 rounded-lg border border-yellow-400/40 px-3 py-2 text-xs font-bold text-yellow-300 hover:bg-yellow-400/10">
                        Play Pac-Man
                    </button>
                    <button wire:click="pickRole('ghost')"
                            class="flex-1 rounded-lg border border-cyan-400/40 px-3 py-2 text-xs font-bold text-cyan-300 hover:bg-cyan-400/10">
                        Play Ghost
                    </button>
                </div>
            @endif
        </section>

        @error('start')
            <div class="rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-300">{{ $message }}</div>
        @enderror

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
    </div>

    @script
    <script>
        const gameId = $wire.$el.dataset.gameId;

        const markPresence = (members) => {
            document.querySelectorAll('[data-player-id]').forEach((row) => {
                const online = members.some((m) => String(m.id) === row.dataset.playerId);
                row.querySelector('.presence-dot').classList.toggle('bg-emerald-400', online);
                row.querySelector('.presence-dot').classList.toggle('bg-slate-600', !online);
            });
        };

        let members = [];
        window.Echo.join(`game.${gameId}`)
            .here((users) => { members = users; markPresence(members); $wire.$refresh(); })
            .joining((user) => { members.push(user); markPresence(members); $wire.$refresh(); })
            .leaving((user) => { members = members.filter((m) => m.id !== user.id); markPresence(members); $wire.$refresh(); })
            .listen('LobbyUpdated', () => $wire.$refresh())
            .listen('GameStarted', (e) => { window.location = e.playUrl; });
    </script>
    @endscript
</div>
