<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { money, statusColor } from './money'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; number: string; status: string; product: string; good: number; actual: number; standard: number | null; total: number | null
  material: number; labour: number; machine: number; scrap_rework: number; cost_status: string; frozen: boolean }

const props = defineProps<{
  rows: Row[]
  totals: { actual: number; standard: number; total: number; scrap_rework: number }
  filters: { from: string; to: string; factory: string | null; status: string | null }
  factories: Array<{ id: string; name: string }>
  currency: string
}>()

const f = ref({ ...props.filters })
watch(f, v => router.get(route('manufacturing.costing.variances'), { ...v, factory: v.factory || undefined, status: v.status || undefined }, { preserveState: true, replace: true }), { deep: true })
const sign = (n: number | null) => n == null ? '' : n > 0.005 ? 'text-error' : n < -0.005 ? 'text-success' : ''

const headers = [
  { title: t('manufacturing.orders.number'), key: 'number' },
  { title: t('manufacturing.bom.product'), key: 'product' },
  { title: t('manufacturing.orders.made'), key: 'good', align: 'end' as const },
  { title: t('manufacturing.costing.standard'), key: 'standard', align: 'end' as const },
  { title: t('manufacturing.orders.actual'), key: 'actual', align: 'end' as const },
  { title: t('manufacturing.costing.material'), key: 'material', align: 'end' as const },
  { title: t('manufacturing.costing.labour'), key: 'labour', align: 'end' as const },
  { title: t('manufacturing.costing.machine'), key: 'machine', align: 'end' as const },
  { title: t('manufacturing.costing.kind.TOTAL'), key: 'total', align: 'end' as const },
  { title: t('manufacturing.costing.cost_status'), key: 'cost_status' },
]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.costing.variances_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.costing.variances_subtitle', { currency }) }}</p>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap">
        <VTextField v-model="f.from" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.from')" />
        <VTextField v-model="f.to" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.to')" />
        <VSelect v-if="factories.length > 1" v-model="f.factory" :items="factories" item-value="id" item-title="name" clearable hide-details style="max-width: 240px" :label="t('manufacturing.orders.factory')" />
        <VSelect v-model="f.status" clearable hide-details style="max-width: 200px"
                 :items="['PROVISIONAL', 'FINAL'].map(s => ({ title: t(`manufacturing.costing.status.${s}`), value: s }))" :label="t('manufacturing.costing.cost_status')" />
      </VCardText>
    </VCard>

    <VRow class="mb-1">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.costing.standard') }}</div><div class="text-h5">{{ money(totals.standard) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.orders.actual') }}</div><div class="text-h5">{{ money(totals.actual) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.costing.kind.TOTAL') }}</div><div class="text-h5" :class="sign(totals.total)">{{ money(totals.total) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.costing.kind.SCRAP_REWORK') }}</div><div class="text-h5 text-error">{{ money(totals.scrap_rework) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border>
      <VDataTable :headers="headers" :items="rows" item-value="id" hover :items-per-page="50"
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.production.orders.show', item.id))">
        <template #item.standard="{ item }">{{ money(item.standard) }}</template>
        <template #item.actual="{ item }">{{ money(item.actual) }}</template>
        <template #item.material="{ item }"><span :class="sign(item.material)">{{ money(item.material) }}</span></template>
        <template #item.labour="{ item }"><span :class="sign(item.labour)">{{ money(item.labour) }}</span></template>
        <template #item.machine="{ item }"><span :class="sign(item.machine)">{{ money(item.machine) }}</span></template>
        <template #item.total="{ item }"><span class="font-weight-medium" :class="sign(item.total)">{{ money(item.total) }}</span></template>
        <template #item.cost_status="{ item }"><VChip size="small" label variant="tonal" :color="statusColor(item.cost_status)">{{ t(`manufacturing.costing.status.${item.cost_status}`) }}</VChip></template>
        <template #no-data><div class="py-8 text-medium-emphasis">{{ t('manufacturing.costing.no_orders') }}</div></template>
      </VDataTable>
    </VCard>
  </div>
</template>
