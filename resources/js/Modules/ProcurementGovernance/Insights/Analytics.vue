<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import KpiCard from '../Partials/KpiCard.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import { money } from '../Partials/format'

const { t } = useI18n()

/**
 * Cross-module analytics: the governance picture, as opposed to the finance one on Reports.
 *
 * Every rate on this page is rendered with the count it came from. A single-bid rate of 40% means
 * something entirely different over five tenders than over five hundred, and a percentage shown on
 * its own invites the reader to supply their own denominator — usually a flattering one.
 */
const props = defineProps<{
  noCompany?: boolean
  financialYear?: string
  quarter?: string
  period?: { from: string; to: string }
  financialYears?: string[]
  headlines?: any
  pipeline?: any
  competition?: any
  intake?: any | null
  deviations?: any
  approvals?: any
  awards?: any
  trend?: any[]
}>()

/** An em dash, not a zero. "We measured nothing" and "we measured zero" are different findings. */
function pct(value: number | null | undefined) {
  return value === null || value === undefined ? '—' : `${value}%`
}

function num(value: number | null | undefined) {
  return value === null || value === undefined ? '—' : String(value)
}

function days(value: number | null | undefined) {
  return value === null || value === undefined ? '—' : t('procurement_governance.reports.days', { n: value })
}

function reload(params: Record<string, string>) {
  router.get('/procurement-governance/analytics',
    { financial_year: props.financialYear, quarter: props.quarter, ...params },
    { preserveState: true, preserveScroll: true })
}

/**
 * The trend chart is drawn as scaled bars rather than pulled in as a charting library.
 *
 * The page ships no external dependency for four series of at most thirty-six points, and a bar
 * whose height is a percentage is readable at any width — which a fixed-size canvas is not.
 */
const trendMax = computed(() => Math.max(1, ...(props.trend ?? []).map(m => m.records_opened)))

const funnel = computed(() => {
  const p = props.pipeline ?? {}
  return [
    { key: 'plan_items', value: p.plan_items ?? 0 },
    { key: 'records_opened', value: p.records_opened ?? 0 },
    { key: 'tenders', value: p.tenders ?? 0 },
    { key: 'tenders_awarded', value: p.tenders_awarded ?? 0 },
    { key: 'records_closed', value: p.records_closed ?? 0 },
  ]
})

const funnelMax = computed(() => Math.max(1, ...funnel.value.map(s => s.value)))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.analytics.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('procurement_governance.analytics.subtitle') }}
          <template v-if="period"> · {{ period.from }} → {{ period.to }}</template>
        </p>
      </div>

      <VSelect
        v-if="!noCompany"
        :model-value="financialYear" :items="financialYears ?? []"
        :label="t('procurement_governance.planning.financial_year')"
        variant="outlined" density="compact" hide-details style="max-width: 200px"
        @update:model-value="v => reload({ financial_year: v })"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!-- The four numbers that should make somebody act today. -->
      <VRow dense class="mb-2">
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.analytics.single_bid')"
            :value="pct(headlines?.single_bid_pct)"
            :hint="t('procurement_governance.analytics.of_n_tenders', { n: headlines?.single_bid_of ?? 0 })"
            icon="mdi-account-alert-outline"
            :tone="(headlines?.single_bid_pct ?? 0) >= 30 ? 'warning' : 'default'"
          />
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.analytics.deviation_rate')"
            :value="pct(headlines?.deviation_rate_pct)"
            :hint="t('procurement_governance.analytics.of_procurements', { n: deviations?.records_opened ?? 0 })"
            icon="mdi-directions-fork"
            :tone="(headlines?.deviation_rate_pct ?? 0) >= 20 ? 'warning' : 'default'"
          />
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.analytics.overdue_approvals')"
            :value="num(headlines?.overdue_approvals)"
            :hint="t('procurement_governance.analytics.past_sla')"
            icon="mdi-timer-alert-outline"
            :tone="(headlines?.overdue_approvals ?? 0) > 0 ? 'error' : 'default'"
          />
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.analytics.approval_speed')"
            :value="days(headlines?.median_approval_days)"
            :hint="t('procurement_governance.analytics.median_hint')"
            icon="mdi-speedometer"
          />
        </VCol>
      </VRow>

      <VRow dense>
        <!-- ── Funnel ────────────────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.analytics.pipeline') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <p class="text-caption text-medium-emphasis">
                {{ t('procurement_governance.analytics.pipeline_hint') }}
              </p>

              <div v-for="stage in funnel" :key="stage.key" class="mb-3">
                <div class="d-flex justify-space-between text-body-2 mb-1">
                  <span>{{ t(`procurement_governance.analytics.funnel.${stage.key}`) }}</span>
                  <span class="font-weight-bold">{{ stage.value }}</span>
                </div>
                <VProgressLinear
                  :model-value="(stage.value / funnelMax) * 100"
                  color="primary" height="8" rounded
                />
              </div>

              <VDivider class="my-3" />
              <div class="d-flex justify-space-between text-body-2">
                <span class="text-medium-emphasis">{{ t('procurement_governance.analytics.plan_to_market') }}</span>
                <span class="font-weight-bold">{{ pct(pipeline?.plan_to_market_pct) }}</span>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ── Competition ───────────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.analytics.competition') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <p class="text-caption text-medium-emphasis">
                {{ t('procurement_governance.analytics.competition_hint') }}
              </p>

              <VTable density="compact" class="text-body-2">
                <tbody>
                  <tr>
                    <td>{{ t('procurement_governance.analytics.median_bidders') }}</td>
                    <td class="text-end font-weight-bold">{{ num(competition?.median_bidders) }}</td>
                  </tr>
                  <tr>
                    <td>{{ t('procurement_governance.analytics.average_bidders') }}</td>
                    <td class="text-end">{{ num(competition?.average_bidders) }}</td>
                  </tr>
                  <tr>
                    <td>{{ t('procurement_governance.analytics.single_bid_tenders') }}</td>
                    <td class="text-end">{{ num(competition?.single_bid_tenders) }}</td>
                  </tr>
                  <tr>
                    <td>{{ t('procurement_governance.analytics.no_bid_tenders') }}</td>
                    <td class="text-end">{{ num(competition?.tenders_with_no_bids) }}</td>
                  </tr>
                  <tr>
                    <td>{{ t('procurement_governance.analytics.disqualified') }}</td>
                    <td class="text-end">
                      {{ num(competition?.disqualified) }}
                      <span class="text-medium-emphasis">({{ pct(competition?.disqualified_pct) }})</span>
                    </td>
                  </tr>
                  <tr>
                    <td>{{ t('procurement_governance.analytics.late_bids') }}</td>
                    <td class="text-end">{{ num(competition?.late_bids) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ── Where approvals stall ─────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.analytics.bottlenecks') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <p class="text-caption text-medium-emphasis">
                {{ t('procurement_governance.analytics.bottlenecks_hint') }}
              </p>

              <div
                v-if="!approvals?.slowest_steps?.length"
                class="text-center py-6 text-medium-emphasis text-body-2"
              >
                {{ t('procurement_governance.analytics.no_decisions') }}
              </div>

              <VTable v-else density="compact" class="text-body-2">
                <thead>
                  <tr>
                    <th scope="col">{{ t('procurement_governance.settings.step_name') }}</th>
                    <th scope="col" class="text-end">{{ t('procurement_governance.analytics.decisions') }}</th>
                    <th scope="col" class="text-end">{{ t('procurement_governance.analytics.median_days') }}</th>
                    <th scope="col" class="text-end">{{ t('procurement_governance.analytics.breached') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="step in approvals.slowest_steps" :key="step.step">
                    <td>{{ step.step }}</td>
                    <td class="text-end text-medium-emphasis">{{ step.decisions }}</td>
                    <td class="text-end font-weight-bold">{{ num(step.median_days) }}</td>
                    <td class="text-end">
                      <span :class="step.breached > 0 ? 'text-error font-weight-bold' : 'text-medium-emphasis'">
                        {{ step.breached }}
                      </span>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ── Deviations ────────────────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.analytics.deviations') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span class="text-medium-emphasis">{{ t('procurement_governance.analytics.deviation_turnaround') }}</span>
                <span class="font-weight-bold">{{ days(deviations?.median_turnaround_days) }}</span>
              </div>
              <div class="d-flex justify-space-between text-body-2 mb-3">
                <span class="text-medium-emphasis">{{ t('procurement_governance.analytics.high_risk') }}</span>
                <span class="font-weight-bold">{{ num(deviations?.high_risk) }}</span>
              </div>

              <div
                v-if="!deviations?.by_type?.length"
                class="text-center py-4 text-medium-emphasis text-body-2"
              >
                {{ t('procurement_governance.analytics.no_deviations') }}
              </div>

              <VTable v-else density="compact" class="text-body-2">
                <tbody>
                  <tr v-for="row in deviations.by_type" :key="row.code">
                    <td>{{ row.code }}</td>
                    <td class="text-end">{{ row.count }}</td>
                    <td class="text-end text-medium-emphasis">{{ money(row.value) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ── Electronic intake ─────────────────────────────────────────────────────────────── -->
        <VCol v-if="intake" cols="12" md="6">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.analytics.intake') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <VRow dense>
                <VCol cols="6">
                  <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.analytics.invited') }}</div>
                  <div class="text-h6 font-weight-bold">{{ intake.invitations }}</div>
                </VCol>
                <VCol cols="6">
                  <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.analytics.submitted') }}</div>
                  <div class="text-h6 font-weight-bold">{{ intake.submissions }}</div>
                </VCol>
                <VCol cols="6">
                  <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.analytics.conversion') }}</div>
                  <div class="text-h6 font-weight-bold">{{ pct(intake.conversion_pct) }}</div>
                </VCol>
                <VCol cols="6">
                  <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.analytics.late_rate') }}</div>
                  <div class="text-h6 font-weight-bold">{{ pct(intake.late_pct) }}</div>
                </VCol>
              </VRow>

              <VAlert
                v-if="intake.awaiting_opening" type="info" variant="tonal" density="compact"
                class="mt-3 text-body-2"
              >
                {{ t('procurement_governance.analytics.awaiting_opening', { n: intake.awaiting_opening }) }}
              </VAlert>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ── Awards ────────────────────────────────────────────────────────────────────────── -->
        <VCol cols="12" :md="intake ? 6 : 12">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.analytics.awards') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <p class="text-caption text-medium-emphasis">
                {{ t('procurement_governance.analytics.awards_hint', { n: awards?.awarded ?? 0 }) }}
              </p>

              <div
                v-if="!awards?.by_preference_level?.length"
                class="text-center py-4 text-medium-emphasis text-body-2"
              >
                {{ t('procurement_governance.analytics.no_awards') }}
              </div>

              <VTable v-else density="compact" class="text-body-2">
                <tbody>
                  <tr v-for="row in awards.by_preference_level" :key="String(row.level)">
                    <td>
                      {{ row.level === null
                        ? t('procurement_governance.analytics.no_level')
                        : t('procurement_governance.settings.field_bbbee_level') + ' ' + row.level }}
                    </td>
                    <td class="text-end font-weight-bold">{{ row.count }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ── Trend ─────────────────────────────────────────────────────────────────────────── -->
        <VCol cols="12">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.analytics.trend') }}
            </VCardTitle>
            <VCardText class="pt-0">
              <p class="text-caption text-medium-emphasis">
                {{ t('procurement_governance.analytics.trend_hint') }}
              </p>

              <div class="procgov-scroll">
                <div class="d-flex align-end ga-2" style="min-height: 160px; min-inline-size: 480px">
                  <div v-for="month in trend" :key="month.month" class="flex-grow-1 text-center">
                    <div
                      class="bg-primary rounded-t mx-auto"
                      :style="{
                        height: `${Math.max(2, (month.records_opened / trendMax) * 130)}px`,
                        inlineSize: '60%',
                      }"
                      :aria-label="`${month.label}: ${month.records_opened}`"
                    />
                    <div class="text-caption font-weight-medium mt-1">{{ month.records_opened }}</div>
                    <div class="text-caption text-medium-emphasis">{{ month.label }}</div>
                  </div>
                </div>
              </div>

              <!-- The bars are one series; the table beneath is every series, and is what a screen
                   reader and a spreadsheet both need. -->
              <VDivider class="my-3" />
              <div class="procgov-scroll">
                <VTable density="compact" class="text-body-2">
                  <thead>
                    <tr>
                      <th scope="col">{{ t('procurement_governance.analytics.month') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.analytics.funnel.records_opened') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.analytics.funnel.tenders') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.analytics.deviations') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.reports.awarded_value') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="month in trend" :key="month.month">
                      <td>{{ month.label }}</td>
                      <td class="text-end">{{ month.records_opened }}</td>
                      <td class="text-end">{{ month.tenders }}</td>
                      <td class="text-end">{{ month.deviations }}</td>
                      <td class="text-end">{{ money(month.awarded_value) }}</td>
                    </tr>
                  </tbody>
                </VTable>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
</style>
