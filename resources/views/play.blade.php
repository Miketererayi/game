<x-layouts.app title="Match — {{ $game->code }}">
    <div id="game-root"
         class="flex min-h-screen flex-col items-center justify-center gap-4 p-4"
         data-game-id="{{ $game->id }}"
         data-player-id="{{ $player->id }}"
         data-input-url="{{ route('games.input', $game) }}"
         data-ability-url="{{ route('games.ability', $game) }}"
         data-lobby-url="{{ route('games.lobby', $game) }}"
         data-maze="{{ json_encode($game->maze_layout) }}"
         data-sprites="{{ json_encode(config('sprites')) }}"
         data-sounds="{{ json_encode(config('sounds')) }}"
         {{-- The same movement rule the engine runs, so the browser can
              predict this player's next moves instead of waiting a round
              trip to draw them. --}}
         data-movement="{{ json_encode(config('game.movement')) }}">
        <div id="hud" class="flex w-full max-w-3xl items-center justify-between font-mono text-sm text-slate-300">
            <span id="hud-role" class="font-bold text-yellow-400">—</span>
            <span id="hud-pellets">Pellets: —</span>
            <span id="hud-power" class="text-fuchsia-400"></span>
            <button id="hud-ability" class="hidden rounded border border-cyan-500/40 px-2 py-0.5 text-cyan-300"></button>
            <span id="hud-timer">--:--</span>
            <button id="hud-mute" class="rounded border border-slate-700 px-2 py-0.5 text-slate-400" title="Toggle sound (M)">🔊</button>
        </div>
        {{-- The canvas keeps its pixel size; max-w-full scales the big maze
             down to fit narrower screens. --}}
        <canvas id="game-canvas" class="max-w-full rounded-xl border border-slate-800 bg-black"></canvas>
        <div id="game-banner" class="pointer-events-none fixed inset-0 z-20 flex hidden items-center justify-center">
            <div id="game-banner-text" class="rounded-2xl bg-slate-900/95 px-10 py-6 text-center text-3xl font-black"></div>
        </div>
        <div id="dpad" class="grid grid-cols-3 gap-1 md:hidden">
            <div></div>
            <button data-dir="up" class="h-14 w-14 rounded-lg bg-slate-800 text-xl">▲</button>
            <div></div>
            <button data-dir="left" class="h-14 w-14 rounded-lg bg-slate-800 text-xl">◀</button>
            <div></div>
            <button data-dir="right" class="h-14 w-14 rounded-lg bg-slate-800 text-xl">▶</button>
            <div></div>
            <button data-dir="down" class="h-14 w-14 rounded-lg bg-slate-800 text-xl">▼</button>
            <div></div>
        </div>

        {{-- Plain forms rather than the JS client: these have to work even
             when the tick loop is dead and no state is arriving. --}}
        <div class="flex items-center gap-3 text-sm">
            <form method="POST" action="{{ route('games.leave', $game) }}"
                  onsubmit="return confirm('Leave the match? The AI will take over your character.')">
                @csrf
                <button type="submit"
                        class="rounded-lg border border-slate-700 px-3 py-1.5 text-slate-400 hover:border-red-500/50 hover:text-red-300">
                    Leave match
                </button>
            </form>

            @if ($player->is_host)
                <form method="POST" action="{{ route('games.end', $game) }}"
                      onsubmit="return confirm('End the match for everyone?')">
                    @csrf
                    <button type="submit"
                            class="rounded-lg border border-red-500/40 px-3 py-1.5 text-red-300 hover:bg-red-500/10">
                        End match
                    </button>
                </form>
            @endif
        </div>

        @error('end')
            <p class="text-sm text-red-300">{{ $message }}</p>
        @enderror
    </div>
    @vite('resources/js/game/client.js')
</x-layouts.app>
