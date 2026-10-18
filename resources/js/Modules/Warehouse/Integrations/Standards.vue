<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  from: string
  to: string
  coverage: { events: number; identifiers_gs1: number; identifiers_internal: number; items_without_gtin: string[]; warehouses_without_gln: string[]; unmapped_movement_types: string[] }
  conformance: string
  by_type: Record<string, number>
  sample: Array<Record<string, unknown>>
  movements: Record<string, [string, string, string, string | null]>
  dispositions: Record<string, string>
  warehouses: Array<{ id: string; code: string; gln: string | null }>
  can: { manage: boolean }
}>()

const range = reactive({ from: props.from, to: props.to })
const gln = reactive(Object.fromEntries(props.warehouses.map(w => [w.id, w.gln ?? ''])))
const apply = () => router.get(route('warehouse.integrations.standards'), range, { preserveState: true })
const saveGln = (id: string) => router.post(route('warehouse.integrations.standards.gln', id), { gln: gln[id] || null }, { preserveScroll: true })
const share = () => {
  const total = props.coverage.identifiers_gs1 + props.coverage.identifiers_internal
  return total ? Math.round((props.coverage.identifiers_gs1 / total) * 100) : 0
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.standards.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.standards.subtitle') }}</p>
    </div>
    <VAlert type="info" variant="tonal" class="mb-4">{{ conformance }}</VAlert>

    <div class="d-flex gap-2 mb-4 flex-wrap align-center">
      <VTextField v-model="range.from" type="date" density="compact" hide-details style="max-width: 180px" :label="t('warehouse_management.standards.from')" />
      <VTextField v-model="range.to" type="date" density="compact" hide-details style="max-width: 180px" :label="t('warehouse_management.standards.to')" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
      <VBtn variant="tonal" :href="route('warehouse.integrations.standards.epcis', range)">{{ t('warehouse_management.standards.download') }}</VBtn>
    </div>

    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VCard border class="pa-3" min-width="180"><div class="text-caption">{{ t('warehouse_management.standards.events') }}</div><div class="text-h6">{{ coverage.events }}</div></VCard>
      <VCard border class="pa-3" min-width="180"><div class="text-caption">{{ t('warehouse_management.standards.gs1_share') }}</div><div class="text-h6">{{ share() }}%</div>
        <div class="text-caption">{{ t('warehouse_management.standards.ids', { gs1: coverage.identifiers_gs1, internal: coverage.identifiers_internal }) }}</div></VCard>
    </div>

    <VCard border class="mb-4" :title="t('warehouse_management.standards.gaps')">
      <VCardText class="text-body-2">
        <div v-if="!coverage.items_without_gtin.length && !coverage.warehouses_without_gln.length && !coverage.unmapped_movement_types.length">{{ t('warehouse_management.standards.no_gaps') }}</div>
        <div v-if="coverage.items_without_gtin.length">{{ t('warehouse_management.standards.no_gtin') }} {{ coverage.items_without_gtin.slice(0, 40).join(', ') }}<template v-if="coverage.items_without_gtin.length > 40"> …</template></div>
        <div v-if="coverage.warehouses_without_gln.length">{{ t('warehouse_management.standards.no_gln') }} {{ coverage.warehouses_without_gln.join(', ') }}</div>
        <div v-if="coverage.unmapped_movement_types.length">{{ t('warehouse_management.standards.unmapped') }} {{ coverage.unmapped_movement_types.join(', ') }}</div>
      </VCardText>
    </VCard>

    <VCard border class="mb-4" :title="t('warehouse_management.standards.gln_title')">
      <VCardText>
        <VRow v-for="w in warehouses" :key="w.id" dense align="center">
          <VCol cols="3" class="text-body-2">{{ w.code }}</VCol>
          <VCol cols="6"><VTextField v-model="gln[w.id]" density="compact" hide-details :disabled="!can.manage" :label="t('warehouse_management.standards.gln')" /></VCol>
          <VCol cols="3"><VBtn v-if="can.manage" size="small" variant="tonal" @click="saveGln(w.id)">{{ t('warehouse_management.common.save') }}</VBtn></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard border class="mb-4" :title="t('warehouse_management.standards.mapping')">
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead><tr><th>{{ t('warehouse_management.standards.movement') }}</th><th>{{ t('warehouse_management.standards.event') }}</th><th>{{ t('warehouse_management.standards.action') }}</th><th>bizStep</th><th>disposition</th></tr></thead>
          <tbody>
            <tr v-for="(m, k) in movements" :key="k"><td>{{ k }}</td><td>{{ m[0] }}</td><td>{{ m[1] }}</td><td>{{ m[2] }}</td><td>{{ m[3] ?? t('warehouse_management.standards.from_status') }}</td></tr>
            <tr><td>mfg_issue + mfg_receipt</td><td>TransformationEvent</td><td>—</td><td>commissioning</td><td>—</td></tr>
            <tr><td>{{ t('warehouse_management.standards.packed') }}</td><td>AggregationEvent</td><td>ADD</td><td>packing</td><td>container_closed</td></tr>
          </tbody>
        </VTable>
      </div>
      <VCardText class="text-caption">{{ Object.entries(dispositions).map(([k, v]) => `${k} → ${v}`).join(' · ') }}</VCardText>
    </VCard>

    <VCard border :title="t('warehouse_management.standards.sample')">
      <VCardText><div v-for="(n, k) in by_type" :key="k" class="text-body-2">{{ k }}: {{ n }}</div>
        <pre v-if="sample.length" class="text-caption mt-2" style="overflow-x: auto; white-space: pre">{{ JSON.stringify(sample, null, 2) }}</pre></VCardText>
    </VCard>
  </div>
</template>
