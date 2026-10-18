<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { VueFlow, useVueFlow, Handle, Position, MarkerType, type Connection } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'
import { MiniMap } from '@vue-flow/minimap'
import dagre from '@dagrejs/dagre'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'
import '@vue-flow/minimap/dist/style.css'
import { label, describe, statusColor } from './useWorkflow'
import ConditionEditor from './components/ConditionEditor.vue'
import AssigneePicker from './components/AssigneePicker.vue'
import { useFitToViewport } from '@/composables/useFitToViewport'

const route = ziggyRoute
type GNode = { key: string; type: string; name: string; x: number; y: number; config: Record<string, any> }
type GEdge = { key: string; from: string; to: string; label?: string; outcome?: string; condition?: any; is_default?: boolean }
type Problem = { node: string | null; edge: string | null; message: string; fix: string }

const props = defineProps<{
  definition: { uuid: string; name: string; module: string; status: string; category: string | null; process_owner: string | null }
  version: { uuid: string; version: number; status: string; graph: { nodes: GNode[]; edges: GEdge[] }; validation: Problem[]; change_summary: string | null; editable: boolean }
  catalog: { nodes: Record<string, { label: string; icon: string; color: string; outcomes: string[] }>; assignees: Record<string, string>; escalations: Record<string, string>
    operators: Record<string, string>; patterns: string[]; actions: Array<{ key: string; label: string; risk: string; permitted: boolean; inputs: any[] }>; recordFields: Array<{ key: string; label: string; type: string }> }
  forms: Array<{ id: number; name: string; fields: Array<{ key: string; label: string; type: string }> }>
  roles: string[]; people: Array<{ id: number; name: string }>; departments: Array<{ id: number; name: string }>
  subworkflows: Array<{ key: string; name: string }>
  can: { design: boolean; ai: boolean }
  requiresReview: boolean
}>()

const editable = computed(() => props.can.design && props.version.editable)
// PHP sends an empty settings map as [] — an array would silently drop any key set on it, so
// every step's settings (and an action's inputs) are made plain objects before editing.
function normalise(g: { nodes: GNode[]; edges: GEdge[] }) {
  for (const n of g.nodes) {
    if (!n.config || Array.isArray(n.config)) n.config = {}
    if (n.config.input !== undefined && Array.isArray(n.config.input)) n.config.input = {}
  }
  return g
}
const graph = ref<{ nodes: GNode[]; edges: GEdge[] }>(normalise(JSON.parse(JSON.stringify(props.version.graph))))
const problems = ref<Problem[]>(props.version.validation ?? [])
const dirty = ref(false)
const selectedNode = ref<string | null>(null)
const selectedEdge = ref<string | null>(null)
const { el: canvas, height: fitHeight } = useFitToViewport(16)
// The canvas fills the window below the toolbar, but never shrinks past a usable size.
const height = computed(() => `${Math.max(460, parseInt(fitHeight.value) || 0)}px`)

const { fitView, setCenter, project, onConnect, onNodeDragStop, onPaneClick, onNodeClick, onEdgeClick } = useVueFlow()

const problemNodes = computed(() => new Set(problems.value.map(p => p.node).filter(Boolean)))
const vfNodes = computed(() => graph.value.nodes.map(n => ({
  id: n.key, type: 'wf', position: { x: n.x, y: n.y }, data: n, draggable: editable.value, selected: selectedNode.value === n.key,
})))
const vfEdges = computed(() => graph.value.edges.map(e => ({
  id: e.key, source: e.from, target: e.to, type: 'smoothstep', markerEnd: MarkerType.ArrowClosed, selectable: true,
  label: e.label || (e.outcome ? label(e.outcome) : e.is_default ? 'otherwise' : e.condition ? describe(e.condition, props.catalog.operators) : undefined),
  style: { stroke: selectedEdge.value === e.key ? '#7367f0' : problems.value.some(p => p.edge === e.key) ? '#ea5455' : e.outcome === 'rejected' ? '#ea5455' : '#90a4ae', strokeWidth: selectedEdge.value === e.key ? 2.5 : 1.5 },
  labelStyle: { fontSize: '11px' }, labelBgStyle: { fill: 'rgb(var(--v-theme-surface))' },
})))

function key(prefix: string) {
  let i = graph.value.nodes.length + graph.value.edges.length + 1
  const used = new Set([...graph.value.nodes.map(n => n.key), ...graph.value.edges.map(e => e.key)])
  while (used.has(`${prefix}${i}`)) i++
  return `${prefix}${i}`
}
function changed() { dirty.value = true }

// ── Editing ────────────────────────────────────────────────────────────────
function defaults(type: string): Record<string, any> {
  switch (type) {
    case 'approval': return { assignee: { type: 'line_manager' }, mode: 'any' }
    case 'user_task': case 'review': return { assignee: { type: 'requester' } }
    case 'end': return { outcome: 'completed' }
    case 'join': return { mode: 'all' }
    case 'delay': return { hours: 24, business_hours: true }
    case 'notification': return { to: { type: 'requester' }, subject: '', message: '' }
    case 'action': return { action: 'add_comment', input: {}, max_attempts: 3 }
    case 'document': return { title: '{{requester.name}} — summary', body: '' }
    case 'subworkflow': return { definition_key: null, wait: true }
    case 'exception': return { assignee: { type: 'process_owner' } }
    default: return {}
  }
}
function addNode(type: string, at?: { x: number; y: number }) {
  if (!editable.value) return
  const k = key(type === 'user_task' ? 'task' : type)
  const pos = at ?? { x: 120 + (graph.value.nodes.length % 5) * 60, y: 120 + (graph.value.nodes.length % 7) * 50 }
  graph.value.nodes.push({ key: k, type, name: props.catalog.nodes[type].label, x: pos.x, y: pos.y, config: defaults(type) })
  selectedNode.value = k; selectedEdge.value = null; changed()
}
function onDragStart(e: DragEvent, type: string) { e.dataTransfer?.setData('application/wf-node', type) }
function onDrop(e: DragEvent) {
  const type = e.dataTransfer?.getData('application/wf-node')
  if (!type || !canvas.value) return
  const b = canvas.value.getBoundingClientRect()
  const p = project({ x: e.clientX - b.left, y: e.clientY - b.top })
  addNode(type, { x: p.x - 85, y: p.y - 24 })
}
onConnect((c: Connection) => {
  if (!editable.value || c.source === c.target) return
  if (graph.value.edges.some(e => e.from === c.source && e.to === c.target)) return
  const from = graph.value.nodes.find(n => n.key === c.source)
  const outcomes = props.catalog.nodes[from?.type ?? '']?.outcomes ?? []
  const used = graph.value.edges.filter(e => e.from === c.source).map(e => e.outcome)
  const edge: GEdge = { key: key('e'), from: c.source, to: c.target }
  if (outcomes.length) edge.outcome = outcomes.find(o => !used.includes(o)) ?? outcomes[0]
  graph.value.edges.push(edge)
  selectedEdge.value = edge.key; selectedNode.value = null; changed()
})
onNodeDragStop(({ node }) => {
  const n = graph.value.nodes.find(x => x.key === node.id)
  if (n) { n.x = Math.round(node.position.x); n.y = Math.round(node.position.y); changed() }
})
onNodeClick(({ node }) => { selectedNode.value = node.id; selectedEdge.value = null })
onEdgeClick(({ edge }) => { selectedEdge.value = edge.id; selectedNode.value = null })
onPaneClick(() => { selectedNode.value = null; selectedEdge.value = null })

const node = computed(() => graph.value.nodes.find(n => n.key === selectedNode.value) ?? null)
const edge = computed(() => graph.value.edges.find(e => e.key === selectedEdge.value) ?? null)
const edgeFrom = computed(() => graph.value.nodes.find(n => n.key === edge.value?.from) ?? null)

function removeSelected() {
  if (!editable.value) return
  if (node.value) {
    if (node.value.type === 'start') return
    const k = node.value.key
    graph.value.nodes = graph.value.nodes.filter(n => n.key !== k)
    graph.value.edges = graph.value.edges.filter(e => e.from !== k && e.to !== k)
    selectedNode.value = null
  } else if (edge.value) {
    graph.value.edges = graph.value.edges.filter(e => e.key !== edge.value!.key)
    selectedEdge.value = null
  }
  changed()
}
function duplicate() {
  if (!node.value || !editable.value || node.value.type === 'start') return
  const copy = JSON.parse(JSON.stringify(node.value)) as GNode
  copy.key = key(copy.type === 'user_task' ? 'task' : copy.type); copy.name = `${copy.name} (copy)`; copy.x += 40; copy.y += 40
  graph.value.nodes.push(copy); selectedNode.value = copy.key; changed()
}
function onKey(e: KeyboardEvent) {
  const t = e.target as HTMLElement
  if (['INPUT', 'TEXTAREA'].includes(t.tagName) || t.isContentEditable) return
  if (e.key === 'Delete' || e.key === 'Backspace') removeSelected()
}

// Delete works wherever focus is (a clicked connection does not take focus), except while typing.
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// ── Layout, search ─────────────────────────────────────────────────────────
function autoLayout() {
  const g = new dagre.graphlib.Graph()
  g.setGraph({ rankdir: 'LR', nodesep: 50, ranksep: 90 })
  g.setDefaultEdgeLabel(() => ({}))
  graph.value.nodes.forEach(n => g.setNode(n.key, { width: 180, height: 56 }))
  graph.value.edges.forEach(e => g.setEdge(e.from, e.to))
  dagre.layout(g)
  graph.value.nodes.forEach(n => { const p = g.node(n.key); if (p) { n.x = Math.round(p.x - 90); n.y = Math.round(p.y - 28) } })
  changed(); nextTick(() => fitView({ padding: 0.2 }))
}
const search = ref('')
function find() {
  const n = graph.value.nodes.find(x => x.name.toLowerCase().includes(search.value.toLowerCase()))
  if (n) { selectedNode.value = n.key; setCenter(n.x + 90, n.y + 28, { zoom: 1.2, duration: 300 }) }
}
function focusProblem(p: Problem) {
  if (p.node) { const n = graph.value.nodes.find(x => x.key === p.node); if (n) { selectedNode.value = n.key; selectedEdge.value = null; setCenter(n.x + 90, n.y + 28, { zoom: 1.1, duration: 300 }) } }
  if (p.edge) { selectedEdge.value = p.edge; selectedNode.value = null }
}

// ── Fields a condition or form-picker can use ──────────────────────────────
const formFields = computed(() => {
  const out: Array<{ key: string; label: string; type: string }> = []
  for (const n of graph.value.nodes) {
    const f = props.forms.find(x => x.id === n.config?.form_id)
    if (f) out.push(...f.fields.map(x => ({ key: x.key, label: `${x.label} (${f.name})`, type: x.type })))
  }
  return out
})
const conditionFields = computed(() => [
  ...formFields.value,
  ...props.catalog.recordFields.map(f => ({ key: `record.${f.key}`, label: `Record: ${f.label}`, type: f.type })),
  { key: 'requester.department_id', label: "Requester's department (id)", type: 'number' },
])

// ── Server round-trips ─────────────────────────────────────────────────────
const validating = ref(false)
async function validate() {
  validating.value = true
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  try {
    const r = await fetch(route('workflow.definitions.validate', props.definition.uuid), { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: JSON.stringify({ graph: graph.value }) })
    problems.value = (await r.json()).problems ?? []
    // Show the result: the problem list lives where a selected step's settings would be.
    selectedNode.value = null; selectedEdge.value = null
  } finally { validating.value = false }
}
const saving = ref(false)
function save(after?: () => void) {
  saving.value = true
  router.post(route('workflow.definitions.draft', props.definition.uuid), { graph: graph.value }, {
    preserveScroll: true, preserveState: true,
    onSuccess: (page: any) => { dirty.value = false; problems.value = page.props.version?.validation ?? problems.value; after?.() },
    onFinish: () => { saving.value = false },
  })
}
const publishDialog = ref(false)
const publishForm = useForm({ change_summary: props.version.change_summary ?? '' })
function publish() { publishForm.post(route('workflow.definitions.submit', props.definition.uuid), { onSuccess: () => { publishDialog.value = false } }) }
function openPublish() { if (dirty.value) save(() => { publishDialog.value = true }); else publishDialog.value = true }

const explanation = ref<{ text: string; source: string } | null>(null)
const explaining = ref(false)
async function explain() {
  explaining.value = true
  if (dirty.value) await new Promise<void>(res => save(() => res()))
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  try {
    const r = await fetch(route('workflow.definitions.explain', props.definition.uuid), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } })
    explanation.value = await r.json()
  } finally { explaining.value = false }
}

watch(() => props.version.validation, v => { problems.value = v ?? [] })
const actionDef = computed(() => props.catalog.actions.find(a => a.key === node.value?.config?.action))
const nodeTypeItems = computed(() => Object.entries(props.catalog.nodes).filter(([k]) => k !== 'start').map(([k, v]) => ({ key: k, ...v })))
const outcomeItems = computed(() => (props.catalog.nodes[edgeFrom.value?.type ?? '']?.outcomes ?? []).map(o => ({ title: label(o), value: o })))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-3">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="ms-n3" @click="router.get(route('workflow.definitions.show', definition.uuid))">{{ definition.name }}</VBtn>
        <h4 class="text-h5">Designer <span class="text-medium-emphasis">· version {{ version.version }}</span>
          <VChip size="small" :color="statusColor[version.status]" label class="ms-2">{{ label(version.status) }}</VChip>
          <VChip v-if="dirty" size="small" color="warning" label class="ms-1">Unsaved changes</VChip>
        </h4>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VTextField v-model="search" density="compact" hide-details placeholder="Find a step" prepend-inner-icon="tabler-search" style="max-inline-size: 200px" @keydown.enter="find" />
        <VBtn variant="tonal" prepend-icon="tabler-layout-distribute-horizontal" :disabled="!editable" @click="autoLayout">Tidy</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-checks" :loading="validating" @click="validate">Check</VBtn>
        <VBtn v-if="can.ai" variant="tonal" prepend-icon="tabler-sparkles" :loading="explaining" @click="explain">Explain</VBtn>
        <VBtn variant="tonal" color="primary" prepend-icon="tabler-device-floppy" :disabled="!editable || !dirty" :loading="saving" @click="save()">Save draft</VBtn>
        <VBtn color="primary" prepend-icon="tabler-rocket" :disabled="!editable" @click="openPublish">{{ requiresReview ? 'Submit for review' : 'Publish' }}</VBtn>
      </div>
    </div>
    <VAlert v-if="!editable" type="info" variant="tonal" density="compact" class="mb-3">
      {{ version.status === 'in_review' ? 'This version is waiting for review; withdraw it from the workflow page to edit.' : 'Read-only.' }}
    </VAlert>

    <div class="d-flex gap-3" style="align-items: stretch">
      <!-- Palette -->
      <VCard border class="flex-shrink-0" style="inline-size: 170px">
        <VCardText class="pa-2">
          <div class="text-caption text-medium-emphasis mb-2">Drag or click to add</div>
          <div v-for="t in nodeTypeItems" :key="t.key" class="wf-palette d-flex align-center gap-2 pa-2 rounded mb-1" :draggable="editable"
               :class="{ 'opacity-50': !editable }" @dragstart="onDragStart($event, t.key)" @click="addNode(t.key)">
            <VIcon :icon="`tabler-${t.icon}`" :color="t.color" size="18" /><span class="text-body-2">{{ t.label }}</span>
          </div>
        </VCardText>
      </VCard>

      <!-- Canvas -->
      <div ref="canvas" class="flex-grow-1 border rounded wf-canvas" :style="{ height, minWidth: 0 }" @drop.prevent="onDrop" @dragover.prevent>
        <VueFlow :nodes="vfNodes" :edges="vfEdges" fit-view-on-init :min-zoom="0.2" :max-zoom="2" :snap-to-grid="true" :snap-grid="[10, 10]" :delete-key-code="null">
          <template #node-wf="{ data }">
            <div class="wf-node" :class="{ 'wf-node--selected': selectedNode === data.key, 'wf-node--problem': problemNodes.has(data.key), 'wf-node--round': ['decision', 'start', 'end'].includes(data.type) }">
              <Handle v-if="data.type !== 'start'" type="target" :position="Position.Left" />
              <VIcon :icon="`tabler-${catalog.nodes[data.type]?.icon ?? 'square'}`" :color="catalog.nodes[data.type]?.color" size="18" />
              <div class="wf-node__text">
                <div class="wf-node__name">{{ data.name }}</div>
                <div class="wf-node__type">{{ catalog.nodes[data.type]?.label }}{{ data.config?.sla?.hours ? ` · ${data.config.sla.hours} h` : '' }}</div>
              </div>
              <Handle v-if="data.type !== 'end'" type="source" :position="Position.Right" />
            </div>
          </template>
          <Background :gap="20" />
          <Controls />
          <MiniMap pannable zoomable :node-color="(n: any) => (problemNodes.has(n.id) ? '#ea5455' : '#7367f0')" />
        </VueFlow>
      </div>

      <!-- Properties -->
      <VCard border class="flex-shrink-0 overflow-y-auto" :style="{ inlineSize: '340px', maxHeight: height }">
        <template v-if="node">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ catalog.nodes[node.type].label }}</VCardTitle>
            <template #append>
              <VBtn v-if="node.type !== 'start'" icon="tabler-copy" size="small" variant="text" :disabled="!editable" title="Duplicate" @click="duplicate" />
              <VBtn v-if="node.type !== 'start'" icon="tabler-trash" size="small" variant="text" color="error" :disabled="!editable" title="Delete" @click="removeSelected" />
            </template>
          </VCardItem>
          <VCardText class="d-flex flex-column gap-2">
            <VTextField v-model="node.name" label="Name" density="compact" :disabled="!editable" @update:model-value="changed" />
            <VSelect v-if="['start', 'user_task', 'review'].includes(node.type)" v-model="node.config.form_id" :items="forms" item-title="name" item-value="id" clearable density="compact"
                     :label="node.type === 'start' ? 'Request form' : 'Task form'" :disabled="!editable" @update:model-value="changed" />
            <template v-if="['user_task', 'approval', 'review', 'exception'].includes(node.type)">
              <AssigneePicker v-model="node.config.assignee" :types="catalog.assignees" :roles="roles" :people="people" :departments="departments" :fields="formFields"
                              :allow-record="definition.module !== 'generic'" :disabled="!editable" @update:model-value="changed" />
              <VSwitch v-model="node.config.queue" label="One shared queue: first to claim it does it" density="compact" hide-details color="primary" :disabled="!editable" @update:model-value="changed" />
              <VTextarea v-model="node.config.instructions" label="Instructions" rows="2" auto-grow density="compact" :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="node.type === 'approval'">
              <VSelect v-model="node.config.mode" :items="[{ title: 'Any one approver', value: 'any' }, { title: 'Everyone must approve', value: 'all' }, { title: 'A quorum', value: 'quorum' }]"
                       label="Approval rule" density="compact" :disabled="!editable" @update:model-value="changed" />
              <VTextField v-if="node.config.mode === 'quorum'" v-model.number="node.config.quorum" type="number" min="1" label="Approvals needed" density="compact" :disabled="!editable" @update:model-value="changed" />
              <VCheckbox v-model="node.config.allow_return" label="Allow return for correction" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
              <VCheckbox v-model="node.config.allow_abstain" label="Allow abstaining (conflict of interest)" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
              <VCheckbox :model-value="node.config.distinct_approvers !== false" label="Someone who approved an earlier level may not approve this one" density="compact" hide-details :disabled="!editable"
                         @update:model-value="node.config.distinct_approvers = !!$event; changed()" />
              <VCheckbox v-model="node.config.allow_self_approval" label="Allow self-approval (only if company policy permits)" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="['user_task', 'review'].includes(node.type)">
              <VCheckbox v-model="node.config.allow_return" label="Allow return for correction" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="['user_task', 'approval', 'review'].includes(node.type)">
              <VCheckbox :model-value="node.config.allow_delegate !== false" label="Assignee may delegate it" density="compact" hide-details :disabled="!editable"
                         @update:model-value="node.config.allow_delegate = !!$event; changed()" />
              <div class="text-caption text-medium-emphasis mt-2">Service level</div>
              <div class="d-flex gap-2">
                <VTextField :model-value="node.config.sla?.hours" type="number" min="1" label="Target (hours)" density="compact" :disabled="!editable"
                            @update:model-value="node.config.sla = $event ? { ...(node.config.sla ?? { business_hours: true, warn_percent: 75, escalation: 'notify_manager' }), hours: Number($event) } : undefined; changed()" />
                <VTextField v-if="node.config.sla" v-model.number="node.config.sla.warn_percent" type="number" label="Warn at %" density="compact" :disabled="!editable" @update:model-value="changed" />
              </div>
              <template v-if="node.config.sla">
                <VCheckbox v-model="node.config.sla.business_hours" label="Count business hours only" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
                <VSelect v-model="node.config.sla.escalation" :items="Object.entries(catalog.escalations).map(([value, title]) => ({ value, title }))" label="When it is overdue" density="compact" :disabled="!editable" @update:model-value="changed" />
              </template>
            </template>
            <template v-if="node.type === 'join'">
              <VSelect v-model="node.config.mode" :items="[{ title: 'Wait for every branch', value: 'all' }, { title: 'Continue after the first', value: 'any' }, { title: 'Continue after a quorum', value: 'quorum' }]"
                       label="Continue when" density="compact" :disabled="!editable" @update:model-value="changed" />
              <VTextField v-if="node.config.mode === 'quorum'" v-model.number="node.config.quorum" type="number" min="1" label="Branches needed" density="compact" :disabled="!editable" @update:model-value="changed" />
              <VCheckbox v-model="node.config.cancel_remaining" label="Cancel the branches still running" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="node.type === 'delay'">
              <VTextField v-model.number="node.config.hours" type="number" min="0" label="Wait (hours)" density="compact" :disabled="!editable" @update:model-value="changed" />
              <VCheckbox v-model="node.config.business_hours" label="Business hours" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
              <VSelect v-model="node.config.until_field" :items="formFields.filter(f => f.type === 'date')" item-title="label" item-value="key" clearable label="…or until a date on the form" density="compact" :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="node.type === 'notification'">
              <AssigneePicker v-model="node.config.to" label="Notify" :types="catalog.assignees" :roles="roles" :people="people" :departments="departments" :fields="formFields" :allow-record="definition.module !== 'generic'" :disabled="!editable" @update:model-value="changed" />
              <VTextField v-model="node.config.subject" label="Subject (may use {{field}})" density="compact" :disabled="!editable" @update:model-value="changed" />
              <VTextarea v-model="node.config.message" label="Message (may use {{field}})" rows="3" auto-grow density="compact" :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="node.type === 'action'">
              <VSelect v-model="node.config.action" :items="catalog.actions.map(a => ({ title: a.label + (a.permitted ? '' : ' — not permitted'), value: a.key, props: { disabled: !a.permitted } }))"
                       label="Action" density="compact" :disabled="!editable" @update:model-value="node.config.input = {}; changed()" />
              <VAlert v-if="actionDef?.risk === 'material'" type="warning" variant="tonal" density="compact">This changes a business record: it must sit behind an approval.</VAlert>
              <template v-for="inp in actionDef?.inputs ?? []" :key="inp.key">
                <AssigneePicker v-if="inp.type === 'assignee'" v-model="node.config.input[inp.key]" :label="inp.label" :types="catalog.assignees" :roles="roles" :people="people" :departments="departments" :fields="formFields" :disabled="!editable" @update:model-value="changed" />
                <VSelect v-else-if="inp.type === 'select'" v-model="node.config.input[inp.key]" :items="inp.options" :label="inp.label" density="compact" :disabled="!editable" @update:model-value="changed" />
                <VTextarea v-else-if="inp.type === 'textarea'" v-model="node.config.input[inp.key]" :label="inp.label" rows="2" auto-grow density="compact" :disabled="!editable" @update:model-value="changed" />
                <VTextField v-else v-model="node.config.input[inp.key]" :label="inp.label" :type="inp.type === 'number' ? 'number' : 'text'" density="compact" :disabled="!editable" @update:model-value="changed" />
              </template>
              <VTextField v-model.number="node.config.max_attempts" type="number" min="1" max="10" label="Attempts before it is raised as a failure" density="compact" :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="node.type === 'document'">
              <VTextField v-model="node.config.title" label="Document title" density="compact" :disabled="!editable" @update:model-value="changed" />
              <VTextarea v-model="node.config.body" label="Content (may use {{field}})" rows="5" auto-grow density="compact" :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="node.type === 'subworkflow'">
              <VSelect v-model="node.config.definition_key" :items="subworkflows" item-title="name" item-value="key" label="Workflow to start" density="compact" :disabled="!editable" @update:model-value="changed" />
              <VCheckbox v-model="node.config.wait" label="Wait for it to finish" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
              <VCheckbox v-model="node.config.pass_data" label="Pass this request's values" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="node.type === 'end'">
              <VSelect v-model="node.config.outcome" :items="[{ title: 'Completed / approved', value: 'completed' }, { title: 'Rejected', value: 'rejected' }, { title: 'Cancelled', value: 'cancelled' }]"
                       label="Outcome" density="compact" :disabled="!editable" @update:model-value="changed" />
            </template>
            <template v-if="node.type === 'decision'">
              <div class="text-body-2 text-medium-emphasis">Draw a connection for each route, then select the connection to give it a condition. One route is the default.</div>
            </template>
          </VCardText>
        </template>
        <template v-else-if="edge">
          <VCardItem>
            <VCardTitle class="text-body-1">Connection</VCardTitle>
            <VCardSubtitle>{{ edgeFrom?.name }} → {{ graph.nodes.find(n => n.key === edge!.to)?.name }}</VCardSubtitle>
            <template #append><VBtn icon="tabler-trash" size="small" variant="text" color="error" :disabled="!editable" @click="removeSelected" /></template>
          </VCardItem>
          <VCardText class="d-flex flex-column gap-2">
            <VSelect v-if="outcomeItems.length" v-model="edge.outcome" :items="outcomeItems" label="Taken when the step finishes as" density="compact" :disabled="!editable" @update:model-value="changed" />
            <VTextField v-model="edge.label" label="Label (optional)" density="compact" :disabled="!editable" @update:model-value="changed" />
            <template v-if="edgeFrom?.type === 'decision'">
              <VCheckbox v-model="edge.is_default" label="Default route (when no condition matches)" density="compact" hide-details :disabled="!editable" @update:model-value="changed" />
              <ConditionEditor v-if="!edge.is_default" v-model="edge.condition" :fields="conditionFields" :operators="catalog.operators" :patterns="catalog.patterns" :disabled="!editable" @update:model-value="changed" />
            </template>
          </VCardText>
        </template>
        <template v-else>
          <VCardItem><VCardTitle class="text-body-1">{{ problems.length ? `${problems.length} thing(s) to fix` : 'Ready' }}</VCardTitle>
            <VCardSubtitle>{{ problems.length ? 'Click one to find it on the canvas.' : 'Check again after changes; publishing checks too.' }}</VCardSubtitle></VCardItem>
          <VList density="compact" lines="three">
            <VListItem v-for="(p, i) in problems" :key="i" @click="focusProblem(p)">
              <template #prepend><VIcon icon="tabler-alert-circle" color="error" size="18" /></template>
              <VListItemTitle class="text-body-2 text-wrap">{{ p.message }}</VListItemTitle>
              <VListItemSubtitle class="text-wrap">{{ p.fix }}</VListItemSubtitle>
            </VListItem>
          </VList>
          <VCardText v-if="!problems.length" class="text-body-2 text-medium-emphasis">Select a step or connection to edit it. Drag from a step's right edge to another step to connect them. Delete removes the selection.</VCardText>
        </template>
      </VCard>
    </div>

    <VDialog v-model="publishDialog" max-width="520">
      <VCard :title="requiresReview ? 'Submit for review' : 'Publish this version'">
        <VCardText>
          <p class="text-body-2 mb-3">{{ requiresReview ? 'Someone with publishing rights, other than you, reviews it before it goes live.' : 'New requests use this version; requests already running stay on theirs.' }}</p>
          <VTextarea v-model="publishForm.change_summary" label="What changed and why" rows="3" auto-grow :error-messages="publishForm.errors.change_summary" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="publishDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="publishForm.processing" :disabled="publishForm.change_summary.trim().length < 3" @click="publish">{{ requiresReview ? 'Submit' : 'Publish' }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!explanation" max-width="640" @update:model-value="explanation = null">
      <VCard title="What this workflow does">
        <VCardText><div class="text-body-2" style="white-space: pre-wrap">{{ explanation?.text }}</div>
          <div class="text-caption text-medium-emphasis mt-2">{{ explanation?.source === 'ai' ? 'Written by AI from the saved draft.' : 'Generated from the saved draft.' }}</div></VCardText>
        <VCardActions><VSpacer /><VBtn @click="explanation = null">Close</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.wf-palette { cursor: grab; border: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity)); }
.wf-palette:hover { background: rgba(var(--v-theme-primary), 0.06); }
.wf-node { display: flex; align-items: center; gap: 8px; padding: 8px 12px; inline-size: 180px; border-radius: 8px;
  background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-on-surface)); border: 1.5px solid rgba(var(--v-border-color), 0.5); box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
.wf-node--round { border-radius: 22px; }
.wf-node--selected { border-color: rgb(var(--v-theme-primary)); box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), .18); }
.wf-node--problem { border-color: rgb(var(--v-theme-error)); }
.wf-node__text { min-inline-size: 0; }
.wf-node__name { font-size: 12.5px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wf-node__type { font-size: 10.5px; opacity: .65; }
</style>
