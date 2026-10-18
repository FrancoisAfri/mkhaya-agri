<script setup lang="ts">
import { reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import RegisterTable, { type Column } from '../Partials/RegisterTable.vue'
import { humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  records: any[]
  filters: Record<string, any>
  stages: string[]
  officers: { id: number; name: string }[]
  noCompany?: boolean
}>()

const filters = reactive({
  search: props.filters.search ?? '',
  stage: props.filters.stage ?? null,
  status: props.filters.status ?? null,
  officer_id: props.filters.officer_id ?? null,
})

let timer: ReturnType<typeof setTimeout> | undefined
watch(filters, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get('/procurement-governance/records', { ...filters }, {
      preserveState: true, preserveScroll: true, replace: true,
    })
  }, 300)
})

const columns: Column[] = [
  {
    key: 'reference',
    label: t('procurement_governance.records.reference'),
    kind: 'link',
    href: row => `/procurement-governance/records/${row.uuid}`,
    width: '160px',
  },
  { key: 'title', label: t('procurement_governance.common.title') },
  { key: 'department', label: t('procurement_governance.common.department'), secondary: true },
  { key: 'officer', label: t('procurement_governance.common.officer'), secondary: true },
  { key: 'estimated_value', label: t('procurement_governance.records.estimated'), kind: 'money', align: 'end' },
  { key: 'awarded_value', label: t('procurement_governance.records.awarded'), kind: 'money', align: 'end', secondary: true },
  { key: 'stage', label: t('procurement_governance.records.stage'), kind: 'status', width: '170px' },
]

/** Past its target date and still open — the row a manager is looking for. */
const flag = (row: any) => row.status === 'ACTIVE'
  && row.target_completion_at
  && new Date(row.target_completion_at) < new Date()
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.records.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.records.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VCard v-if="!noCompany" elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex flex-wrap ga-3 py-3">
        <VTextField
          v-model="filters.search" :label="t('procurement_governance.common.search')"
          prepend-inner-icon="mdi-magnify" clearable
          density="compact" variant="outlined" hide-details style="max-inline-size: 280px"
        />
        <VSelect
          v-model="filters.stage" :items="stages.map(s => ({ title: humanise(s), value: s }))" clearable
          :label="t('procurement_governance.records.stage')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 220px"
        />
        <VSelect
          v-model="filters.status"
          :items="['ACTIVE', 'ON_HOLD', 'CLOSED', 'CANCELLED'].map(s => ({ title: humanise(s), value: s }))"
          clearable :label="t('procurement_governance.common.status')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 200px"
        />
        <VSelect
          v-model="filters.officer_id" :items="officers.map(o => ({ title: o.name, value: o.id }))" clearable
          :label="t('procurement_governance.common.officer')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 240px"
        />
      </VCardText>
    </VCard>

    <RegisterTable
      v-if="!noCompany"
      :rows="records"
      :columns="columns"
      :search-keys="['reference', 'title', 'department', 'officer']"
      :empty-hint="t('procurement_governance.records.empty')"
      :row-flag="flag"
    />
  </div>
</template>
