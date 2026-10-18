<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  scorecard: Array<{ supplier_id: number; supplier: string; orders: number; delivered: number; on_time_percent: number | null; late_open: number; inspections: number; failure_percent: number | null; ncrs: number }>
  risks: Array<{ po: string; supplier: string | null; expected: string | null; reasons: string[] }>
  item: null | { id: number; name: string; substitutes: Array<{ id: number; code: string; name: string; ratio: number }>
    prices: Array<{ supplier_id: number; supplier: string; orders: number; last_price: number; last_order: string; last_date: string; year_average: number | null; change_percent: number | null; actual_lead_days: number | null }> }
  materials: Array<{ id: number; name: string }>
  filters: { from: string; to: string; item: number | null }
}>()

const f = ref({ ...props.filters })
watch(f, v => router.get(route('manufacturing.planning.suppliers'), { ...v, item: v.item || undefined }, { preserveState: true, replace: true }), { deep: true })
const pct = (v: number | null) => v === null ? '—' : `${v}%`
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.plan.suppliers_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.plan.suppliers_subtitle') }}</p>
    </div>
    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap">
        <VTextField v-model="f.from" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.from')" />
        <VTextField v-model="f.to" type="date" hide-details style="max-width: 180px" :label="t('manufacturing.floor.to')" />
        <VAutocomplete v-model="f.item" :items="materials" item-value="id" item-title="name" clearable hide-details style="min-width: 320px" :label="t('manufacturing.plan.compare_for')" />
      </VCardText>
    </VCard>

    <VAlert v-if="risks.length" type="warning" variant="tonal" class="mb-4">
      <div class="font-weight-medium mb-1">{{ t('manufacturing.plan.risks_n', { n: risks.length }) }}</div>
      <div v-for="r in risks" :key="r.po" class="text-body-2">{{ r.po }} · {{ r.supplier ?? '—' }} · {{ r.expected ?? '—' }} — {{ r.reasons.join(' ') }}</div>
    </VAlert>

    <VCard v-if="item" border class="mb-4">
      <VCardItem><VCardTitle>{{ item.name }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.plan.prices_hint') }}</VCardSubtitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.quality.supplier') }}</th><th class="text-end">{{ t('manufacturing.plan.last_price') }}</th><th class="text-end">{{ t('manufacturing.plan.year_average') }}</th>
          <th class="text-end">{{ t('manufacturing.plan.change') }}</th><th class="text-end">{{ t('manufacturing.plan.lead_days') }}</th><th>{{ t('manufacturing.plan.last_order') }}</th></tr></thead>
        <tbody>
          <tr v-for="p in item.prices" :key="p.supplier_id">
            <td>{{ p.supplier }}</td><td class="text-end">{{ p.last_price.toFixed(2) }}</td><td class="text-end">{{ p.year_average?.toFixed(2) ?? '—' }}</td>
            <td class="text-end" :class="(p.change_percent ?? 0) > 0 ? 'text-error' : 'text-success'">{{ pct(p.change_percent) }}</td>
            <td class="text-end">{{ p.actual_lead_days ?? '—' }}</td><td>{{ p.last_order }} · {{ p.last_date }}</td>
          </tr>
          <tr v-if="!item.prices.length"><td colspan="6" class="text-center text-medium-emphasis py-4">{{ t('manufacturing.plan.no_purchases') }}</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="item.substitutes.length">
        {{ t('manufacturing.plan.substitutes') }}: <VChip v-for="s in item.substitutes" :key="s.id" size="small" label class="me-1">{{ s.code }} — {{ s.name }} (×{{ s.ratio }})</VChip>
      </VCardText>
    </VCard>

    <VCard border>
      <VCardItem><VCardTitle>{{ t('manufacturing.plan.scorecard') }}</VCardTitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.quality.supplier') }}</th><th class="text-end">{{ t('manufacturing.plan.orders') }}</th><th class="text-end">{{ t('manufacturing.plan.on_time') }}</th>
          <th class="text-end">{{ t('manufacturing.plan.late_open') }}</th><th class="text-end">{{ t('manufacturing.plan.inspections') }}</th><th class="text-end">{{ t('manufacturing.plan.failure_rate') }}</th>
          <th class="text-end">{{ t('manufacturing.plan.ncrs') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in scorecard" :key="s.supplier_id">
            <td>{{ s.supplier }}</td><td class="text-end">{{ s.orders }}</td><td class="text-end">{{ pct(s.on_time_percent) }}</td>
            <td class="text-end" :class="{ 'text-error': s.late_open > 0 }">{{ s.late_open }}</td><td class="text-end">{{ s.inspections }}</td>
            <td class="text-end" :class="{ 'text-error': (s.failure_percent ?? 0) > 10 }">{{ pct(s.failure_percent) }}</td><td class="text-end">{{ s.ncrs }}</td>
          </tr>
          <tr v-if="!scorecard.length"><td colspan="7" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.plan.no_suppliers') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
