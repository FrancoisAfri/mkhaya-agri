import { ref, readonly } from 'vue'
import axios from 'axios'
import { offlineDb } from '@/lib/offlineDb'

// ── Singleton state ───────────────────────────────────────────────────────────

const isOnline     = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const pendingCount = ref(0)
const syncStatus   = ref<'idle' | 'syncing'>('idle')

export interface SyncResult {
  success: number
  failed: number
  failedLabels: string[]
}

const lastSyncResult = ref<SyncResult | null>(null)

export interface StorageEstimate {
  usageBytes:  number
  quotaBytes:  number
  usedMB:      string
  totalMB:     string
  percentUsed: number
  isPersisted: boolean
}

const storageEstimate = ref<StorageEstimate | null>(null)

// ── Helpers ───────────────────────────────────────────────────────────────────

async function refreshCount() {
  pendingCount.value = await offlineDb.pending.count()
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

function deriveLabel(method: string, url: string): string {
  try {
    const parts = new URL(url, location.origin).pathname.split('/').filter(Boolean)
    return `${method.toUpperCase()} /${parts.slice(0, 3).join('/')}`
  } catch {
    return `${method.toUpperCase()} ${url}`
  }
}

// ── Storage ───────────────────────────────────────────────────────────────────

export async function refreshStorageEstimate(): Promise<void> {
  if (!navigator.storage?.estimate) return
  const { usage = 0, quota = 0 } = await navigator.storage.estimate()
  const isPersisted = (await navigator.storage.persisted?.()) ?? false
  storageEstimate.value = {
    usageBytes:  usage,
    quotaBytes:  quota,
    usedMB:      (usage / 1024 / 1024).toFixed(1),
    totalMB:     (quota / 1024 / 1024).toFixed(0),
    percentUsed: quota > 0 ? Math.round((usage / quota) * 100) : 0,
    isPersisted,
  }
}

async function requestPersist(): Promise<void> {
  if (navigator.storage?.persist) {
    await navigator.storage.persist()
    await refreshStorageEstimate()
  }
}

// ── Queue operations ──────────────────────────────────────────────────────────

export async function enqueue(
  method: string,
  url: string,
  data: Record<string, any>,
  label = '',
  userId: number | null = null,
  headers: Record<string, string> = {},
): Promise<void> {
  const uuid = crypto.randomUUID()

  await offlineDb.pending.add({
    uuid,
    userId,
    method:     method.toUpperCase(),
    url,
    data,
    // The row's own id doubles as the idempotency key unless the caller named one. Generated here,
    // once, so all five retry attempts present the same key and the server replays rather than
    // repeats.
    headers:    { 'Idempotency-Key': uuid, ...headers },
    label:      label || deriveLabel(method, url),
    created_at: new Date().toISOString(),
    retries:    0,
  })
  await refreshCount()
}

/**
 * Drain the queue for the given user.
 * userId = null means "sync requests that have no owner" (migrated legacy rows).
 */
export async function syncQueue(userId: number | null = null): Promise<void> {
  if (syncStatus.value === 'syncing') return

  // Match rows owned by this user OR ownerless legacy rows
  const all = await offlineDb.pending
    .orderBy('created_at')
    .filter(r => r.userId === userId || r.userId === null)
    .toArray()

  if (!all.length) return

  syncStatus.value     = 'syncing'
  lastSyncResult.value = null

  let success = 0
  const failedLabels: string[] = []

  for (const req of all) {
    try {
      await axios({
        method:  req.method as any,
        url:     req.url,
        data:    req.data,
        headers: {
          'X-CSRF-TOKEN':   csrfToken(),
          'Content-Type':   'application/json',
          'Accept':         'application/json',
          'X-Offline-Sync': '1',
          // Whatever the caller queued — chiefly Idempotency-Key, which has to be the same on
          // every retry or the retry is a second write rather than the same one.
          ...(req.headers ?? {}),
        },
      })
      await offlineDb.pending.delete(req.id!)
      success++
    } catch (err: any) {
      const status = err?.response?.status

      // 419 = CSRF mismatch (session just refreshed) — keep for next attempt
      // 422 = validation error — keep so user can review
      // 401/403 = auth problem — discard (session is gone)
      if (status === 401 || status === 403) {
        failedLabels.push(req.label + ' (auth error)')
        await offlineDb.pending.delete(req.id!)
      } else {
        const newRetries = req.retries + 1
        if (newRetries >= 5) {
          failedLabels.push(req.label)
          await offlineDb.pending.delete(req.id!)
        } else {
          await offlineDb.pending.update(req.id!, { retries: newRetries })
        }
      }
    }
  }

  lastSyncResult.value = { success, failed: failedLabels.length, failedLabels }
  syncStatus.value     = 'idle'
  await refreshCount()
  await refreshStorageEstimate()
}

/** How many requests are waiting for a specific user. */
export async function pendingCountForUser(userId: number | null): Promise<number> {
  return offlineDb.pending
    .filter(r => r.userId === userId || r.userId === null)
    .count()
}

// ── Public API ────────────────────────────────────────────────────────────────

export function useOfflineQueue() {
  return {
    isOnline:             readonly(isOnline),
    pendingCount:         readonly(pendingCount),
    syncStatus:           readonly(syncStatus),
    lastSyncResult:       readonly(lastSyncResult),
    storageEstimate:      readonly(storageEstimate),
    enqueue,
    syncQueue,
    refreshCount,
    refreshStorageEstimate,
    pendingCountForUser,
  }
}

// ── Bootstrap once (module load) ──────────────────────────────────────────────

if (typeof window !== 'undefined') {
  // online event: user regained connectivity — sync for whoever is currently logged in
  window.addEventListener('online', () => {
    isOnline.value = true
    // userId is resolved by the caller (MainLayout) via the 'offline-sync-now' custom event
    window.dispatchEvent(new CustomEvent('offline-sync-now'))
    refreshStorageEstimate()
  })

  window.addEventListener('offline', () => {
    isOnline.value = false
  })

  refreshCount()
  refreshStorageEstimate()
  requestPersist()
}
