<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import RowMenu from '../Components/RowMenu.vue'
import { human, statusColor } from '../Components/agri'

const props = defineProps<{
  tasks: any[]
  counts: { mine: number; review: number; overdue: number }
  filters: { farm: string | null; view: string }
  options: { farms: Array<{ id: string; name: string; fields: Array<{ id: string; code: string; name: string }> }>; people: Array<{ id: number; name: string }>; priorities: string[] }
  can: { manage: boolean; approve: boolean }
}>()
const dialog = ref<null | string>(null)
const target = ref<any>(null)
const open = (k: string, t: any = null) => { target.value = t; dialog.value = k }
const close = (v: boolean) => { if (!v) dialog.value = null }
const keep = { preserveScroll: true }
const setView = (view: string) => router.get(route('agri.tasks.index'), { farm: props.filters.farm || undefined, view }, { preserveState: false })
const act = (t: any, action: string, extra: Record<string, any> = {}) => router.post(route('agri.tasks.transition', t.id), { action, ...extra }, keep)
function reject(t: any) { const note = prompt('What needs to be redone?'); if (note) act(t, 'reject', { review_note: note }) }

const taskFields = (create: boolean): FormField[] => [
  ...(create ? [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: props.options.farms.map(f => ({ title: f.name, value: f.id })) }] : []),
  { key: 'title', label: 'Task', required: true, cols: create ? 6 : 12 },
  { key: 'description', label: 'Instructions', type: 'textarea', cols: 12 },
  { key: 'assigned_to', label: 'Assign to', type: 'autocomplete', items: props.options.people.map(p => ({ title: p.name, value: p.id })), hint: 'Must have access to the farm' },
  { key: 'field_id', label: 'Field / camp', type: 'select', items: (f: any) => (props.options.farms.find(x => x.id === (f.farm_id ?? target.value?.farm_id))?.fields ?? []).map(x => ({ title: `${x.code} · ${x.name}`, value: x.id })) },
  { key: 'priority', label: 'Priority', type: 'select', required: true, items: props.options.priorities, default: 'normal', cols: 4 },
  { key: 'due_on', label: 'Due', type: 'date', cols: 4 },
  { key: 'requires_approval', label: 'Needs sign-off', type: 'switch', default: true, cols: 4 },
]
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Farm Tasks</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Give work to people on the farm, track it, and sign it off. Nobody approves their own work.</p>
      </div>
      <div class="d-flex gap-2">
        <FarmFilter :farms="options.farms" :model-value="filters.farm" />
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open('create')">New task</VBtn>
      </div>
    </div>

    <VCard>
      <VCardText class="d-flex flex-wrap gap-2">
        <VChip :variant="filters.view === 'open' ? 'flat' : 'tonal'" :color="filters.view === 'open' ? 'primary' : undefined" @click="setView('open')">Open</VChip>
        <VChip :variant="filters.view === 'mine' ? 'flat' : 'tonal'" :color="filters.view === 'mine' ? 'primary' : undefined" @click="setView('mine')">Mine ({{ counts.mine }})</VChip>
        <VChip :variant="filters.view === 'review' ? 'flat' : 'tonal'" :color="filters.view === 'review' ? 'warning' : undefined" @click="setView('review')">Waiting for sign-off ({{ counts.review }})</VChip>
        <VChip :variant="filters.view === 'all' ? 'flat' : 'tonal'" :color="filters.view === 'all' ? 'primary' : undefined" @click="setView('all')">All</VChip>
        <VSpacer />
        <VChip v-if="counts.overdue" color="error" variant="tonal" prepend-icon="tabler-clock-exclamation">{{ counts.overdue }} overdue</VChip>
      </VCardText>
      <VTable density="comfortable">
        <thead><tr><th>Task</th><th>Farm</th><th>Assigned to</th><th>Due</th><th>Priority</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="t in tasks" :key="t.id">
            <td><strong>{{ t.title }}</strong><div v-if="t.description" class="text-caption">{{ t.description }}</div>
              <div v-if="t.completion_notes" class="text-caption text-success">Done: {{ t.completion_notes }}<template v-if="t.labour_hours"> ({{ t.labour_hours }} h)</template></div>
              <div v-if="t.status === 'rejected' && t.review_note" class="text-caption text-error">Redo: {{ t.review_note }}</div></td>
            <td>{{ t.farm }}<div class="text-caption">{{ t.field }}</div></td>
            <td>{{ t.assignee ?? 'Unassigned' }}</td>
            <td :class="t.overdue ? 'text-error font-weight-medium' : ''">{{ t.due_on ?? '—' }}</td>
            <td><VChip :color="statusColor(t.priority)" size="small" variant="tonal">{{ human(t.priority) }}</VChip></td>
            <td><VChip :color="statusColor(t.status)" size="small" variant="tonal">{{ human(t.status) }}</VChip></td>
            <td class="text-end"><RowMenu :actions="[
              { title: 'Start', icon: 'tabler-player-play', show: t.mine && ['open', 'rejected'].includes(t.status), onClick: () => act(t, 'start') },
              { title: 'Submit as done', icon: 'tabler-send', show: t.mine && ['open', 'in_progress', 'rejected'].includes(t.status), onClick: () => open('submit', t) },
              { title: 'Approve', icon: 'tabler-check', show: t.can_review, onClick: () => act(t, 'approve') },
              { title: 'Send back', icon: 'tabler-arrow-back-up', show: t.can_review, onClick: () => reject(t) },
              { title: 'Edit', icon: 'tabler-edit', show: can.manage && ['open', 'in_progress', 'rejected'].includes(t.status), onClick: () => open('edit', t) },
              { title: 'Cancel', icon: 'tabler-x', color: 'error', show: can.manage && ['open', 'in_progress', 'rejected'].includes(t.status), onClick: () => confirm('Cancel this task?') && act(t, 'cancel') },
            ]" /></td>
          </tr>
          <tr v-if="!tasks.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No tasks here.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <FormDialog :model-value="dialog === 'create'" title="New task" :fields="taskFields(true)" :url="route('agri.tasks.store')"
      :values="{ farm_id: filters.farm ?? options.farms[0]?.id }" @update:model-value="close" />
    <template v-if="target">
      <FormDialog :model-value="dialog === 'edit'" title="Edit task" :fields="taskFields(false)" :values="target" method="patch" :url="route('agri.tasks.update', target.id)" @update:model-value="close" />
      <FormDialog :model-value="dialog === 'submit'" :title="`Done: ${target.title}`" :url="route('agri.tasks.transition', target.id)" :values="{ action: 'submit' }" submit-label="Submit"
        :fields="[{ key: 'labour_hours', label: 'Hours spent', type: 'number' }, { key: 'completion_notes', label: 'What was done', type: 'textarea', cols: 12 }]" @update:model-value="close" />
    </template>
  </div>
</template>
