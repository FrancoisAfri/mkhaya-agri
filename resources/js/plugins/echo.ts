/**
 * Echo plugin — wraps window.Echo if present, stubs it out when absent.
 *
 * To activate real-time broadcasting:
 *   1. npm install laravel-echo pusher-js
 *   2. Add VITE_REVERB_* keys to .env (see .env.example)
 *   3. Uncomment the echo-init.ts import in this file (or import it from app.ts)
 *   4. php artisan reverb:start
 */
import type { App } from 'vue'

import './echo-init'

type ChannelLike = {
  listen: (event: string, cb: (data: any) => void) => ChannelLike
  stopListening: (event: string) => ChannelLike
}

function getEcho(): any {
  return (window as any).Echo ?? null
}

function stubChannel(): ChannelLike {
  return {
    listen: (_e: string, _cb: any) => stubChannel(),
    stopListening: (_e: string) => stubChannel(),
  }
}

export default {
  install(app: App) {
    const proxy = new Proxy({} as any, {
      get(_t, prop: string) {
        if (prop === 'channel') {
          return (ch: string) => getEcho()?.channel(ch) ?? stubChannel()
        }
        if (prop === 'private') {
          return (ch: string) => getEcho()?.private(ch) ?? stubChannel()
        }
        if (prop === 'leaveChannel') {
          return (ch: string) => getEcho()?.leaveChannel(ch)
        }
        if (prop === 'leave') {
          return (ch: string) => getEcho()?.leave(ch)
        }
        return getEcho()?.[prop]
      },
    })

    app.provide('echo', proxy)
  },
}
