<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { usePage, useForm, Link } from '@inertiajs/vue3'
import { syncQueue, pendingCountForUser, useOfflineQueue } from '@/composables/useOfflineQueue'
import { useI18n } from 'vue-i18n'
import { useTheme } from 'vuetify'
import { HorizontalNavLayout, VerticalNavLayout } from '@/@layouts/components'
import Footer from '@/Core/Components/Footer.vue'
import OfflineBanner from '@/Components/OfflineBanner.vue'
import OfflineGuard  from '@/Components/OfflineGuard.vue'
import NavBarActions from '@/Components/Shared/NavBarActions.vue'
import FinanceEntityPicker from '@/Components/Shared/FinanceEntityPicker.vue'
import { faToMdi } from '@/utils/faToMdi'
import { ribbonLabelKeys, ribbonSlug } from '@/utils/locales/nav'
import { darkenHex } from '@/plugins/vuetify/theme'
import { useConfigStore, initConfigStore } from '@/@core/stores/config'
import { cookieRef } from '@/@layouts/stores/config'
import { useSkins } from '@/@core/composable/useSkins'
import initCore from '@/@core/initCore'
import type { NavGroup, NavLink } from '@/@layouts/types'
import { navActiveHref } from '@/@layouts/utils'

// Bootstrap the theme system (skin classes, dark/light mode, colour changes)
initCore()
initConfigStore()
useSkins().injectSkinClasses()

const { t, te } = useI18n()

// Navigation category/module names are translated via the `nav.*` i18n keys
// (see utils/locales/nav.ts). Falls back to the DB name when no translation
// exists for the current locale.
function catSlug(name: string): string {
  return (name || '').toLowerCase().replace(/&/g, ' ').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
}
function tCategory(cat: { name: string }): string {
  const key = 'nav.categories.' + catSlug(cat.name)
  return te(key) ? t(key) : cat.name
}
function tModule(mod: { name: string; code_name?: string }): string {
  const key = 'nav.modules.' + (mod.code_name || '')
  return mod.code_name && te(key) ? t(key) : mod.name
}
// Ribbon labels resolve to the page's own title key (see ribbonLabelKeys), so the
// sidebar entry and the heading it leads to are the same string in every language.
// Unmapped paths keep the English DB name.
function tRibbon(ribbon: { ribbon_name: string; ribbon_path: string }): string {
  const key = ribbonLabelKeys[ribbonSlug(ribbon.ribbon_path)]
  return key && te(key) ? t(key) : ribbon.ribbon_name
}
const page = usePage()

// Trial countdown shown along the bottom of every page while the company is on a free trial.
const companyTrial = computed<{ days_left: number; ends_at: string } | null>(() => (page.props as any).auth?.company_trial ?? null)
const form = useForm({})

// ── Flash snackbar ──────────────────────────────────────────────────────────
const flashSnackVisible = ref(false)
const flashSnackText = ref('')
const flashSnackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')

function showFlashSnack(flashed: unknown, color: 'success' | 'error' | 'warning' | 'info') {
  // A controller may flash an i18n key instead of a sentence, so server-side messages can be
  // translated too (there is no PHP translation catalogue — the app's i18n is client-side).
  // Guarded by te(): a plain-English flash is not a known key and passes through unchanged.
  //
  // A key with placeholders cannot be rendered on the server either, so a controller flashes
  // { key, params } for those and the values are substituted here. Passing them through __()
  // server-side silently dropped them and the user read "Report sent to :email".
  let normalized: string
  let params: Record<string, unknown> | undefined

  if (flashed && typeof flashed === 'object' && 'key' in (flashed as any)) {
    normalized = String((flashed as any).key || '').trim()
    params = (flashed as any).params ?? undefined
  } else {
    normalized = String(flashed ?? '').trim()
  }

  if (!normalized) return

  flashSnackText.value = te(normalized)
    ? (params ? t(normalized, params) : t(normalized))
    : normalized
  flashSnackColor.value = color
  flashSnackVisible.value = true
}

watch(
  [
    () => (page.props as any).flash?.success,
    () => (page.props as any).flash?.error,
    () => (page.props as any).flash?.warning,
    () => (page.props as any).flash?.info,
    () => (page.props as any).flash?.message,
  ],
  ([success, error, warning, info, message], [prevSuccess, prevError, prevWarning, prevInfo, prevMessage]) => {
    if      (success && success !== prevSuccess) showFlashSnack(success, 'success')
    else if (error   && error   !== prevError)   showFlashSnack(error,   'error')
    else if (warning && warning !== prevWarning) showFlashSnack(warning, 'warning')
    else if (info    && info    !== prevInfo)    showFlashSnack(info,    'info')
    else if (message && message !== prevMessage) showFlashSnack(message, 'info')
  },
  { immediate: true },
)

// ── User menu ───────────────────────────────────────────────────────────────
const userMenuOpen = ref(false)

function submitLock() {
  userMenuOpen.value = false
  form.post(route('lock.session'))
}

function submitLogout() {
  form.post(route('auth.logout'), {
    onSuccess: () => window.location.replace('/login'),
  })
}

// ── Storage low/full banner ─────────────────────────────────────────────────
const storageDismissed = ref(false)
const storageWarn = computed(() => {
  const s = (page.props as any).quota?.storage
  if (!s || s.unlimited || storageDismissed.value) return null
  if (s.exceeded) return { color: 'error', text: 'Your storage is full — new uploads are blocked. Buy or request more to keep uploading.' }
  if (s.low) return { color: 'warning', text: `Storage is running low (${s.percent}% used). Buy more before you run out.` }
  return null
})

// ── Profile completion ring ─────────────────────────────────────────────────
const profileCompletion = computed(() => (page.props as any).profile_completion || 0)
const radius = 16
const circumference = 2 * Math.PI * radius
const ringOffset = computed(() => circumference - (profileCompletion.value / 100) * circumference)
const strokeColor = computed(() => {
  if (profileCompletion.value < 30) return '#ef4444'
  if (profileCompletion.value < 80) return '#f59e0b'
  return '#10b981'
})

// ── Navigation ──────────────────────────────────────────────────────────────
type PhpRibbon = { ribbon_name: string; ribbon_path: string; font_awesome?: string }
type PhpModule = { id: number; name: string; code_name?: string; font_awesome?: string; module_ribbons?: PhpRibbon[] }
type PhpCategory = { id: number; name: string; icon?: string; modules?: PhpModule[] }

function convertNavigation(categories: PhpCategory[], includeHome = true): (NavLink | NavGroup)[] {
  const items: (NavLink | NavGroup)[] = []

  // Guests (public pages) have no home/dashboard — skip the Home link.
  if (includeHome) {
    items.push({
      title: 'Home',
      href: '/',
      icon: { icon: 'mdi-home' },
    } as NavLink)
  }

  for (const cat of categories) {
    const moduleChildren: (NavLink | NavGroup)[] = []

    for (const mod of cat.modules ?? []) {
      const ribbonLinks: NavLink[] = (mod.module_ribbons ?? []).map(ribbon => ({
        title: tRibbon(ribbon),
        href: ribbon.ribbon_path.startsWith('/') ? ribbon.ribbon_path : `/${ribbon.ribbon_path}`,
        icon: { icon: faToMdi(ribbon.font_awesome || '', ribbon.ribbon_name) },
      }))

      if (ribbonLinks.length > 0) {
        moduleChildren.push({
          title: tModule(mod),
          icon: { icon: faToMdi(mod.font_awesome || '', mod.code_name || mod.name) },
          children: ribbonLinks,
        } as NavGroup)
      }
    }

    if (moduleChildren.length > 0) {
      items.push({
        title: tCategory(cat),
        icon: { icon: faToMdi(cat.icon || '', cat.name) },
        children: moduleChildren,
      } as NavGroup)
    }
  }

  return items
}

// Keep the ribbon the server resolved for this page (hub cards included) in sync, so the menu
// highlights and keeps open the branch you are actually working in.
watch(
  () => (page.props as any).nav_active_path,
  path => { navActiveHref.value = (path as string | null) || null },
  { immediate: true },
)

const navItems = computed(() => {
  const isAuthed = !!(page.props as any).auth?.user
  const navigation = (page.props as any).navigation as PhpCategory[] ?? []
  return convertNavigation(navigation, isAuthed)
})

const authUser = computed(() => (page.props as any).auth?.user)
const impersonating = computed(() => (page.props as any).impersonating)
// White-label: the company's own in-system logo when active, else the default.
const systemLogo = computed(() => (page.props as any).branding?.system_logo || '/images/logo_default.png')
// When white-label is on, the company's own name (from Settings › Company Identity)
// replaces the product name beside the logo.
const systemName = computed(() => (page.props as any).branding?.system_name || 'MKHAYA AGRI')
function stopImpersonating() { userMenuOpen.value = false; useForm({}).post(route('tenancy.stop-impersonating')) }

// ── Theme: apply saved DB settings on load ──────────────────────────────────
const vuetifyTheme = useTheme()
const configStore = useConfigStore()

const isVertical = computed(() => configStore.appContentLayoutNav === 'vertical')

function applyThemeSettings(settings: Record<string, any>) {
  if (!settings || !Object.keys(settings).length) return

  if (settings.theme)
    configStore.theme = settings.theme

  if (settings.primaryColor) {
    const color = settings.primaryColor
    // Derived, not copied: `primary-darken-1` is what hover and pressed states resolve to, so
    // setting it equal to the primary makes a restored theme stop responding to the pointer.
    const darken = darkenHex(color)
    vuetifyTheme.themes.value.light.colors.primary = color
    vuetifyTheme.themes.value.dark.colors.primary = color
    vuetifyTheme.themes.value.light.colors['primary-darken-1'] = darken
    vuetifyTheme.themes.value.dark.colors['primary-darken-1'] = darken
    cookieRef<string | null>('lightThemePrimaryColor', null).value = color
    cookieRef<string | null>('darkThemePrimaryColor', null).value = color
  }

  if (settings.skin !== undefined)
    configStore.skin = settings.skin

  if (settings.contentWidth !== undefined)
    configStore.appContentWidth = settings.contentWidth

  if (settings.isVerticalNavCollapsed !== undefined)
    configStore.isVerticalNavCollapsed = settings.isVerticalNavCollapsed

  if (settings.isVerticalNavSemiDark !== undefined)
    configStore.isVerticalNavSemiDark = settings.isVerticalNavSemiDark

  if (settings.navbarBlur !== undefined)
    configStore.isNavbarBlurEnabled = settings.navbarBlur

  // Only honour an explicit saved choice; a null/empty value falls back to the
  // system default (horizontal), so users aren't forced back onto vertical.
  if (settings.contentLayoutNav)
    configStore.appContentLayoutNav = settings.contentLayoutNav
}

// ── Offline queue: sync on mount (handles post-login with stale queue) ─────────
const { isOnline } = useOfflineQueue()

const logoutWarningDialog = ref(false)
const logoutPendingCount  = ref(0)
let pendingLogoutFn: (() => void) | null = null

async function handleSyncNow() {
  const uid: number | null = (page.props as any)?.auth?.user?.id ?? null
  const count = await pendingCountForUser(uid)
  if (count > 0 && isOnline.value) {
    syncQueue(uid)
  }
}

// Called from user menu instead of submitLogout directly
async function requestLogout() {
  userMenuOpen.value = false
  const uid: number | null = (page.props as any)?.auth?.user?.id ?? null
  const count = await pendingCountForUser(uid)
  if (count > 0) {
    logoutPendingCount.value  = count
    logoutWarningDialog.value = true
    pendingLogoutFn = () => form.post(route('auth.logout'), {
      onSuccess: () => window.location.replace('/login'),
    })
  } else {
    form.post(route('auth.logout'), {
      onSuccess: () => window.location.replace('/login'),
    })
  }
}

function confirmLogout() {
  logoutWarningDialog.value = false
  pendingLogoutFn?.()
  pendingLogoutFn = null
}

function cancelLogout() {
  logoutWarningDialog.value = false
  pendingLogoutFn = null
}

// Listen for the online event re-dispatched by the composable
function onSyncNow() { handleSyncNow() }

/*
 * One control, two states: pinned open, or collapsed to a hover rail.
 *
 * The fully hidden state is cleared on the way through. It was what this button used to set, it is
 * kept in a cookie, and the button no longer offers it — so anybody who had hidden the menu would
 * otherwise be left with no sidebar and nothing on screen to bring it back.
 */
function toggleMenuRail() {
  configStore.isVerticalNavHidden = false
  configStore.isVerticalNavCollapsed = !configStore.isVerticalNavCollapsed
}

onMounted(() => {
  const saved = (page.props as any).theme_settings
  if (saved) applyThemeSettings(saved)

  // Somebody who hid the menu before the rail existed wanted it out of the way, not gone for good:
  // give them the rail, which is the nearest thing that can still be reached.
  if (configStore.isVerticalNavHidden) {
    configStore.isVerticalNavHidden = false
    configStore.isVerticalNavCollapsed = true
  }

  // Drain any queued items from a previous offline session
  handleSyncNow()

  window.addEventListener('offline-sync-now', onSyncNow)
})

onUnmounted(() => {
  window.removeEventListener('offline-sync-now', onSyncNow)
})

// ── --v-global-theme-primary CSS var ───────────────────────────────────────
const globalThemePrimary = computed(() => {
  const hex = vuetifyTheme.current.value.colors.primary ?? '#7367F0'
  const r = parseInt(hex.slice(1, 3), 16)
  const g = parseInt(hex.slice(3, 5), 16)
  const b = parseInt(hex.slice(5, 7), 16)
  return `${r}, ${g}, ${b}`
})
</script>

<template>
  <v-app :style="`--v-global-theme-primary: ${globalThemePrimary}`">

    <!-- ── Vertical Navigation Layout ───────────────────────────────────── -->
    <VerticalNavLayout v-if="isVertical" :nav-items="navItems">

      <!-- Logo in sidebar -->
      <template #vertical-nav-header>
        <Link href="/" class="d-flex align-center gap-x-3 text-decoration-none py-2 ps-2">
          <img
            :src="systemLogo"
            :alt="systemName"
            style="width: 36px; height: 36px; object-fit: contain; flex-shrink: 0;"
          />
          <span
            class="font-weight-bold text-body-1"
            style="color: rgb(var(--v-theme-primary)); letter-spacing: 0.5px; white-space: nowrap;"
          >
            {{ systemName }}
          </span>
        </Link>
      </template>

      <!-- Navbar: hamburger (mobile) + actions + user menu -->
      <template #navbar="{ toggleVerticalOverlayNavActive }">
        <VBtn
          icon
          variant="text"
          density="comfortable"
          class="d-lg-none me-1 nav-action"
          aria-label="Open navigation menu"
          @click="toggleVerticalOverlayNavActive(true)"
        >
          <VIcon size="22">mdi-menu</VIcon>
        </VBtn>

        <!--
          Collapse the sidebar to an icon rail, and pin it open again.

          Collapsed, the menu leaves a narrow rail of icons and opens over the page while the pointer
          is on it — the way Chrome's collapsed vertical tabs work — so it takes almost no room and
          is never more than a hover away. It used to vanish entirely, which meant a click on this
          button every single time somebody wanted to go anywhere.

          Only on the wide screens where a sidebar is actually shown alongside the page — below the
          breakpoint it is already an overlay.
        -->
        <VBtn
          icon
          variant="text"
          density="comfortable"
          class="d-none d-lg-inline-flex me-1 nav-action"
          :aria-label="configStore.isVerticalNavCollapsed
            ? t('layout.pin_menu')
            : t('layout.collapse_menu')"
          :title="configStore.isVerticalNavCollapsed
            ? t('layout.pin_menu')
            : t('layout.collapse_menu')"
          :aria-pressed="configStore.isVerticalNavCollapsed ? 'true' : 'false'"
          @click="toggleMenuRail"
        >
          <VIcon size="22">
            {{ configStore.isVerticalNavCollapsed ? 'mdi-menu-open' : 'mdi-menu-close' }}
          </VIcon>
        </VBtn>

        <FinanceEntityPicker />

        <VSpacer />
        <NavBarActions />

        <div v-if="authUser" class="position-relative" v-click-outside="() => { userMenuOpen = false }">
          <v-btn
            variant="text" density="comfortable" class="px-2 nav-action"
            :aria-label="`Account menu for ${authUser?.name ?? 'your account'}`"
            :aria-expanded="userMenuOpen ? 'true' : 'false'"
            @click="userMenuOpen = !userMenuOpen"
          >
            <div class="position-relative d-flex align-center justify-center" style="width: 36px; height: 36px;">
              <svg class="position-absolute" style="width: 42px; height: 42px; transform: rotate(-90deg);">
                <circle cx="21" cy="21" r="16" stroke="#e5e7eb" stroke-width="3" fill="transparent" />
                <circle cx="21" cy="21" r="16" :stroke="strokeColor" stroke-width="3" fill="transparent" stroke-linecap="round" :stroke-dasharray="circumference" :stroke-dashoffset="ringOffset" style="transition: stroke-dashoffset 0.5s ease;" />
              </svg>
              <div class="rounded-circle bg-grey-300 d-flex align-center justify-center overflow-hidden" style="width: 28px; height: 28px; position: relative; z-index: 1;">
                <img v-if="authUser?.avatar" :src="authUser.avatar" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;" />
                <VIcon v-else size="16" color="grey-darken-1">mdi-account</VIcon>
              </div>
            </div>
            <span class="d-none d-sm-block text-body-2 font-weight-medium text-high-emphasis ms-2">{{ authUser?.name }}</span>
            <VIcon size="14" class="ms-1 text-medium-emphasis">{{ userMenuOpen ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</VIcon>
          </v-btn>
          <Transition enter-active-class="transition-all duration-150" enter-from-class="opacity-0 translate-y-1" enter-to-class="opacity-100 translate-y-0" leave-active-class="transition-all duration-100" leave-to-class="opacity-0">
            <div v-show="userMenuOpen" class="position-absolute bg-surface rounded-lg elevation-8 py-1" style="right: 0; top: calc(100% + 8px); min-width: 220px; z-index: 100;">
              <div class="px-4 py-3 border-b">
                <p class="text-body-2 font-weight-semibold text-high-emphasis mb-0 text-truncate">{{ authUser?.name }}</p>
                <p class="text-caption text-medium-emphasis mb-0 text-truncate">{{ authUser?.email }}</p>
              </div>
              <Link href="/profile" class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg" @click="userMenuOpen = false">
                <VIcon size="16" color="grey-darken-1">mdi-account-circle</VIcon>{{ t('my_profile') }}
              </Link>
              <Link href="/settings/theme" class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg" @click="userMenuOpen = false">
                <VIcon size="16" color="grey-darken-1">mdi-palette</VIcon>Theme Settings
              </Link>
              <Link v-if="(authUser?.is_company_owner && authUser?.company_id) || impersonating" href="/subscription" class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg" @click="userMenuOpen = false">
                <VIcon size="16" color="grey-darken-1">mdi-card-account-details-outline</VIcon>Manage Subscription
              </Link>
              <Link v-if="(authUser?.is_company_owner && authUser?.company_id) || impersonating" href="/usage" class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg" @click="userMenuOpen = false">
                <VIcon size="16" color="grey-darken-1">mdi-gauge</VIcon>Usage &amp; Add-ons
              </Link>
              <Link v-if="authUser?.is_platform_admin" href="/platform/overview" class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg" @click="userMenuOpen = false">
                <VIcon size="16" color="primary">mdi-shield-crown</VIcon>Platform Admin
              </Link>
              <button v-if="impersonating" class="w-100 d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-warning hover-bg bg-transparent border-0 cursor-pointer" style="width: 100%;" @click="stopImpersonating">
                <VIcon size="16" color="warning">mdi-eye-off</VIcon>Exit "view as company"
              </button>
              <button class="w-100 d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis hover-bg bg-transparent border-0 cursor-pointer" style="width: 100%;" @click="submitLock">
                <VIcon size="16" color="grey-darken-1">mdi-lock</VIcon>{{ t('lock') }}
              </button>
              <div class="border-t mt-1">
                <button class="w-100 d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-error hover-bg-error bg-transparent border-0 cursor-pointer" style="width: 100%;" @click="submitLogout">
                  <VIcon size="16" color="error">mdi-logout</VIcon>{{ t('logout') }}
                </button>
              </div>
            </div>
          </Transition>
        </div>
      </template>

      <VAlert
        v-if="storageWarn"
        :type="storageWarn.color"
        variant="tonal"
        closable
        class="ma-4 mb-0"
        @click:close="storageDismissed = true"
      >
        <div class="d-flex flex-wrap align-center justify-space-between gap-2">
          <span>{{ storageWarn.text }}</span>
          <Link v-if="authUser?.is_company_owner || impersonating" :href="'/usage'"><VBtn size="small" :color="storageWarn.color" variant="flat">Manage storage</VBtn></Link>
        </div>
      </VAlert>
      <slot />
      <template #footer><Footer /></template>
    </VerticalNavLayout>

    <!-- ── Horizontal Navigation Layout ─────────────────────────────────── -->
    <HorizontalNavLayout v-else :nav-items="navItems">

      <!-- ── Navbar (top bar with logo + actions) ── -->
      <template #navbar>
        <!-- Brand -->
        <Link href="/" class="d-flex align-center gap-x-3 text-decoration-none py-1 me-4">
          <img
            :src="systemLogo"
            :alt="systemName"
            style="width: 32px; height: 32px; object-fit: contain; flex-shrink: 0;"
          />
          <span
            class="font-weight-bold text-body-1"
            style="color: rgb(var(--v-theme-primary)); letter-spacing: 0.5px; white-space: nowrap;"
          >
            {{ systemName }}
          </span>
        </Link>

        <FinanceEntityPicker />

        <VSpacer />

        <!-- Action icons -->
        <NavBarActions />

        <!-- User menu -->
        <div
          v-if="authUser"
          class="position-relative"
          v-click-outside="() => { userMenuOpen = false }"
        >
          <v-btn
            variant="text"
            density="comfortable"
            class="px-2 nav-action"
            :aria-label="`Account menu for ${authUser?.name ?? 'your account'}`"
            :aria-expanded="userMenuOpen ? 'true' : 'false'"
            @click="userMenuOpen = !userMenuOpen"
          >
            <div class="position-relative d-flex align-center justify-center" style="width: 36px; height: 36px;">
              <svg class="position-absolute" style="width: 42px; height: 42px; transform: rotate(-90deg);">
                <circle cx="21" cy="21" r="16" stroke="#e5e7eb" stroke-width="3" fill="transparent" />
                <circle
                  cx="21" cy="21" r="16"
                  :stroke="strokeColor"
                  stroke-width="3"
                  fill="transparent"
                  stroke-linecap="round"
                  :stroke-dasharray="circumference"
                  :stroke-dashoffset="ringOffset"
                  style="transition: stroke-dashoffset 0.5s ease;"
                />
              </svg>
              <div
                class="rounded-circle bg-grey-300 d-flex align-center justify-center overflow-hidden"
                style="width: 28px; height: 28px; position: relative; z-index: 1;"
              >
                <img v-if="authUser?.avatar" :src="authUser.avatar" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;" />
                <VIcon v-else size="16" color="grey-darken-1">mdi-account</VIcon>
              </div>
            </div>
            <span class="d-none d-sm-block text-body-2 font-weight-medium text-high-emphasis ms-2">
              {{ authUser?.name }}
            </span>
            <VIcon size="14" class="ms-1 text-medium-emphasis">
              {{ userMenuOpen ? 'mdi-chevron-up' : 'mdi-chevron-down' }}
            </VIcon>
          </v-btn>

          <Transition
            enter-active-class="transition-all duration-150"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-100"
            leave-to-class="opacity-0"
          >
            <div
              v-show="userMenuOpen"
              class="position-absolute bg-surface rounded-lg elevation-8 py-1"
              style="right: 0; top: calc(100% + 8px); min-width: 220px; z-index: 100;"
            >
              <div class="px-4 py-3 border-b">
                <p class="text-body-2 font-weight-semibold text-high-emphasis mb-0 text-truncate">{{ authUser?.name }}</p>
                <p class="text-caption text-medium-emphasis mb-0 text-truncate">{{ authUser?.email }}</p>
              </div>

              <Link
                href="/profile"
                class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg"
                @click="userMenuOpen = false"
              >
                <VIcon size="16" color="grey-darken-1">mdi-account-circle</VIcon>
                {{ t('my_profile') }}
              </Link>

              <Link
                href="/settings/theme"
                class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg"
                @click="userMenuOpen = false"
              >
                <VIcon size="16" color="grey-darken-1">mdi-palette</VIcon>
                Theme Settings
              </Link>

              <Link
                v-if="(authUser?.is_company_owner && authUser?.company_id) || impersonating"
                href="/subscription"
                class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg"
                @click="userMenuOpen = false"
              >
                <VIcon size="16" color="grey-darken-1">mdi-card-account-details-outline</VIcon>
                Manage Subscription
              </Link>

              <Link
                v-if="(authUser?.is_company_owner && authUser?.company_id) || impersonating"
                href="/usage"
                class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg"
                @click="userMenuOpen = false"
              >
                <VIcon size="16" color="grey-darken-1">mdi-gauge</VIcon>
                Usage &amp; Add-ons
              </Link>

              <Link
                v-if="authUser?.is_platform_admin"
                href="/platform/overview"
                class="d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis text-decoration-none hover-bg"
                @click="userMenuOpen = false"
              >
                <VIcon size="16" color="primary">mdi-shield-crown</VIcon>
                Platform Admin
              </Link>

              <button
                v-if="impersonating"
                class="w-100 d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-warning hover-bg bg-transparent border-0 cursor-pointer"
                style="width: 100%;"
                @click="stopImpersonating"
              >
                <VIcon size="16" color="warning">mdi-eye-off</VIcon>
                Exit "view as company"
              </button>

              <button
                class="w-100 d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-high-emphasis hover-bg bg-transparent border-0 cursor-pointer"
                style="width: 100%;"
                @click="submitLock"
              >
                <VIcon size="16" color="grey-darken-1">mdi-lock</VIcon>
                {{ t('lock') }}
              </button>

              <div class="border-t mt-1">
                <button
                  class="w-100 d-flex align-center gap-x-3 px-4 py-2 text-body-2 text-error hover-bg-error bg-transparent border-0 cursor-pointer"
                  style="width: 100%;"
                  @click="requestLogout"
                >
                  <VIcon size="16" color="error">mdi-logout</VIcon>
                  {{ t('logout') }}
                </button>
              </div>
            </div>
          </Transition>
        </div>
      </template>

      <!-- ── Main content ── -->
      <VAlert
        v-if="storageWarn"
        :type="storageWarn.color"
        variant="tonal"
        closable
        class="ma-4 mb-0"
        @click:close="storageDismissed = true"
      >
        <div class="d-flex flex-wrap align-center justify-space-between gap-2">
          <span>{{ storageWarn.text }}</span>
          <Link v-if="authUser?.is_company_owner || impersonating" :href="'/usage'"><VBtn size="small" :color="storageWarn.color" variant="flat">Manage storage</VBtn></Link>
        </div>
      </VAlert>
      <slot />

      <!-- ── Footer ── -->
      <template #footer>
        <Footer />
      </template>
    </HorizontalNavLayout>

    <VSnackbar
      v-model="flashSnackVisible"
      :timeout="3200"
      :color="flashSnackColor"
      location="bottom right"
      rounded="lg"
    >
      <div class="d-flex align-center gap-2">
        <VIcon
          size="16"
          :icon="flashSnackColor === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle'"
        />
        <span>{{ flashSnackText }}</span>
      </div>
    </VSnackbar>

    <!-- ── Theme Customizer slide-out panel ── -->
    <TheCustomizer />

    <!-- ── Offline / sync banner + toasts ── -->
    <OfflineBanner />

    <!-- ── Offline guard (timer + lockout screen) ── -->
    <OfflineGuard />

    <!-- ── Logout warning when offline changes are pending ── -->
    <VDialog v-model="logoutWarningDialog" max-width="460" persistent>
      <VCard rounded="lg">
        <VCardTitle class="pt-5 px-5 d-flex align-center gap-2">
          <VIcon icon="mdi-cloud-alert-outline" color="warning" />
          Unsaved Offline Changes
        </VCardTitle>
        <VCardText class="px-5">
          <p class="mb-2">
            You have <strong>{{ logoutPendingCount }} change{{ logoutPendingCount > 1 ? 's' : '' }}</strong>
            saved offline that have not been synced to the server yet.
          </p>
          <p class="mb-0 text-medium-emphasis text-body-2">
            If you log out now, these changes will be synced automatically the next time
            you log back in on this device — as long as you use the same browser.
          </p>
        </VCardText>
        <VCardActions class="pa-5 pt-2 justify-end gap-2">
          <VBtn variant="tonal" @click="cancelLogout">Stay Logged In</VBtn>
          <VBtn color="error" variant="tonal" @click="confirmLogout">
            Log Out Anyway
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Trial countdown banner ── -->
    <div v-if="companyTrial" class="trial-banner" :class="{ 'trial-banner--urgent': companyTrial.days_left <= 3 }" role="status">
      <VIcon size="16" class="me-2" :icon="companyTrial.days_left <= 3 ? 'tabler-alert-triangle' : 'tabler-clock'" />
      <span v-if="companyTrial.days_left > 1">Your free trial ends in <strong>{{ companyTrial.days_left }} days</strong> ({{ companyTrial.ends_at }}). Contact Mkhaya to activate your subscription.</span>
      <span v-else-if="companyTrial.days_left === 1">Your free trial ends <strong>tomorrow</strong>. Contact Mkhaya to activate your subscription.</span>
      <span v-else>Your free trial ends <strong>today</strong>. Contact Mkhaya to activate your subscription.</span>
    </div>
  </v-app>
</template>

<style scoped>
.trial-banner {
  position: fixed; inset-inline: 0; bottom: 0; z-index: 1500;
  display: flex; align-items: center; justify-content: center; text-align: center;
  padding: 8px 16px; font-size: 0.82rem; color: #fff;
  background: linear-gradient(90deg, #4f46e5, #7c3aed);
  box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.15);
}
.trial-banner--urgent { background: linear-gradient(90deg, #dc2626, #ea580c); }
/* The bar sits over the page: give the page room at the bottom while it shows. */
:global(body:has(.trial-banner) .layout-wrapper) { padding-block-end: 40px; }
@media (max-width: 600px) {
  :global(body:has(.trial-banner) .layout-wrapper) { padding-block-end: 60px; } /* the message wraps to two lines */
}

/*
 * The 44px accessibility minimum for a touch target. Declared here as well as in NavBarActions
 * because scoped styles do not cross a component boundary, and the hamburger and the account menu
 * live in this template — the two controls a phone user reaches for first.
 */
.nav-action {
  min-width: 44px;
  min-height: 44px;
}

.hover-bg:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
.hover-bg-error:hover { background-color: rgba(var(--v-theme-error), 0.08); }

:deep(.layout-navbar) {
  background: rgb(var(--v-theme-surface));
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  box-shadow: 0 2px 8px rgba(var(--v-theme-on-surface), 0.04);
}

:deep(.navbar-content-container) {
  display: flex;
  align-items: center;
  width: 100%;
  height: 100%;
  padding: 0 1.5rem;
  gap: 0.5rem;
}

:deep(.layout-page-content) {
  flex: 1;
  padding: 0;
  background: rgb(var(--v-theme-background));
}
</style>
