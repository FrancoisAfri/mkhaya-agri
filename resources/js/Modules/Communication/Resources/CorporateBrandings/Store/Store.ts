import { defineStore } from 'pinia'
import axios from 'axios'
import { router, usePage } from '@inertiajs/vue3'
import type {
  CorporateBranding,
  RawCorporateBranding,
  BrandingSubmitPayload,
} from './type'

export const useCorporateBrandingStore = defineStore('corporateBranding', {
  // ─── STATE ──────────────────────────────────────────────────────────────────
  state: () => ({
    /** Liste complète des brandings */
    list: [] as CorporateBranding[],
    /** Branding courant (index page ou edit page) */
    current: null as CorporateBranding | null,

    isLoading: false,
    isSaving: false,

    // Snackbar
    snackbar: false,
    snackMsg: '',
    snackColor: 'success' as 'success' | 'error',

    // Polling
    _interval: null as ReturnType<typeof setInterval> | null,
  }),

  // ─── ACTIONS ────────────────────────────────────────────────────────────────
  actions: {

    // ── Transform raw → internal ─────────────────────────────────────────────

    _transform(raw: RawCorporateBranding): CorporateBranding {
      return {
        id: raw.id,
        name: raw.name,
        company_unit_id: raw.company_unit_id ?? null,
        user_id: raw.user_id ?? null,

        logo_light_id: raw.logo_light_id ?? null,
        logo_dark_id: raw.logo_dark_id ?? null,
        favicon_id: raw.favicon_id ?? null,

        logoLight: raw.logo_light
          ? { id: raw.logo_light.id, title: raw.logo_light.title, image: raw.logo_light.image, video: raw.logo_light.video }
          : null,
        logoDark: raw.logo_dark
          ? { id: raw.logo_dark.id, title: raw.logo_dark.title, image: raw.logo_dark.image, video: raw.logo_dark.video }
          : null,
        favicon: raw.favicon
          ? { id: raw.favicon.id, title: raw.favicon.title, image: raw.favicon.image, video: raw.favicon.video }
          : null,

        logo_light_url: raw.logo_light_url ?? null,
        logo_dark_url: raw.logo_dark_url ?? null,
        favicon_url: raw.favicon_url ?? null,

        primary_color: raw.primary_color ?? '#6366f1',
        secondary_color: raw.secondary_color ?? '#8b5cf6',
        accent_color: raw.accent_color ?? '#f59e0b',
        background_color: raw.background_color ?? '#ffffff',
        text_color: raw.text_color ?? '#111827',

        font_family: raw.font_family ?? '',
        font_url: raw.font_url ?? '',

        meta_data: raw.meta_data ?? null,
        is_active: raw.is_active ?? true,

        created_at: raw.created_at,
        updated_at: raw.updated_at,
        deleted_at: raw.deleted_at ?? null,
      }
    },

    // ── Apply branding to document root ──────────────────────────────────────

    _applyToDocument(b: CorporateBranding | null) {
      try {
        const root = document.documentElement
        if (!b) {
          ;['--company-primary', '--company-secondary', '--company-accent',
            '--company-background', '--company-text', '--company-font'
          ].forEach(v => root.style.removeProperty(v))
          document.getElementById('company-brand-font')?.remove()
          return
        }
        root.style.setProperty('--company-primary', b.primary_color)
        root.style.setProperty('--company-secondary', b.secondary_color)
        root.style.setProperty('--company-accent', b.accent_color)
        root.style.setProperty('--company-background', b.background_color)
        root.style.setProperty('--company-text', b.text_color)
        root.style.setProperty('--company-font', b.font_family || 'inherit')

        if (b.font_url) {
          let link = document.getElementById('company-brand-font') as HTMLLinkElement | null
          if (!link) {
            link = document.createElement('link')
            link.id = 'company-brand-font'
            link.rel = 'stylesheet'
            document.head.appendChild(link)
          }
          link.href = b.font_url
        }
      } catch {
        // SSR / non-browser
      }
    },

    // ── Fetch all ────────────────────────────────────────────────────────────

   async fetchAll() {
  this.isLoading = true
  try {
    // Prefer server-rendered Inertia props when available (no API mode)
    try {
      const page = usePage() as any
      const props = page?.props ?? (window as any).__INITIAL_PAGE__?.props ?? null
      const payload = props?.brandings ?? props?.branding ?? null

      if (payload) {
        // ✅ CORRECTION : Gérer les différents types de payload
        let rawArr: RawCorporateBranding[] = []

        if (Array.isArray(payload)) {
          // Cas 1 : Tableau d'objets
          rawArr = payload
        } else if (Array.isArray(payload.data)) {
          // Cas 2 : Objet paginé { data: [...] }
          rawArr = payload.data
        } else if (typeof payload === 'object' && payload !== null) {
          // Cas 3 : Objet unique (envoyé depuis le contrôleur comme objet simple)
          rawArr = [payload]
        }

        if (rawArr.length > 0) {
          this.list = rawArr.map(r => this._transform(r))
          const active = this.list.find(b => b.is_active) ?? this.list[0] ?? null
          if (active) this._applyToDocument(active)
          return
        }
      }
    } catch {
      // continue to fallback
    }

    // Fallback: try legacy endpoint if present
    const { data } = await axios.get('/corporate-branding')
    const raw: RawCorporateBranding[] = Array.isArray(data.data) ? data.data : (Array.isArray(data) ? data : [data])
    this.list = raw.map(r => this._transform(r))
    const active = this.list.find(b => b.is_active) ?? this.list[0] ?? null
    if (active) this._applyToDocument(active)
  } catch (e) {
    console.error('[CorporateBranding] fetchAll error', e)
    this.showSnack('Failed to load brandings', 'error')
  } finally {
    this.isLoading = false
  }
},

    // ── Fetch one ────────────────────────────────────────────────────────────

    async fetchOne(id: number) {
      this.isLoading = true
      try {
        // Prefer local list or page props since there is no JSON API endpoint
        const found = this.list.find(x => x.id === id)
        if (found) {
          this.current = found
          return
        }
        // Try Inertia props
        try {
          const page = usePage() as any
          const props = page?.props ?? (window as any).__INITIAL_PAGE__?.props ?? null
          const payload = props?.branding ?? null
          if (payload && payload.id === id) {
            this.current = this._transform(payload)
            return
          }
        } catch {}
        // No API available — mark not found
        throw new Error('not found')
      } catch (e) {
        console.error('[CorporateBranding] fetchOne error', e)
        this.current = null
        this.showSnack('Branding not found', 'error')
      } finally {
        this.isLoading = false
      }
    },

    // ── Submit (create or update) ─────────────────────────────────────────────

    async submit(payload: BrandingSubmitPayload, id?: number) {
      this.isSaving = true
      const form = new FormData()

      form.append('name', payload.name)
      form.append('primary_color', payload.primary_color)
      form.append('secondary_color', payload.secondary_color)
      form.append('accent_color', payload.accent_color)
      form.append('background_color', payload.background_color)
      form.append('text_color', payload.text_color)
      form.append('font_family', payload.font_family)
      form.append('font_url', payload.font_url)
      form.append('is_active', payload.is_active ? '1' : '0')
      if (payload.company_unit_id != null) form.append('company_unit_id', String(payload.company_unit_id))
      if (payload.logo_light instanceof File) form.append('logo_light', payload.logo_light)
      if (payload.logo_dark instanceof File) form.append('logo_dark', payload.logo_dark)
      if (payload.favicon instanceof File) form.append('favicon', payload.favicon)

      try {
        // Submit using Inertia router to the non-API routes
        if (id) {
          form.append('_method', 'PUT')
          router.post(`/corporate-branding/update/${id}`, form)
          this.showSnack('Branding updated ✓', 'success')
        } else {
          router.post('/corporate-branding/store', form)
          this.showSnack('Branding created ✓', 'success')
        }
        // We cannot reliably return the saved resource without an API; caller should rely on server redirection
        return null
      } catch (e: any) {
        const msg = e?.response?.data?.message ?? 'Une erreur est survenue'
        this.showSnack(msg, 'error')
        throw e
      } finally {
        this.isSaving = false
      }
    },

    // ── Delete ────────────────────────────────────────────────────────────────

    async destroy(id: number) {
      // No delete route available — inform user
      this.showSnack('Delete operation not supported by server', 'error')
      return
    },

    // ── Toggle active ─────────────────────────────────────────────────────────

    async toggleActive(branding: CorporateBranding) {
      await this.submit(
        {
          name: branding.name,
          primary_color: branding.primary_color,
          secondary_color: branding.secondary_color,
          accent_color: branding.accent_color,
          background_color: branding.background_color,
          text_color: branding.text_color,
          font_family: branding.font_family,
          font_url: branding.font_url,
          is_active: !branding.is_active,
          company_unit_id: branding.company_unit_id,
        },
        branding.id,
      )
    },

    // ── Polling ───────────────────────────────────────────────────────────────

    startPolling(seconds = 60) {
      this.stopPolling()
      this._interval = setInterval(() => this.fetchAll(), seconds * 1000)
    },

    stopPolling() {
      if (this._interval) { clearInterval(this._interval); this._interval = null }
    },

    // ── Snackbar ──────────────────────────────────────────────────────────────

    showSnack(msg: string, color: 'success' | 'error' = 'success') {
      this.snackMsg = msg
      this.snackColor = color
      this.snackbar = true
      setTimeout(() => { this.snackbar = false }, 3500)
    },
  },

  // ─── GETTERS ────────────────────────────────────────────────────────────────
  getters: {
    activeBranding: (s) => s.list.find(b => b.is_active) ?? null,
    hasLogoLight: (s) => !!s.current?.logo_light_url,
    hasLogoDark: (s) => !!s.current?.logo_dark_url,
    hasFavicon: (s) => !!s.current?.favicon_url,
  },
})
