import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Laravel Echo over Reverb (Pusher protocol). Long-running AI and lead-sourcing
 * jobs broadcast progress on private team channels (wired per module from
 * Phase 6 on); this is the shared client. Config comes from the VITE_REVERB_*
 * env keys (see .env.example).
 */
declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

export function initializeEcho(): Echo<'reverb'> {
    window.Pusher = Pusher;

    const echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    window.Echo = echo;

    return echo;
}
