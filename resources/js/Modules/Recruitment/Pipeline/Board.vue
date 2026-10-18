<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type PipelineItem = {
  id: number
  candidate: { id: number; full_name: string }
  current_stage: { name: string } | null
  fit_score: number | null
  created_at: string
}

type PipelineColumn = {
  status: string
  label: string
  count: number
  items: PipelineItem[]
  has_more: boolean
  next_cursor: string | null
}

const props = defineProps<{
  job: { id: number; title: string }
  columns: PipelineColumn[]
  filters: Record<string, any>
}>()

// ─── Column colours ───────────────────────────────────────────────────────────
const columnColor: Record<string, string> = {
  applied:    'primary',
  screening:  'default',
  assessment: 'secondary',
  interview:  'warning',
  shortlisted:'info',
  offer_sent: 'secondary',
  hired:      'success',
  rejected:   'error',
  withdrawn:  'default',
}

const columnBg: Record<string, string> = {
  applied:    'rgba(115,103,240,0.06)',
  screening:  'rgba(0,0,0,0.03)',
  assessment: 'rgba(134,96,231,0.06)',
  interview:  'rgba(255,159,67,0.06)',
  shortlisted:'rgba(0,207,232,0.06)',
  offer_sent: 'rgba(134,96,231,0.06)',
  hired:      'rgba(40,199,111,0.06)',
  rejected:   'rgba(234,84,85,0.06)',
  withdrawn:  'rgba(0,0,0,0.03)',
}

// ─── Stats ────────────────────────────────────────────────────────────────────
const totalCount = computed(() => props.columns.reduce((sum, c) => sum + c.count, 0))

// ─── Helpers ─────────────────────────────────────────────────────────────────
function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', {
    day:   '2-digit',
    month: 'short',
  })
}

// ─── Load more (fixed: cursor key must match backend's `cursor_{status}`) ─────
function loadMoreColumn(col: PipelineColumn) {
  if (!col.has_more) return
  router.get(
    route('recruitment.jobs.pipeline', props.job.id),
    { ...props.filters, [`cursor_${col.status}`]: col.next_cursor },
    { preserveState: true },
  )
}

// ─── Quick-move to next stage ─────────────────────────────────────────────────
const statusOrder = [
  'applied', 'screening', 'assessment', 'phone_interview',
  'technical_assessment', 'interview', 'reference_check',
  'shortlisted', 'offer_sent', 'offered', 'accepted',
]

function nextStatus(current: string): string | null {
  const idx = statusOrder.indexOf(current)
  if (idx === -1 || idx >= statusOrder.length - 1) return null
  return statusOrder[idx + 1]
}

const movingIds = ref<Set<number>>(new Set())

function moveCard(applicationId: number, toStatus: string) {
  movingIds.value = new Set([...movingIds.value, applicationId])
  router.patch(
    route('recruitment.jobs.applications.move', [props.job.id, applicationId]),
    { status: toStatus },
    {
      preserveState: true,
      onFinish: () => {
        const updated = new Set(movingIds.value)
        updated.delete(applicationId)
        movingIds.value = updated
      },
    },
  )
}

function moveToNext(applicationId: number, currentStatus: string) {
  const next = nextStatus(currentStatus)
  if (!next) return
  moveCard(applicationId, next)
}

// ─── Drag-and-drop ────────────────────────────────────────────────────────────
const dragState = ref<{ applicationId: number; fromStatus: string } | null>(null)
const dragOverStatus = ref<string | null>(null)

function onDragStart(event: DragEvent, item: PipelineItem, fromStatus: string) {
  dragState.value = { applicationId: item.id, fromStatus }
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', String(item.id))
  }
}

function onDragOver(event: DragEvent, toStatus: string) {
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
  dragOverStatus.value = toStatus
}

function onDragLeave(toStatus: string) {
  if (dragOverStatus.value === toStatus) dragOverStatus.value = null
}

function onDrop(toStatus: string) {
  dragOverStatus.value = null
  const state = dragState.value
  dragState.value = null
  if (!state || state.fromStatus === toStatus) return
  moveCard(state.applicationId, toStatus)
}

function onDragEnd() {
  dragState.value = null
  dragOverStatus.value = null
}
</script>

<template>
  <div
    class="d-flex flex-column"
    style="height: calc(100vh - 64px);"
  >
    <!-- Header -->
    <div class="d-flex align-center gap-3 pa-5 pb-4 flex-shrink-0">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        size="small"
        @click="router.visit(route('recruitment.jobs.show', job.id))"
      />
      <div class="flex-grow-1">
        <div class="d-flex align-center gap-2">
          <h4 class="text-h5 font-weight-bold mb-0">
            {{ job.title }}
          </h4>
          <span class="text-medium-emphasis text-body-2">Pipeline</span>
        </div>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ totalCount }} total applicants
        </p>
      </div>
      <VBtn
        variant="tonal"
        color="primary"
        prepend-icon="tabler-list"
        @click="router.visit(route('recruitment.jobs.applications.index', job.id))"
      >
        List View
      </VBtn>
    </div>

    <!-- Board -->
    <div
      class="d-flex gap-4 overflow-x-auto px-5 pb-5 flex-grow-1"
      style="align-items: flex-start;"
    >
      <div
        v-for="col in columns"
        :key="col.status"
        class="pipeline-column d-flex flex-column rounded-lg flex-shrink-0"
        :style="{
          minWidth: '268px',
          maxWidth: '280px',
          background: dragOverStatus === col.status
            ? 'rgba(115,103,240,0.12)'
            : (columnBg[col.status] ?? 'rgba(0,0,0,0.03)'),
          border: dragOverStatus === col.status
            ? '2px dashed rgba(115,103,240,0.5)'
            : '1px solid rgba(0,0,0,0.08)',
          height: '100%',
          transition: 'background 0.15s, border 0.15s',
        }"
        @dragover="onDragOver($event, col.status)"
        @dragleave="onDragLeave(col.status)"
        @drop="onDrop(col.status)"
      >
        <!-- Column header -->
        <div class="d-flex align-center justify-space-between pa-3 flex-shrink-0">
          <div class="d-flex align-center gap-2">
            <VIcon
              icon="tabler-circle-filled"
              size="10"
              :color="columnColor[col.status] ?? 'default'"
            />
            <span class="text-body-2 font-weight-semibold">{{ col.label }}</span>
          </div>
          <VChip
            size="x-small"
            variant="tonal"
            :color="columnColor[col.status] ?? 'default'"
            label
          >
            {{ col.count }}
          </VChip>
        </div>

        <VDivider />

        <!-- Scrollable cards area -->
        <div
          class="overflow-y-auto pa-2 flex-grow-1"
        >
          <VCard
            v-for="item in col.items"
            :key="item.id"
            elevation="0"
            border
            draggable="true"
            class="mb-2 cursor-pointer"
            :style="{ opacity: dragState?.applicationId === item.id ? 0.4 : 1 }"
            @dragstart="onDragStart($event, item, col.status)"
            @dragend="onDragEnd"
            @click="router.visit(route('recruitment.jobs.applications.show', [job.id, item.id]))"
          >
            <VCardText class="pa-3">
              <!-- Candidate row -->
              <div class="d-flex align-center gap-2 mb-2">
                <VAvatar size="30" color="primary" variant="tonal" class="flex-shrink-0">
                  <span class="text-caption font-weight-bold">
                    {{ (item.candidate?.full_name ?? '?')[0].toUpperCase() }}
                  </span>
                </VAvatar>
                <p
                  class="text-body-2 font-weight-semibold mb-0 text-truncate flex-grow-1"
                  style="min-width: 0;"
                >
                  {{ item.candidate?.full_name ?? '—' }}
                </p>
              </div>

              <!-- Stage + score + date -->
              <div class="d-flex align-center justify-space-between">
                <span class="text-caption text-medium-emphasis text-truncate" style="max-width: 55%;">
                  {{ item.current_stage?.name ?? 'No stage' }}
                </span>
                <div class="d-flex align-center gap-1 flex-shrink-0">
                  <VChip
                    v-if="item.fit_score !== null"
                    size="x-small"
                    color="info"
                    label
                    class="font-weight-medium"
                  >
                    {{ item.fit_score }}%
                  </VChip>
                  <span class="text-caption text-medium-emphasis">
                    {{ formatDate(item.created_at) }}
                  </span>
                </div>
              </div>

              <!-- Quick-move button -->
              <div v-if="nextStatus(col.status)" class="d-flex justify-end mt-1">
                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="primary"
                  :title="`Move to ${nextStatus(col.status)?.replace(/_/g, ' ')}`"
                  @click.stop="moveToNext(item.id, col.status)"
                >
                  <VProgressCircular
                    v-if="movingIds.has(item.id)"
                    indeterminate
                    size="14"
                    width="2"
                  />
                  <VIcon v-else icon="tabler-arrow-right-circle" size="16" />
                </VBtn>
              </div>
            </VCardText>
          </VCard>

          <!-- Empty column -->
          <div
            v-if="!col.items.length"
            class="text-center py-6 text-medium-emphasis"
          >
            <VIcon icon="tabler-user-off" size="32" class="mb-1 d-block mx-auto" />
            <p class="text-caption mb-0">No candidates</p>
          </div>

          <!-- Load more -->
          <VBtn
            v-if="col.has_more"
            block
            size="small"
            variant="text"
            color="primary"
            class="mt-1"
            @click="loadMoreColumn(col)"
          >
            Load more
          </VBtn>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pipeline-column {
  flex-shrink: 0;
}
</style>
