/*
 * Mkhaya Agri — offline support for "My Work" (spec Module K).
 *
 * Served at /agri/sw.js, so it controls /agri/ pages only and never touches the rest of the platform.
 *
 *  - My Work (the page and its Inertia data): network first, the last good copy when offline.
 *  - Built assets (/build/…, hashed and immutable), fonts and stylesheets: cache first.
 *  - Everything else, and every POST, goes straight to the network: changes made offline are queued
 *    by the page itself and sent to /agri/my-work/sync when the phone is back online.
 */
const VERSION = 'agri-v1'
const SHELL = `${VERSION}-shell`
const ASSETS = `${VERSION}-assets`
const OFFLINE_PAGES = ['/agri/my-work']

self.addEventListener('install', event => {
  event.waitUntil(self.skipWaiting())
})

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keep = [SHELL, ASSETS]
    for (const key of await caches.keys()) {
      if (!keep.includes(key)) await caches.delete(key)
    }
    await self.clients.claim()
  })())
})

const isOfflinePage = url => OFFLINE_PAGES.includes(url.pathname)
const isAsset = url => url.pathname.startsWith('/build/') || url.pathname.startsWith('/images/')
  || url.pathname === '/favicon.png' || url.hostname !== self.location.hostname

self.addEventListener('fetch', event => {
  const req = event.request
  if (req.method !== 'GET') return
  const url = new URL(req.url)

  if (isOfflinePage(url) && url.hostname === self.location.hostname) {
    // The Inertia JSON and the full HTML of the same URL are different answers: key them apart.
    const inertia = req.headers.get('X-Inertia') === 'true'
    const key = new Request(url.pathname + (inertia ? '?__inertia=1' : ''))
    event.respondWith((async () => {
      try {
        const fresh = await fetch(req)
        if (fresh.ok && !fresh.redirected) {
          const cache = await caches.open(SHELL)
          await cache.put(key, fresh.clone())
        }
        return fresh
      } catch (e) {
        const cached = await caches.match(key)
        if (cached) return cached
        throw e
      }
    })())
    return
  }

  if (isAsset(url)) {
    event.respondWith((async () => {
      const cached = await caches.match(req)
      if (cached) return cached
      try {
        const fresh = await fetch(req)
        if (fresh.ok || fresh.type === 'opaque') {
          const cache = await caches.open(ASSETS)
          await cache.put(req, fresh.clone())
        }
        return fresh
      } catch (e) {
        return Response.error()
      }
    })())
  }
})
