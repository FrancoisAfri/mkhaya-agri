<script setup lang="ts">
import { reactive, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'
import RegisterTable, { type Column } from '../Partials/RegisterTable.vue'
import { daysUntil, humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  reviews: any[]
  filters: Record<string, any>
  statuses: string[]
  officers: { id: number; name: string }[]
  noCompany?: boolean
  can: { create: boolean; moderate: boolean }
}>()

const filters = reactive({
  status: props.filters.status ?? null,
  officer_id: props.filters.officer_id ?? null,
})

let timer: ReturnType<typeof setTimeout> | undefined
watch(filters, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get('/procurement-governance/tenders', { ...filters }, {
      preserveState: true, preserveScroll: true, replace: true,
    })
  }, 250)
})

const columns: Column[] = [
  {
    key: 'reference',
    label: t('procurement_governance.tenders.reference'),
    kind: 'link',
    href: row => `/procurement-governance/tenders/${row.uuid}`,
    width: '130px',
  },
  { key: 'title', label: t('procurement_governance.common.title') },
  { key: 'tender_number', label: t('procurement_governance.tenders.tender_number'), kind: 'muted', secondary: true },
  { key: 'bidder_count', label: t('procurement_governance.tenders.bids'), kind: 'count', align: 'end', width: '70px' },
  { key: 'estimated_value', label: t('procurement_governance.common.value'), kind: 'money', align: 'end' },
  { key: 'evaluation_deadline', label: t('procurement_governance.tenders.deadline'), kind: 'date', width: '130px' },
  { key: 'status', label: t('procurement_governance.common.status'), kind: 'status', width: '170px' },
]

/** An evaluation past its deadline is the condition this register exists to surface. */
function overdue(row: any): boolean {
  const days = daysUntil(row.evaluation_deadline)
  return row.status === 'EVALUATION' && days !== null && days < 0
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.tenders.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.tenders.subtitle') }}</p>
      </div>
      <Link v-if="can.create" href="/procurement-governance/tenders/create">
        <VBtn color="primary" prepend-icon="mdi-plus-circle-outline">
          {{ t('procurement_governance.tenders.initiate') }}
        </VBtn>
      </Link>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VCard v-if="!noCompany" elevation="0" border rounded="lg" class="mb-4">
      <VCardText class="d-flex flex-wrap ga-3 py-3">
        <VSelect
          v-model="filters.status" :items="statuses.map(s => ({ title: humanise(s), value: s }))" clearable
          :label="t('procurement_governance.common.status')"
          density="compact" variant="outlined" hide-details style="max-inline-size: 240px"
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
      :rows="reviews"
      :columns="columns"
      :search-keys="['reference', 'title', 'tender_number', 'committee']"
      :empty-hint="t('procurement_governance.tenders.empty')"
      :row-flag="overdue"
    />
  </div>
</template>
