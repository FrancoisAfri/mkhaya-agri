<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; lot: string; kind: string; item: string; item_name: string; uom: string; supplier: string | null; supplier_lot: string | null; origin: string | null; manufactured_on: string | null; expires_on: string | null; received_on: string | null; days_left: number | null; on_hand: number; available: number; warehouses: number }
const props = defineProps<{ batches: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> }; filters: { q?: string; kind?: string; expiring_within?: string | number; in_stock?: boolean }; not_found?: boolean }>()

const f = reactive({ q: props.filters.q ?? '', kind: props.filters.kind ?? null, expiring_within: props.filters.expiring_within ?? null, in_stock: props.filters.in_stock ?? true })
const apply = () => router.get(route('warehouse.batches.index'), f, { preserveState: true })
const serial = ref('')
const findSerial = () => serial.value.trim() && router.get(route('warehouse.batches.serial'), { q: serial.value.trim() })
const expiryColor = (d: number | null) => d === null ? '' : d < 0 ? 'text-error font-weight-bold' : d <= 30 ? 'text-error' : d <= 90 ? 'text-warning' : ''
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.batches.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.batches.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 align-center">
        <VTextField v-model="serial" density="compact" hide-details prepend-inner-icon="tabler-scan" style="min-width: 240px" :label="t('warehouse_management.batches.find_serial')" @keyup.enter="findSerial" />
        <VBtn variant="tonal" :href="route('warehouse.batches.expiry')">{{ t('warehouse_management.cards.expiry.title') }}</VBtn>
      </div>
    </div>
    <VAlert v-if="not_found" type="warning" variant="tonal" class="mb-3">{{ t('warehouse_management.batches.not_found', { q: filters.q }) }}</VAlert>
    <div class="d-flex gap-2 mb-3 flex-wrap align-center">
      <VTextField v-model="f.q" density="compact" hide-details style="max-width: 260px" :label="t('warehouse_management.batches.search')" @keyup.enter="apply" />
      <VSelect v-model="f.kind" :items="[{ value: 'LOT', title: t('warehouse_management.batches.kind_LOT') }, { value: 'SERIAL', title: t('warehouse_management.batches.kind_SERIAL') }]" clearable density="compact" hide-details style="max-width: 180px" :label="t('warehouse_management.batches.kind')" />
      <VSelect v-model="f.expiring_within" :items="[30, 60, 90, 180].map(n => ({ value: n, title: t('warehouse_management.batches.within', { n }) }))" clearable density="compact" hide-details style="max-width: 200px" :label="t('warehouse_management.batches.expiring')" />
      <VCheckbox v-model="f.in_stock" hide-details density="compact" :label="t('warehouse_management.batches.in_stock')" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>
    <VCard border>
      <VCardText v-if="!batches.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.batches.none') }}</VCardText>
      <div v-else class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.batches.lot') }}</th><th>{{ t('warehouse_management.counts.item') }}</th><th>{{ t('warehouse_management.batches.supplier') }}</th>
            <th>{{ t('warehouse_management.batches.received') }}</th><th>{{ t('warehouse_management.batches.expires') }}</th>
            <th class="text-end">{{ t('warehouse_management.batches.on_hand') }}</th><th class="text-end">{{ t('warehouse_management.batches.available') }}</th></tr></thead>
          <tbody>
            <tr v-for="b in batches.data" :key="b.id" style="cursor: pointer" @click="router.get(route('warehouse.batches.show', b.id))">
              <td class="font-weight-medium">{{ b.lot }}<VChip v-if="b.kind === 'SERIAL'" size="x-small" class="ms-1">{{ t('warehouse_management.batches.kind_SERIAL') }}</VChip></td>
              <td>{{ b.item }} <span class="text-medium-emphasis">{{ b.item_name }}</span></td>
              <td>{{ b.supplier ?? '—' }}<div v-if="b.supplier_lot" class="text-caption">{{ b.supplier_lot }}</div></td>
              <td>{{ b.received_on ?? '—' }}</td>
              <td :class="expiryColor(b.days_left)">{{ b.expires_on ?? '—' }}<div v-if="b.days_left !== null" class="text-caption">{{ b.days_left < 0 ? t('warehouse_management.batches.expired_ago', { n: -b.days_left }) : t('warehouse_management.batches.days_left', { n: b.days_left }) }}</div></td>
              <td class="text-end">{{ b.on_hand }} {{ b.uom }}</td><td class="text-end">{{ b.available }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
    <div class="d-flex gap-1 mt-3 flex-wrap">
      <VBtn v-for="l in batches.links" :key="l.label" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
    </div>
  </div>
</template>
