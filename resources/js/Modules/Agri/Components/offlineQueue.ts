/**
 * The phone's queue of changes made without signal (spec Module K).
 *
 * Kept in localStorage per user, so it survives the app being closed or the phone restarting. Each
 * change gets its id here, at the moment the person makes it; the server applies an id once, so
 * sending the queue again after a dropped connection is always safe.
 */
import axios from 'axios'
import { route } from 'ziggy-js'

export type QueuedOp = { client_id: string; op: string; payload: Record<string, any>; captured_at: string; label: string }
export type SyncResult = { client_id: string; status: 'applied' | 'rejected'; message: string | null }

const key = (userId: number | string) => `agri-offline-queue:${userId}`

export const uuid = () => (crypto as any).randomUUID ? crypto.randomUUID()
  : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })

export function readQueue(userId: number | string): QueuedOp[] {
  try { return JSON.parse(localStorage.getItem(key(userId)) ?? '[]') } catch { return [] }
}

function writeQueue(userId: number | string, ops: QueuedOp[]) {
  try { localStorage.setItem(key(userId), JSON.stringify(ops)) } catch { /* storage full or blocked: nothing more we can do */ }
}

export function enqueue(userId: number | string, op: string, payload: Record<string, any>, label: string): QueuedOp {
  const item = { client_id: uuid(), op, payload, captured_at: new Date().toISOString(), label }
  writeQueue(userId, [...readQueue(userId), item])
  return item
}

/** Send everything queued. Applied and rejected changes leave the queue; a network failure keeps it. */
export async function flush(userId: number | string): Promise<SyncResult[] | null> {
  const ops = readQueue(userId)
  if (!ops.length) return []
  try {
    const { data } = await axios.post(route('agri.my-work.sync'), {
      ops: ops.map(({ client_id, op, payload, captured_at }) => ({ client_id, op, payload, captured_at })),
    })
    const done = new Set((data.results as SyncResult[]).map(r => r.client_id))
    writeQueue(userId, readQueue(userId).filter(o => !done.has(o.client_id)))
    return data.results
  } catch {
    return null
  }
}

export const isOffline = () => typeof navigator !== 'undefined' && navigator.onLine === false

/** Register the service worker that keeps My Work and its assets on the phone. */
export function registerAgriServiceWorker() {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return
  navigator.serviceWorker.register('/agri/sw.js', { scope: '/agri/' }).catch(() => { /* offline support is a bonus, never a blocker */ })
}
