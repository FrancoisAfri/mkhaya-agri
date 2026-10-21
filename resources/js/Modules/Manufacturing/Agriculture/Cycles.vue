<script setup lang="ts">
/** Production cycles: flocks, crop seasons, herds, ponds and processing batches, and starting a new one. */
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import { CATEGORY_ICON, human, num, statusColor } from './prd'

type Opt = { value: string; title: string; category?: string; kind?: string }
const props = defineProps<{
  cycles: { data: Array<Record<string, any>>; links: any[] }
  filters: { status: string; category: string | null }
  categories: string[]
  options: { types: Opt[]; units: Opt[]; farms: Opt[]; plans: Opt[]; fields: Opt[] }
  can: { manage: boolean; record: boolean }
}>()
const f = ref({ ...props.filters })
watch(f, v => router.get('/manufacturing/agriculture/cycles', v, { preserveState: true, replace: true }), { deep: true })
const starting = ref(false)
const category = (form: Record<string, any>) => props.options.types.find(t => t.value === form.production_type_id)?.category
const fields = computed<FormField[]>(() => [
  { key: 'production_type_id', label: 'Production type', type: 'select', items: props.options.types, required: true, cols: 6 },
  { key: 'unit_id', label: 'House, field, pond or line', type: 'select', items: props.options.units, cols: 6 },
  { key: 'farm_id', label: 'Farm (when there is no unit)', type: 'select', items: props.options.farms, cols: 6, show: fm => !fm.unit_id },
  { key: 'code', label: 'Code', hint: 'Left empty, one is made', cols: 6 },
  { key: 'name', label: 'Name', cols: 6 },
  { key: 'started_on', label: 'Started (or placed) on', type: 'date', cols: 6 },
  { key: 'opening_count', label: 'Placed / stocked (count)', type: 'number', cols: 6, show: fm => ['poultry', 'livestock', 'dairy', 'aquaculture', 'greenhouse'].includes(category(fm) ?? '') },
  { key: 'crop_plan_id', label: 'Crop plan', type: 'select', items: props.options.plans, cols: 6, show: fm => ['crop', 'greenhouse'].includes(category(fm) ?? '') },
  { key: 'area', label: 'Area', type: 'number', cols: 6, show: fm => ['crop', 'greenhouse', 'aquaculture'].includes(category(fm) ?? '') },
  { key: 'breed', label: 'Breed / variety', cols: 6 },
  { key: 'source_ref', label: 'Hatchery, seed lot or source', cols: 6 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
])
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Production cycles</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">A flock is the farm's livestock group, a crop cycle the farm's crop plan — kept once, worked through here.</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="starting = true">Start a cycle</VBtn>
    </div>
    <VCard border class="mb-4"><VCardText class="d-flex gap-3 flex-wrap">
      <VSelect v-model="f.status" :items="['active', 'planned', 'closed', 'all'].map(s => ({ value: s, title: human(s) }))" label="Status" hide-details style="max-width: 200px" />
      <VSelect v-model="f.category" :items="categories.map(c => ({ value: c, title: human(c) }))" label="Kind" clearable hide-details style="max-width: 240px" />
    </VCardText></VCard>
    <VCard border>
      <VTable density="comfortable">
        <thead><tr><th>Cycle</th><th>Type</th><th>Where</th><th>Stage</th><th>Started</th><th class="text-end">Count / area</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="c in cycles.data" :key="c.id">
            <td><Link :href="`/manufacturing/agriculture/cycles/${c.id}`" class="font-weight-medium">{{ c.code }}</Link><div class="text-caption text-medium-emphasis">{{ c.name }}</div></td>
            <td><VIcon :icon="CATEGORY_ICON[c.category] ?? 'tabler-leaf'" size="16" /> {{ c.type }}</td>
            <td>{{ c.farm }}<div class="text-caption">{{ c.unit }}</div></td>
            <td>{{ human(c.stage) }}</td>
            <td>{{ c.started_on ?? '—' }}<div v-if="c.age_days !== null" class="text-caption">day {{ c.age_days }}</div></td>
            <td class="text-end"><template v-if="c.current_count !== null">{{ num(c.current_count, 0) }} {{ c.count_unit }}</template><template v-else-if="c.area">{{ num(c.area, 1) }} {{ c.area_unit }}</template></td>
            <td><VChip size="small" label :color="statusColor(c.status)">{{ human(c.status) }}</VChip></td>
          </tr>
          <tr v-if="!cycles.data.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No cycles.</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <FormDialog v-model="starting" title="Start a production cycle" :fields="fields" url="/manufacturing/agriculture/cycles" submit-label="Start" />
  </div>
</template>
