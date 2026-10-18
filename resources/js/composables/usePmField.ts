import { ref, readonly } from 'vue'
import axios from 'axios'
import { offlineDb, type FieldTask, type FieldChecklistItem } from '@/lib/offlineDb'
import { enqueue, syncQueue } from '@/composables/useOfflineQueue'

/**
 * The field loop: pull a day's work, work through it with no signal, push it back (EPIC-060/061).
 *
 * Every change is written to IndexedDB first and queued second. The screen therefore shows what the
 * person just did, immediately, whether or not there is a network — which is the whole point. A
 * spinner on a task tick in a basement is a feature nobody uses twice.
 *
 * Conflicts are avoided by construction rather than resolved. A status and a checklist tick are
 * declarations of a final state, so two devices arriving in either order settle on the same answer.
 * Anything that genuinely needed merging would be evidence, and evidence is append-only.
 */

const syncing = ref(false)
const lastSyncedAt = ref<string | null>(null)
const lastError = ref<string | null>(null)
const truncated = ref(false)

async function readMeta() {
  const meta = await offlineDb.pmMeta.get('pm')
  lastSyncedAt.value = meta?.synced_at ?? null
  truncated.value = meta?.truncated ?? false
}

/**
 * Pull the bundle. Sends `since` when we have synced before, so every sync after the first carries
 * only what moved — which is what makes syncing on the way out of the yard realistic.
 */
export async function pull(): Promise<void> {
  if (syncing.value) return

  syncing.value = true
  lastError.value = null

  try {
    await readMeta()

    const { data } = await axios.get('/api/v1/pm/sync', {
      params: lastSyncedAt.value ? { since: lastSyncedAt.value } : {},
      headers: { Accept: 'application/json' },
    })

    await offlineDb.transaction('rw', offlineDb.pmProjects, offlineDb.pmTasks, offlineDb.pmChecklist, offlineDb.pmMeta, async () => {
      // bulkPut, not clear-then-insert: a delta must not wipe the tasks it did not mention, and a
      // failed write halfway through a clear would leave the phone with nothing at all.
      if (data.projects?.length) await offlineDb.pmProjects.bulkPut(data.projects)
      if (data.tasks?.length) await offlineDb.pmTasks.bulkPut(data.tasks)
      if (data.checklists?.length) await offlineDb.pmChecklist.bulkPut(data.checklists)

      await offlineDb.pmMeta.put({
        id: 'pm',
        synced_at: data.synced_at,
        truncated: data.limits?.truncated ?? false,
      })
    })

    await readMeta()
  } catch (error: any) {
    // Offline is not an error worth shouting about — it is the expected state on a site.
    lastError.value = navigator.onLine
      ? (error?.response?.data?.detail ?? 'Could not reach the server.')
      : null
  } finally {
    syncing.value = false
  }
}

/** Everything cached, assigned work first. */
export async function tasks(projectId?: string): Promise<FieldTask[]> {
  const all = await offlineDb.pmTasks.toArray()

  return all
    .filter(t => !projectId || t.project_id === projectId)
    .sort((a, b) => {
      if (a.is_assigned !== b.is_assigned) return a.is_assigned ? -1 : 1
      return (a.planned_end ?? '9999').localeCompare(b.planned_end ?? '9999')
    })
}

export async function checklistFor(taskId: string): Promise<FieldChecklistItem[]> {
  return offlineDb.pmChecklist.where('task_id').equals(taskId).toArray()
}

/**
 * Change a task's status.
 *
 * Written locally first so the tick is instant, then queued. `local_status` is what the screen
 * reads until a pull overwrites the row — the person's own action is never the thing that
 * disappears when they scroll away.
 */
export async function setTaskStatus(taskId: string, status: string, userId: number | null): Promise<void> {
  await offlineDb.pmTasks.update(taskId, { local_status: status })

  await enqueue(
    'patch',
    `/api/v1/pm/tasks/${taskId}`,
    { status, occurred_at: new Date().toISOString() },
    `Task → ${status}`,
    userId,
  )
}

export async function tickChecklistItem(itemId: string, done: boolean, userId: number | null): Promise<void> {
  await offlineDb.pmChecklist.update(itemId, { local_is_done: done })

  await enqueue('patch', `/api/v1/pm/checklist/${itemId}`, { is_done: done }, 'Checklist', userId)
}

/**
 * Record measured quantity as evidence.
 *
 * The client generates the uuid (PM-ADR-004) so the record has an identity before it has ever
 * reached a server — which is what lets a photo be attached to it, and what makes a retry the same
 * record rather than a second one.
 */
export async function recordQuantity(
  projectId: string,
  taskId: string,
  quantity: number,
  unit: string | null,
  userId: number | null,
): Promise<void> {
  const uuid = crypto.randomUUID()

  await enqueue(
    'post',
    '/api/v1/pm/evidence',
    {
      uuid,
      project_id: projectId,
      subject_type: 'task',
      subject_id: taskId,
      event_type: 'quantity.installed',
      source_module: 'mobile',
      source_ref: uuid,
      occurred_at: new Date().toISOString(),
      occurred_tz: Intl.DateTimeFormat().resolvedOptions().timeZone,
      actor_type: 'user',
      quantity,
      unit,
      payload: { quantity },
    },
    `${quantity}${unit ? ' ' + unit : ''} installed`,
    userId,
    { 'Idempotency-Key': uuid },
  )
}

export async function logHours(
  projectId: string,
  taskId: string | null,
  hours: number,
  workedOn: string,
  userId: number | null,
): Promise<void> {
  await enqueue(
    'post',
    '/api/v1/pm/time',
    { project_id: projectId, task_id: taskId, worked_on: workedOn, hours },
    `${hours}h booked`,
    userId,
  )
}

/** Push everything queued, then pull, so the phone ends the sync holding the server's version. */
export async function fullSync(userId: number | null): Promise<void> {
  await syncQueue(userId)
  await pull()
}

export function usePmField() {
  return {
    syncing: readonly(syncing),
    lastSyncedAt: readonly(lastSyncedAt),
    lastError: readonly(lastError),
    truncated: readonly(truncated),
    pull,
    tasks,
    checklistFor,
    setTaskStatus,
    tickChecklistItem,
    recordQuantity,
    logHours,
    fullSync,
    readMeta,
  }
}
