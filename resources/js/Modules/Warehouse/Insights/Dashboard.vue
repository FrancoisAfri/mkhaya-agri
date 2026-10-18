<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  kind: 'inventory' | 'suppliers' | 'protection' | 'executive'
  filters: Record<string, any>
  data: Record<string, any>
  generated_at: string
  warehouses: Array<{ value: number; title: string }>
  available: string[]
  with_values: boolean
}>()

const f = reactive({ ...props.filters })
const apply = () => router.get(route('warehouse.insights.dashboard', props.kind), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== '')), { preserveState: true })
const n = (v: number | null | undefined, digits = 2) => (v === null || v === undefined ? '—' : new Intl.NumberFormat(undefined, { maximumFractionDigits: digits }).format(v))
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${Math.round(v * 1000) / 10}%`)
const max = (rows: Array<Record<string, any>>, k: string) => Math.max(1, ...rows.map(r => Number(r[k]) || 0))
const reportFor: Record<string, string> = { inventory: 'stock_on_hand', suppliers: 'supplier_performance', protection: 'losses', executive: 'excess' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-3 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t(`warehouse_management.insights.${kind}.title`) }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t(`warehouse_management.insights.${kind}.subtitle`) }}</p>
      </div>
      <VSpacer />
      <VBtnToggle :model-value="kind" density="compact" variant="outlined" divided>
        <VBtn v-for="k in available" :key="k" :value="k" :href="route('warehouse.insights.dashboard', k)">{{ t(`warehouse_management.insights.${k}.short`) }}</VBtn>
      </VBtnToggle>
    </div>

    <div class="d-flex gap-2 mb-4 flex-wrap align-center">
      <template v-if="kind !== 'inventory'">
        <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.standards.from')" />
        <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.standards.to')" />
      </template>
      <VSelect v-if="kind === 'inventory'" v-model="f.warehouse_id" :items="warehouses" clearable density="compact" hide-details style="max-width: 200px" :label="t('warehouse_management.insights.warehouse')" />
      <VTextField v-if="kind === 'inventory'" v-model="f.item" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.insights.item')" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
      <VSpacer />
      <span class="text-caption text-medium-emphasis">{{ t('warehouse_management.insights.generated', { at: new Date(generated_at).toLocaleString() }) }}</span>
      <VBtn variant="tonal" size="small" :href="route('warehouse.reports.index', { report: reportFor[kind] })">{{ t('warehouse_management.insights.export') }}</VBtn>
    </div>
    <VAlert v-if="!with_values && kind !== 'executive'" type="info" variant="tonal" density="compact" class="mb-4">{{ t('warehouse_management.insights.no_values') }}</VAlert>

    <!-- WMS-181 inventory -->
    <template v-if="kind === 'inventory'">
      <VRow class="mb-1">
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.on_hand') }}</div><div class="text-h5">{{ n(data.total.qty) }}</div><div v-if="data.total.value !== null" class="text-caption">{{ n(data.total.value) }}</div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.expiring_90') }}</div><div class="text-h5">{{ data.expiry.lots }}</div><div class="text-caption">{{ t('warehouse_management.insights.expired_n', { n: data.expiry.expired }) }}<template v-if="data.expiry.exposure !== null"> · {{ n(data.expiry.exposure) }}</template></div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.tracked') }}</div><div class="text-h5">{{ data.tracked.lots }}</div><div class="text-caption">{{ t('warehouse_management.insights.serials_n', { n: data.tracked.serials }) }}</div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.reserved') }}</div><div class="text-h5">{{ n(data.reservations.reduce((a: number, r: any) => a + r.qty, 0)) }}</div><div class="text-caption">{{ data.reservations.map((r: any) => `${r.source}: ${r.reservations}`).join(' · ') }}</div></VCard></VCol>
      </VRow>
      <VRow>
        <VCol cols="12" md="6">
          <VCard border class="mb-4" :title="t('warehouse_management.insights.by_status')">
            <VCardText><div v-for="s in data.by_status" :key="s.status" class="mb-2">
              <div class="d-flex text-body-2"><span>{{ t(`warehouse_management.stock_state.${s.status}`) }}</span><VSpacer /><span>{{ n(s.qty) }}<template v-if="s.value !== null"> · {{ n(s.value) }}</template></span></div>
              <VProgressLinear :model-value="s.qty / max(data.by_status, 'qty') * 100" :color="s.status === 'AVAILABLE' ? 'success' : 'warning'" rounded height="6" />
            </div><div v-if="!data.by_status.length" class="text-medium-emphasis">{{ t('warehouse_management.insights.none') }}</div></VCardText>
          </VCard>
          <VCard border class="mb-4" :title="t('warehouse_management.insights.ageing')">
            <VCardText><div v-for="a in data.ageing" :key="a.bucket" class="mb-2">
              <div class="d-flex text-body-2"><span>{{ t('warehouse_management.insights.days', { b: a.bucket }) }}</span><VSpacer /><span>{{ n(a.qty) }}<template v-if="a.value !== null"> · {{ n(a.value) }}</template></span></div>
              <VProgressLinear :model-value="a.qty / max(data.ageing, 'qty') * 100" color="primary" rounded height="6" />
            </div></VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" md="6">
          <VCard border class="mb-4" :title="t('warehouse_management.insights.by_warehouse')">
            <VTable density="compact"><tbody><tr v-for="w in data.by_warehouse" :key="w.warehouse"><td>{{ w.warehouse }}</td><td class="text-end">{{ t('warehouse_management.insights.items_n', { n: w.items }) }}</td><td class="text-end">{{ n(w.qty) }}</td><td v-if="with_values" class="text-end">{{ n(w.value) }}</td></tr></tbody></VTable>
          </VCard>
          <VCard border class="mb-4" :title="t('warehouse_management.insights.top_products')">
            <VTable density="compact"><tbody><tr v-for="p in data.top_products" :key="p.item"><td>{{ p.item }} <span class="text-caption">{{ p.name }}</span></td><td class="text-end">{{ n(p.qty) }} {{ p.uom }}</td><td v-if="with_values" class="text-end">{{ n(p.value) }}</td></tr></tbody></VTable>
          </VCard>
          <VCard border :title="t('warehouse_management.insights.soonest_expiry')">
            <VTable density="compact"><tbody><tr v-for="e in data.expiry.soonest" :key="e.lot + e.warehouse"><td>{{ e.item }} · {{ e.lot }}</td><td>{{ e.warehouse }}</td><td class="text-end" :class="{ 'text-error': e.days_left < 0 }">{{ t('warehouse_management.batches.days_left', { n: e.days_left }) }}</td><td class="text-end">{{ n(e.qty) }}</td></tr></tbody></VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- WMS-182 suppliers -->
    <template v-else-if="kind === 'suppliers'">
      <VRow class="mb-1">
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.deliveries') }}</div><div class="text-h5">{{ data.overall.deliveries }}</div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.on_time') }}</div><div class="text-h5">{{ pct(data.overall.on_time_rate) }}</div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.qty_accuracy') }}</div><div class="text-h5">{{ pct(data.overall.quantity_accuracy) }}</div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.to_buy') }}</div><div class="text-h5">{{ data.replenishment.items }}</div><div v-if="data.replenishment.value !== null" class="text-caption">{{ n(data.replenishment.value) }}</div></VCard></VCol>
      </VRow>
      <VCard border class="mb-4" :title="t('warehouse_management.insights.scorecards')">
        <div style="overflow-x: auto"><VTable density="compact">
          <thead><tr><th>{{ t('warehouse_management.insights.supplier') }}</th><th class="text-end">{{ t('warehouse_management.insights.deliveries') }}</th><th class="text-end">{{ t('warehouse_management.insights.on_time') }}</th><th class="text-end">{{ t('warehouse_management.insights.qty_accuracy') }}</th><th class="text-end">{{ t('warehouse_management.insights.damage') }}</th><th class="text-end">{{ t('warehouse_management.insights.lead') }}</th></tr></thead>
          <tbody><tr v-if="!data.scores.length"><td colspan="6" class="text-center text-medium-emphasis">{{ t('warehouse_management.insights.none') }}</td></tr>
            <tr v-for="s in data.scores" :key="s.supplier_id"><td>{{ s.supplier }}</td><td class="text-end">{{ s.deliveries }}</td><td class="text-end" :class="{ 'text-error': s.on_time_rate !== null && s.on_time_rate < 0.8 }">{{ pct(s.on_time_rate) }}</td><td class="text-end">{{ pct(s.quantity_accuracy) }}</td><td class="text-end">{{ pct(s.damage_rate) }}</td><td class="text-end">{{ n(s.avg_lead_time_days, 1) }}</td></tr></tbody>
        </VTable></div>
      </VCard>
      <VRow>
        <VCol cols="12" md="6"><VCard border :title="t('warehouse_management.insights.overdue')">
          <VTable density="compact"><tbody><tr v-if="!data.overdue.length"><td class="text-medium-emphasis">{{ t('warehouse_management.insights.none') }}</td></tr>
            <tr v-for="o in data.overdue" :key="o.po"><td>{{ o.po }}</td><td>{{ o.supplier }}</td><td class="text-end text-error">{{ t('warehouse_management.insights.days_late', { n: o.days_late }) }}</td></tr></tbody></VTable></VCard></VCol>
        <VCol cols="12" md="6"><VCard border class="mb-4" :title="t('warehouse_management.insights.recurring')">
          <VTable density="compact"><tbody><tr v-if="!data.recurring_discrepancies.length"><td class="text-medium-emphasis">{{ t('warehouse_management.insights.none') }}</td></tr>
            <tr v-for="(r, i) in data.recurring_discrepancies" :key="i"><td>{{ r.supplier }}</td><td>{{ t(`warehouse_management.discrepancy_kind.${r.kind}`) }}</td><td class="text-end">×{{ r.times }}</td></tr></tbody></VTable></VCard>
          <VCard border :title="t('warehouse_management.insights.most_urgent')">
            <VTable density="compact"><tbody><tr v-for="r in data.replenishment.most_urgent" :key="r.item"><td>{{ r.item }}</td><td>{{ r.supplier ?? '—' }}</td><td class="text-end">{{ n(r.qty) }}</td><td class="text-end">{{ r.days_of_cover === null ? '—' : t('warehouse_management.insights.cover_days', { n: r.days_of_cover }) }}</td></tr></tbody></VTable></VCard></VCol>
      </VRow>
    </template>

    <!-- WMS-183 profit protection -->
    <template v-else-if="kind === 'protection'">
      <VRow class="mb-1">
        <VCol v-for="k in ['potential_loss', 'confirmed_loss', 'unresolved_variance', 'recovered']" :key="k" cols="6" md="3">
          <VCard border class="pa-3"><div class="text-caption">{{ t(`warehouse_management.insights.impact.${k}`) }}</div><div class="text-h5">{{ n(data.impact[k]) }}</div></VCard>
        </VCol>
      </VRow>
      <VRow>
        <VCol cols="12" md="6"><VCard border class="mb-4" :title="t('warehouse_management.insights.investigation_age')">
          <VCardText><div v-for="a in data.ageing" :key="a.bucket" class="mb-2"><div class="d-flex text-body-2"><span>{{ t('warehouse_management.insights.days', { b: a.bucket }) }}</span><VSpacer /><span>{{ a.open }}</span></div>
            <VProgressLinear :model-value="a.open / max(data.ageing, 'open') * 100" :color="a.bucket === '90+' ? 'error' : 'warning'" rounded height="6" /></div></VCardText></VCard>
          <VCard border :title="t('warehouse_management.insights.open_by_priority')"><VCardText>
            <VChip v-for="(c, p) in data.by_priority" :key="p" class="me-2 mb-2" :color="p === 'URGENT' ? 'error' : p === 'HIGH' ? 'warning' : undefined">{{ p }} · {{ c }}</VChip>
            <div v-if="!Object.keys(data.by_priority).length" class="text-medium-emphasis">{{ t('warehouse_management.insights.none') }}</div></VCardText></VCard></VCol>
        <VCol cols="12" md="6"><VCard border :title="t('warehouse_management.insights.effectiveness')">
          <VTable density="compact"><tbody>
            <tr v-for="(v, k) in data.effectiveness" :key="k"><td>{{ t(`warehouse_management.insights.eff.${k}`) }}</td><td class="text-end">{{ v === null ? '—' : v }}</td></tr>
            <tr v-if="data.impact.avoided_cost !== null"><td>{{ t('warehouse_management.insights.impact.avoided_cost') }}</td><td class="text-end">{{ n(data.impact.avoided_cost) }}</td></tr>
          </tbody></VTable></VCard></VCol>
      </VRow>
    </template>

    <!-- WMS-184 executive -->
    <template v-else>
      <VRow class="mb-1">
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.inventory_value') }}</div><div class="text-h5">{{ n(data.inventory_value) }}</div><div v-if="data.carrying_cost !== null" class="text-caption">{{ t('warehouse_management.insights.carrying', { v: n(data.carrying_cost) }) }}</div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.excess_value') }}</div><div class="text-h5">{{ n(data.excess.value) }}</div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.write_offs') }}</div><div class="text-h5">{{ n(data.write_offs.confirmed) }}</div><div class="text-caption">{{ t('warehouse_management.insights.recovered_v', { v: n(data.write_offs.recovered) }) }}</div></VCard></VCol>
        <VCol cols="6" md="3"><VCard border class="pa-3"><div class="text-caption">{{ t('warehouse_management.insights.stockout_exposure') }}</div><div class="text-h5" :class="{ 'text-error': data.stockout_exposure.value > 0 }">{{ n(data.stockout_exposure.value) }}</div><div class="text-caption">{{ t('warehouse_management.insights.items_n', { n: data.stockout_exposure.items }) }}</div></VCard></VCol>
      </VRow>
      <VRow>
        <VCol cols="12" md="6"><VCard border :title="t('warehouse_management.insights.service')"><VTable density="compact"><tbody>
          <tr><td>{{ t('warehouse_management.insights.dispatched') }}</td><td class="text-end">{{ data.service.dispatched }}</td></tr>
          <tr><td>{{ t('warehouse_management.insights.on_time') }}</td><td class="text-end">{{ data.service.on_time_rate === null ? '—' : data.service.on_time_rate + '%' }}</td></tr>
          <tr><td>{{ t('warehouse_management.insights.count_accuracy') }}</td><td class="text-end">{{ data.count_accuracy === null ? '—' : data.count_accuracy + '%' }}</td></tr>
          <tr><td>{{ t('warehouse_management.insights.carrier_cost') }}</td><td class="text-end">{{ n(data.fulfilment_cost.carrier_quoted) }}</td></tr>
          <tr><td>{{ t('warehouse_management.insights.labour_cost') }}</td><td class="text-end text-medium-emphasis">{{ t('warehouse_management.insights.not_recorded') }}</td></tr>
        </tbody></VTable></VCard></VCol>
        <VCol cols="12" md="6"><VCard border :title="t('warehouse_management.insights.stockout_top')"><VTable density="compact"><tbody>
          <tr v-if="!data.stockout_exposure.top.length"><td class="text-medium-emphasis">{{ t('warehouse_management.insights.none') }}</td></tr>
          <tr v-for="s in data.stockout_exposure.top" :key="s.item"><td>{{ s.item }}</td><td class="text-end">{{ t('warehouse_management.insights.days_short', { n: s.days_short }) }}</td><td class="text-end">{{ n(s.value) }}</td></tr>
        </tbody></VTable></VCard></VCol>
      </VRow>
    </template>
  </div>
</template>
