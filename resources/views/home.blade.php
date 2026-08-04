<x-layouts.app title="Maze Chase — Lobby">
    {{-- Decorative attract-mode chase behind the page; see resources/js/game/attract.js --}}
    <canvas id="attract-canvas"
            aria-hidden="true"
            class="pointer-events-none fixed inset-0 -z-10 h-full w-full"
            data-sprites="{{ json_encode(config('sprites')) }}"></canvas>

    <div class="flex min-h-screen items-center justify-center p-6">
        <div class="w-full max-w-md space-y-8">
            <header class="text-center">
                <h1 class="text-4xl font-black tracking-tight text-yellow-400">MAZE CHASE</h1>
                {{-- Read from the lobby sizes on offer rather than typed in,
                     so raising the cap can never leave the front page
                     advertising a smaller game than the one you can host. --}}
                <p class="mt-2 text-sm text-slate-400">1 Pac-Man vs up to {{ max(\App\Livewire\Lobby::LOBBY_SIZES) - 1 }} ghosts. Catch or be caught. The catcher takes the crown.</p>
                <p class="mt-1 text-xs text-slate-500">Short on people? Fill the maze with AI players and play solo.</p>
            </header>

            @if ($errors->any())
                <div class="rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('games.store') }}" class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900/85 p-6 shadow-xl shadow-slate-950/50 backdrop-blur-sm">
                @csrf
                <h2 class="font-bold text-slate-200">Create a game</h2>
                <input name="name" value="{{ old('name') }}" maxlength="20" required placeholder="Your nickname"
                       class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm placeholder-slate-500 focus:border-yellow-400 focus:outline-none">
                <button class="w-full rounded-lg bg-yellow-400 px-4 py-2 font-bold text-slate-950 hover:bg-yellow-300">
                    Create game
                </button>
            </form>

            <form method="POST" action="{{ route('games.join') }}" class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900/85 p-6 shadow-xl shadow-slate-950/50 backdrop-blur-sm">
                @csrf
                <h2 class="font-bold text-slate-200">Join with a code</h2>
                <input name="name" value="{{ old('name') }}" maxlength="20" required placeholder="Your nickname"
                       class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm placeholder-slate-500 focus:border-cyan-400 focus:outline-none">
                <input name="code" value="{{ old('code') }}" maxlength="8" required placeholder="Game code e.g. AB3XK9"
                       class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm uppercase tracking-widest placeholder-slate-500 focus:border-cyan-400 focus:outline-none">
                <button class="w-full rounded-lg bg-cyan-400 px-4 py-2 font-bold text-slate-950 hover:bg-cyan-300">
                    Join game
                </button>
            </form>
        </div>
    </div>

    @vite('resources/js/game/attract.js')
</x-layouts.app>
