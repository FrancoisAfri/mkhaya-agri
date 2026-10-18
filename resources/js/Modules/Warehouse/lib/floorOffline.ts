import Dexie, { type Table } from 'dexie'
import { enqueue } from '@/composables/useOfflineQueue'

/**
 * The floor's offline store (WMS-153). It keeps the person's own task bundle -- never a stock
 * balance -- encrypted with an AES-GCM key the browser generates and will not export, and a list of
 * what was done here so the screen shows it. Actions themselves go through the shared offline queue,
 * each with its own reference as the Idempotency-Key, so a retry is the same action, not another.
 */
interface Sealed { id: string; iv: Uint8Array; data: ArrayBuffer; saved_at: string }
interface KeyRow { id: string; key: CryptoKey }
export interface DoneRow { ref: string; kind: string; target: string; summary: string; at: string }

class FloorDb extends Dexie {
  bundles!: Table<Sealed>
  keys!: Table<KeyRow>
  done!: Table<DoneRow>
  constructor() {
    super('MkhayaWarehouseFloor')
    this.version(1).stores({ bundles: 'id', keys: 'id', done: 'ref, kind, target' })
  }
}

const db = new FloorDb()
const canEncrypt = typeof crypto !== 'undefined' && !!crypto.subtle

async function key(): Promise<CryptoKey | null> {
  if (!canEncrypt) return null
  const row = await db.keys.get('k')
  if (row) return row.key
  const k = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
  await db.keys.put({ id: 'k', key: k })
  return k
}

/** Plain JSON in and out: IndexedDB will not clone a Vue proxy. */
export async function saveBundle(userId: number, bundle: unknown): Promise<void> {
  const json = new TextEncoder().encode(JSON.stringify(bundle))
  const k = await key()
  const iv = crypto.getRandomValues(new Uint8Array(12))
  const data = k ? await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, k, json) : json.buffer
  await db.bundles.put({ id: `u${userId}`, iv, data, saved_at: new Date().toISOString() })
}

export async function loadBundle(userId: number): Promise<{ bundle: any; saved_at: string } | null> {
  const row = await db.bundles.get(`u${userId}`)
  if (!row) return null
  const k = await key()
  const plain = k ? await crypto.subtle.decrypt({ name: 'AES-GCM', iv: row.iv }, k, row.data) : row.data
  return { bundle: JSON.parse(new TextDecoder().decode(plain)), saved_at: row.saved_at }
}

export async function forget(userId: number): Promise<void> {
  await db.bundles.delete(`u${userId}`)
  await db.done.clear()
}

/** Record an action here and queue it for the server; the reference is made once, here. */
export async function record(kind: string, payload: Record<string, unknown>, target: string, summary: string, userId: number, syncUrl: string): Promise<string> {
  const ref = crypto.randomUUID()
  const clean = JSON.parse(JSON.stringify(payload))
  await enqueue('POST', syncUrl, { client_ref: ref, kind, recorded_at: new Date().toISOString(), payload: clean }, summary, userId, { 'Idempotency-Key': ref })
  await db.done.put({ ref, kind, target, summary, at: new Date().toISOString() })
  return ref
}

export async function doneTargets(): Promise<Set<string>> {
  return new Set((await db.done.toArray()).map(d => `${d.kind}:${d.target}`))
}

export async function recent(): Promise<DoneRow[]> {
  return (await db.done.orderBy('ref').toArray()).sort((a, b) => b.at.localeCompare(a.at)).slice(0, 30)
}

/** A short tone and a buzz: scanning is done by ear as much as by eye. */
export function signal(kind: 'ok' | 'error') {
  try {
    const ctx = new (window.AudioContext || (window as any).webkitAudioContext)()
    const o = ctx.createOscillator()
    o.frequency.value = kind === 'ok' ? 1200 : 220
    o.connect(ctx.destination)
    o.start()
    o.stop(ctx.currentTime + (kind === 'ok' ? 0.08 : 0.3))
  } catch { /* no audio on this device */ }
  navigator.vibrate?.(kind === 'ok' ? 40 : [80, 60, 80])
}
