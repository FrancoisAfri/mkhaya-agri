<script setup lang="ts">
/** Process 1 — each field's season from soil test to weighbridge, run on the workflow engine. */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import { human, num, statusColor } from './prd'

const props = defineProps<{
  fields: Array<Record<string, any>>
  farms: Array<{ value: string; title: string }>
  seasons: Array<{ value: string; title: string }>
  cropFields: Array<{ value: string; title: string }>
  installed: boolean
  processes: Array<{ key: string; status: string }>
  can: { plan: boolean; record: boolean }
}>()
const starting = ref(false)
const reporting = ref(false)
const soil = ref<string | null>(null)
const today = new Date().toISOString().slice(0, 10)
const startFields: FormField[] = [
  { key: 'farm_id', label: 'Farm', type: 'select', items: props.farms, required: true, cols: 6 },
  { key: 'season_id', label: 'Season', type: 'select', items: props.seasons, required: true, cols: 6 },
  { key: 'planting_window_start', label: 'Planting window opens', type: 'date', default: today, required: true, cols: 6 },
  { key: 'planting_window_end', label: 'Planting window closes', type: 'date', required: true, cols: 6 },
  { key: 'replant_window_end', label: 'Replanting possible until', type: 'date', cols: 6 },
  { key: 'rain_threshold_mm', label: 'Planting rain (mm in 7 days)', type: 'number', default: 25, cols: 6 },
  { key: 'laboratory', label: 'Soil laboratory', default: 'Soil laboratory', cols: 12 },
]
const damageFields: FormField[] = [
  { key: 'field_id', label: 'Field', type: 'select', items: props.cropFields, required: true, cols: 6 },
  { key: 'kind', label: 'What happened', type: 'select', items: ['hail', 'drought', 'flood', 'frost', 'fire'], default: 'hail', required: true, cols: 6 },
  { key: 'notes', label: 'What was seen', type: 'textarea', cols: 12 },
]
const soilFields: FormField[] = [
  { key: 'ph', label: 'pH', type: 'number', required: true, cols: 4 },
  { key: 'p_mg_kg', label: 'P (mg/kg)', type: 'number', cols: 4 }, { key: 'k_mg_kg', label: 'K (mg/kg)', type: 'number', cols: 4 },
  { key: 'recommendation', label: "The lab's recommendation", type: 'textarea', cols: 12 },
]
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Season processes</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          One field season per crop field: soil test, rotation check, inputs, planting rain, scouting every 14 days, withholding and the weighbridge.
          The steps arrive in <a href="/workflow">My work</a>.
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.record" variant="tonal" color="error" prepend-icon="tabler-cloud-storm" @click="reporting = true">Report hail or drought</VBtn>
        <VBtn v-if="can.plan && installed" color="primary" prepend-icon="tabler-player-play" @click="starting = true">Start a season</VBtn>
      </div>
    </div>

    <VAlert v-if="!installed" type="info" variant="tonal" class="mb-4">
      The crop season processes are not published for your organisation yet:
      <span v-for="p in processes" :key="p.key" class="me-2">{{ human(p.key.replace('agri_', '')) }} — {{ human(p.status) }}</span>
      <template #append>
        <VBtn v-if="can.plan" color="primary" size="small" @click="router.post('/manufacturing/agriculture/seasons/install')">Install and publish</VBtn>
      </template>
    </VAlert>

    <VCard border>
      <VTable density="comfortable">
        <thead><tr><th>Field</th><th>Crop</th><th class="text-end">Soil pH</th><th>Planted</th><th>Harvest from</th><th>Now</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="f in fields" :key="f.id">
            <td class="font-weight-medium"><a :href="`/workflow/instances/${f.id}`">{{ f.field }}</a>
              <div class="text-caption text-medium-emphasis">{{ f.reference }} · {{ f.farm }} · {{ f.season }}</div></td>
            <td>{{ f.crop ?? '—' }}<div v-if="f.variety" class="text-caption">{{ f.variety }}</div></td>
            <td class="text-end">
              <template v-if="f.ph !== null">{{ num(f.ph, 1) }}</template>
              <VBtn v-else-if="f.soil_test && can.record" size="x-small" variant="tonal" @click="soil = f.soil_test">Enter lab result</VBtn>
              <template v-else>—</template>
            </td>
            <td>{{ f.planted_on ?? '—' }}</td>
            <td>{{ f.harvest_from ?? '—' }}<div v-if="f.withholding_until" class="text-caption text-warning">withholding until {{ f.withholding_until }}</div></td>
            <td>
              <div v-for="t in f.tasks" :key="t" class="text-body-2">{{ t }}</div>
              <div v-for="w in f.waiting" :key="w" class="text-caption text-medium-emphasis">Waiting: {{ w }}</div>
              <VChip v-if="f.damage" size="x-small" color="error" label class="mt-1">{{ human(f.damage) }}</VChip>
            </td>
            <td><VChip size="small" label :color="statusColor(f.status)">{{ human(f.status) }}</VChip>
              <div v-if="f.yield" class="text-caption">{{ num(f.yield, 2) }} t/ha</div></td>
          </tr>
          <tr v-if="!fields.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No season has been started yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <FormDialog v-model="starting" title="Start a crop season" :fields="startFields" url="/manufacturing/agriculture/seasons"
      intro="Every active crop field on the farm gets its own field season. A soil sample is sent to the lab for each one." />
    <FormDialog v-model="reporting" title="Report hail or drought" :fields="damageFields" url="/manufacturing/agriculture/seasons/damage"
      intro="The field's season stops what it is doing for a damage assessment, an insurance claim and — inside the replant window — a replant decision." />
    <FormDialog :model-value="!!soil" title="Soil lab result" :fields="soilFields" :url="`/manufacturing/agriculture/seasons/soil/${soil}`"
      @update:model-value="v => { if (!v) soil = null }" intro="Below pH 5.5 the field is limed before planting." />
  </div>
</template>
