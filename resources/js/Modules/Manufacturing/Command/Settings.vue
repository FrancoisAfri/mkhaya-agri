<script setup lang="ts">
/**
 * Alarm settings: the range each measurement must stay in (for a production type or one unit) and
 * how long it may be out before it counts, and for each severity who is told first and who next
 * when nobody acknowledges.
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import { human, num, severityColor } from '../Agriculture/prd'

const props = defineProps<{
  thresholds: Array<{ id: string; metric: string; min: number | null; max: number | null; severity: string; sustain_minutes: number; scope: string | null; is_active: boolean; message: string | null }>
  policies: Array<{ severity: string; steps: Array<{ after_minutes: number | null; user_ids: number[]; channels: string[] }> | null; default_minutes: number | null }>
  types: Array<{ value: string; title: string }>
  units: Array<{ value: string; title: string }>
  users: Array<{ value: number; title: string }>
  severities: string[]
}>()

const adding = ref(false)
const fields: FormField[] = [
  { key: 'metric', label: 'Measurement', type: 'select', items: ['temperature', 'humidity', 'ammonia', 'co2', 'water_flow', 'soil_moisture', 'dissolved_oxygen', 'ph', 'ec'], required: true, cols: 6 },
  { key: 'severity', label: 'Severity', type: 'select', items: props.severities, default: 'warning', cols: 6 },
  { key: 'min_value', label: 'Minimum', type: 'number', cols: 4 }, { key: 'max_value', label: 'Maximum', type: 'number', cols: 4 },
  { key: 'sustain_minutes', label: 'Out of range for (minutes)', type: 'number', default: 10, cols: 4 },
  { key: 'production_type_id', label: 'For every unit of type', type: 'select', items: props.types, cols: 6 },
  { key: 'unit_id', label: 'Or for one unit', type: 'select', items: props.units, cols: 6 },
  { key: 'message', label: 'What to tell people', cols: 12 },
]
const editing = ref<{ severity: string; steps: Array<{ after_minutes: number | null; user_ids: number[]; channels: string[] }> } | null>(null)
const edit = (p: any) => { editing.value = { severity: p.severity, steps: p.steps ? JSON.parse(JSON.stringify(p.steps)) : [{ after_minutes: p.default_minutes, user_ids: [], channels: ['in_app'] }, { after_minutes: p.default_minutes, user_ids: [], channels: ['in_app'] }] } }
const save = () => router.post('/manufacturing/command/settings/escalation', editing.value!, { preserveScroll: true, onSuccess: () => { editing.value = null } })
const name = (id: number) => props.users.find(u => u.value === id)?.title ?? `#${id}`
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Alarm settings</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Limits, how long before they count, and who is told — then who next.</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="adding = true">Add threshold</VBtn>
    </div>
    <VCard border class="mb-4">
      <VCardTitle>Thresholds</VCardTitle>
      <VTable density="comfortable">
        <thead><tr><th>Measurement</th><th>For</th><th class="text-end">Range</th><th class="text-end">Sustained</th><th>Severity</th></tr></thead>
        <tbody>
          <tr v-for="t in thresholds" :key="t.id">
            <td class="font-weight-medium">{{ human(t.metric) }}<div v-if="t.message" class="text-caption">{{ t.message }}</div></td>
            <td>{{ t.scope ?? 'Everything' }}</td>
            <td class="text-end">{{ t.min !== null ? num(t.min) : '–' }} to {{ t.max !== null ? num(t.max) : '–' }}</td>
            <td class="text-end">{{ t.sustain_minutes ? `${num(t.sustain_minutes, 1)} min` : 'at once' }}</td>
            <td><VChip size="small" label :color="severityColor(t.severity)">{{ t.severity }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
    <VCard border>
      <VCardTitle>Escalation</VCardTitle>
      <VCardText class="text-body-2 text-medium-emphasis">Without a policy, step 1 is everyone who responds to incidents and step 2 everyone who manages agricultural production. SMS and WhatsApp are logged until their gateways are set up.</VCardText>
      <VTable density="comfortable">
        <thead><tr><th>Severity</th><th>Steps</th><th /></tr></thead>
        <tbody>
          <tr v-for="p in policies" :key="p.severity">
            <td><VChip size="small" label :color="severityColor(p.severity)">{{ p.severity }}</VChip></td>
            <td>
              <template v-if="p.steps">
                <div v-for="(s, k) in p.steps" :key="k" class="text-body-2">Step {{ k + 1 }}: {{ s.user_ids.map(name).join(', ') }} by {{ s.channels.join(', ') }}<template v-if="s.after_minutes"> — next step after {{ s.after_minutes }} min</template></div>
              </template>
              <span v-else class="text-medium-emphasis">Default<template v-if="p.default_minutes"> — next step after {{ p.default_minutes }} min</template></span>
            </td>
            <td class="text-end"><VBtn size="small" variant="tonal" @click="edit(p)">Edit</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
    <FormDialog v-model="adding" title="Add a threshold" :fields="fields" url="/manufacturing/command/settings/thresholds" />
    <VDialog :model-value="!!editing" max-width="720" @update:model-value="v => !v && (editing = null)">
      <VCard v-if="editing" :title="`${human(editing.severity)} escalation`">
        <VCardText>
          <VRow v-for="(s, k) in editing.steps" :key="k" dense>
            <VCol cols="12" class="text-subtitle-2">Step {{ k + 1 }}</VCol>
            <VCol cols="12" md="6"><VSelect v-model="s.user_ids" :items="users" multiple chips label="Tell" /></VCol>
            <VCol cols="6" md="3"><VSelect v-model="s.channels" :items="['in_app', 'email', 'sms', 'whatsapp']" multiple label="By" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="s.after_minutes" type="number" label="Next step after (min)" /></VCol>
          </VRow>
          <VBtn size="small" variant="tonal" :disabled="editing.steps.length >= 5" @click="editing.steps.push({ after_minutes: 15, user_ids: [], channels: ['in_app'] })">Add a step</VBtn>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="editing = null">Cancel</VBtn><VBtn color="primary" @click="save">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
