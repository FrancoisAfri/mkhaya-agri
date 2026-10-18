<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Cycle = { id: number; name: string; status: string }
type BoxDef = { label: string; perf: string; pot: string; color: string }
type Emp = {
  id: number; employee_id: number; name: string; position: string | null
  box: number; performance_band: string; potential_band: string
  performance_score: number | null; potential_score: number | null; is_manual: boolean
}

const props = defineProps<{
  cycles: Cycle[]
  cycleId: number
  boxes: Record<number, BoxDef>
  employees: Emp[]
}>()

const selectedCycle = ref<number>(props.cycleId)
const dragging = ref<Emp | null>(null)

// Grid layout: rows top→bottom are potential high→low; cols left→right perf low→high.
const layout: number[][] = [
  [7, 8, 9],
  [4, 5, 6],
  [1, 2, 3],
]

const byBox = computed(() => {
  const map: Record<number, Emp[]> = {}
  for (let i = 1; i <= 9; i++) map[i] = []
  for (const e of props.employees) (map[e.box] ??= []).push(e)
  return map
})

function changeCycle() {
  router.get(route('performance.nine-box.index'), { cycle_id: selectedCycle.value }, { preserveState: false })
}

function rebuild() {
  router.post(route('performance.nine-box.rebuild'), { cycle_id: selectedCycle.value }, { preserveScroll: true })
}

function onDrop(box: number) {
  if (!dragging.value || dragging.value.box === box) { dragging.value = null; return }
  router.post(route('performance.nine-box.move'), {
    cycle_id: selectedCycle.value,
    employee_id: dragging.value.employee_id,
    box,
  }, { preserveScroll: true, onFinish: () => (dragging.value = null) })
}

const summary = computed(() => {
  const stars = props.employees.filter(e => e.box === 9).length
  const risks = props.employees.filter(e => e.box === 1).length
  return { total: props.employees.length, stars, risks }
})
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded><VIcon icon="tabler-grid-dots" size="26" /></VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('performance_nine-box.title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('performance_nine-box.subtitle') }}</p>
        </div>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VSelect v-model="selectedCycle" :items="cycles" item-title="name" item-value="id"
          density="compact" variant="outlined" :label="t('performance_nine-box.cycle_label')" style="min-width: 200px" hide-details @update:model-value="changeCycle" />
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-refresh" @click="rebuild">{{ t('performance_nine-box.rebuild_button') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar color="info" variant="tonal" rounded><VIcon icon="tabler-users" /></VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ summary.total }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('performance_nine-box.stats.placed') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar color="success" variant="tonal" rounded><VIcon icon="tabler-star" /></VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ summary.stars }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('performance_nine-box.stats.consistent_stars') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar color="error" variant="tonal" rounded><VIcon icon="tabler-alert-triangle" /></VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ summary.risks }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('performance_nine-box.stats.at_risk') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <div v-if="!employees.length" class="text-center py-12 text-medium-emphasis">
      {{ t('performance_nine-box.empty', { cta: t('performance_nine-box.rebuild_button') }) }}
    </div>

    <div v-else class="ninebox-wrap">
      <!-- Y axis label -->
      <div class="axis-y text-caption text-medium-emphasis">Potential →</div>
      <div class="ninebox-grid">
        <template v-for="(rowBoxes, rIdx) in layout" :key="rIdx">
          <div
            v-for="box in rowBoxes" :key="box"
            class="ninebox-cell"
            :style="{ borderTop: `3px solid ${boxes[box].color}` }"
            @dragover.prevent
            @drop="onDrop(box)"
          >
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-caption font-weight-bold" :style="{ color: boxes[box].color }">{{ boxes[box].label }}</span>
              <VChip size="x-small" variant="tonal">{{ byBox[box].length }}</VChip>
            </div>
            <div class="cell-people">
              <VChip
                v-for="emp in byBox[box]" :key="emp.employee_id"
                size="small" class="ma-1" label :draggable="true"
                :color="boxes[box].color" variant="tonal"
                @dragstart="dragging = emp"
              >
                <VIcon v-if="emp.is_manual" icon="tabler-hand-finger" size="12" start />
                {{ emp.name }}
              </VChip>
            </div>
          </div>
        </template>
      </div>
      <div class="axis-x text-caption text-medium-emphasis">Performance →</div>
    </div>
  </div>
</template>

<style scoped>
.ninebox-wrap { position: relative; }
.ninebox-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}
.ninebox-cell {
  min-height: 150px;
  border: 1px solid rgba(120,120,120,0.2);
  border-radius: 10px;
  padding: 10px;
  background: rgba(120,120,120,0.03);
  transition: background 0.15s;
}
.ninebox-cell:hover { background: rgba(120,120,120,0.07); }
.cell-people { display: flex; flex-wrap: wrap; }
.axis-x { text-align: center; margin-top: 8px; }
.axis-y { transform: rotate(-90deg); position: absolute; left: -42px; top: 50%; }
@media (max-width: 600px) {
  .ninebox-grid { gap: 6px; }
  .ninebox-cell { min-height: 110px; padding: 6px; }
  .axis-y { display: none; }
}
</style>
