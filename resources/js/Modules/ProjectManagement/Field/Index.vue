<script setup lang="ts">
/**
 * My Work — everything assigned to one person, across every project, on a board.
 *
 * ── Why the board comes from the server ──────────────────────────────────────
 *
 * This screen used to render entirely from IndexedDB so that a phone with no reception showed the
 * day's work instead of an error. The goal was right; the consequence was that a browser which had
 * never completed a sync showed "No work assigned to you yet" to somebody holding a list of
 * assigned tasks. An empty cache and an empty workload looked identical, and the page picked the
 * reassuring reading of the two.
 *
 * So the board arrives with the page. The cache still exists and still fills from the same sync,
 * but it is now the fallback: when the network is gone, the screen says "this is what we last saw"
 * rather than "you have nothing to do".
 *
 * ── Why moving a card here moves it on the project ───────────────────────────
 *
 * Dropping a card posts to project-management.board.move — the same route the project board posts
 * to, not a parallel one that writes the same column. There is one status per task and two screens
 * looking at it, which is the only arrangement in which the two cannot disagree.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useFitToViewport } from '@/composables/useFitToViewport'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useOfflineQueue } from '@/composables/useOfflineQueue'
import { usePmField } from '@/composables/usePmField'
import type { FieldTask, FieldChecklistItem } from '@/lib/offlineDb'

interface Card {
  id: string
  code: string
  name: string
  status: string
  priority: string | null
  due: string | null
  project: { id: string | null; code: string | null; name: string | null }
}

const props = defineProps<{
  columns: string[]
  tasks: Record<string, Card[]>
  people: { id: number; name: string }[]
  subject: number
  canPickPerson: boolean
}>()

const { t } = useI18n()
const page = usePage()

const { isOnline, pendingCount, lastSyncResult } = useOfflineQueue()
const field = usePmField()

const userId = computed(() => (page.props.auth as any)?.user?.id ?? null)

/** The cached rows, kept only so the board still has something to draw with no signal. */
const cached = ref<FieldTask[]>([])
const checklist = ref<FieldChecklistItem[]>([])
const openTask = ref<Card | null>(null)
const busy = ref(false)

const serverCount = computed(() =>
  props.columns.reduce((n, key) => n + (props.tasks[key]?.length ?? 0), 0))

/**
 * Server first, cache only when there is nothing from the server AND no network to explain it.
 *
 * The order matters: falling back whenever the server list is empty would hide a genuinely empty
 * workload behind stale cards from last week.
 */
const usingCache = computed(() => serverCount.value === 0 && !isOnline.value && cached.value.length > 0)

const board = computed<Record<string, Card[]>>(() => {
  if (!usingCache.value) return props.tasks

  const grouped: Record<string, Card[]> = Object.fromEntries(props.columns.map(k => [k, []]))

  for (const row of cached.value) {
    const status = row.local_status ?? row.status
    if (!grouped[status]) continue
    grouped[status].push({
      id: row.id,
      code: row.code,
      name: row.name,
      status,
      priority: null,
      due: row.planned_end?.slice(0, 10) ?? null,
      project: { id: row.project_id, code: null, name: null },
    })
  }

  return grouped
})

const total = computed(() => props.columns.reduce((n, k) => n + (board.value[k]?.length ?? 0), 0))

/** Only offered to somebody with more than themselves to look at. */
const subject = ref<number>(props.subject)
watch(() => props.subject, v => { subject.value = v })

function viewPerson(id: number) {
  router.get(route('project-management.field'), { user: id }, {
    preserveState: true, preserveScroll: true, replace: true,
  })
}

async function refreshCache() {
  cached.value = await field.tasks()
}

async function sync() {
  busy.value = true
  await field.fullSync(userId.value)
  await refreshCache()
  busy.value = false
  // The board itself is server data, so a sync has to bring the page with it.
  router.reload({ only: ['tasks'] })
}

// ── Moving a card ────────────────────────────────────────────────────────────

const dragging = ref<string | null>(null)

function cardById(id: string): Card | null {
  for (const key of props.columns) {
    const found = (board.value[key] ?? []).find(c => c.id === id)
    if (found) return found
  }

  return null
}

function drop(status: string) {
  const id = dragging.value
  dragging.value = null
  if (!id) return

  const card = cardById(id)
  if (!card || card.status === status) return

  move(card, status)
}

/**
 * Online, this is the project board's own write. Offline it is queued locally and replayed, which
 * is the whole reason the field cache exists — a card moved in a basement must not be lost.
 */
function move(card: Card, status: string) {
  if (!isOnline.value || !card.project.id) {
    field.setTaskStatus(card.id, status, userId.value).then(refreshCache)

    return
  }

  router.patch(
    route('project-management.board.move', [card.project.id, card.id]),
    { status },
    { preserveScroll: true, preserveState: true, only: ['tasks'] },
  )
}

async function open(card: Card) {
  openTask.value = card
  checklist.value = await field.checklistFor(card.id)
}

/** The cached row behind the open card, where there is one — it carries the field extras. */
const openCached = computed(() =>
  openTask.value ? cached.value.find(r => r.id === openTask.value!.id) ?? null : null)

async function tick(item: FieldChecklistItem, done: boolean) {
  await field.tickChecklistItem(item.id, done, userId.value)
  checklist.value = await field.checklistFor(openTask.value!.id)
}

const quantity = ref<number | null>(null)

async function recordQuantity() {
  const row = openCached.value
  if (!row || !quantity.value) return

  await field.recordQuantity(row.project_id, row.id, quantity.value, row.quantity_unit, userId.value)
  quantity.value = null
}

const hours = ref<number | null>(null)

async function bookHours() {
  if (!openTask.value || !hours.value) return

  await field.logHours(
    openCached.value?.project_id ?? openTask.value.project.id ?? '',
    openTask.value.id,
    hours.value,
    new Date().toISOString().slice(0, 10),
    userId.value,
  )

  hours.value = null
}

onMounted(async () => {
  await field.readMeta()
  await refreshCache()

  // Only reach for the network if there is one. A failed request on a site with no signal is a
  // spinner and a scary message for something that is working exactly as designed.
  if (isOnline.value) await sync()
})

/*
 * Same fixed height as the project board, and for the same reason: the columns scroll, the page
 * does not. Measured rather than a `calc(100dvh - …)`, because this sits below the shell's navbar
 * and above its footer and neither has a height worth guessing at.
 */
const { el: pageEl, height: pageHeight } = useFitToViewport()

const statusColour: Record<string, string> = {
  not_started: 'grey', in_progress: 'info', blocked: 'error', done: 'success', cancelled: 'grey',
}
</script>

<template>
  <div ref="pageEl" class="pa-3 d-flex flex-column field-page" :style="{ blockSize: pageHeight }">
    <!-- Connection first: on a site, "will this save?" is the only question that matters. -->
    <VAlert
      :type="isOnline ? 'success' : 'warning'" variant="tonal" density="compact" class="mb-3"
      :icon="isOnline ? 'tabler-wifi' : 'tabler-wifi-off'"
    >
      <div class="d-flex align-center justify-space-between flex-wrap gap-2">
        <div>
          <div class="text-body-2">
            {{ isOnline ? t('project_management.field.online') : t('project_management.field.offline') }}
          </div>
          <div class="text-caption">
            <template v-if="pendingCount">
              {{ t('project_management.field.waiting_to_sync', { count: pendingCount }) }}
            </template>
            <template v-else-if="field.lastSyncedAt.value">
              {{ t('project_management.field.last_synced', { when: new Date(field.lastSyncedAt.value).toLocaleString() }) }}
            </template>
            <template v-else>{{ t('project_management.field.never_synced') }}</template>
          </div>
        </div>
        <VBtn
          size="small" variant="tonal" prepend-icon="tabler-refresh"
          :loading="busy || field.syncing.value" :disabled="!isOnline" @click="sync"
        >
          {{ t('project_management.field.sync_now') }}
        </VBtn>
      </div>
    </VAlert>

    <VAlert v-if="lastSyncResult && lastSyncResult.failed" type="error" variant="tonal" density="compact" class="mb-3">
      <div class="text-body-2">{{ t('project_management.field.some_did_not_sync', { count: lastSyncResult.failed }) }}</div>
      <ul class="text-caption mb-0 ps-4">
        <li v-for="label in lastSyncResult.failedLabels" :key="label">{{ label }}</li>
      </ul>
      <div class="text-caption mt-1">{{ t('project_management.field.tell_the_office') }}</div>
    </VAlert>

    <!-- Said plainly, because "what you are looking at is old" is not a detail. -->
    <VAlert v-if="usingCache" type="info" variant="tonal" density="compact" class="mb-3">
      {{ t('project_management.field.showing_cached') }}
    </VAlert>

    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
      <h5 class="text-h5 mb-0">{{ t('project_management.field.my_work') }}</h5>

      <!-- A manager's crew are the people on their jobs; somebody with one project sees one name. -->
      <VAutocomplete
        v-if="canPickPerson"
        :model-value="subject"
        :items="people.map(p => ({ title: p.name, value: p.id }))"
        :label="t('project_management.field.showing_work_for')"
        density="compact" variant="outlined" hide-details
        prepend-inner-icon="tabler-user"
        style="max-width: 280px"
        @update:model-value="viewPerson"
      />
    </div>

    <!--
      Columns scroll on their own, and the row scrolls sideways. A board that grows the page
      instead loses its own headings the moment one column is longer than the screen.
    -->
    <div class="d-flex gap-3 overflow-x-auto pb-2 align-start field-strip">
      <div
        v-for="column in columns" :key="column"
        class="flex-shrink-0 rounded-lg pm-column d-flex flex-column"
        @dragover.prevent
        @drop="drop(column)"
      >
        <div class="d-flex align-center justify-space-between px-3 py-2">
          <span class="text-body-2 font-weight-medium">
            {{ t(`project_management.task_status.${column}`) }}
          </span>
          <VChip size="x-small" label variant="tonal">{{ (board[column] ?? []).length }}</VChip>
        </div>
        <VDivider />

        <div class="pa-2 pm-column-body flex-grow-1">
          <VCard
            v-for="card in board[column]" :key="card.id"
            border class="mb-2" draggable="true"
            @dragstart="dragging = card.id"
            @dragend="dragging = null"
            @click="open(card)"
          >
            <VCardText class="pa-3">
              <div class="text-caption text-medium-emphasis d-flex align-center justify-space-between">
                <span>{{ card.code }}</span>
                <span v-if="card.project.code">{{ card.project.code }}</span>
              </div>
              <div class="text-body-2 mt-1">{{ card.name }}</div>
              <div v-if="card.due" class="text-caption text-medium-emphasis mt-1">
                {{ t('project_management.field.due', { date: card.due }) }}
              </div>
            </VCardText>

            <VDivider />
            <!-- Dragging is not available to everybody on every device, so the move is also a menu. -->
            <VCardActions class="py-1 px-2">
              <VMenu location="bottom start">
                <template #activator="{ props: mp }">
                  <VBtn
                    size="x-small" variant="text" v-bind="mp"
                    prepend-icon="tabler-arrows-exchange" @click.stop
                  >
                    {{ t('project_management.field.move') }}
                  </VBtn>
                </template>
                <VList density="compact">
                  <VListItem
                    v-for="target in columns.filter(c => c !== card.status)" :key="target"
                    :title="t(`project_management.task_status.${target}`)"
                    @click.stop="move(card, target)"
                  />
                </VList>
              </VMenu>
              <VSpacer />
              <VChip size="x-small" label :color="statusColour[card.status] ?? 'grey'">
                {{ t(`project_management.task_status.${card.status}`) }}
              </VChip>
            </VCardActions>
          </VCard>

          <div
            v-if="!(board[column] ?? []).length"
            class="text-caption text-medium-emphasis text-center py-6"
          >
            —
          </div>
        </div>
      </div>
    </div>

    <VCard v-if="!total" border class="mt-3">
      <VCardText class="text-center text-medium-emphasis py-8">
        {{ isOnline ? t('project_management.field.nothing_yet') : t('project_management.field.nothing_cached') }}
      </VCardText>
    </VCard>

    <!-- One task -->
    <VDialog :model-value="openTask !== null" fullscreen transition="dialog-bottom-transition"
             @update:model-value="openTask = null">
      <VCard v-if="openTask">
        <VToolbar density="compact">
          <VBtn icon="tabler-x" :aria-label="t('project_management.actions.close')" @click="openTask = null" />
          <VToolbarTitle class="text-body-1">{{ openTask.code }}</VToolbarTitle>
        </VToolbar>

        <VCardText>
          <h6 class="text-h6 mb-1">{{ openTask.name }}</h6>
          <div v-if="openTask.project.name" class="text-caption text-medium-emphasis mb-3">
            {{ openTask.project.name }}
          </div>

          <!-- Big targets: this is used in gloves, in sunlight, one-handed. -->
          <div class="d-flex flex-wrap gap-2 mb-4">
            <VBtn
              v-if="openTask.status === 'not_started'" size="small" color="info"
              prepend-icon="tabler-player-play" @click="move(openTask, 'in_progress')"
            >
              {{ t('project_management.field.start') }}
            </VBtn>
            <VBtn
              v-if="openTask.status === 'in_progress'" size="small" color="success"
              prepend-icon="tabler-check" @click="move(openTask, 'done')"
            >
              {{ t('project_management.field.finish') }}
            </VBtn>
            <VBtn
              v-if="openTask.status !== 'done' && openTask.status !== 'blocked'" size="small"
              variant="text" color="error" @click="move(openTask, 'blocked')"
            >
              {{ t('project_management.field.blocked') }}
            </VBtn>
          </div>

          <template v-if="checklist.length">
            <div class="text-body-2 font-weight-medium mb-1">{{ t('project_management.tasks.checklist') }}</div>
            <VList density="compact" class="mb-4 py-0">
              <VListItem v-for="item in checklist" :key="item.id" class="px-0">
                <template #prepend>
                  <VCheckbox
                    :model-value="item.local_is_done ?? item.is_done"
                    density="compact" hide-details color="primary"
                    :aria-label="item.text"
                    @update:model-value="v => tick(item, !!v)"
                  />
                </template>
                <VListItemTitle class="text-body-2">{{ item.text }}</VListItemTitle>
              </VListItem>
            </VList>
          </template>

          <template v-if="openCached?.planned_quantity">
            <div class="text-body-2 font-weight-medium mb-1">
              {{ t('project_management.field.record_quantity') }}
            </div>
            <div class="text-caption text-medium-emphasis mb-2">
              {{ t('project_management.field.of_planned', {
                planned: openCached.planned_quantity, unit: openCached.quantity_unit ?? '' }) }}
            </div>
            <div class="d-flex gap-2 mb-4">
              <VTextField
                v-model.number="quantity" type="number" inputmode="decimal" min="0"
                :suffix="openCached.quantity_unit ?? ''"
                :aria-label="t('project_management.field.record_quantity')"
                variant="outlined" density="compact" hide-details
              />
              <VBtn color="primary" :disabled="!quantity" @click="recordQuantity">
                {{ t('project_management.field.record') }}
              </VBtn>
            </div>
          </template>

          <div class="text-body-2 font-weight-medium mb-1">{{ t('project_management.field.book_hours') }}</div>
          <div class="d-flex gap-2">
            <VTextField
              v-model.number="hours" type="number" inputmode="decimal" step="0.5" min="0.25" max="24"
              :suffix="t('project_management.delivery.hours')"
              :aria-label="t('project_management.field.book_hours')"
              variant="outlined" density="compact" hide-details
            />
            <VBtn color="primary" :disabled="!hours" @click="bookHours">
              {{ t('project_management.field.book') }}
            </VBtn>
          </div>

          <p class="text-caption text-medium-emphasis mt-4 mb-0">
            {{ t('project_management.field.saved_locally') }}
          </p>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/* Height comes from useFitToViewport — see the composable for why it is measured, not calculated. */
.field-page { overflow: hidden; }

/*
 * Takes whatever is left below the banners. `min-block-size: 0` is the load-bearing line: without
 * it a flex child refuses to shrink under its content and the strip grows to its tallest column,
 * which puts the page back to scrolling.
 */
.field-strip {
  flex: 1 1 auto;
  min-block-size: 0;
}

.pm-column {
  inline-size: 300px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  block-size: 100%;
  min-block-size: 0;
}

/*
 * Each column scrolls inside its own height rather than growing the page, so the headings stay put
 * when one column is much longer than the others.
 */
.pm-column-body {
  min-block-size: 120px;
  overflow-y: auto;
}
</style>
