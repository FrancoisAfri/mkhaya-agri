<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Chip = { value: string; label: string; color: string }

type Task = {
  uuid: string
  description: string
  sequence: number
  is_done: boolean
  done_by: string | null
  done_at: string | null
}

type Part = {
  uuid: string
  part_id: number | null
  description: string
  quantity: string
  unit_cost: string
  line_cost: string
}

type Order = {
  uuid: string
  work_order_number: string
  title: string
  description: string | null
  type: string
  type_value: string
  priority: Chip
  status: Chip
  status_value: string
  editable: boolean
  all_tasks_done: boolean
  asset: { uuid: string; name: string; asset_number: string } | null
  assignee_name: string | null
  requester_name: string | null
  scheduled_for: string | null
  started_at: string | null
  completed_at: string | null
  resolution_notes: string | null
  labor_hours: string
  labor_cost: string
  parts_cost: string
  total_cost: string
  costs: { labor: string; parts: string; total: string }
  tasks: Task[]
  parts: Part[]
}

const props = defineProps<{ order: Order }>()

const wo = computed(() => props.order)

// ── Tasks ──
const newTask = ref('')
function addTask() {
  if (!newTask.value.trim()) return
  router.post(route('assets.work-orders.tasks.store', wo.value.uuid), { description: newTask.value }, {
    preserveScroll: true,
    onSuccess: () => { newTask.value = '' },
  })
}
function toggleTask(t: Task) {
  router.post(route('assets.work-orders.tasks.toggle', [wo.value.uuid, t.uuid]), {}, { preserveScroll: true })
}
function removeTask(t: Task) {
  router.delete(route('assets.work-orders.tasks.destroy', [wo.value.uuid, t.uuid]), { preserveScroll: true })
}

// ── Parts ──
const part = ref({ description: '', quantity: 1, unit_cost: 0, part_id: null as number | null })
function addPart() {
  if (!part.value.description.trim()) return
  router.post(route('assets.work-orders.parts.store', wo.value.uuid), part.value, {
    preserveScroll: true,
    onSuccess: () => { part.value = { description: '', quantity: 1, unit_cost: 0, part_id: null } },
  })
}
function removePart(p: Part) {
  router.delete(route('assets.work-orders.parts.destroy', [wo.value.uuid, p.uuid]), { preserveScroll: true })
}

// ── Labour ──
const laborHours = ref(Number(props.order.labor_hours))
function saveLabor() {
  router.post(route('assets.work-orders.labor', wo.value.uuid), { labor_hours: laborHours.value }, { preserveScroll: true })
}

// ── Status flow ──
const resolutionNotes = ref('')
function start() { router.post(route('assets.work-orders.start', wo.value.uuid), {}, { preserveScroll: true }) }
function hold() { router.post(route('assets.work-orders.hold', wo.value.uuid), {}, { preserveScroll: true }) }
function resume() { router.post(route('assets.work-orders.resume', wo.value.uuid), {}, { preserveScroll: true }) }
function complete() {
  router.post(route('assets.work-orders.complete', wo.value.uuid), {
    resolution_notes: resolutionNotes.value || undefined,
    force: true,
  }, { preserveScroll: true })
}
function cancel() {
  router.post(route('assets.work-orders.cancel', wo.value.uuid), {
    resolution_notes: resolutionNotes.value || undefined,
  }, { preserveScroll: true })
}

const canStart = computed(() => wo.value.status_value === 'open')
const canHold = computed(() => wo.value.status_value === 'in_progress')
const canResume = computed(() => wo.value.status_value === 'on_hold')
const canClose = computed(() => wo.value.editable)

function formatCurrency(val: string | number | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 2 }).format(Number(val))
}
function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <VBtn icon="tabler-arrow-left" variant="text" :href="route('assets.work-orders.index')" />
      <div class="flex-grow-1">
        <div class="d-flex align-center gap-3 flex-wrap">
          <h4 class="text-h4 font-weight-bold mb-0">{{ wo.work_order_number }}</h4>
          <VChip :color="wo.status.color">{{ wo.status.label }}</VChip>
          <VChip size="small" :color="wo.priority.color">{{ wo.priority.label }}</VChip>
          <VChip size="small" variant="tonal">{{ wo.type }}</VChip>
        </div>
        <p class="text-h6 text-medium-emphasis mb-0 mt-1">{{ wo.title }}</p>
      </div>
    </div>

    <!-- Status flow buttons -->
    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-4 d-flex gap-2 flex-wrap align-center">
        <span class="text-caption text-medium-emphasis me-2">Status flow:</span>
        <VBtn v-if="canStart" color="info" size="small" prepend-icon="tabler-player-play" @click="start">Start</VBtn>
        <VBtn v-if="canHold" color="warning" size="small" prepend-icon="tabler-player-pause" @click="hold">Hold</VBtn>
        <VBtn v-if="canResume" color="info" size="small" prepend-icon="tabler-player-play" @click="resume">Resume</VBtn>
        <VBtn v-if="canClose" color="success" size="small" prepend-icon="tabler-check" @click="complete">Complete</VBtn>
        <VBtn v-if="canClose" color="error" variant="tonal" size="small" prepend-icon="tabler-x" @click="cancel">Cancel</VBtn>
        <span v-if="!wo.editable" class="text-caption text-medium-emphasis">This work order is closed.</span>
        <VSpacer />
        <VChip v-if="wo.all_tasks_done" size="small" color="success" variant="tonal">All tasks done</VChip>
        <VChip v-else size="small" color="warning" variant="tonal">Tasks outstanding</VChip>
      </VCardText>
      <VCardText v-if="canClose" class="pt-0 px-4 pb-4">
        <VTextField
          v-model="resolutionNotes"
          label="Resolution / closing note (optional)"
          density="compact"
          hide-details
        />
      </VCardText>
    </VCard>

    <VRow>
      <!-- Left column -->
      <VCol cols="12" md="8">
        <!-- Details -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-bold">Details</VCardTitle>
          <VCardText>
            <p v-if="wo.description" class="mb-4">{{ wo.description }}</p>
            <VRow dense>
              <VCol cols="6" sm="4"><div class="text-caption text-medium-emphasis">Asset</div><div>{{ wo.asset?.name ?? '—' }}</div></VCol>
              <VCol cols="6" sm="4"><div class="text-caption text-medium-emphasis">Asset #</div><div>{{ wo.asset?.asset_number ?? '—' }}</div></VCol>
              <VCol cols="6" sm="4"><div class="text-caption text-medium-emphasis">Assignee</div><div>{{ wo.assignee_name ?? '—' }}</div></VCol>
              <VCol cols="6" sm="4"><div class="text-caption text-medium-emphasis">Requested by</div><div>{{ wo.requester_name ?? '—' }}</div></VCol>
              <VCol cols="6" sm="4"><div class="text-caption text-medium-emphasis">Scheduled</div><div>{{ formatDate(wo.scheduled_for) }}</div></VCol>
              <VCol cols="6" sm="4"><div class="text-caption text-medium-emphasis">Started</div><div>{{ formatDate(wo.started_at) }}</div></VCol>
              <VCol cols="6" sm="4"><div class="text-caption text-medium-emphasis">Completed</div><div>{{ formatDate(wo.completed_at) }}</div></VCol>
            </VRow>
            <div v-if="wo.resolution_notes" class="mt-4">
              <div class="text-caption text-medium-emphasis">Resolution notes</div>
              <div>{{ wo.resolution_notes }}</div>
            </div>
          </VCardText>
        </VCard>

        <!-- Tasks checklist -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-bold d-flex align-center justify-space-between">
            <span>Tasks</span>
            <span class="text-caption text-medium-emphasis">{{ wo.tasks.filter(t => t.is_done).length }} / {{ wo.tasks.length }} done</span>
          </VCardTitle>
          <VCardText>
            <div v-for="t in wo.tasks" :key="t.uuid" class="d-flex align-center gap-2 py-1">
              <VCheckbox
                :model-value="t.is_done"
                :disabled="!wo.editable"
                hide-details
                density="compact"
                @update:model-value="toggleTask(t)"
              />
              <div class="flex-grow-1">
                <span :class="t.is_done ? 'text-decoration-line-through text-medium-emphasis' : ''">{{ t.description }}</span>
                <div v-if="t.is_done && t.done_by" class="text-caption text-medium-emphasis">Done by {{ t.done_by }} · {{ formatDate(t.done_at) }}</div>
              </div>
              <VBtn v-if="wo.editable" icon="tabler-trash" variant="text" size="x-small" color="error" @click="removeTask(t)" />
            </div>
            <p v-if="!wo.tasks.length" class="text-medium-emphasis text-center py-4 mb-0">No tasks yet.</p>

            <div v-if="wo.editable" class="d-flex gap-2 mt-3">
              <VTextField
                v-model="newTask"
                label="Add a task"
                density="compact"
                hide-details
                @keyup.enter="addTask"
              />
              <VBtn color="primary" variant="tonal" @click="addTask">Add</VBtn>
            </div>
          </VCardText>
        </VCard>

        <!-- Parts table -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-bold">Parts</VCardTitle>
          <VTable density="compact">
            <thead>
              <tr>
                <th>Description</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Unit Cost</th>
                <th class="text-right">Line Cost</th>
                <th v-if="wo.editable"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in wo.parts" :key="p.uuid">
                <td>{{ p.description }}</td>
                <td class="text-right">{{ p.quantity }}</td>
                <td class="text-right">{{ formatCurrency(p.unit_cost) }}</td>
                <td class="text-right">{{ formatCurrency(p.line_cost) }}</td>
                <td v-if="wo.editable" class="text-right">
                  <VBtn icon="tabler-trash" variant="text" size="x-small" color="error" @click="removePart(p)" />
                </td>
              </tr>
              <tr v-if="!wo.parts.length">
                <td :colspan="wo.editable ? 5 : 4" class="text-center text-medium-emphasis py-4">No parts added.</td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-if="wo.editable" class="d-flex gap-2 flex-wrap align-center pt-4">
            <VTextField v-model="part.description" label="Part / description" density="compact" hide-details style="min-width:220px" class="flex-grow-1" />
            <VTextField v-model.number="part.quantity" type="number" label="Qty" density="compact" hide-details style="max-width:90px" />
            <VTextField v-model.number="part.unit_cost" type="number" label="Unit cost" density="compact" hide-details style="max-width:130px" />
            <VBtn color="primary" variant="tonal" @click="addPart">Add Part</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right column: labour + cost breakdown -->
      <VCol cols="12" md="4">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-bold">Labour</VCardTitle>
          <VCardText>
            <VTextField
              v-model.number="laborHours"
              type="number"
              label="Labour hours"
              density="comfortable"
              :disabled="!wo.editable"
              hide-details
            />
            <VBtn v-if="wo.editable" color="primary" variant="tonal" size="small" class="mt-3" block @click="saveLabor">Save Labour</VBtn>
          </VCardText>
        </VCard>

        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1 font-weight-bold">Cost Breakdown</VCardTitle>
          <VCardText>
            <div class="d-flex justify-space-between py-2">
              <span class="text-medium-emphasis">Labour</span>
              <span class="font-weight-medium">{{ formatCurrency(wo.costs.labor) }}</span>
            </div>
            <div class="d-flex justify-space-between py-2">
              <span class="text-medium-emphasis">Parts</span>
              <span class="font-weight-medium">{{ formatCurrency(wo.costs.parts) }}</span>
            </div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between py-2">
              <span class="text-subtitle-1 font-weight-bold">Total</span>
              <span class="text-subtitle-1 font-weight-bold text-primary">{{ formatCurrency(wo.costs.total) }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
