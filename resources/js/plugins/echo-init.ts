/**
 * Bootstraps Laravel Echo with Reverb.
 * Only import this file AFTER running: npm install laravel-echo pusher-js
 */
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

;(window as any).Pusher = Pusher

const reverbKey = import.meta.env.VITE_REVERB_APP_KEY

if (reverbKey) {
  ;(window as any).Echo = new Echo({
    broadcaster:       'reverb',
    key:               reverbKey,
    wsHost:            import.meta.env.VITE_REVERB_HOST,
    wsPort:            import.meta.env.VITE_REVERB_PORT    ?? 8080,
    wssPort:           import.meta.env.VITE_REVERB_PORT    ?? 8080,
    forceTLS:          (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint:      '/broadcasting/auth',
  })
}
