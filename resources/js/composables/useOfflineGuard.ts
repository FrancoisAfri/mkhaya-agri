import { ref, readonly, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

// ── Types ─────────────────────────────────────────────────────────────────────

export interface OfflinePolicy {
  enabled: boolean
  max_offline_minutes: number
  warn_before_minutes: number
  lockout_message: string
}

// ── Singleton state ───────────────────────────────────────────────────────────

const isLockedOut    = ref(false)
const warningSeen    = ref(false)
const elapsedMinutes = ref(0)
const showWarning    = ref(false)

let intervalId: ReturnType<typeof setInterval> | null = null
let pollId:     ReturnType<typeof setInterval> | null = null

const STORAGE_KEY = 'mkhaya_offline_started_at'

// ── Helpers ───────────────────────────────────────────────────────────────────

function getPolicy(): OfflinePolicy | null {
  try {
    const page = usePage()
    return (page.props as any).offline_policy ?? null
  } catch {
    return null
  }
}

function recordOfflineStart(userId: number | null) {
  const key = `${STORAGE_KEY}_${userId ?? 'anon'}`
  if (!localStorage.getItem(key)) {
    localStorage.setItem(key, Date.now().toString())
  }
}

function clearOfflineStart(userId: number | null) {
  const key = `${STORAGE_KEY}_${userId ?? 'anon'}`
  localStorage.removeItem(key)
}

function getElapsedMinutes(userId: number | null): number {
  const key   = `${STORAGE_KEY}_${userId ?? 'anon'}`
  const start = parseInt(localStorage.getItem(key) ?? '0', 10)
  if (!start) return 0
  return Math.floor((Date.now() - start) / 60_000)
}

// ── Guard lifecycle ───────────────────────────────────────────────────────────

export function startGuard(userId: number | null) {
  const policy = getPolicy()
  if (!policy?.enabled) return

  recordOfflineStart(userId)
  stopGuard(userId)   // clear any existing interval

  intervalId = setInterval(() => {
    const policy = getPolicy()
    if (!policy?.enabled) return

    const elapsed = getElapsedMinutes(userId)
    elapsedMinutes.value = elapsed

    const warnAt = policy.max_offline_minutes - policy.warn_before_minutes

    if (elapsed >= policy.max_offline_minutes) {
      isLockedOut.value = true
      showWarning.value = false
      startConnectivityPoll(userId)
      if (intervalId) clearInterval(intervalId)
    } else if (elapsed >= warnAt && !warningSeen.value) {
      showWarning.value = true
      warningSeen.value = true
    }
  }, 30_000) // check every 30 seconds
}

export function stopGuard(userId: number | null) {
  if (intervalId) { clearInterval(intervalId); intervalId = null }
  stopConnectivityPoll()
  clearOfflineStart(userId)
  isLockedOut.value = false
  showWarning.value = false
  warningSeen.value = false
  elapsedMinutes.value = 0
}

// Poll for actual connectivity while locked out (navigator.onLine can lie)
function startConnectivityPoll(userId: number | null) {
  if (pollId) return
  pollId = setInterval(async () => {
    try {
      await fetch('/up', { method: 'HEAD', cache: 'no-store' })
      // If we get here, we're truly online
      stopGuard(userId)
      window.dispatchEvent(new CustomEvent('offline-guard-unlocked'))
    } catch {
      // Still offline — keep waiting
    }
  }, 15_000)
}

function stopConnectivityPoll() {
  if (pollId) { clearInterval(pollId); pollId = null }
}

// ── Computed helpers for the UI ───────────────────────────────────────────────

export function useOfflineGuard() {
  const policy = computed<OfflinePolicy | null>(getPolicy)

  const minutesRemaining = computed(() => {
    if (!policy.value) return 0
    return Math.max(0, policy.value.max_offline_minutes - elapsedMinutes.value)
  })

  const elapsedFormatted = computed(() => {
    const h = Math.floor(elapsedMinutes.value / 60)
    const m = elapsedMinutes.value % 60
    return h > 0 ? `${h}h ${m}m` : `${m}m`
  })

  const limitFormatted = computed(() => {
    if (!policy.value) return ''
    const total = policy.value.max_offline_minutes
    const h = Math.floor(total / 60)
    const m = total % 60
    return h > 0 ? (m > 0 ? `${h}h ${m}m` : `${h}h`) : `${m}m`
  })

  return {
    isLockedOut:       readonly(isLockedOut),
    showWarning:       readonly(showWarning),
    elapsedMinutes:    readonly(elapsedMinutes),
    minutesRemaining,
    elapsedFormatted,
    limitFormatted,
    policy,
    startGuard,
    stopGuard,
  }
}
