<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import FlowCanvas from './components/FlowCanvas.vue'
const route = ziggyRoute
const { t } = useI18n()

interface Condition { field: string; operator: string; value: string }
interface Action { type: string; subject?: string; body?: string; field?: string; value?: string; user_id?: number; due_in_days?: number; assign_to?: string; title?: string; ai?: string; url?: string; method?: string; amount?: number }
type Step = Record<string, any>
interface Workflow {
  id: number; uuid: string; name: string; description: string | null
  trigger: string; trigger_label: string; trigger_config: Record<string, any>
  match_type: string; conditions: Condition[]; actions: Action[]; steps: Step[]; graph: { nodes: any[]; edges: any[] } | null
  is_active: boolean; run_count: number; runs: number; last_run_at: string | null
}
interface RunRow { id: number; workflow: string | null; entity: string; status: string; detail: any; when: string | null }

const props = defineProps<{
  workflows: Workflow[]
  recentRuns: RunRow[]
  triggers: Record<string, string>
  actionTypes: Record<string, string>
  aiActions: Record<string, string>
  dateFields: Record<string, string[]>
  stepTypes: Record<string, string>
  templates: Step[]
  conditionFields: Record<string, string[]>
  triggerEntity: Record<string, string>
  stages: { id: number; name: string }[]
}>()

// The entity the chosen trigger fires on → which fields conditions can use.
const flowEntity = computed(() => form.trigger === 'date_reached'
  ? (form.trigger_config.entity || 'deal')
  : (props.triggerEntity[form.trigger] ?? 'deal'))
const entityFields = computed(() => props.conditionFields[flowEntity.value] ?? [])

// Templates grouped for the "Start from template" menu.
const groupedTemplates = computed(() => {
  const groups: Record<string, Step[]> = {}
  for (const t of props.templates) (groups[t.group ?? 'General'] ||= []).push(t)
  return groups
})

const dialog = ref(false)
const canvasKey = ref(0) // bump to remount the canvas with fresh graph
const fieldOps = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'in', 'is_empty', 'not_empty']

const emptyGraph = () => ({ nodes: [{ id: 'trigger', type: 'trigger', data: {}, position: { x: 300, y: 20 } }], edges: [] as any[] })

const blank = () => ({
  id: null as number | null,
  name: '', description: '', trigger: 'deal.stage_changed',
  trigger_config: { stage_id: null as number | null, field: '', entity: 'deal', days_before: 7 },
  match_type: 'all',
  conditions: [] as Condition[],
  actions: [] as Action[],
  steps: [] as Step[],
  graph: emptyGraph() as { nodes: any[]; edges: any[] },
  is_active: true, run_order: 0,
})
const form = useForm(blank())

/** Convert a legacy linear/branch step tree into a node/edge graph. */
function stepsToGraph(steps: Step[]) {
  let c = 0
  const nid = () => 'n' + (++c)
  const nodes: any[] = [{ id: 'trigger', type: 'trigger', data: {}, position: { x: 300, y: 20 } }]
  const edges: any[] = []
  let row = 1
  const connect = (from: { id: string; handle: string | null }[], to: string) =>
    from.forEach(o => edges.push({ id: `e${edges.length + 1}`, source: o.id, target: to, sourceHandle: o.handle }))

  const seq = (list: Step[], incoming: { id: string; handle: string | null }[], x: number): { id: string; handle: string | null }[] => {
    let open = incoming
    for (const s of (list ?? [])) {
      const id = nid()
      const pos = { x, y: row * 110 + 20 }; row++
      if (s.type === 'branch') {
        nodes.push({ id, type: 'branch', data: { match_type: s.match_type ?? 'all', conditions: s.conditions ?? [] }, position: pos })
        connect(open, id)
        const yes = seq(s.then ?? [], [{ id, handle: 'yes' }], x - 180)
        const no = seq(s.else ?? [], [{ id, handle: 'no' }], x + 180)
        open = [...yes, ...no]
      } else {
        const type = s.type === 'wait' ? 'wait' : s.type === 'condition' ? 'condition' : 'action'
        const data = type === 'action' ? { ...s, action: s.action ?? s.type }
          : type === 'wait' ? { amount: s.amount ?? 1, unit: s.unit ?? 'days' }
            : { match_type: s.match_type ?? 'all', conditions: s.conditions ?? [] }
        nodes.push({ id, type, data, position: pos })
        connect(open, id)
        open = [{ id, handle: null }]
      }
    }
    return open
  }
  seq(steps, [{ id: 'trigger', handle: null }], 300)
  return { nodes, edges }
}

function openCreate() { form.defaults(blank()); form.reset(); canvasKey.value++; dialog.value = true }
function openEdit(w: Workflow) {
  const legacySteps: Step[] = w.steps?.length
    ? w.steps
    : (w.actions ?? []).map(a => ({ type: 'action', action: a.type, ...a }))
  const graph = w.graph?.nodes?.length ? JSON.parse(JSON.stringify(w.graph)) : stepsToGraph(legacySteps)
  form.defaults({
    id: w.id, name: w.name, description: w.description ?? '', trigger: w.trigger,
    trigger_config: { stage_id: w.trigger_config?.stage_id ?? null, field: w.trigger_config?.field ?? '', entity: w.trigger_config?.entity ?? 'deal', days_before: w.trigger_config?.days_before ?? 7 },
    match_type: w.match_type, conditions: [...w.conditions], actions: [], steps: [], graph,
    is_active: w.is_active, run_order: 0,
  })
  form.reset()
  canvasKey.value++
  dialog.value = true
}
function addCondition() { form.conditions.push({ field: entityFields.value[0] ?? 'status', operator: '=', value: '' }) }
function removeCondition(i: number) { form.conditions.splice(i, 1) }

// Keep the date-trigger field valid for the chosen entity.
function onDateEntity() {
  const allowed = props.dateFields[form.trigger_config.entity] ?? []
  if (!allowed.includes(form.trigger_config.field)) form.trigger_config.field = allowed[0] ?? ''
}

function applyTemplate(t: Step) {
  form.defaults({
    ...blank(),
    name: t.name, description: t.description ?? '', trigger: t.trigger,
    trigger_config: { ...blank().trigger_config, ...(t.trigger_config ?? {}) },
    graph: stepsToGraph(JSON.parse(JSON.stringify(t.steps ?? []))),
  })
  form.reset()
  canvasKey.value++
  dialog.value = true
}

function save() {
  if (form.id) form.patch(route('crm.automations.update', form.id), { preserveScroll: true, onSuccess: () => dialog.value = false })
  else form.post(route('crm.automations.store'), { preserveScroll: true, onSuccess: () => dialog.value = false })
}
function toggle(w: Workflow) { useForm({}).post(route('crm.automations.toggle', w.id), { preserveScroll: true }) }
function remove(w: Workflow) { useForm({}).delete(route('crm.automations.destroy', w.id), { preserveScroll: true }) }

const statusColor = (s: string) => s === 'success' ? 'success' : s === 'failed' ? 'error' : 'blue-grey'
// Kept as a string constant so the literal {{ }} braces aren't parsed as Vue mustaches.
const mergeTagHint = "Supports merge tags {{first_name}}, {{company}}, {{rep_name}}."
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_automations_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_automations_index.description') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VMenu>
          <template #activator="{ props: menu }">
            <VBtn v-bind="menu" variant="tonal" prepend-icon="tabler-template" append-icon="tabler-chevron-down">Start from template</VBtn>
          </template>
          <VList density="compact" max-height="500" style="overflow-y:auto; max-width: 420px;">
            <template v-for="(items, group) in groupedTemplates" :key="group">
              <VListSubheader class="font-weight-bold">{{ group }}</VListSubheader>
              <VListItem v-for="t in items" :key="t.key" :title="t.name" :subtitle="t.description" @click="applyTemplate(t)" />
            </template>
          </VList>
        </VMenu>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">New workflow</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard :title="t('crm_automations_index.workflows')">
          <VList>
            <template v-for="w in workflows" :key="w.id">
              <VListItem>
                <template #prepend>
                  <VAvatar :color="w.is_active ? 'success' : 'blue-grey'" variant="tonal"><VIcon icon="tabler-robot" /></VAvatar>
                </template>
                <VListItemTitle class="font-weight-medium">{{ w.name }}</VListItemTitle>
                <VListItemSubtitle>
                  {{ w.trigger_label }} · {{ w.conditions.length }} condition(s) ·
                  <span v-if="w.graph?.nodes?.length">{{ w.graph.nodes.length - 1 }} node(s)</span><span v-else-if="w.steps?.length">{{ w.steps.length }} step(s)</span><span v-else>{{ w.actions.length }} action(s)</span>
                  · ran {{ w.run_count }}×
                </VListItemSubtitle>
                <template #append>
                  <div class="d-flex gap-1 align-center">
                    <VChip size="x-small" :color="w.is_active ? 'success' : 'blue-grey'" label>{{ w.is_active ? t('crm_automations_index.active') : t('crm_automations_index.paused') }}</VChip>
                    <VBtn size="x-small" variant="text" :icon="w.is_active ? 'tabler-player-pause' : 'tabler-player-play'" @click="toggle(w)" />
                    <VBtn size="x-small" variant="text" icon="tabler-edit" @click="openEdit(w)" />
                    <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click="remove(w)" />
                  </div>
                </template>
              </VListItem>
              <VDivider />
            </template>
            <VListItem v-if="!workflows.length" :title="t('crm_automations_index.no_workflows')" />
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard :title="t('crm_automations_index.recent_runs')">
          <VList density="compact">
            <VListItem v-for="r in recentRuns" :key="r.id" :title="r.workflow ?? '—'" :subtitle="`${r.entity} · ${r.when}`">
              <template #append><VChip size="x-small" :color="statusColor(r.status)" label>{{ r.status }}</VChip></template>
            </VListItem>
            <VListItem v-if="!recentRuns.length" :title="t('crm_automations_index.no_runs')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="720" scrollable persistent>
      <VCard>
        <VCardTitle>{{ form.id ? t('crm_automations_index.dialog.title_edit') : t('crm_automations_index.dialog.title_create') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" :label="t('crm_automations_index.dialog.name')" class="mb-3" />
          <VTextField v-model="form.description" :label="t('crm_automations_index.dialog.description')" class="mb-3" />

          <div class="text-overline mt-2">{{ t('crm_automations_index.dialog.trigger_label') }}</div>
          <VRow>
            <VCol cols="12" :md="(form.trigger === 'deal.stage_changed' || form.trigger.endsWith('.updated')) ? 6 : 12">
              <VSelect v-model="form.trigger" label="Trigger" :items="Object.entries(triggers).map(([v, t]) => ({ title: t, value: v }))" />
            </VCol>
            <VCol v-if="form.trigger === 'deal.stage_changed'" cols="12" md="6">
              <VSelect v-model="form.trigger_config.stage_id" :label="t('crm_automations_index.dialog.into_stage')" clearable
                :items="stages.map(s => ({ title: s.name, value: s.id }))" />
            </VCol>
            <VCol v-else-if="form.trigger.endsWith('.updated')" cols="12" md="6">
              <VTextField v-model="form.trigger_config.field" label="Only when this field changes (optional)" placeholder="e.g. status, amount, owner_id" />
            </VCol>
          </VRow>

          <!-- date_reached config -->
          <VRow v-if="form.trigger === 'date_reached'">
            <VCol cols="12" md="4">
              <VSelect v-model="form.trigger_config.entity" label="Record type" :items="Object.keys(dateFields)" class="text-capitalize" @update:model-value="onDateEntity" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.trigger_config.field" label="Date field" :items="dateFields[form.trigger_config.entity] ?? []" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="form.trigger_config.days_before" type="number" label="Days before (negative = after)" />
            </VCol>
          </VRow>

          <div class="d-flex align-center justify-space-between mt-2">
            <div class="text-overline">{{ t('crm_automations_index.dialog.conditions') }}</div>
            <VBtnToggle v-model="form.match_type" density="compact" mandatory variant="outlined">
              <VBtn value="all" size="x-small">{{ t('crm_automations_index.dialog.match_all') }}</VBtn>
              <VBtn value="any" size="x-small">{{ t('crm_automations_index.dialog.match_any') }}</VBtn>
            </VBtnToggle>
          </div>
          <div v-for="(c, i) in form.conditions" :key="i" class="d-flex gap-2 mb-2 align-center">
            <VCombobox v-model="c.field" label="Field" :items="entityFields" density="compact" hide-details style="max-width: 180px" />
            <VSelect v-model="c.operator" :items="fieldOps" density="compact" hide-details style="max-width: 130px" />
            <VTextField v-model="c.value" :label="t('crm_automations_index.dialog.value')" density="compact" hide-details />
            <VBtn size="x-small" variant="text" icon="tabler-x" color="error" @click="removeCondition(i)" />
          </div>
          <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addCondition">{{ t('crm_automations_index.dialog.add_condition') }}</VBtn>

          <div class="text-overline mt-4">Then (flow canvas)</div>
          <p class="text-caption text-medium-emphasis mb-2">Drag from a node's handle to the next node to connect them. Add Actions, Waits, Gates and Branches; a Branch splits into <span class="text-success">YES</span> / <span class="text-error">NO</span> paths.</p>

          <FlowCanvas :key="canvasKey" v-model="form.graph" :action-types="actionTypes" :ai-actions="aiActions" :field-ops="fieldOps" :field-suggestions="entityFields" :merge-tag-hint="mergeTagHint" />

          <VSwitch v-model="form.is_active" :label="t('crm_automations_index.dialog.active_switch')" color="success" class="mt-3" hide-details />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_automations_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('crm_automations_index.dialog.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
