<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Month = { month: string; forecast: number; orders: number; production: number; projected: number; uncovered_forecast: number }

const props = defineProps<{
  products: Array<{ id: string; code: string | null; name: string | null; on_hand: number; months: Month[]; short_in: string | null }>
  status: Record<string, number>
  orders: Array<{ rank: number; id: string; number: string; status: string; priority: number; product: string; due_date: string | null; is_overdue: boolean; demand_type: string | null; remaining_minutes: number }>
  capacity: Array<{ id: number; code: string; name: string; bottleneck: boolean; weeks: Array<{ week: string; load: number; available: number; percent: number | null; overloaded: boolean }> }>
  rule: string
  rules: string[]
  forecasts: Array<{ id: string; item: string; factory: string | null; period: string; qty: number; status: string; notes: string | null }>
  options: null | { products: Array<{ id: number; name: string }>; factories: Array<{ id: number; name: string }> }
  can: { manage: boolean }
}>()

const tab = ref('products')
const setRule = (r: string) => router.get(route('manufacturing.planning.demand'), { rule: r }, { preserveState: true, preserveScroll: true })
const forecast = useForm({ stock_item_id: null as number | null, factory_id: null as number | null, period: new Date().toISOString().slice(0, 7), qty: null as number | null, notes: '' })
const forecastDialog = ref(false)
const saveForecast = () => forecast.post(route('manufacturing.planning.forecasts.store'), { preserveScroll: true, onSuccess: () => { forecastDialog.value = false; forecast.reset() } })
const approveForecast = (id: string) => router.post(route('manufacturing.planning.forecasts.approve', id), {}, { preserveScroll: true })
const removeForecast = (id: string) => router.delete(route('manufacturing.planning.forecasts.destroy', id), { preserveScroll: true })
const loadColor = (p: number | null) => p === null ? 'grey' : p > 100 ? 'error' : p > 85 ? 'warning' : 'success'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.plan.demand_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.plan.demand_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-plus" @click="forecastDialog = true">{{ t('manufacturing.plan.add_forecast') }}</VBtn>
        <VBtn color="primary" :href="route('manufacturing.planning.mrp')">{{ t('manufacturing.plan.mrp_title') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-1">
      <VCol v-for="k in ['planned', 'confirmed', 'released', 'completed', 'overdue', 'on_hold']" :key="k" cols="6" md="2">
        <VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t(`manufacturing.plan.prod_status.${k}`) }}</div>
          <div class="text-h5" :class="{ 'text-error': k === 'overdue' && status[k] > 0 }">{{ status[k] }}</div></VCardText></VCard>
      </VCol>
    </VRow>

    <VCard border>
      <VTabs v-model="tab">
        <VTab value="products">{{ t('manufacturing.plan.tab.products') }}</VTab>
        <VTab value="priority">{{ t('manufacturing.plan.tab.priority') }}</VTab>
        <VTab value="capacity">{{ t('manufacturing.plan.tab.capacity') }}</VTab>
        <VTab value="forecasts">{{ t('manufacturing.plan.tab.forecasts') }}</VTab>
      </VTabs>
      <VDivider />
      <VWindow v-model="tab">
        <VWindowItem value="products">
          <div class="overflow-x-auto">
            <VTable density="compact">
              <thead><tr><th>{{ t('manufacturing.bom.product') }}</th><th class="text-end">{{ t('manufacturing.plan.on_hand') }}</th>
                <th v-for="m in products[0]?.months ?? []" :key="m.month" class="text-center">{{ m.month }}</th></tr></thead>
              <tbody>
                <tr v-for="p in products" :key="p.id">
                  <td>{{ p.code }}<div class="text-caption text-medium-emphasis">{{ p.name }}</div>
                    <VChip v-if="p.short_in" size="x-small" color="error" label>{{ t('manufacturing.plan.short_in', { m: p.short_in }) }}</VChip></td>
                  <td class="text-end">{{ p.on_hand }}</td>
                  <td v-for="m in p.months" :key="m.month" class="text-caption text-no-wrap">
                    <div>{{ t('manufacturing.plan.f_short') }} {{ m.forecast }} · {{ t('manufacturing.plan.o_short') }} {{ m.orders }}</div>
                    <div>{{ t('manufacturing.plan.p_short') }} {{ m.production }}</div>
                    <div :class="m.projected < 0 ? 'text-error font-weight-medium' : ''">= {{ m.projected }}</div>
                  </td>
                </tr>
                <tr v-if="!products.length"><td colspan="8" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.plan.no_demand') }}</td></tr>
              </tbody>
            </VTable>
          </div>
          <VCardText class="text-caption text-medium-emphasis">{{ t('manufacturing.plan.products_legend') }}</VCardText>
        </VWindowItem>

        <VWindowItem value="priority">
          <VCardText><VBtnToggle :model-value="rule" mandatory divided variant="outlined" density="comfortable" @update:model-value="setRule">
            <VBtn v-for="r in rules" :key="r" :value="r">{{ t(`manufacturing.plan.rule.${r}`) }}</VBtn></VBtnToggle></VCardText>
          <VTable density="compact">
            <thead><tr><th>#</th><th>{{ t('manufacturing.orders.number') }}</th><th>{{ t('manufacturing.bom.product') }}</th><th>{{ t('manufacturing.orders.due') }}</th>
              <th>{{ t('manufacturing.orders.priority') }}</th><th class="text-end">{{ t('manufacturing.plan.remaining_min') }}</th><th>{{ t('manufacturing.bom.status') }}</th></tr></thead>
            <tbody>
              <tr v-for="o in orders" :key="o.id">
                <td>{{ o.rank }}</td><td><a :href="route('manufacturing.production.orders.show', o.id)">{{ o.number }}</a></td><td>{{ o.product }}</td>
                <td :class="{ 'text-error': o.is_overdue }">{{ o.due_date }}</td><td>{{ t(`manufacturing.orders.priority_${o.priority}`) }}</td>
                <td class="text-end">{{ o.remaining_minutes }}</td><td>{{ t(`manufacturing.order_status.${o.status}`) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="capacity">
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.floor.work_centre') }}</th><th v-for="w in capacity[0]?.weeks ?? []" :key="w.week" class="text-center">{{ w.week.slice(5) }}</th></tr></thead>
            <tbody>
              <tr v-for="c in capacity" :key="c.id">
                <td>{{ c.code }} <VChip v-if="c.bottleneck" size="x-small" color="error" label>{{ t('manufacturing.plan.bottleneck') }}</VChip></td>
                <td v-for="w in c.weeks" :key="w.week" class="text-center">
                  <VChip size="small" label variant="tonal" :color="loadColor(w.percent)">{{ w.percent === null ? (w.load ? t('manufacturing.plan.no_shift') : '—') : `${w.percent}%` }}</VChip>
                  <div class="text-caption text-medium-emphasis">{{ w.load }} / {{ w.available }}</div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <VWindowItem value="forecasts">
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.plan.period') }}</th><th>{{ t('manufacturing.bom.product') }}</th><th>{{ t('manufacturing.orders.factory') }}</th>
              <th class="text-end">{{ t('manufacturing.orders.quantity') }}</th><th>{{ t('manufacturing.bom.status') }}</th><th v-if="can.manage" /></tr></thead>
            <tbody>
              <tr v-for="f in forecasts" :key="f.id">
                <td>{{ f.period }}</td><td>{{ f.item }}</td><td>{{ f.factory ?? '—' }}</td><td class="text-end">{{ f.qty }}</td>
                <td><VChip size="x-small" label :color="f.status === 'APPROVED' ? 'success' : 'warning'">{{ t(`manufacturing.plan.forecast_status.${f.status}`) }}</VChip></td>
                <td v-if="can.manage" class="text-end text-no-wrap">
                  <VBtn v-if="f.status === 'DRAFT'" size="x-small" variant="tonal" color="success" @click="approveForecast(f.id)">{{ t('manufacturing.common.approve') }}</VBtn>
                  <VBtn size="x-small" variant="text" color="error" @click="removeForecast(f.id)">{{ t('manufacturing.common.remove') }}</VBtn>
                </td>
              </tr>
              <tr v-if="!forecasts.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.plan.no_forecasts') }}</td></tr>
            </tbody>
          </VTable>
          <VCardText class="text-caption text-medium-emphasis">{{ t('manufacturing.plan.forecast_note') }}</VCardText>
        </VWindowItem>
      </VWindow>
    </VCard>

    <VDialog v-if="options" v-model="forecastDialog" max-width="480">
      <VCard :title="t('manufacturing.plan.add_forecast')">
        <VCardText>
          <VAutocomplete v-model="forecast.stock_item_id" :items="options.products" item-value="id" item-title="name" :error-messages="forecast.errors.stock_item_id" :label="t('manufacturing.bom.product')" class="mb-2" />
          <VSelect v-model="forecast.factory_id" :items="options.factories" item-value="id" item-title="name" clearable :label="t('manufacturing.orders.factory')" class="mb-2" />
          <VTextField v-model="forecast.period" type="month" :error-messages="forecast.errors.period" :label="t('manufacturing.plan.period')" class="mb-2" />
          <VTextField v-model.number="forecast.qty" type="number" min="0" step="any" :error-messages="forecast.errors.qty" :label="t('manufacturing.orders.quantity')" class="mb-2" />
          <VTextField v-model="forecast.notes" :label="t('manufacturing.orders.notes')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="forecastDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="forecast.processing" @click="saveForecast">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
