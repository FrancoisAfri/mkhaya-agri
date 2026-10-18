<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Task = { type: string; ref: string; number: string; title: string; item: string | null; qty: number | null; assigned_to: number | null; assignee: string | null; priority: string; due_at: string | null; state: string; overdue: boolean; waits_for: string[] }
const props = defineProps<{ tasks: Task[]; warehouse: string | null; warehouses: Array<{ id: string; title: string }>; filters: { type: string | null; mine: boolean };
  types: string[]; priorities: string[]; people: Array<{ id: number; title: string; skills: string[] }>; can: { supervise: boolean } }>()

const go = (patch: Record<string, unknown>) => router.get(route('warehouse.work.board'), { warehouse: props.warehouse, type: props.filters.type, mine: props.filters.mine ? 1 : 0, ...patch }, { preserveState: true })
const editing = ref<Task | null>(null)
const form = useForm({ type: '', ref: '', user_id: null as number | null, priority: null as string | null, due_at: '', note: '' })
function edit(task: Task) { editing.value = task; Object.assign(form, { type: task.type, ref: task.ref, user_id: task.assigned_to, priority: task.priority, due_at: task.due_at ? task.due_at.slice(0, 16) : '', note: '' }) }
const skilled = (task: Task) => props.people.filter(p => p.skills.includes(task.type === 'REPLENISH' ? 'REPLENISH' : task.type))
const color = (p: string) => ({ URGENT: 'error', HIGH: 'warning', MEDIUM: 'info', LOW: 'secondary' } as Record<string, string>)[p]
const when = (s: string | null) => s ? new Date(s).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' }) : '—'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.work.board_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.work.board_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 align-center flex-wrap">
        <VSelect :model-value="warehouse" :items="warehouses" item-value="id" density="compact" hide-details style="min-width: 220px" @update:model-value="v => go({ warehouse: v })" />
        <VSelect :model-value="filters.type" :items="types.map(x => ({ value: x, title: t(`warehouse_management.task_type.${x}`) }))" clearable density="compact" hide-details style="min-width: 170px"
                 :label="t('warehouse_management.common.type')" @update:model-value="v => go({ type: v })" />
        <VSwitch :model-value="filters.mine" hide-details density="compact" color="primary" :label="t('warehouse_management.work.mine')" @update:model-value="v => go({ mine: v ? 1 : 0 })" />
      </div>
    </div>
    <RefusalAlert />
    <VCard border>
      <VCardText v-if="!tasks.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.work.no_tasks') }}</VCardText>
      <div v-else class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.work.priority') }}</th><th>{{ t('warehouse_management.common.type') }}</th><th>{{ t('warehouse_management.work.task') }}</th>
            <th>{{ t('warehouse_management.work.who') }}</th><th>{{ t('warehouse_management.work.due') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="task in tasks" :key="`${task.type}${task.ref}`">
              <td><VChip size="small" :color="color(task.priority)">{{ t(`warehouse_management.priority.${task.priority}`) }}</VChip></td>
              <td>{{ t(`warehouse_management.task_type.${task.type}`) }}</td>
              <td class="font-weight-medium">{{ task.number }}<div class="text-caption">{{ task.title }}<span v-if="task.qty"> · {{ task.qty }}</span></div>
                <div v-for="w in task.waits_for" :key="w" class="text-caption text-warning"><VIcon icon="tabler-lock" size="12" /> {{ t('warehouse_management.work.waits', { w }) }}</div></td>
              <td>{{ task.assignee ?? t('warehouse_management.work.unassigned') }}</td>
              <td :class="task.overdue ? 'text-error font-weight-bold' : ''">{{ when(task.due_at) }}</td>
              <td class="text-end"><VBtn v-if="can.supervise" size="small" variant="text" @click="edit(task)">{{ t('warehouse_management.work.assign') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
    <VDialog :model-value="editing !== null" max-width="480" @update:model-value="v => !v && (editing = null)">
      <VCard v-if="editing" :title="`${editing.number} · ${t(`warehouse_management.task_type.${editing.type}`)}`">
        <VCardText class="d-flex flex-column gap-3">
          <VSelect v-model="form.user_id" :items="skilled(editing)" item-value="id" clearable :label="t('warehouse_management.work.who')" :hint="t('warehouse_management.work.skilled_hint')" persistent-hint />
          <VSelect v-model="form.priority" :items="priorities.map(p => ({ value: p, title: t(`warehouse_management.priority.${p}`) }))" :label="t('warehouse_management.work.priority')" />
          <VTextField v-model="form.due_at" type="datetime-local" :label="t('warehouse_management.work.due')" />
          <VTextField v-model="form.note" :label="t('warehouse_management.counts.investigation')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="form.transform(d => ({ ...d, due_at: d.due_at || null })).post(route('warehouse.work.assign'), { preserveScroll: true, onSuccess: () => editing = null })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
