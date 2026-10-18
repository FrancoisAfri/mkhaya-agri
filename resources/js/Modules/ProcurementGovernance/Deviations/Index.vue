<script setup lang="ts">
import { reactive, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import RegisterTable, { type Column } from '../Partials/RegisterTable.vue'
import { humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  deviations: any[]
  filters: Record<string, any>
  statuses: string[]
  riskLevels: string[]
  departments: { id: number; name: string }[]
  noCompany?: boolean
  can: { create: boolean; approve: boolean }
}>()

const filters = reactive({
  status: props.filters.status ?? null,
  risk_level: props.filters.risk_level ?? null,
  company_unit_id: props.filters.company_unit_id ?? null,
})

let timer: ReturnType<typeof setTimeout> | undefined
watch(filters, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get('/procurement-governance/deviations', { ...filters }, {
      preserveState: true, preserveScroll: true, replace: true,
    })
  }, 250)
})

const columns: Column[] = [
  {
    key: 'reference',
    label: t('procurement_governance.deviations.reference'),
    kind: 'link',
    href: row => `/procurement-governance/deviations/${row.uuid}`,
    width: '130px',
  },
  { key: 'title', label: t('procurement_governance.common.title') },
  { key: 'deviation_type_code', label: t('procurement_governance.deviations.type'), kind: 'muted', secondary: true },
  { key: 'department', label: t('procurement_governance.common.department'), secondary: true },
  { key: 'procurement_value', label: t('procurement_governance.common.value'), kind: 'money', align: 'end' },
  { key: 'risk_level', label: t('procurement_governance.deviations.risk'), kind: 'status', width: '130px' },
  { key: 'compliance_result', label: t('procurement_governance.deviations.compliance'), kind: 'status', width: '130px' },
  { key: 'status', label: t('procurement_governance.common.status'), kind: 'status', width: '180px' },
]

/** A deviation that failed compliance and is still moving is the row to look at first. */
const flag = (row: any) => row.compliance_result === 'FAIL'
  && !['APPROVED', 'REJECTED', 'CLOSED', 'WITHDRAWN'].includes(row.status)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.deviations.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.deviations.subtitle') }}</p>
      </div>
      <Link v-if="can.create" href="/procurement-governance/deviations/create">
        <VBtn color="primary" prepend-icon="mdi-plus-circle-outline">
          {{ t('procurement_governance.deviations.new') }}
        </VBtn>
      </Link>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VCard v-if="!noCompany" elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex flex-wrap ga-3 py-3">
        <VSelect
          v-model="filters.status" :items="statuses.map(s => ({ title: humanise(s), value: s }))" clearable
          :label="t('procurement_governance.common.status')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 250px"
        />
        <VSelect
          v-model="filters.risk_level" :items="riskLevels.map(r => ({ title: humanise(r), value: r }))" clearable
          :label="t('procurement_governance.deviations.risk')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 200px"
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
      :rows="deviations"
      :columns="columns"
      :search-keys="['reference', 'title', 'supplier_name', 'officer']"
      :empty-hint="t('procurement_governance.deviations.empty')"
      :row-flag="flag"
    />
  </div>
</template>
