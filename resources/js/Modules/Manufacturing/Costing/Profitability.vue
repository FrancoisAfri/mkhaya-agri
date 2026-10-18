<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { money, statusColor } from './money'

const { t } = useI18n()
const route = ziggyRoute

type Row = { key: string; label: string; group: string | null; qty: number; revenue: number; cost: number; margin: number
  margin_percent: number | null; unit_cost: number | null; cost_status: string; basis: string }

const props = defineProps<{
  report: { currency: string; from: string; to: string; rows: Row[]
    totals: { revenue: number; cost: number; margin: number; margin_percent: number | null; status: string }
    excluded: Array<{ invoice: string; currency: string; amount: number }> }
  filters: { dimension: string; from: string; to: string; factory: string | null }
  factories: Array<{ id: string; name: string }>
}>()

const f = ref({ ...props.filters })
watch(f, v => router.get(route('manufacturing.costing.profitability'), { ...v, factory: v.factory || undefined }, { preserveState: true, replace: true }), { deep: true })
const dims = ['product', 'category', 'customer', 'order', 'factory', 'line']

const headers = [
  { title: t('manufacturing.costing.dimension_col'), key: 'label' },
  { title: t('manufacturing.orders.quantity'), key: 'qty', align: 'end' as const },
  { title: t('manufacturing.costing.revenue'), key: 'revenue', align: 'end' as const },
  { title: t('manufacturing.costing.cost'), key: 'cost', align: 'end' as const },
  { title: t('manufacturing.costing.margin'), key: 'margin', align: 'end' as const },
  { title: '%', key: 'margin_percent', align: 'end' as const },
  { title: t('manufacturing.costing.cost_status'), key: 'cost_status' },
]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.costing.profit_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.costing.profit_subtitle', { currency: report.currency }) }}</p>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VBtnToggle v-model="f.dimension" mandatory divided variant="outlined" density="comfortable">
          <VBtn v-for="d in dims" :key="d" :value="d">{{ t(`manufacturing.costing.dimension.${d}`) }}</VBtn>
        </VBtnToggle>
        <VTextField v-model="f.from" type="date" hide-details style="max-width: 170px" :label="t('manufacturing.floor.from')" />
        <VTextField v-model="f.to" type="date" hide-details style="max-width: 170px" :label="t('manufacturing.floor.to')" />
        <VSelect v-if="factories.length > 1" v-model="f.factory" :items="factories" item-value="id" item-title="name" clearable hide-details style="max-width: 240px" :label="t('manufacturing.orders.factory')" />
      </VCardText>
    </VCard>

    <VRow class="mb-1">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.costing.revenue') }}</div><div class="text-h5">{{ money(report.totals.revenue) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.costing.cost') }}</div><div class="text-h5">{{ money(report.totals.cost) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.costing.margin') }}</div><div class="text-h5">{{ money(report.totals.margin) }} <span class="text-body-2" v-if="report.totals.margin_percent !== null">({{ report.totals.margin_percent }}%)</span></div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.costing.cost_status') }}</div>
        <VChip label :color="statusColor(report.totals.status)">{{ t(`manufacturing.costing.status.${report.totals.status}`) }}</VChip></VCardText></VCard></VCol>
    </VRow>

    <VAlert v-if="report.totals.status !== 'FINAL'" type="info" variant="tonal" density="compact" class="mb-3">{{ t('manufacturing.costing.margin_not_final') }}</VAlert>
    <VAlert v-if="report.excluded.length" type="warning" variant="tonal" density="compact" class="mb-3">
      {{ t('manufacturing.costing.excluded', { n: report.excluded.length, currency: report.currency }) }}
      {{ report.excluded.map(e => `${e.invoice} (${e.currency} ${money(e.amount)})`).join(', ') }}
    </VAlert>

    <VCard border>
      <VDataTable :headers="headers" :items="report.rows" item-value="key" :items-per-page="50">
        <template #item.label="{ item }">
          <div>{{ item.label }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.group ? `${item.group} · ` : '' }}{{ item.basis }}</div>
        </template>
        <template #item.revenue="{ item }">{{ money(item.revenue) }}</template>
        <template #item.cost="{ item }">{{ money(item.cost) }}<div v-if="item.unit_cost !== null" class="text-caption text-medium-emphasis">{{ money(item.unit_cost) }} / {{ t('manufacturing.costing.unit') }}</div></template>
        <template #item.margin="{ item }"><span :class="item.margin < 0 ? 'text-error' : ''">{{ money(item.margin) }}</span></template>
        <template #item.margin_percent="{ item }">{{ item.margin_percent ?? '—' }}</template>
        <template #item.cost_status="{ item }"><VChip size="small" label variant="tonal" :color="statusColor(item.cost_status)">{{ t(`manufacturing.costing.status.${item.cost_status}`) }}</VChip></template>
        <template #no-data><div class="py-8 text-medium-emphasis">{{ t('manufacturing.costing.no_sales') }}</div></template>
      </VDataTable>
    </VCard>
  </div>
</template>
