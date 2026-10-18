<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import GoalNode from './GoalNode.vue'

const route = ziggyRoute

interface Node {
  id: number; title: string; level: string; status: string
  progress_pct: number; weight: number | null; owner_name: string
  kpis_count: number; children: Node[]
}

const props = defineProps<{
  tree: Node[]
  cycles: { id: number; name: string }[]
  cycleId: number | null
}>()

const selectedCycle = ref<number | null>(props.cycleId)

function changeCycle() {
  router.get(route('performance.goals.tree'), { cycle_id: selectedCycle.value }, { preserveState: false })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="indigo" variant="tonal" size="48" rounded><VIcon icon="tabler-sitemap" size="26" /></VAvatar>
        <div>
          <h4 class="text-h4 mb-0">Goal Alignment Tree</h4>
          <p class="text-caption text-medium-emphasis mb-0">How objectives cascade from organisation → department → team</p>
        </div>
      </div>
      <VSelect v-model="selectedCycle" :items="cycles" item-title="name" item-value="id" clearable
        density="compact" variant="outlined" label="Cycle" style="min-width: 220px" hide-details @update:model-value="changeCycle" />
    </div>

    <div v-if="!tree.length" class="text-center py-12 text-medium-emphasis">
      No aligned goals yet. Set a goal's “parent goal” on the Goals page to build the cascade.
    </div>

    <div v-else>
      <GoalNode v-for="n in tree" :key="n.id" :node="n" :depth="0" />
    </div>
  </div>
</template>
