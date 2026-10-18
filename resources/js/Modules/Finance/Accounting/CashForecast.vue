<script lang="ts" setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Week { week: number; start: string; end: string; inflow: number; outflow: number; net: number; balance: number }
interface Summary { total_in: number; total_out: number; closing: number; lowest: { balance: number; week_start: string }; goes_negative: boolean; horizon_weeks: number }
const props = defineProps<{ forecast: { opening: number; weeks: Week[]; summary: Summary }; weeks: number }>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const setHorizon = (w: number) => router.get(route('finance.accounting.cash-forecast'), { weeks: w }, { preserveState: false })

// Bar scaling for the projected-balance column.
const maxAbs = computed(() => Math.max(1, ...props.forecast.weeks.map(w => Math.abs(w.balance)), Math.abs(props.forecast.opening)))
const barPct = (v: number) => Math.round((Math.abs(v) / maxAbs.value) * 100)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_cash') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.cash_flow_forecast') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.cash_forecast_sub') }}</div>
      </div>
      <VBtnToggle :model-value="weeks" mandatory divided density="comfortable" color="primary" @update:model-value="setHorizon">
        <VBtn :value="13" size="small">13 {{ t('acc.wk') }}</VBtn>
        <VBtn :value="26" size="small">26 {{ t('acc.wk') }}</VBtn>
        <VBtn :value="52" size="small">52 {{ t('acc.wk') }}</VBtn>
      </VBtnToggle>
    </div>

    <VAlert v-if="forecast.summary.goes_negative" type="warning" variant="tonal" density="comfortable" class="mb-4" prepend-icon="tabler-alert-triangle">
      {{ t('acc.cash_negative_1') }} <strong>{{ money(forecast.summary.lowest.balance) }}</strong> {{ t('acc.cash_negative_2', { week: forecast.summary.lowest.week_start }) }}
    </VAlert>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.opening_cash') }}</div><div class="text-h6">{{ money(forecast.opening) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.expected_in') }}</div><div class="text-h6 text-success">{{ money(forecast.summary.total_in) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.expected_out') }}</div><div class="text-h6 text-error">{{ money(forecast.summary.total_out) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.projected_closing') }}</div><div class="text-h6" :class="forecast.summary.closing < 0 ? 'text-error' : ''">{{ money(forecast.summary.closing) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('acc.week') }}</th><th>{{ t('acc.dates') }}</th>
            <th class="text-right">{{ t('acc.in_col') }}</th><th class="text-right">{{ t('acc.out_col') }}</th><th class="text-right">{{ t('acc.net_col') }}</th>
            <th class="text-right">{{ t('acc.projected_balance') }}</th><th style="width: 160px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="w in forecast.weeks" :key="w.week">
            <td class="text-caption">{{ w.week }}</td>
            <td class="text-caption text-medium-emphasis">{{ w.start }} → {{ w.end }}</td>
            <td class="text-right text-success">{{ w.inflow ? money(w.inflow) : '—' }}</td>
            <td class="text-right text-error">{{ w.outflow ? money(w.outflow) : '—' }}</td>
            <td class="text-right" :class="w.net < 0 ? 'text-error' : (w.net > 0 ? 'text-success' : 'text-medium-emphasis')">{{ w.net ? money(w.net) : '—' }}</td>
            <td class="text-right font-weight-medium" :class="w.balance < 0 ? 'text-error' : ''">{{ money(w.balance) }}</td>
            <td>
              <div class="d-flex align-center" style="height: 14px">
                <div :style="{ width: barPct(w.balance) + '%' }" :class="w.balance < 0 ? 'bg-error' : 'bg-primary'" style="height: 8px; border-radius: 4px; opacity: 0.75"></div>
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
