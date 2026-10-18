<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import KpiCard from '../Partials/KpiCard.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import { count, date, dateTime, money, score } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  noCompany?: boolean
  financialYear?: string
  quarter?: string
  period?: { from: string; to: string }
  filters?: Record<string, any>
  spend?: any
  suppliers?: any
  performance?: any
  financialYears?: string[]
  departments?: { id: number; name: string }[]
  officers?: { id: number; name: string }[]
  categories?: string[]
  methods?: string[]
  savedRuns?: any[]
  can?: { export: boolean }
}>()

const tab = ref('spend')

const selection = reactive({
  financial_year: props.financialYear ?? null,
  quarter: props.quarter || null,
  company_unit_id: props.filters?.company_unit_id ?? null,
  category: props.filters?.category ?? null,
  officer_id: props.filters?.officer_id ?? null,
  method: props.filters?.method ?? null,
})

let timer: ReturnType<typeof setTimeout> | undefined
watch(selection, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get('/procurement-governance/reports', { ...selection }, {
      preserveState: true, preserveScroll: true, replace: true,
    })
  }, 300)
})

const generateForm = useForm({
  report_type: 'FULL',
  financial_year: '',
  quarter: '',
  filters: {} as Record<string, any>,
})

function generate(type: string) {
  generateForm.report_type = type
  generateForm.financial_year = selection.financial_year ?? ''
  generateForm.quarter = selection.quarter ?? ''
  generateForm.filters = {
    company_unit_id: selection.company_unit_id,
    category: selection.category,
    officer_id: selection.officer_id,
    method: selection.method,
  }
  generateForm.post('/procurement-governance/reports/generate', { preserveScroll: true })
}

const totals = computed(() => props.spend?.totals ?? {})
const cycle = computed(() => props.performance?.cycle_times_days ?? {})
const volumes = computed(() => props.performance?.volumes ?? {})
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.reports.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('procurement_governance.reports.subtitle') }}
          <template v-if="period"> · {{ date(period.from) }} – {{ date(period.to) }}</template>
        </p>
      </div>
      <VBtn
        v-if="can?.export" color="primary" prepend-icon="mdi-content-save-outline"
        :loading="generateForm.processing" @click="generate('FULL')"
      >
        {{ t('procurement_governance.reports.freeze') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap ga-3 py-3">
          <VSelect
            v-model="selection.financial_year" :items="financialYears ?? []"
            :label="t('procurement_governance.planning.financial_year')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 180px"
          />
          <VSelect
            v-model="selection.quarter"
            :items="[
              { title: t('procurement_governance.reports.full_year'), value: null },
              ...['Q1', 'Q2', 'Q3', 'Q4'].map(q => ({ title: q, value: q })),
            ]"
            :label="t('procurement_governance.reports.quarter')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 160px"
          />
          <VSelect
            v-model="selection.company_unit_id"
            :items="(departments ?? []).map(d => ({ title: d.name, value: d.id }))" clearable
            :label="t('procurement_governance.common.department')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 220px"
          />
          <VSelect
            v-model="selection.category" :items="categories ?? []" clearable
            :label="t('procurement_governance.common.category')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 200px"
          />
          <VSelect
            v-model="selection.officer_id"
            :items="(officers ?? []).map(o => ({ title: o.name, value: o.id }))" clearable
            :label="t('procurement_governance.common.officer')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 220px"
          />
        </VCardText>
      </VCard>

      <VTabs v-model="tab" class="mb-4" density="comfortable">
        <VTab value="spend">{{ t('procurement_governance.reports.tabs.spend') }}</VTab>
        <VTab value="suppliers">{{ t('procurement_governance.reports.tabs.suppliers') }}</VTab>
        <VTab value="performance">{{ t('procurement_governance.reports.tabs.performance') }}</VTab>
        <VTab value="saved">{{ t('procurement_governance.reports.tabs.saved') }}</VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- §3.2 spend analysis -->
        <VWindowItem value="spend">
          <VRow class="mb-2">
            <VCol v-for="key in ['planned_spend', 'approved_spend', 'awarded_value', 'contract_value', 'actual_spend', 'budget_variance']"
                  :key="key" cols="6" md="4" lg="2">
              <KpiCard
                :label="t(`procurement_governance.reports.${key}`)"
                :value="money(totals[key])"
                :hint="totals[key] === null ? t('procurement_governance.reports.module_absent') : undefined"
              />
            </VCol>
          </VRow>

          <VRow>
            <VCol v-for="dimension in ['by_department', 'by_category', 'by_method']" :key="dimension" cols="12" lg="4">
              <VCard elevation="0" border rounded="lg" class="h-100">
                <VCardTitle class="text-body-1 font-weight-bold py-3">
                  {{ t(`procurement_governance.reports.${dimension}`) }}
                </VCardTitle>
                <div v-if="!spend?.[dimension]?.length" class="text-center py-8 text-medium-emphasis text-body-2">
                  {{ t('procurement_governance.common.empty') }}
                </div>
                <VTable v-else density="compact">
                  <thead>
                    <tr>
                      <th scope="col">{{ t('procurement_governance.common.name') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.common.count') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.reports.estimated') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.reports.awarded') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, i) in spend[dimension]" :key="i">
                      <td class="text-body-2">{{ row.label }}</td>
                      <td class="text-end text-body-2">{{ count(row.count) }}</td>
                      <td class="text-end text-body-2">{{ money(row.estimated) }}</td>
                      <td class="text-end text-body-2">{{ money(row.awarded) }}</td>
                    </tr>
                  </tbody>
                </VTable>
              </VCard>
            </VCol>
          </VRow>
        </VWindowItem>

        <!-- §3.3 supplier statistics -->
        <VWindowItem value="suppliers">
          <VAlert
            v-if="suppliers && suppliers.available === false" type="info" variant="tonal"
            class="mb-4 text-body-2"
          >
            {{ t('procurement_governance.reports.no_supplier_module') }}
          </VAlert>

          <template v-else>
            <VRow class="mb-2">
              <VCol cols="6" md="3">
                <KpiCard :label="t('procurement_governance.reports.active_suppliers')" :value="count(suppliers?.active_count)" />
              </VCol>
              <VCol cols="6" md="3">
                <KpiCard :label="t('procurement_governance.reports.new_suppliers')" :value="count(suppliers?.new_count)" />
              </VCol>
              <VCol cols="6" md="3">
                <KpiCard :label="t('procurement_governance.reports.suppliers_used')" :value="count(suppliers?.suppliers_used)" />
              </VCol>
              <VCol cols="6" md="3">
                <KpiCard
                  :label="t('procurement_governance.reports.concentration')"
                  :value="suppliers?.concentration_top3_pct ? `${score(suppliers.concentration_top3_pct)}%` : '—'"
                  :hint="t('procurement_governance.reports.concentration_hint')"
                  :tone="(suppliers?.concentration_top3_pct ?? 0) > 60 ? 'warning' : 'default'"
                />
              </VCol>
            </VRow>

            <VCard elevation="0" border rounded="lg">
              <VCardTitle class="text-body-1 font-weight-bold py-3">
                {{ t('procurement_governance.reports.awards_by_supplier') }}
              </VCardTitle>
              <div v-if="!suppliers?.by_supplier?.length" class="text-center py-8 text-medium-emphasis text-body-2">
                {{ t('procurement_governance.reports.no_awards') }}
              </div>
              <div v-else class="procgov-scroll">
                <VTable density="compact">
                  <thead>
                    <tr>
                      <th scope="col">{{ t('procurement_governance.tenders.supplier') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.reports.awards') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.common.value') }}</th>
                      <th scope="col" class="text-end">{{ t('procurement_governance.reports.share') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(row, i) in suppliers.by_supplier" :key="i">
                      <td class="text-body-2">{{ row.supplier }}</td>
                      <td class="text-end text-body-2">{{ count(row.awards) }}</td>
                      <td class="text-end text-body-2">{{ money(row.value) }}</td>
                      <td class="text-end text-body-2">{{ row.share_pct === null ? '—' : `${score(row.share_pct)}%` }}</td>
                    </tr>
                  </tbody>
                </VTable>
              </div>
            </VCard>
          </template>
        </VWindowItem>

        <!-- §3.4 and §4.4 -->
        <VWindowItem value="performance">
          <VRow class="mb-2">
            <VCol v-for="key in ['procurements_opened', 'procurements_completed', 'tenders', 'tenders_completed', 'tenders_delayed', 'deviations']"
                  :key="key" cols="6" md="4" lg="2">
              <KpiCard
                :label="t(`procurement_governance.reports.volumes.${key}`)"
                :value="count(volumes[key])"
                :tone="key === 'tenders_delayed' && volumes[key] > 0 ? 'warning' : 'default'"
              />
            </VCol>
          </VRow>

          <VRow class="mb-2">
            <VCol v-for="key in ['procurement_cycle', 'tender_evaluation', 'approval']" :key="key" cols="12" md="4">
              <KpiCard
                :label="t(`procurement_governance.reports.cycle.${key}`)"
                :value="cycle[key] === null ? '—' : t('procurement_governance.reports.days', { n: cycle[key] })"
                :hint="cycle[key] === null ? t('procurement_governance.reports.no_completed') : undefined"
                icon="mdi-timer-outline" colour="teal"
              />
            </VCol>
          </VRow>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.reports.stage_durations') }}
            </VCardTitle>
            <VCardSubtitle class="text-caption pb-2">
              {{ t('procurement_governance.reports.stage_durations_hint') }}
            </VCardSubtitle>
            <div v-if="!performance?.stage_durations_days?.length" class="text-center py-8 text-medium-emphasis text-body-2">
              {{ t('procurement_governance.reports.no_transitions') }}
            </div>
            <VTable v-else density="compact">
              <thead>
                <tr>
                  <th scope="col">{{ t('procurement_governance.reports.transition') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.reports.avg_days') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.reports.occurrences') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in performance.stage_durations_days" :key="i">
                  <td class="text-body-2">{{ row.transition }}</td>
                  <td class="text-end text-body-2 font-weight-medium">{{ score(row.average_days) }}</td>
                  <td class="text-end text-body-2">{{ count(row.count) }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VWindowItem>

        <!-- Frozen runs (§3): the figures as at the reporting date, kept that way. -->
        <VWindowItem value="saved">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-body-1 font-weight-bold py-3">
              {{ t('procurement_governance.reports.saved_runs') }}
            </VCardTitle>
            <VCardSubtitle class="text-caption pb-2">
              {{ t('procurement_governance.reports.saved_runs_hint') }}
            </VCardSubtitle>

            <div v-if="!savedRuns?.length" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-file-clock-outline" size="32" class="mb-2" />
              <div class="text-body-2">{{ t('procurement_governance.reports.no_saved') }}</div>
            </div>

            <VList v-else density="comfortable">
              <VListItem
                v-for="run in savedRuns" :key="run.uuid"
                :href="`/procurement-governance/reports/runs/${run.uuid}`" class="procgov-link-item"
              >
                <VListItemTitle class="text-body-2 font-weight-medium">{{ run.title }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  {{ dateTime(run.generated_at) }}
                  <template v-if="run.payload_hash">
                    · {{ t('procurement_governance.reports.hash') }} {{ run.payload_hash.slice(0, 12) }}…
                  </template>
                </VListItemSubtitle>
              </VListItem>
            </VList>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.procgov-link-item { cursor: pointer; }
</style>
