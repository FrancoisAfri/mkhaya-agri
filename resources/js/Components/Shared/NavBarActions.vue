<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { SUPPORTED_LOCALES } from '@/i18n'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { useTheme } from 'vuetify'
import { route } from 'ziggy-js'

const { locale } = useI18n()
const theme = useTheme()
const page = usePage()

// ── Role helpers ──
// Guests (public support/portal pages) only submit queries — they don't need
// the account tools (search, theme, shortcuts, notifications). Only the
// language switcher stays available to them.
const isAuthenticated = computed(() => !!(page.props as any).auth?.user)
const authRoles = computed<string[]>(() => (page.props as any).auth?.roles ?? [])
const hasRole   = (...roles: string[]) => roles.some(r => authRoles.value.includes(r))
const isHrAdmin = computed(() => hasRole('super admin', 'administrator', 'HR'))
const isRegularUser = computed(() => authRoles.value.length > 0 && authRoles.value.every(r => r === 'user'))
const isClaimsApprover = computed(() => hasRole('super admin', 'administrator', 'finance manager', 'manager', 'senior manager', 'executive', 'HR'))

// ── Theme switcher ──
const isDark = computed(() => theme.global.current.value.dark)
function toggleTheme() {
  theme.global.name.value = isDark.value ? 'light' : 'dark'
}

// ── Language ──
const currentLang = ref<string>(
  typeof window !== 'undefined'
    ? ((page.props as any).auth?.user?.locale ?? localStorage.getItem('lang') ?? 'en')
    : 'en',
)
// South Africa's 11 official languages (+ French kept for existing users).
const languages = [
  { code: 'en',  label: 'English',    flag: '🇬🇧' },
  { code: 'af',  label: 'Afrikaans',  flag: '🇿🇦' },
  { code: 'zu',  label: 'isiZulu',    flag: '🇿🇦' },
  { code: 'xh',  label: 'isiXhosa',   flag: '🇿🇦' },
  { code: 'nso', label: 'Sepedi',     flag: '🇿🇦' },
  { code: 'st',  label: 'Sesotho',    flag: '🇿🇦' },
  { code: 'tn',  label: 'Setswana',   flag: '🇿🇦' },
  { code: 'ss',  label: 'siSwati',    flag: '🇿🇦' },
  { code: 've',  label: 'Tshivenda',  flag: '🇿🇦' },
  { code: 'ts',  label: 'Xitsonga',   flag: '🇿🇦' },
  { code: 'nr',  label: 'isiNdebele', flag: '🇿🇦' },
  { code: 'fr',  label: 'Français',   flag: '🇫🇷' },
]

/*
 * Offered only where the language was actually shipped.
 *
 * The labels below are the full table and stay that way: `SUPPORTED_LOCALES` is derived from the
 * locale files present at build time, so removing a language removes it from this menu, and putting
 * its files back brings it straight back. A hard-coded menu would offer a flag that produces a
 * screen of English.
 */
const availableLanguages = computed(() => languages.filter(l => SUPPORTED_LOCALES.includes(l.code)))

function switchLanguage(code: string) {
  if (currentLang.value === code) return

  currentLang.value = code
  locale.value = code

  if (typeof window !== 'undefined') {
    localStorage.setItem('lang', code)
    document.documentElement.setAttribute('lang', code)
  }

  // The UI has already switched (vue-i18n + localStorage). Persist to the
  // server in the background — fire-and-forget, no Inertia visit, so there's
  // no reload or redirect for guests or logged-in users. It just changes.
  axios.patch(route('user.locale'), { locale: code }).catch(() => {})
}

// ── Shortcuts (ribbons from PHP nav) ──
type PhpRibbon = { ribbon_name: string; ribbon_path: string; font_awesome?: string }
type PhpModule = { module_ribbons?: PhpRibbon[] }
type PhpCategory = { modules?: PhpModule[] }

const shortcuts = computed(() => {
  const navigation = (page.props as any).navigation as PhpCategory[] ?? []
  const links: { title: string; href: string }[] = []
  for (const cat of navigation) {
    for (const mod of cat.modules ?? []) {
      for (const ribbon of mod.module_ribbons ?? []) {
        links.push({
          title: ribbon.ribbon_name,
          href: ribbon.ribbon_path.startsWith('/') ? ribbon.ribbon_path : `/${ribbon.ribbon_path}`,
        })
        if (links.length >= 9) break
      }
      if (links.length >= 9) break
    }
    if (links.length >= 9) break
  }
  return links
})

// ── Notifications ──
interface AppNotification {
  id: string
  type: string
  title: string
  body: string
  url: string | null
  created_at: string
}

// Local refs so we can update them without a full page reload
const notifications = ref<AppNotification[]>((page.props as any).notifications ?? [])
const unreadCount   = ref<number>((page.props as any).unread_count ?? 0)

// Keep in sync when Inertia reloads the page normally
watch(() => (page.props as any).notifications, (v) => { if (v) notifications.value = v })
watch(() => (page.props as any).unread_count,  (v) => { if (v !== undefined) unreadCount.value = v })

// Poll every 30 s — Inertia only re-fetches the two shared props, no full page render
let pollTimer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  pollTimer = setInterval(() => {
    router.reload({
      only: ['notifications', 'unread_count'],
      preserveScroll: true,
      preserveState: true,
    })
  }, 30_000)
})
onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})

function notificationIcon(type: string): string {
  const map: Record<string, string> = {
    // Leave
    leave_submitted:                'mdi-file-send-outline',
    leave_submitted_employee:       'mdi-file-clock-outline',
    leave_approved:                 'mdi-check-circle-outline',
    leave_rejected:                 'mdi-close-circle-outline',
    leave_escalated:                'mdi-alert-circle-outline',
    leave_medical_required:         'mdi-medical-bag',
    leave_balance_low:              'mdi-battery-low',
    leave_cycle_ending:             'mdi-calendar-alert',
    leave_anomaly_detected:         'mdi-alert-outline',
    leave_report_ready:             'mdi-file-chart-outline',
    policy_override_requested:      'mdi-shield-edit-outline',
    // Policies
    policy_violation:               'mdi-shield-alert-outline',
    policy_violation_escalation:    'mdi-shield-alert',
    policy_violation_critical:      'mdi-shield-off-outline',
    policy_violation_alert:         'mdi-shield-lock-outline',
    attestation_reminder:           'mdi-clipboard-clock-outline',
    attestation_escalation:         'mdi-clipboard-alert-outline',
    quiz_failed:                    'mdi-head-question-outline',
    // Attendance
    attendance_late_arrival:        'mdi-clock-alert-outline',
    attendance_absent:              'mdi-calendar-remove-outline',
    attendance_overtime:            'mdi-clock-plus-outline',
    attendance_early_departure:     'mdi-exit-run',
    attendance_missing_clockout:    'mdi-clock-remove-outline',
    attendance_weekly_summary:      'mdi-calendar-week-outline',
    // Profile
    profile_change_submitted:       'mdi-account-edit-outline',
    profile_change_pending_approval:'mdi-account-clock-outline',
    profile_change_approved:        'mdi-account-check-outline',
    profile_change_rejected:        'mdi-account-cancel-outline',
    // Account
    user_account_created:           'mdi-account-plus-outline',
    // Content & Interactions
    comment_added_content:          'mdi-comment-outline',
    comment_added_spotlight:        'mdi-comment-star-outline',
    like_added_content:             'mdi-heart-outline',
    like_added_spotlight:           'mdi-heart-outline',
    content_created:                'mdi-newspaper-variant-outline',
    spotlight_created:              'mdi-star-outline',
    // Fleet — Bookings
    fleet_booking_approval:         'mdi-car-clock',
    fleet_booking_approved:         'mdi-car-check',
    fleet_booking_rejected:         'mdi-car-off',
    fleet_booking_cancelled:        'mdi-car-remove',
    fleet_booking_returned:         'mdi-car-arrow-right',
    // Fleet — Vehicles & Maintenance
    fleet_high_risk_vehicle:        'mdi-car-emergency',
    fleet_maintenance_due:          'mdi-wrench-clock',
    fleet_document_expiry:          'mdi-file-clock-outline',
    // Fleet — AI & Incidents
    fleet_anomaly_detected:         'mdi-fuel',
    fleet_incident_reported:        'mdi-alert-octagon-outline',
    // Claims
    claim_submitted:                'mdi-receipt-send-outline',
    claim_approved:                 'mdi-receipt-check-outline',
    claim_rejected:                 'mdi-receipt-remove-outline',
    claim_paid:                     'mdi-cash-check',
    claim_payment_ready:            'mdi-cash-clock',
    claim_under_review:             'mdi-receipt-clock-outline',
    claim_high_risk:                'mdi-alert-circle-outline',
    // Performance Appraisal
    pa_kpi_assigned:                        'mdi-chart-bar',
    pa_checkin_scheduled:                   'mdi-calendar-check',
    pa_checkin_reminder_day_before:         'mdi-calendar-clock',
    pa_checkin_reminder_day_of:             'mdi-calendar-today',
    pa_coaching_scheduled:                  'mdi-account-voice',
    pa_coaching_reminder_day_before:        'mdi-account-clock-outline',
    pa_coaching_reminder_day_of:            'mdi-account-voice',
    pa_feedback_received:                   'mdi-message-text-outline',
    pa_360_review_created:                  'mdi-rotate-360',
    pa_360_added_as_rater:                  'mdi-star-outline',
    pa_probation_started:                   'mdi-clock-alert-outline',
    pa_probation_checkin_scheduled:         'mdi-clipboard-clock-outline',
    pa_probation_checkin_reminder_day_before: 'mdi-clipboard-clock-outline',
    pa_probation_checkin_reminder_day_of:   'mdi-clipboard-check-outline',
    pa_probation_feedback:                  'mdi-message-badge-outline',
  }
  return map[type] ?? 'mdi-bell-outline'
}

function notificationColor(type: string): string {
  const error = [
    'leave_rejected', 'leave_escalated', 'leave_anomaly_detected',
    'policy_violation_critical', 'policy_violation_escalation',
    'profile_change_rejected', 'attestation_escalation',
    'attendance_absent',
    'fleet_booking_rejected', 'fleet_high_risk_vehicle', 'fleet_anomaly_detected', 'fleet_incident_reported',
    'claim_rejected', 'claim_high_risk',
  ]
  const success = [
    'leave_approved', 'profile_change_approved', 'user_account_created', 'leave_report_ready',
    'pa_kpi_assigned', 'pa_checkin_scheduled', 'pa_coaching_scheduled',
    'pa_feedback_received', 'pa_360_review_created', 'pa_360_added_as_rater',
    'fleet_booking_approved', 'fleet_booking_returned',
    'claim_approved', 'claim_paid',
  ]
  const warning = [
    'leave_medical_required', 'leave_balance_low', 'leave_cycle_ending',
    'policy_override_requested', 'policy_violation', 'policy_violation_alert',
    'attestation_reminder', 'quiz_failed', 'profile_change_pending_approval',
    'attendance_late_arrival', 'attendance_overtime', 'attendance_early_departure', 'attendance_missing_clockout',
    'fleet_booking_cancelled', 'fleet_maintenance_due', 'fleet_document_expiry',
    'claim_under_review',
    'pa_probation_started', 'pa_probation_checkin_scheduled',
    'pa_probation_checkin_reminder_day_before', 'pa_probation_checkin_reminder_day_of',
    'pa_probation_feedback', 'pa_checkin_reminder_day_before', 'pa_checkin_reminder_day_of',
    'pa_coaching_reminder_day_before', 'pa_coaching_reminder_day_of',
  ]
  const info = [
    'comment_added_content', 'comment_added_spotlight',
    'like_added_content', 'like_added_spotlight',
    'content_created', 'spotlight_created',
  ]
  if (error.includes(type)) return 'error'
  if (success.includes(type)) return 'success'
  if (warning.includes(type)) return 'warning'
  if (info.includes(type)) return 'info'
  return 'primary'
}

function markRead(id: string, url: string | null) {
  router.post(`/notifications/${id}/read`, {}, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { if (url) window.location.href = url },
  })
}

function markAllRead() {
  router.post('/notifications/read-all', {}, { preserveScroll: true, preserveState: true })
}

// ── Search ──
const searchOpen = ref(false)
const searchQuery = ref('')
const allRibbons = computed(() => {
  const navigation = (page.props as any).navigation as PhpCategory[] ?? []
  const links: { title: string; href: string }[] = []
  for (const cat of navigation) {
    for (const mod of cat.modules ?? []) {
      for (const ribbon of mod.module_ribbons ?? []) {
        links.push({
          title: ribbon.ribbon_name,
          href: ribbon.ribbon_path.startsWith('/') ? ribbon.ribbon_path : `/${ribbon.ribbon_path}`,
        })
      }
    }
  }
  return links
})
const searchResults = computed(() => {
  if (!searchQuery.value.trim()) return []
  const q = searchQuery.value.toLowerCase()
  return allRibbons.value.filter(r => r.title.toLowerCase().includes(q)).slice(0, 8)
})
function openSearch() {
  searchOpen.value = true
  searchQuery.value = ''
}
function closeSearch() {
  searchOpen.value = false
  searchQuery.value = ''
}
function navigate(href: string) {
  closeSearch()
  window.location.href = href
}
</script>

<template>
  <div class="d-flex align-center gap-x-1 nav-actions">

    <!-- ── Search ── -->
    <v-btn v-if="isAuthenticated" icon variant="text" density="comfortable" class="nav-action" aria-label="Search" @click="openSearch">
      <v-icon size="22">mdi-magnify</v-icon>
      <v-tooltip activator="parent" location="bottom">Search</v-tooltip>
    </v-btn>

    <!-- ── Language ── -->
    <v-menu location="bottom end" :close-on-content-click="true">
      <template #activator="{ props: menuProps }">
        <v-btn icon variant="text" density="comfortable" class="nav-action" aria-label="Language" v-bind="menuProps">
          <v-icon size="22">mdi-translate</v-icon>
          <v-tooltip activator="parent" location="bottom">Language</v-tooltip>
        </v-btn>
      </template>
      <v-list density="compact" min-width="160">
        <v-list-item
          v-for="lang in availableLanguages"
          :key="lang.code"
          :active="currentLang === lang.code"
          active-color="primary"
          @click="switchLanguage(lang.code)"
        >
          <template #prepend>
            <span class="me-2 text-base">{{ lang.flag }}</span>
          </template>
          <v-list-item-title>{{ lang.label }}</v-list-item-title>
          <template #append>
            <v-icon v-if="currentLang === lang.code" size="14" color="primary">mdi-check</v-icon>
          </template>
        </v-list-item>
      </v-list>
    </v-menu>

    <!-- ── Theme switcher ── -->
    <v-btn
      v-if="isAuthenticated" icon variant="text" density="comfortable" class="nav-action"
      :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
      @click="toggleTheme"
    >
      <v-icon size="22">{{ isDark ? 'mdi-weather-night' : 'mdi-weather-sunny' }}</v-icon>
      <v-tooltip activator="parent" location="bottom">{{ isDark ? 'Light mode' : 'Dark mode' }}</v-tooltip>
    </v-btn>

    <!-- ── Support / Help ── (available to every client) -->
    <v-menu v-if="isAuthenticated" location="bottom end" :close-on-content-click="true">
      <template #activator="{ props: menuProps }">
        <v-btn icon variant="text" density="comfortable" class="nav-action" aria-label="Support" v-bind="menuProps">
          <v-icon size="22">mdi-lifebuoy</v-icon>
          <v-tooltip activator="parent" location="bottom">Support</v-tooltip>
        </v-btn>
      </template>
      <v-card min-width="260" max-width="300" elevation="8">
        <v-card-title class="text-body-2 font-weight-semibold pa-4 pb-2 text-medium-emphasis">
          Need help?
        </v-card-title>
        <v-divider />
        <v-list density="comfortable" class="py-1">
          <v-list-item href="mailto:support@mkhayamk.co.za" prepend-icon="mdi-email-outline">
            <v-list-item-title>Email support</v-list-item-title>
            <v-list-item-subtitle>support@mkhayamk.co.za</v-list-item-subtitle>
          </v-list-item>
          <v-list-item href="https://hub.mkhayamk.co.za/support" target="_blank" rel="noopener" prepend-icon="mdi-ticket-outline">
            <v-list-item-title>Log a ticket</v-list-item-title>
            <v-list-item-subtitle>Open a support request</v-list-item-subtitle>
          </v-list-item>
          <v-list-item href="https://hub.mkhayamk.co.za/support/chat" target="_blank" rel="noopener" prepend-icon="mdi-chat-outline">
            <v-list-item-title>Live chat</v-list-item-title>
            <v-list-item-subtitle>Chat with our team</v-list-item-subtitle>
          </v-list-item>
        </v-list>
      </v-card>
    </v-menu>

    <!-- ── Shortcuts ── -->
    <v-menu v-if="isAuthenticated" location="bottom end" :close-on-content-click="true">
      <template #activator="{ props: menuProps }">
        <v-btn icon variant="text" density="comfortable" class="nav-action" aria-label="Quick access" v-bind="menuProps">
          <v-icon size="22">mdi-view-grid-outline</v-icon>
          <v-tooltip activator="parent" location="bottom">Quick Access</v-tooltip>
        </v-btn>
      </template>
      <v-card min-width="280" max-width="320" elevation="8">
        <v-card-title class="text-body-2 font-weight-semibold pa-4 pb-2 text-medium-emphasis">
          Quick Access
        </v-card-title>
        <v-divider />
        <div
          v-if="shortcuts.length"
          class="pa-2"
          style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px;"
        >
          <a
            v-for="item in shortcuts"
            :key="item.href"
            :href="item.href"
            class="d-flex flex-column align-center justify-center text-decoration-none rounded pa-2 shortcut-item"
          >
            <v-icon size="22" color="primary" class="mb-1">mdi-link</v-icon>
            <span class="text-caption text-high-emphasis text-center" style="line-height: 1.2; font-size: 0.7rem;">
              {{ item.title }}
            </span>
          </a>
        </div>
        <div v-else class="pa-4 text-center text-caption text-medium-emphasis">
          No shortcuts available
        </div>
      </v-card>
    </v-menu>

    <!-- ── Notifications ── -->
    <v-menu v-if="isAuthenticated" location="bottom end" :close-on-content-click="false" max-width="400">
      <template #activator="{ props: menuProps }">
        <v-btn
          icon variant="text" density="comfortable" class="me-1 nav-action"
          :aria-label="unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications'"
          v-bind="menuProps"
        >
          <v-badge v-if="unreadCount > 0" color="error" :content="unreadCount > 99 ? '99+' : unreadCount" floating>
            <v-icon size="22">mdi-bell-outline</v-icon>
          </v-badge>
          <v-icon v-else size="22">mdi-bell-outline</v-icon>
          <v-tooltip activator="parent" location="bottom">Notifications</v-tooltip>
        </v-btn>
      </template>
      <v-card elevation="8" min-width="360">
        <div class="d-flex align-center justify-space-between px-4 py-3">
          <span class="text-body-1 font-weight-semibold">Notifications</span>
          <div class="d-flex align-center gap-2">
            <v-chip v-if="unreadCount > 0" size="x-small" color="error" variant="tonal">{{ unreadCount }} new</v-chip>
            <v-btn
              v-if="unreadCount > 0"
              variant="text"
              size="x-small"
              color="primary"
              @click.stop="markAllRead"
            >Mark all read</v-btn>
          </div>
        </div>
        <v-divider />
        <v-list density="compact" lines="two" style="max-height: 360px; overflow-y: auto;">
          <template v-if="notifications.length">
            <v-list-item
              v-for="n in notifications"
              :key="n.id"
              :title="n.title"
              :subtitle="n.body ? `${n.body} · ${n.created_at}` : n.created_at"
              class="py-2 cursor-pointer notification-item"
              @click="markRead(n.id, n.url)"
            >
              <template #prepend>
                <v-avatar :color="notificationColor(n.type)" variant="tonal" size="36" class="me-3">
                  <v-icon :color="notificationColor(n.type)" size="18">{{ notificationIcon(n.type) }}</v-icon>
                </v-avatar>
              </template>
              <template #append>
                <v-icon size="8" color="primary" class="ms-2">mdi-circle</v-icon>
              </template>
            </v-list-item>
          </template>
          <div v-else class="pa-6 text-center text-medium-emphasis">
            <v-icon size="32" class="mb-2 text-disabled">mdi-bell-off-outline</v-icon>
            <p class="text-caption">No new notifications</p>
          </div>
        </v-list>
      </v-card>
    </v-menu>
  </div>

  <!-- ── Search overlay dialog ── -->
  <v-dialog v-model="searchOpen" max-width="600" :scrim="true" @keydown.esc="closeSearch">
    <v-card rounded="lg" elevation="24">
      <div class="d-flex align-center pa-4 gap-x-3">
        <v-icon color="medium-emphasis">mdi-magnify</v-icon>
        <input
          v-model="searchQuery"
          placeholder="Search ribbons, modules..."
          autofocus
          class="flex-1 text-body-1 bg-transparent border-0 outline-none"
          style="outline: none; border: none; background: transparent; width: 100%; color: inherit;"
          @keydown.esc="closeSearch"
        />
        <v-btn icon variant="text" size="small" @click="closeSearch">
          <v-icon size="18">mdi-close</v-icon>
        </v-btn>
      </div>
      <v-divider />

      <!-- Results -->
      <div v-if="searchQuery && searchResults.length" style="max-height: 320px; overflow-y: auto;">
        <v-list density="compact">
          <v-list-item
            v-for="result in searchResults"
            :key="result.href"
            :href="result.href"
            :title="result.title"
            prepend-icon="mdi-link-variant"
            rounded="lg"
            class="mx-2 my-1"
            @click="navigate(result.href)"
          />
        </v-list>
      </div>

      <!-- No results -->
      <div v-else-if="searchQuery && !searchResults.length" class="pa-8 text-center text-medium-emphasis">
        <v-icon size="40" class="mb-2 text-disabled">mdi-magnify-remove-outline</v-icon>
        <p class="text-body-2">No results for "{{ searchQuery }}"</p>
      </div>

      <!-- Empty state -->
      <div v-else class="pa-6 text-center text-medium-emphasis">
        <p class="text-caption">Start typing to search through all modules and ribbons</p>
      </div>
    </v-card>
  </v-dialog>
</template>

<style scoped>
/*
 * The shell's icon row was the reason every page in the product scrolled sideways on a phone: it
 * was a flex row that never wrapped, so six 36px controls plus the account menu measured wider than
 * a 375px viewport and dragged the whole document with them. Allowing it to wrap costs nothing on a
 * desktop, where it never needs to.
 */
.nav-actions {
  flex-wrap: wrap;
  row-gap: 0.25rem;
  justify-content: flex-end;
  min-width: 0;
}

/*
 * A 44px hit area, which is the accessibility minimum for a touch target. Vuetify's
 * density="comfortable" gives these 36px. The icon inside stays 22px, so this is a larger target
 * rather than a larger-looking button.
 */
.nav-action {
  min-width: 44px;
  min-height: 44px;
}

@media (max-width: 599px) {
  /* Tighter on the smallest screens, where the row has to share the bar with the brand and the
     account menu — still 44px, just without the extra gap between them. */
  .nav-actions {
    column-gap: 0;
  }
}

.shortcut-item {
  transition: background 0.15s ease;
  cursor: pointer;
}
.shortcut-item:hover {
  background: rgba(var(--v-theme-primary), 0.08);
}
</style>
