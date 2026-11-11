/*
 * The app's service worker: what lets it be installed and open like an app from a home screen or
 * a desktop icon. Copied from Mkhaya Medic's (public/sw.js there) and kept as small.
 *
 * It never keeps a page or a data response. A cached screen is a stale screen — yesterday's
 * balance, a job already done, last week's version of the app after a deploy — and that is what
 * the old generated worker did (vite-plugin-pwa precached the whole bundle and served an old build
 * after every release, which is why it was switched off). So:
 *
 *  - pages and API calls always go to the network; with no network a page navigation gets the
 *    offline notice instead of the browser's own error page;
 *  - only the build's content-hashed files (/build/...) and images are kept: a hashed name that
 *    matches is the same bytes, so a kept copy can never be the wrong version;
 *  - writes (anything not GET) are never touched, never queued.
 *
 * Apps with their own narrower worker (a contractor, farm or till app under its own path) keep it:
 * the narrower scope wins on those pages.
 */
const VERSION = 'mkhaya-app-v1';
const SHELL = VERSION + '-shell';
const OFFLINE = '/offline.html';
const CACHEABLE = [/^\/build\//, /^\/icons\//, /^\/images\//, /^\/favicon\./];

self.addEventListener('install', (event) => {
  // The offline notice is the one page kept, so there is something to show with no network.
  event.waitUntil(
    caches.open(SHELL)
      .then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' })))
      .catch(() => null)
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // Drop this worker's own older versions, so nothing stale survives a deploy. Only its own:
    // caches are shared by every worker on the site, and the contractor, farm or till app's
    // offline copies are theirs to manage.
    for (const name of await caches.keys()) {
      if (name.startsWith('mkhaya-app-') && name !== SHELL) await caches.delete(name);
    }
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (!CACHEABLE.some((p) => p.test(url.pathname))) {
    // A page or an API call: network only. A failed navigation gets the offline notice.
    if (request.mode === 'navigate') {
      event.respondWith(
        fetch(request)
          .then((response) => {
            // Put the offline notice back if it has gone (cleared storage, or another worker on
            // this site tidying caches), while there is a connection to fetch it with.
            event.waitUntil(caches.match(OFFLINE).then((hit) => hit || caches.open(SHELL)
              .then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' }))).catch(() => null)));
            return response;
          })
          .catch(() => caches.match(OFFLINE).then((hit) => hit
            || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain' } }))),
      );
    }
    return;
  }

  // A hashed asset or an image: from the cache when we have it, else the network (and keep it).
  event.respondWith((async () => {
    const cache = await caches.open(SHELL);
    const hit = await cache.match(request);
    if (hit) return hit;
    const response = await fetch(request);
    if (response.ok && response.type === 'basic') cache.put(request, response.clone());
    return response;
  })());
});
