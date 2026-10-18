<script setup lang="ts">
// The workflow drawn read-only, with the path this request took: steps it passed are filled,
// the steps it waits at pulse, and the connections it followed are drawn solid.
import { computed } from 'vue'
import { VueFlow, MarkerType } from '@vue-flow/core'
import { Controls } from '@vue-flow/controls'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'

const props = defineProps<{ graph: { nodes: any[]; edges: any[] }; height?: number }>()
// Fit when the pane is ready and the steps measured (fitting on init can run before either).
function fit(instance: any) { setTimeout(() => instance.fitView({ padding: 0.12 }), 50) }

const colour: Record<string, string> = { done: '#2e7d32', approved: '#2e7d32', resolved: '#2e7d32', routed: '#2e7d32', split: '#2e7d32', waiting: '#1565c0', active: '#1565c0',
  rejected: '#c62828', failed: '#c62828', cancelled: '#757575', returned: '#ef6c00', completed: '#2e7d32', skipped: '#9e9e9e' }

const nodes = computed(() => props.graph.nodes.map(n => ({
  id: n.key, position: { x: n.x ?? 0, y: n.y ?? 0 }, data: { label: n.name },
  label: n.name, draggable: false, connectable: false,
  class: n.state === 'waiting' ? 'wf-waiting' : '',
  style: { borderColor: n.state ? (colour[n.state] ?? '#1565c0') : '#b0bec5', borderWidth: n.state ? '2px' : '1px',
    background: n.state && n.state !== 'waiting' ? `${colour[n.state] ?? '#1565c0'}18` : 'rgb(var(--v-theme-surface))', color: 'rgb(var(--v-theme-on-surface))',
    fontSize: '12px', width: '170px', borderRadius: n.type === 'decision' ? '18px' : '8px' },
})))
const edges = computed(() => props.graph.edges.map((e, i) => ({
  id: e.key ?? `e${i}`, source: e.from, target: e.to, label: e.label ?? undefined, type: 'smoothstep',
  animated: false, markerEnd: MarkerType.ArrowClosed,
  style: { stroke: e.taken ? '#2e7d32' : '#b0bec5', strokeWidth: e.taken ? 2.5 : 1.2, strokeDasharray: e.taken ? undefined : '4 4' },
  labelStyle: { fontSize: '11px' },
})))
</script>

<template>
  <div :style="{ height: `${height ?? 340}px` }" class="wf-path border rounded">
    <VueFlow :nodes="nodes" :edges="edges" fit-view-on-init :min-zoom="0.2" :max-zoom="1.5" :nodes-draggable="false" :nodes-connectable="false" :elements-selectable="false" @pane-ready="fit">
      <Controls :show-interactive="false" />
    </VueFlow>
  </div>
</template>

<style>
.wf-path .wf-waiting { animation: wf-pulse 1.6s ease-in-out infinite; }
@keyframes wf-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(21, 101, 192, .35) } 50% { box-shadow: 0 0 0 6px rgba(21, 101, 192, 0) } }
@media (prefers-reduced-motion: reduce) { .wf-path .wf-waiting { animation: none; } }
</style>
