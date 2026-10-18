import { createApp, h } from 'vue'
import type { DefineComponent } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import axios from 'axios'
import { enqueue } from './composables/useOfflineQueue'
import vuetify from './plugins/vuetify'
import layouts from './plugins/layouts'
import echoPlugin from './plugins/echo'
import MainLayout from '@/Core/Layouts/MainLayout.vue'
import { createPinia } from 'pinia'
import { i18n, SUPPORTED_LOCALES, loadLocale, storedLocale } from './i18n'
import '@styles/tailwind.css'
import '@styles/styles.scss'



// ── Offline: intercept mutating requests when there is no network ─────────────
//
// We intercept at the axios level (not Inertia's 'before' event) so that
// Inertia's full success lifecycle still fires — onSuccess callbacks run,
// forms reset, modals close — exactly as if the server had responded.
//
// Mechanism: override config.adapter with a function that resolves immediately
// with the current Inertia page as a mock 200 response. Inertia sees a valid
// x-inertia response, fires 'success', and useForm resets processing/errors.
// ──────────────────────────────────────────────────────────────────────────────

const MUTATING = new Set(['post', 'put', 'patch', 'delete'])
const SKIP_QUEUE_PATHS = ['/logout', '/auth/logout', '/lock', '/lock-session', '/login']

function deriveOfflineLabel(method: string, path: string): string {
  const parts = path.split('/').filter(Boolean)
  return `${method.toUpperCase()} /${parts.slice(0, 3).join('/')}`
}

function parseSentData(raw: any): Record<string, any> | null {
  if (!raw) return {}
  if (raw instanceof FormData) return null           // file upload — cannot serialise
  if (typeof raw === 'string') {
    try { return JSON.parse(raw) } catch { return {} }
  }
  if (typeof raw === 'object') return raw as Record<string, any>
  return {}
}

axios.interceptors.request.use(async (config) => {
  const method = (config.method ?? '').toLowerCase()

  // Only intercept mutating requests that are offline and not our own replay calls
  if (
    !navigator.onLine &&
    MUTATING.has(method) &&
    !config.headers?.['X-Offline-Sync']
  ) {
    const url  = config.url ?? ''
    const path = (() => { try { return new URL(url, location.origin).pathname } catch { return url } })()

    // Let session-control routes fail naturally — never queue them
    if (SKIP_QUEUE_PATHS.some(s => path.includes(s))) return config

    const data   = parseSentData(config.data)
    const userId: number | null = (router.page?.props as any)?.auth?.user?.id ?? null
    const label  = deriveOfflineLabel(method, path)

    if (data !== null) {
      // Queue the request for later replay
      await enqueue(method, url, data, label, userId)
      window.dispatchEvent(new CustomEvent('offline-queued', { detail: { label } }))
    } else {
      // FormData — cannot be queued, notify user
      window.dispatchEvent(new CustomEvent('offline-queued', {
        detail: { label: 'File upload — cannot be saved offline' },
      }))
    }

    // Return a mock Inertia "stay on current page" response.
    // This makes Inertia fire its success lifecycle (onSuccess, form.reset,
    // close dialogs) exactly as if the server had responded with a redirect
    // back to the same page.
    config.adapter = async () => ({
      data:       { ...router.page },
      status:     200,
      statusText: 'OK',
      headers: {
        'x-inertia':    'true',
        'content-type': 'application/json; charset=utf-8',
      },
      config,
      request: null,
    })
  }

  return config
})

// ── Expired session (419 / CSRF mismatch): reload, never show "Page Expired" ──
//
// The server already converts 419s into a reload (bootstrap/app.php), so these
// are belt-and-braces for responses that bypass it — a cached 419, or a raw
// axios/fetch call answered before the handler runs.
// ──────────────────────────────────────────────────────────────────────────────

function reloadOnExpiredSession() {
  window.location.reload()
}

axios.interceptors.response.use(
  response => response,
  (error) => {
    const status = error?.response?.status
    // Skip the offline queue's background replays — those retry on their own
    // and must not yank the page out from under the user.
    const isOfflineSync = !!error?.config?.headers?.['X-Offline-Sync']

    if (status === 419 && !isOfflineSync) {
      reloadOnExpiredSession()
    }

    return Promise.reject(error)
  },
)

// Inertia surfaces non-Inertia responses (like a raw 419 "Page Expired" page)
// via 'httpException' — the event whose default action is the full-screen error
// modal. Cancelling it and reloading swaps that modal for a fresh page.
router.on('httpException', (event) => {
  if (event.detail.response?.status === 419) {
    event.preventDefault()
    reloadOnExpiredSession()
  }
})

createInertiaApp({

    resolve: async (name) => {
        /*
         * Every page is found here and loaded on demand.
         *
         * Ninety-five of them used to be imported at the top of this file and held in an
         * `explicitPages` map, because `import.meta.glob` is evaluated when the DEV SERVER starts
         * and a brand-new directory is invisible to it until a restart. That is true, and it is a
         * dev-server annoyance with a dev-server fix — restart it. What the list cost was a
         * production main chunk of 8.3 MB, welded together out of pages almost nobody on a given
         * visit will open, growing by a handful with every module added.
         *
         * A production build always sees every file, so nothing here is reachable that was not
         * reachable before. PageResolvesTest holds that: every page a controller renders must
         * match one of these patterns.
         */
        // Glob covers all page files. Core/Layouts and Core/Components are excluded
        // because they are infrastructure (layouts, shared components), not pages.
        // Note: import.meta.glob is evaluated once at dev-server start. If you add a
        // brand-new page file without restarting the dev server, it won't be found
        // until restart (production builds always see all files).
        const pages = import.meta.glob<DefineComponent<any, any, any>>([
            './Modules/**/*.vue',
            './Policies/**/*.vue',
            './HR/**/*.vue',
            './Pages/**/*.vue',
            './Tenancy/**/*.vue',
            './Reseller/**/*.vue',
            './Core/**/*.vue',
            '!./Core/Layouts/**',
            '!./Core/Components/**',
        ])

        const path = `./${name}.vue`
        if (pages[path]) {
            const module = await pages[path]()
            const page = module.default
            if (page.layout === undefined) page.layout = MainLayout
            return page
        }

        // Case-insensitive fallback: render strings and filenames sometimes differ
        // in case (e.g. a controller renders ".../Forms/Index" but the file is
        // "Forms/index.vue"). An exact-match miss would blank the page, so fall
        // back to a case-insensitive lookup before giving up.
        const lower = path.toLowerCase()
        const ciKey = Object.keys(pages).find((k) => k.toLowerCase() === lower)
        if (ciKey) {
            const module = await pages[ciKey]()
            const page = module.default
            if (page.layout === undefined) page.layout = MainLayout
            return page
        }

        throw new Error(`Page not found: ${name}`)
    },

    async setup({ el, App, props, plugin }) {
        // Sync locale from server-side user preference (overrides localStorage)
        const serverLocale = (props as any)?.initialPage?.props?.auth?.user?.locale
        if (serverLocale && (SUPPORTED_LOCALES as readonly string[]).includes(serverLocale)) {
            ;(i18n.global.locale as any).value = serverLocale
            localStorage.setItem('lang', serverLocale)
        }

        /*
         * Wait for the language before the first frame.
         *
         * Only English is in the bundle now; every other language is fetched on demand. Mounting
         * before it arrives would paint one frame of English and then snap — correct, but visibly
         * wrong for a moment on every page load for anybody not reading English.
         *
         * Awaited rather than raced: `loadLocale` never rejects, so a language that cannot be
         * fetched costs one round trip and then renders in English, which is what the fallback is
         * for. English itself resolves immediately, so nobody reading it waits at all.
         */
        await loadLocale(storedLocale())

        const pinia = createPinia()
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .use(vuetify)
            .use(i18n)
            .use(echoPlugin)

        app.config.errorHandler = (err, _instance, info) => {
            console.error('[Vue error]', err, info)
        }

        // Layouts plugin must be registered AFTER pinia
        layouts(app)

        app.mount(el)
    },
})
