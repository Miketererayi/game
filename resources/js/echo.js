import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Without a key, constructing Echo throws — and an uncaught throw here takes
// down every Livewire script on the page with it, so a missing broadcast
// config would break the lobby buttons rather than just realtime updates.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else {
    console.error(
        '[maze-chase] VITE_REVERB_APP_KEY is not set, so realtime is disabled: '
        + 'lobbies will not update live and matches cannot run. Set the REVERB_* '
        + 'variables and rebuild assets.',
    );
}
