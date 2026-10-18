<script setup lang="ts">
interface Node {
  id: number; title: string; level: string; status: string
  progress_pct: number; weight: number | null; owner_name: string
  kpis_count: number; children: Node[]
}
defineProps<{ node: Node; depth: number }>()

function levelColor(l: string) {
  return l === 'organizational' ? 'deep-purple' : l === 'department' ? 'indigo' : 'teal'
}
function progressColor(p: number) {
  return p >= 75 ? 'success' : p >= 40 ? 'warning' : 'error'
}
</script>

<template>
  <div>
    <VCard border class="mb-2" :style="{ marginLeft: depth * 28 + 'px' }">
      <VCardText class="py-3">
        <div class="d-flex align-center justify-space-between flex-wrap gap-2">
          <div class="d-flex align-center gap-2">
            <VIcon v-if="depth > 0" icon="tabler-corner-down-right" size="16" class="text-medium-emphasis" />
            <span class="text-body-1 font-weight-medium">{{ node.title }}</span>
            <VChip size="x-small" :color="levelColor(node.level)" variant="tonal">{{ node.level }}</VChip>
          </div>
          <div class="d-flex align-center gap-2">
            <VChip v-if="node.kpis_count" size="x-small" variant="tonal">{{ node.kpis_count }} KPIs</VChip>
            <span class="text-caption text-medium-emphasis">{{ node.owner_name }}</span>
            <VChip size="x-small" :color="progressColor(node.progress_pct)" variant="flat">{{ Math.round(node.progress_pct) }}%</VChip>
          </div>
        </div>
        <VProgressLinear :model-value="node.progress_pct" :color="progressColor(node.progress_pct)" height="5" rounded class="mt-2" />
      </VCardText>
    </VCard>
    <GoalNode v-for="child in node.children" :key="child.id" :node="child" :depth="depth + 1" />
  </div>
</template>
