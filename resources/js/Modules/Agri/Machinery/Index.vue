<script setup lang="ts">
import { ref } from 'vue'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import RowMenu from '../Components/RowMenu.vue'
import { human, money, num, statusColor, today } from '../Components/agri'

const props = defineProps<{
  machines: any[]; usage: any[]; services: any[]
  filters: { farm: string | null }
  options: { farms: Array<{ id: string; name: string; status: string }>; types: string[]; service_types: string[]; people: Array<{ id: number; name: string }>; fleet: Array<{ id: number; name: string }> }
  can: { manage: boolean; log: boolean }
}>()
const dialog = ref<null | string>(null)
const target = ref<any>(null)
const open = (k: string, m: any = null) => { target.value = m; dialog.value = k }
const close = (v: boolean) => { if (!v) dialog.value = null }

const machineFields = (create: boolean): FormField[] => [
  ...(create ? [{ key: 'farm_id', label: 'Farm', type: 'select' as const, required: true, items: props.options.farms.filter(f => f.status === 'active').map(f => ({ title: f.name, value: f.id })) },
    { key: 'code', label: 'Code', required: true, cols: 4 }] : []),
  { key: 'name', label: 'Name', required: true, cols: create ? 8 : 12 },
  { key: 'machine_type', label: 'Type', type: 'select', required: true, items: props.options.types, cols: 4 },
  { key: 'make', label: 'Make', cols: 4 }, { key: 'model', label: 'Model', cols: 4 },
  { key: 'year', label: 'Year', type: 'number', cols: 3 }, { key: 'serial_number', label: 'Serial no.', cols: 3 }, { key: 'registration', label: 'Registration', cols: 3 },
  { key: 'fleet_vehicle_id', label: 'Fleet vehicle', type: 'autocomplete', items: props.options.fleet.map(v => ({ title: v.name, value: v.id })), cols: 3, hint: 'If road-registered' },
  ...(create ? [{ key: 'hours_reading', label: 'Hour meter now', type: 'number' as const, cols: 4 }] : []),
  { key: 'service_interval_hours', label: 'Service every (hours)', type: 'number', cols: 4 },
  { key: 'hourly_rate', label: 'Costing rate per hour', type: 'number', cols: 4 },
  ...(create ? [] : [{ key: 'next_service_hours', label: 'Next service at (h)', type: 'number' as const, cols: 4 }, { key: 'next_service_on', label: 'Next service by', type: 'date' as const, cols: 4 }]),
  { key: 'purchase_cost', label: 'Purchase cost', type: 'number', cols: 4 }, { key: 'purchased_on', label: 'Purchased on', type: 'date', cols: 4 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 },
]
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Machinery</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Tractors, harvesters, implements and pumps — hours, fuel, services and costs.</p>
      </div>
      <div class="d-flex gap-2">
        <FarmFilter :farms="options.farms" :model-value="filters.farm" />
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open('create')">Register machine</VBtn>
      </div>
    </div>

    <VCard class="mb-4">
      <VTable density="comfortable">
        <thead><tr><th>Machine</th><th>Farm</th><th class="text-end">Hours</th><th>Next service</th><th class="text-end">Logged</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="m in machines" :key="m.id">
            <td><strong>{{ m.code }}</strong> {{ m.name }}<div class="text-caption">{{ human(m.machine_type) }} · {{ [m.make, m.model, m.year].filter(Boolean).join(' ') }}<template v-if="m.registration"> · {{ m.registration }}</template></div></td>
            <td>{{ m.farm }}</td>
            <td class="text-end">{{ num(m.hours_reading, 1) }} h</td>
            <td>
              <VChip v-if="m.service_due" color="warning" size="small" variant="tonal" prepend-icon="tabler-tool">Due</VChip>
              <span class="text-body-2">{{ m.next_service_hours ? num(m.next_service_hours, 0) + ' h' : '' }} {{ m.next_service_on ?? '' }}</span>
              <span v-if="!m.next_service_hours && !m.next_service_on" class="text-disabled">—</span>
            </td>
            <td class="text-end">{{ num(m.hours_logged, 1) }} h<div class="text-caption">{{ num(m.fuel_litres, 0) }} l fuel</div></td>
            <td><VChip :color="statusColor(m.status)" size="small" variant="tonal">{{ human(m.status) }}</VChip></td>
            <td class="text-end"><RowMenu :actions="[
              { title: 'Log hours / fuel', icon: 'tabler-gas-station', show: can.log && m.status !== 'disposed', onClick: () => open('usage', m) },
              { title: 'Record service / repair', icon: 'tabler-tool', show: can.manage && m.status !== 'disposed', onClick: () => open('service', m) },
              { title: 'Edit', icon: 'tabler-edit', show: can.manage, onClick: () => open('edit', m) },
              { title: 'Dispose of', icon: 'tabler-trash', color: 'error', show: can.manage && m.status !== 'disposed', onClick: () => open('dispose', m) },
            ]" /></td>
          </tr>
          <tr v-if="!machines.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No machines registered.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VRow>
      <VCol cols="12" md="7">
        <VCard title="Recent use">
          <VTable density="compact">
            <thead><tr><th>Date</th><th>Machine</th><th class="text-end">Hours</th><th class="text-end">Meter</th><th class="text-end">Fuel</th><th>Notes</th></tr></thead>
            <tbody>
              <tr v-for="(u, i) in usage" :key="i"><td>{{ u.used_on }}</td><td>{{ u.machine }}</td><td class="text-end">{{ num(u.hours, 1) }}</td><td class="text-end">{{ num(u.reading, 1) }}</td>
                <td class="text-end">{{ u.fuel_litres ? num(u.fuel_litres, 0) + ' l' : '' }}<div v-if="u.fuel_cost" class="text-caption">{{ money(u.fuel_cost) }}</div></td><td class="text-caption">{{ u.notes }}</td></tr>
              <tr v-if="!usage.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No use logged.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard title="Services &amp; repairs">
          <VList v-if="services.length" lines="two" density="compact">
            <VListItem v-for="(s, i) in services" :key="i" :title="`${s.serviced_on} · ${human(s.type)}`" :subtitle="`${s.description}${s.provider ? ' · ' + s.provider : ''}`">
              <template #append>{{ s.cost ? money(s.cost) : '' }}</template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">No services recorded.</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <FormDialog :model-value="dialog === 'create'" title="Register a machine" :fields="machineFields(true)" :url="route('agri.machinery.store')"
      :values="{ farm_id: filters.farm ?? options.farms.find(f => f.status === 'active')?.id, hours_reading: 0 }" @update:model-value="close" />
    <template v-if="target">
      <FormDialog :model-value="dialog === 'edit'" :title="`Edit ${target.code}`" :fields="machineFields(false)" :values="target" method="patch"
        :url="route('agri.machinery.update', target.id)" @update:model-value="close" />
      <FormDialog :model-value="dialog === 'usage'" :title="`Log use: ${target.code}`" :url="route('agri.machinery.usage', target.id)" :values="{ used_on: today(), settled_by: 'bank' }"
        :fields="[
          { key: 'used_on', label: 'Date', type: 'date', required: true, cols: 4 }, { key: 'hours', label: 'Hours worked', type: 'number', cols: 4 },
          { key: 'hours_reading_after', label: 'Or: meter reading now', type: 'number', cols: 4, hint: `Now ${target.hours_reading} h` },
          { key: 'fuel_litres', label: 'Fuel (litres)', type: 'number', cols: 4 }, { key: 'fuel_cost', label: 'Fuel cost', type: 'number', cols: 4, hint: 'Posted to the ledger' },
          { key: 'operator_id', label: 'Operator', type: 'autocomplete', items: options.people.map(p => ({ title: p.name, value: p.id })), cols: 4 },
          { key: 'notes', label: 'Notes', cols: 12 },
        ]" @update:model-value="close" />
      <FormDialog :model-value="dialog === 'service'" :title="`Service: ${target.code}`" :url="route('agri.machinery.services', target.id)" :values="{ serviced_on: today(), service_type: 'service', hours_at_service: target.hours_reading, settled_by: 'payable' }"
        :fields="[
          { key: 'service_type', label: 'Type', type: 'select', required: true, items: options.service_types, cols: 4 },
          { key: 'serviced_on', label: 'Date', type: 'date', required: true, cols: 4 }, { key: 'hours_at_service', label: 'Hour meter', type: 'number', cols: 4 },
          { key: 'description', label: 'Work done', required: true, cols: 12 },
          { key: 'provider', label: 'Workshop / dealer', cols: 6 }, { key: 'cost', label: 'Cost', type: 'number', cols: 6 },
          { key: 'settled_by', label: 'Paid', type: 'select', items: [{ title: 'From the bank', value: 'bank' }, { title: 'Owed to the workshop', value: 'payable' }], cols: 6 },
          { key: 'next_service_on', label: 'Next service by (date)', type: 'date', cols: 6 },
        ]" intro="A service resets the next-due point from the service interval." @update:model-value="close" />
      <FormDialog :model-value="dialog === 'dispose'" :title="`Dispose of ${target.code}`" :url="route('agri.machinery.dispose', target.id)" :values="{ disposed_on: today() }" submit-label="Dispose"
        :fields="[{ key: 'disposed_on', label: 'Date', type: 'date', required: true }, { key: 'value', label: 'Proceeds', type: 'number' }, { key: 'reason', label: 'Reason', required: true, cols: 12 }]"
        intro="The machine's history is kept; it can no longer be used." @update:model-value="close" />
    </template>
  </div>
</template>
