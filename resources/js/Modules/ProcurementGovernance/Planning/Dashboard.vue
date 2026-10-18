<script setup lang="ts">
import { reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import KpiCard from '../Partials/KpiCard.vue'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import RegisterTable, { type Column } from '../Partials/RegisterTable.vue'
import { count, money } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  summary: any | null
  filters: Record<string, any>
  financialYears: string[]
  departments: { id: number; name: string }[]
  officers: { id: number; name: string }[]
  upcoming: any[]
  delayed: any[]
  noCompany?: boolean
}>()

const filters = reactive({
  financial_year: props.filters.financial_year ?? null,
  company_unit_id: props.filters.company_unit_id ?? null,
  officer_id: props.filters.officer_id ?? null,
})

let timer: ReturnType<typeof setTimeout> | undefined
watch(filters, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get('/procurement-governance/planning/dashboard', { ...filters }, {
      preserveState: true, preserveScroll: true, replace: true,
    })
  }, 250)
})

const lineColumns: Column[] = [
  {
    key: 'reference',
    label: t('procurement_governance.planning.reference'),
    kind: 'link',
    href: row => `/procurement-governance/plans/${row.plan_uuid}`,
    width: '150px',
  },
  { key: 'description', label: t('procurement_governance.common.description') },
  { key: 'officer', label: t('procurement_governance.common.officer'), secondary: true },
  { key: 'estimated_value', label: t('procurement_governance.planning.estimated'), kind: 'money', align: 'end' },
  { key: 'planned_tender_date', label: t('procurement_governance.planning.tender_date'), kind: 'date' },
  { key: 'status', label: t('procurement_governance.common.status'), kind: 'status', width: '160px' },
]
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.planning.status_dashboard') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.planning.status_subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany && summary">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap ga-3 py-3">
          <VSelect
            v-model="filters.financial_year" :items="financialYears" clearable
            :label="t('procurement_governance.planning.financial_year')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 200px"
          />
          <VSelect
            v-model="filters.company_unit_id"
            :items="departments.map(d => ({ title: d.name, value: d.id }))" clearable
            :label="t('procurement_governance.common.department')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 240px"
          />
          <VSelect
            v-model="filters.officer_id"
            :items="officers.map(o => ({ title: o.name, value: o.id }))" clearable
            :label="t('procurement_governance.common.officer')"
            density="compact" variant="outlined" hide-details style="max-inline-size: 240px"
          />
        </VCardText>
      </VCard>

      <!-- §2.4, in the order the brief lists them. -->
      <VRow class="mb-2">
        <VCol v-for="key in ['total', 'approved', 'in_progress', 'completed', 'delayed', 'cancelled']"
              :key="key" cols="6" sm="4" md="2">
          <KpiCard
            :label="t(`procurement_governance.planning.counts.${key}`)"
            :value="count(summary.counts[key])"
            :tone="key === 'delayed' && summary.counts[key] > 0 ? 'warning' : 'default'"
          />
        </VCol>
      </VRow>

      <VRow class="mb-2">
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.planning.planned_value')"
            :value="money(summary.value.planned)" icon="mdi-cash-multiple" colour="indigo"
          />
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.planning.committed_value')"
            :value="money(summary.value.committed)" icon="mdi-lock-outline" colour="blue"
          />
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.planning.actual_value')"
            :value="money(summary.value.actual)" icon="mdi-cash-check" colour="green"
          />
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <KpiCard
            :label="t('procurement_governance.planning.utilisation')"
            :value="summary.value.utilisation_pct === null ? '—' : `${summary.value.utilisation_pct}%`"
            :hint="summary.value.utilisation_pct === null ? t('procurement_governance.planning.utilisation_unknown') : undefined"
            icon="mdi-percent-outline" colour="teal"
          />
        </VCol>
      </VRow>

      <RegisterTable
        class="mb-4"
        :rows="delayed"
        :columns="lineColumns"
        :title="t('procurement_governance.planning.delayed_title')"
        :subtitle="t('procurement_governance.planning.delayed_subtitle')"
        :empty-hint="t('procurement_governance.planning.delayed_empty')"
        :row-flag="() => true"
        row-key="uuid"
      />

      <RegisterTable
        :rows="upcoming"
        :columns="lineColumns"
        :title="t('procurement_governance.planning.upcoming_title')"
        :subtitle="t('procurement_governance.planning.upcoming_subtitle')"
        :empty-hint="t('procurement_governance.planning.upcoming_empty')"
        row-key="uuid"
      />
    </template>
  </div>
</template>
