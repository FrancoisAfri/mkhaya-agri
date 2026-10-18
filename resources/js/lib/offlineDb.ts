import Dexie, { type Table } from 'dexie'

export interface PendingRequest {
  id?: number
  uuid: string
  userId: number | null   // owner — only synced when this user is logged in
  method: string
  url: string
  data: Record<string, any>
  /**
   * Sent with the request on every attempt. Carries Idempotency-Key: a queue that retries without
   * one re-books the hours it already booked, which is the single worst thing an offline client
   * can do. Generated when the row is queued, so every retry presents the same key.
   */
  headers?: Record<string, string>
  label: string
  created_at: string
  retries: number
}

/** A project, cached so the phone can show the job with no signal (EPIC-060). */
export interface FieldProject {
  id: string
  code: string
  name: string
  status: string
  timezone: string | null
}

/** A task as the field sees it, plus whatever this device has changed but not yet synced. */
export interface FieldTask {
  id: string
  project_id: string
  code: string
  name: string
  status: string
  planned_end: string | null
  progress_pct: number
  is_assigned: boolean
  planned_quantity: number | null
  quantity_unit: string | null
  /** Set locally the moment someone taps, so the screen never lies about what they just did. */
  local_status?: string
  updated_at: string | null
}

export interface FieldChecklistItem {
  id: string
  task_id: string
  text: string
  is_done: boolean
  local_is_done?: boolean
}

/** One row, id 'pm' — when this device last synced, so `?since=` can be a delta. */
export interface FieldMeta {
  id: string
  synced_at: string | null
  truncated: boolean
}

class OfflineDatabase extends Dexie {
  pending!: Table<PendingRequest>
  pmProjects!: Table<FieldProject>
  pmTasks!: Table<FieldTask>
  pmChecklist!: Table<FieldChecklistItem>
  pmMeta!: Table<FieldMeta>

  constructor() {
    super('MkhayaHubOfflineDB')

    // v1 — original schema (no userId)
    this.version(1).stores({
      pending: '++id, uuid, created_at',
    })

    // v2 — adds userId index; existing rows get userId = null (will sync for any user)
    this.version(2).stores({
      pending: '++id, uuid, userId, created_at',
    }).upgrade(tx =>
      tx.table('pending').toCollection().modify((row: any) => {
        if (row.userId === undefined) row.userId = null
      })
    )

    // v3 — the Project Management field bundle, and headers on queued requests so an offline
    // write can carry its Idempotency-Key through every retry.
    this.version(3).stores({
      pending:     '++id, uuid, userId, created_at',
      pmProjects:  'id, code',
      pmTasks:     'id, project_id, is_assigned, planned_end',
      pmChecklist: 'id, task_id',
      pmMeta:      'id',
    })
  }
}

export const offlineDb = new OfflineDatabase()
