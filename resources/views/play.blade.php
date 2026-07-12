<x-layouts.app title="Match — {{ $game->code }}">
    <div id="game-root"
         class="flex min-h-screen flex-col items-center justify-center gap-4 p-4"
         data-game-id="{{ $game->id }}"
         data-player-id="{{ $player->id }}"
         data-input-url="{{ route('games.input', $game) }}"
         data-ability-url="{{ route('games.ability', $game) }}"
         data-maze="{{ json_encode($game->maze_layout) }}"
         data-sprites="{{ json_encode(config('sprites')) }}"
         data-sounds="{{ json_encode(config('sounds')) }}">
        <div id="hud" class="flex w-full max-w-3xl items-center justify-between font-mono text-sm text-slate-300">
            <span id="hud-role" class="font-bold text-yellow-400">—</span>
            <span id="hud-pellets">Pellets: —</span>
            <span id="hud-power" class="text-fuchsia-400"></span>
            <button id="hud-ability" class="hidden rounded border border-cyan-500/40 px-2 py-0.5 text-cyan-300"></button>
            <span id="hud-timer">--:--</span>
            <button id="hud-mute" class="rounded border border-slate-700 px-2 py-0.5 text-slate-400" title="Toggle sound (M)">🔊</button>
        </div>
        <canvas id="game-canvas" class="rounded-xl border border-slate-800 bg-black"></canvas>
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
    </div>
    @vite('resources/js/game/client.js')
</x-layouts.app>
