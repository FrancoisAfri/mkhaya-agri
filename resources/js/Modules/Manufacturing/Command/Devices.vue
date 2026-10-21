<script setup lang="ts">
/**
 * Sensors, meters, controllers and gateways, and every command sent to equipment with each state
 * it went through. A command is asked for with a reason; a device that needs approval waits for a
 * second person, and an unconfirmed command is never assumed to have worked.
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import ReadingChart from './ReadingChart.vue'
import { ago, at, commandColor, human, num } from '../Agriculture/prd'

const props = defineProps<{
  devices: Array<Record<string, any>>
  commands: Array<{ id: string; command: string; parameters: any; device: string | null; status: string; reason: string | null; origin: string
    requested_by: string | null; failure_reason: string | null; history: Array<{ status: string; at: string; by?: string; note?: string }>; at: string }>
  units: Array<{ value: string; title: string }>
  can: { command: boolean; approve: boolean; configure: boolean }
}>()

const adding = ref(false)
const deviceFields: FormField[] = [
  { key: 'unit_id', label: 'House, field or line', type: 'select', items: props.units, required: true, cols: 6 },
  { key: 'kind', label: 'Kind', type: 'select', items: ['sensor', 'meter', 'controller', 'gateway'], default: 'sensor', cols: 6 },
  { key: 'code', label: 'Code', required: true, cols: 6 }, { key: 'name', label: 'Name', required: true, cols: 6 },
  { key: 'metric', label: 'Measures', type: 'select', items: ['temperature', 'humidity', 'ammonia', 'co2', 'water_flow', 'water_meter', 'feed_weight', 'soil_moisture', 'dissolved_oxygen', 'ph', 'ec', 'fan', 'heater', 'valve'], cols: 6 },
  { key: 'unit', label: 'Unit of measure', hint: '°C, %, ppm, l/h', cols: 6 },
  { key: 'expected_interval_seconds', label: 'Reports every (seconds)', type: 'number', default: 300, cols: 6 },
  { key: 'calibration_offset', label: 'Calibration offset', type: 'number', default: 0, cols: 3 }, { key: 'calibration_factor', label: 'Factor', type: 'number', default: 1, cols: 3 },
  { key: 'controllable', label: 'Accepts commands', type: 'switch', cols: 6 }, { key: 'commands_need_approval', label: 'Commands need a second person', type: 'switch', default: true, cols: 6 },
]
const target = ref<Record<string, any> | null>(null)
const cmd = ref({ command: '', value: null as number | null, reason: '' })
const send = () => router.post(`/manufacturing/command/devices/${target.value!.id}/commands`, cmd.value, { preserveScroll: true, onSuccess: () => { target.value = null } })
const decide = (id: string, decision: string) => router.post(`/manufacturing/command/commands/${id}`, { decision }, { preserveScroll: true })
const graph = ref<Record<string, any> | null>(null)
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Devices &amp; commands</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">What is measuring, what can be controlled, and every instruction sent.</p>
      </div>
      <VBtn v-if="can.configure" color="primary" prepend-icon="tabler-plus" @click="adding = true">Add device</VBtn>
    </div>

    <VCard border class="mb-4">
      <VTable density="comfortable">
        <thead><tr><th>Device</th><th>Where</th><th class="text-end">Last value</th><th>Heard</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="d in devices" :key="d.id">
            <td class="font-weight-medium">{{ d.name }}<div class="text-caption text-medium-emphasis">{{ d.code }} · {{ human(d.kind) }}<template v-if="d.calibrated_on"> · calibrated {{ d.calibrated_on }}</template></div></td>
            <td>{{ d.location }}</td>
            <td class="text-end"><a v-if="d.metric && d.kind !== 'controller'" href="#" @click.prevent="graph = d">{{ num(d.last_value, 2) }} {{ d.unit }}</a><span v-else>—</span>
              <div class="text-caption">{{ d.last_measured_at ? at(d.last_measured_at) : '' }}</div></td>
            <td>{{ ago(d.last_seen_at) }}</td>
            <td><VChip size="small" label :color="d.stale || d.status === 'stale' ? 'warning' : d.status === 'online' ? 'success' : 'secondary'">{{ d.stale || d.status === 'stale' ? 'stale' : human(d.status) }}</VChip></td>
            <td class="text-end"><VBtn v-if="d.controllable && can.command" size="small" variant="tonal" prepend-icon="tabler-send" @click="target = d; cmd = { command: d.allowed_commands?.[0]?.command ?? '', value: null, reason: '' }">Command</VBtn></td>
          </tr>
          <tr v-if="!devices.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No devices yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <VCard v-if="graph" border class="mb-4"><VCardText><ReadingChart :device="graph.id" :metric="graph.metric" :title="graph.name" /></VCardText></VCard>

    <VCard border>
      <VCardTitle>Commands</VCardTitle>
      <VTable density="comfortable">
        <thead><tr><th>Command</th><th>Device</th><th>Asked by</th><th>Path</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="c in commands" :key="c.id">
            <td class="font-weight-medium">{{ human(c.command) }}<span v-if="c.parameters?.value !== undefined"> → {{ c.parameters.value }}</span>
              <div class="text-caption text-medium-emphasis">{{ c.reason }}<template v-if="c.origin === 'recommendation'"> · from a recommendation</template></div></td>
            <td>{{ c.device }}</td>
            <td>{{ c.requested_by ?? '—' }}<div class="text-caption">{{ at(c.at) }}</div></td>
            <td class="text-caption">{{ c.history.map(h => human(h.status)).join(' → ') }}<div v-if="c.failure_reason" class="text-error">{{ c.failure_reason }}</div></td>
            <td><VChip size="small" label :color="commandColor(c.status)">{{ human(c.status) }}</VChip></td>
            <td class="text-end">
              <template v-if="c.status === 'pending_approval' && can.approve">
                <VBtn size="small" color="success" variant="tonal" class="me-1" @click="decide(c.id, 'approve')">Approve</VBtn>
                <VBtn size="small" variant="text" @click="decide(c.id, 'reject')">Reject</VBtn>
              </template>
            </td>
          </tr>
          <tr v-if="!commands.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No commands sent.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <FormDialog v-model="adding" title="Add a device" :fields="deviceFields" url="/manufacturing/command/devices" />
    <VDialog :model-value="!!target" max-width="520" @update:model-value="v => !v && (target = null)">
      <VCard v-if="target" :title="`Command ${target.name}`">
        <VCardText>
          <VSelect v-model="cmd.command" :items="(target.allowed_commands ?? []).map((a: any) => ({ value: a.command, title: human(a.command) + (a.min !== undefined ? ` (${a.min}–${a.max})` : '') }))" label="Command" />
          <VTextField v-if="(target.allowed_commands ?? []).find((a: any) => a.command === cmd.command)?.max !== undefined" v-model.number="cmd.value" type="number" label="Value" />
          <VTextField v-model="cmd.reason" label="Why" />
          <VAlert v-if="target.commands_need_approval" type="info" variant="tonal" density="compact">This device needs a second person to approve the command before it is sent.</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="target = null">Cancel</VBtn><VBtn color="primary" :disabled="!cmd.command || !cmd.reason" @click="send">Send</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
