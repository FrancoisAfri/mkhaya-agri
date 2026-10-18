<script setup lang="ts">
import { reactive, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import RegisterTable, { type Column } from '../Partials/RegisterTable.vue'
import { humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  plans: any[]
  filters: Record<string, any>
  statuses: string[]
  financialYears: string[]
  departments: { id: number; name: string }[]
  noCompany?: boolean
  can: { create: boolean; approve: boolean }
}>()

const filters = reactive({
  financial_year: props.filters.financial_year ?? null,
  status: props.filters.status ?? null,
  company_unit_id: props.filters.company_unit_id ?? null,
})

// Debounced so changing three filters in a row is one request, not three.
let timer: ReturnType<typeof setTimeout> | undefined
watch(filters, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get('/procurement-governance/plans', { ...filters }, {
      preserveState: true, preserveScroll: true, replace: true,
    })
  }, 250)
})

const columns: Column[] = [
  {
    key: 'reference',
    label: t('procurement_governance.planning.reference'),
    kind: 'link',
    href: row => `/procurement-governance/plans/${row.uuid}`,
    width: '140px',
  },
  { key: 'title', label: t('procurement_governance.common.title') },
  { key: 'financial_year_label', label: t('procurement_governance.planning.financial_year'), width: '110px' },
  { key: 'department', label: t('procurement_governance.common.department'), secondary: true },
  { key: 'line_count', label: t('procurement_governance.planning.lines'), kind: 'count', align: 'end', width: '80px' },
  {
    key: 'total_estimated_value',
    label: t('procurement_governance.planning.estimated'),
    kind: 'money',
    align: 'end',
  },
  {
    key: 'total_actual_value',
    label: t('procurement_governance.planning.actual'),
    kind: 'money',
    align: 'end',
    secondary: true,
  },
  { key: 'status', label: t('procurement_governance.common.status'), kind: 'status', width: '180px' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.planning.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.planning.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <Link href="/procurement-governance/planning/dashboard">
          <VBtn variant="tonal" prepend-icon="mdi-view-dashboard-outline">
            {{ t('procurement_governance.planning.status_dashboard') }}
          </VBtn>
        </Link>
        <Link v-if="can.create" href="/procurement-governance/plans/create">
          <VBtn color="primary" prepend-icon="mdi-plus-circle-outline">
            {{ t('procurement_governance.planning.new_plan') }}
          </VBtn>
        </Link>
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VCard v-if="!noCompany" elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex flex-wrap ga-3 py-3">
        <VSelect
          v-model="filters.financial_year" :items="financialYears" clearable
          :label="t('procurement_governance.planning.financial_year')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 200px"
        />
        <VSelect
          v-model="filters.status" :items="statuses.map(s => ({ title: humanise(s), value: s }))" clearable
          :label="t('procurement_governance.common.status')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 240px"
        />
        <VSelect
          v-model="filters.company_unit_id"
          :items="departments.map(d => ({ title: d.name, value: d.id }))" clearable
          :label="t('procurement_governance.common.department')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 240px"
        />
      </VCardText>
    </VCard>

    <RegisterTable
      v-if="!noCompany"
      :rows="plans"
      :columns="columns"
      :search-keys="['reference', 'title', 'department']"
      :empty-hint="t('procurement_governance.planning.empty')"
    />
  </div>
</template>
