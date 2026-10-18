<script lang="ts" setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { VueFlow, useVueFlow, Panel, Handle, Position, MarkerType, type Node, type Edge } from '@vue-flow/core'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import ActionFields from './ActionFields.vue'

const { t } = useI18n()

type Graph = { nodes: any[]; edges: any[] }

const props = defineProps<{
  modelValue: Graph | null
  actionTypes: Record<string, string>
  aiActions: Record<string, string>
  fieldOps: string[]
  fieldSuggestions: string[]
  mergeTagHint: string
}>()
const emit = defineEmits<{ (e: 'update:modelValue', v: Graph): void }>()

let seq = 0
const nid = () => `n${Date.now().toString(36)}${++seq}`

const initialNodes: Node[] = (props.modelValue?.nodes?.length
  ? props.modelValue.nodes
  : [{ id: 'trigger', type: 'trigger', data: {}, position: { x: 300, y: 20 } }]) as Node[]
const initialEdges: Edge[] = (props.modelValue?.edges ?? []) as Edge[]

const { nodes, edges, addNodes, addEdges, removeNodes, removeEdges, onConnect, findNode } = useVueFlow({
  nodes: initialNodes,
  edges: initialEdges,
  defaultEdgeOptions: { type: 'smoothstep', markerEnd: MarkerType.ArrowClosed, style: { strokeWidth: 2 } },
})

onConnect((params: any) => {
  addEdges([{ ...params, id: `e-${params.source}-${params.sourceHandle ?? 'o'}-${params.target}` }])
})

// Serialize back to the parent on any change (add/move/connect/edit/delete).
function serialize(): Graph {
  return {
    nodes: nodes.value.map(n => ({ id: n.id, type: n.type, data: n.data ?? {}, position: n.position })),
    edges: edges.value.map(e => ({ id: e.id, source: e.source, target: e.target, sourceHandle: e.sourceHandle ?? null })),
  }
}
watch([nodes, edges], () => emit('update:modelValue', serialize()), { deep: true })

// ── Add nodes ───────────────────────────────────────────────────────────────
function addNode(type: string) {
  const base = { id: nid(), type, position: { x: 250 + Math.round((Math.random() - 0.5) * 120), y: 120 + nodes.value.length * 90 } }
  const data: Record<string, any> =
    type === 'wait' ? { amount: 1, unit: 'days' }
      : type === 'condition' ? { match_type: 'all', conditions: [] }
        : type === 'branch' ? { match_type: 'all', conditions: [] }
          : { action: 'create_task', subject: '', due_in_days: 2, assign_to: 'owner' }
  addNodes([{ ...base, data } as Node])
}

// ── Edit drawer ───────────────────────────────────────────────────────────────
const editPanel = ref(false)
const selected = ref<any>(null)

function onNodeClick({ node }: any) {
  if (node.type === 'trigger') return
  selected.value = node
  editPanel.value = true
}
function deleteSelected() {
  if (!selected.value) return
  const id = selected.value.id
  removeEdges(edges.value.filter(e => e.source === id || e.target === id).map(e => e.id))
  removeNodes([id])
  editPanel.value = false
  selected.value = null
}
function addCond(d: any) { (d.conditions ||= []).push({ field: props.fieldSuggestions?.[0] ?? 'status', operator: '=', value: '' }) }
function removeCond(d: any, j: number) { d.conditions.splice(j, 1) }

const nodeTitle = (item: string) => ({ action: t('crm_flow_canvas.node_action'), wait: t('crm_flow_canvas.node_wait'), condition: t('crm_flow_canvas.node_condition_gate'), branch: t('crm_flow_canvas.node_branch_if_else') }[item] ?? item)
const actionLabel = (d: any) => props.actionTypes[d?.action] ?? t('crm_flow_canvas.action')
</script>

<template>
  <div class="flow-canvas-wrap" style="height: 460px; border:1px solid rgba(0,0,0,0.12); border-radius:8px; position:relative;">
    <VueFlow :nodes="nodes" :edges="edges" fit-view-on-init :min-zoom="0.3" :max-zoom="1.5" @node-click="onNodeClick">
      <template #node-trigger>
        <div class="fc-node fc-trigger"><VIcon size="16" class="me-1">tabler-bolt</VIcon>{{ t('crm_flow_canvas.trigger') }}</div>
        <Handle type="source" :position="Position.Bottom" />
      </template>

      <template #node-action="{ data }">
        <Handle type="target" :position="Position.Top" />
        <div class="fc-node fc-action"><VIcon size="14" class="me-1">tabler-player-play</VIcon>{{ actionLabel(data) }}</div>
        <Handle type="source" :position="Position.Bottom" />
      </template>

      <template #node-wait="{ data }">
        <Handle type="target" :position="Position.Top" />
        <div class="fc-node fc-wait"><VIcon size="14" class="me-1">tabler-clock</VIcon>{{ t('crm_flow_canvas.wait_amount_unit', { amount: data.amount, unit: data.unit }) }}</div>
        <Handle type="source" :position="Position.Bottom" />
      </template>

      <template #node-condition="{ data }">
        <Handle type="target" :position="Position.Top" />
        <div class="fc-node fc-condition"><VIcon size="14" class="me-1">tabler-filter</VIcon>{{ t('crm_flow_canvas.gate_rule_count', { count: (data.conditions || []).length }) }}</div>
        <Handle type="source" :position="Position.Bottom" />
      </template>

      <template #node-branch="{ data }">
        <Handle type="target" :position="Position.Top" />
        <div class="fc-node fc-branch"><VIcon size="14" class="me-1">tabler-git-branch</VIcon>{{ t('crm_flow_canvas.branch_rule_count', { count: (data.conditions || []).length }) }}</div>
        <Handle id="yes" type="source" :position="Position.Bottom" style="left:28%; background:#4caf50;" />
        <Handle id="no" type="source" :position="Position.Bottom" style="left:72%; background:#f44336;" />
        <div class="fc-branch-labels"><span class="text-success">{{ t('crm_flow_canvas.yes') }}</span><span class="text-error">{{ t('crm_flow_canvas.no') }}</span></div>
      </template>

      <Panel position="top-left">
        <VCard border class="pa-2 d-flex flex-wrap gap-1 align-center" style="max-width: 420px;">
          <span class="text-caption font-weight-medium text-medium-emphasis me-1">{{ t('crm_flow_canvas.add_label') }}</span>
          <VBtn size="x-small" variant="tonal" color="primary" prepend-icon="tabler-player-play" @click="addNode('action')">{{ t('crm_flow_canvas.action') }}</VBtn>
          <VBtn size="x-small" variant="tonal" color="info" prepend-icon="tabler-clock" @click="addNode('wait')">{{ t('crm_flow_canvas.wait') }}</VBtn>
          <VBtn size="x-small" variant="tonal" color="warning" prepend-icon="tabler-filter" @click="addNode('condition')">{{ t('crm_flow_canvas.gate') }}</VBtn>
          <VBtn size="x-small" variant="tonal" color="purple" prepend-icon="tabler-git-branch" @click="addNode('branch')">{{ t('crm_flow_canvas.branch') }}</VBtn>
        </VCard>
      </Panel>
      <Panel position="top-right">
        <span class="text-caption text-medium-emphasis">{{ t('crm_flow_canvas.drag_handles_hint') }}</span>
      </Panel>
    </VueFlow>

    <VNavigationDrawer v-model="editPanel" location="right" temporary width="380" :scrim="false">
      <div v-if="selected" class="pa-4">
        <div class="d-flex align-center justify-space-between mb-3">
          <span class="text-subtitle-1 font-weight-medium">{{ nodeTitle(selected.type) }}</span>
          <VBtn size="small" variant="text" icon="tabler-trash" color="error" @click="deleteSelected" />
        </div>

        <ActionFields v-if="selected.type === 'action'" :model-value="selected.data" :action-types="actionTypes" :ai-actions="aiActions" :merge-tag-hint="mergeTagHint" />

        <template v-else-if="selected.type === 'wait'">
          <VRow>
            <VCol cols="6"><VTextField v-model.number="selected.data.amount" type="number" :label="t('crm_flow_canvas.wait')" density="compact" /></VCol>
            <VCol cols="6"><VSelect v-model="selected.data.unit" :label="t('crm_flow_canvas.unit')" :items="['minutes', 'hours', 'days']" density="compact" /></VCol>
          </VRow>
        </template>

        <template v-else-if="selected.type === 'condition' || selected.type === 'branch'">
          <div class="d-flex align-center justify-space-between mb-2">
            <span class="text-caption text-medium-emphasis">{{ selected.type === 'branch' ? t('crm_flow_canvas.if_ellipsis') : t('crm_flow_canvas.continue_only_if') }}</span>
            <VBtnToggle v-model="selected.data.match_type" density="compact" mandatory variant="outlined">
              <VBtn value="all" size="x-small">{{ t('crm_flow_canvas.all') }}</VBtn>
              <VBtn value="any" size="x-small">{{ t('crm_flow_canvas.any') }}</VBtn>
            </VBtnToggle>
          </div>
          <div v-for="(c, j) in selected.data.conditions" :key="j" class="d-flex gap-2 mb-2 align-center">
            <VCombobox v-model="c.field" :label="t('crm_flow_canvas.field')" :items="fieldSuggestions" density="compact" hide-details style="max-width: 150px" />
            <VSelect v-model="c.operator" :items="fieldOps" density="compact" hide-details style="max-width: 100px" />
            <VTextField v-model="c.value" :label="t('crm_flow_canvas.value')" density="compact" hide-details />
            <VBtn size="x-small" variant="text" icon="tabler-x" color="error" @click="removeCond(selected.data, j)" />
          </div>
          <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="addCond(selected.data)">{{ t('crm_flow_canvas.add_condition') }}</VBtn>
          <p v-if="selected.type === 'branch'" class="text-caption text-medium-emphasis mt-3">{{ t('crm_flow_canvas.connect_yes_no_hint') }}</p>
        </template>

        <VBtn class="mt-4" block variant="tonal" @click="editPanel = false">{{ t('crm_flow_canvas.done') }}</VBtn>
      </div>
    </VNavigationDrawer>
  </div>
</template>

<style scoped>
.fc-node { padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 500; color: #fff; display: flex; align-items: center; white-space: nowrap; }
.fc-trigger { background: #5e35b1; }
.fc-action { background: #1e88e5; }
.fc-wait { background: #00897b; }
.fc-condition { background: #fb8c00; }
.fc-branch { background: #8e24aa; }
.fc-branch-labels { display: flex; justify-content: space-between; font-size: 9px; font-weight: 700; padding: 2px 6px 0; }
</style>
