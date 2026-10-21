<script setup lang="ts">
/** Where production happens — houses, fields, ponds, tunnels, pens and lines — and what is in each now. */
import { ref } from 'vue'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import { human, num, statusColor } from './prd'

const props = defineProps<{
  units: Array<Record<string, any>>
  kinds: string[]
  options: { types: Array<{ value: string; title: string }>; farms: Array<{ value: string; title: string }>; fields: Array<{ value: string; title: string }> }
  can: { manage: boolean }
}>()
const adding = ref(false)
const fields: FormField[] = [
  { key: 'farm_id', label: 'Farm', type: 'select', items: props.options.farms, required: true, cols: 6 },
  { key: 'kind', label: 'Kind', type: 'select', items: props.kinds, default: 'house', cols: 6 },
  { key: 'code', label: 'Code', required: true, cols: 6 }, { key: 'name', label: 'Name', required: true, cols: 6 },
  { key: 'production_type_id', label: 'Usually runs', type: 'select', items: props.options.types, cols: 6 },
  { key: 'field_id', label: 'Farm field (for a field or orchard)', type: 'select', items: props.options.fields, cols: 6, show: f => ['field', 'orchard'].includes(f.kind) },
  { key: 'capacity', label: 'Capacity', type: 'number', cols: 4 }, { key: 'capacity_unit', label: 'of', hint: 'birds, head, fish, plants', cols: 4 },
  { key: 'area', label: 'Area', type: 'number', cols: 2 }, { key: 'area_unit', label: 'Unit', type: 'select', items: ['m2', 'ha'], default: 'm2', cols: 2 },
  { key: 'biosecurity_zone', label: 'Biosecurity zone', cols: 6 },
]
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Houses, fields &amp; lines</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Each place production happens, its capacity and its sensors.</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="adding = true">Add</VBtn>
    </div>
    <VCard border>
      <VTable density="comfortable">
        <thead><tr><th>Unit</th><th>Farm</th><th>Kind</th><th class="text-end">Capacity</th><th class="text-end">Devices</th><th>Running</th><th>Status</th></tr></thead>
        <tbody>
          <tr v-for="u in units" :key="u.id">
            <td class="font-weight-medium">{{ u.name }}<div class="text-caption text-medium-emphasis">{{ u.code }}<template v-if="u.type"> · {{ u.type }}</template><template v-if="u.zone"> · zone {{ u.zone }}</template></div></td>
            <td>{{ u.farm }}</td><td>{{ human(u.kind) }}</td>
            <td class="text-end">{{ u.capacity ? `${num(u.capacity, 0)} ${u.capacity_unit ?? ''}` : '—' }}<div v-if="u.area" class="text-caption">{{ num(u.area, 1) }} {{ u.area_unit }}</div></td>
            <td class="text-end">{{ u.devices }}</td>
            <td>{{ u.cycle ?? '—' }}</td>
            <td><VChip size="small" label :color="statusColor(u.status)">{{ human(u.status) }}</VChip></td>
          </tr>
          <tr v-if="!units.length"><td colspan="7" class="text-center text-medium-emphasis py-6">None yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <FormDialog v-model="adding" title="Add a house, field or line" :fields="fields" url="/manufacturing/agriculture/units" />
  </div>
</template>
