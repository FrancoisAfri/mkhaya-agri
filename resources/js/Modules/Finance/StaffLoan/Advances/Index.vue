<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Advance { id: number; uuid: string; reference_number: string; employee: string; amount_requested: number; amount_approved: number; percentage_of_net: number; repayment_month: string; status: string; risk_level: string; created_at: string }
interface Page<T> { data: T[]; total: number; current_page: number; per_page: number; last_page: number }

const props = defineProps<{ advances: Page<Advance>; filters: Record<string, string> }>()

const search = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')

watch([search, statusFilter], () => {
  router.get(route('staff-loans.advances.index'), { search: search.value, status: statusFilter.value }, { preserveState: true, replace: true })
})

const statusColor = (s: string) => ({ pending: 'warning', approved: 'success', rejected: 'error', disbursed: 'primary', settled: 'grey' }[s] ?? 'grey')
const riskColor   = (r: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'error' }[r] ?? 'grey')

const headers = computed (()=> [
  { title: t('staff_advances_index.table.reference'), key: 'reference_number' },
  { title: t('staff_advances_index.table.employee'), key: 'employee' },
  { title: t('staff_advances_index.table.requested'), key: 'amount_requested' },
  { title: t('staff_advances_index.table.percentage_of_net'), key: 'percentage_of_net' },
  { title: t('staff_advances_index.table.repayment_month'), key: 'repayment_month' },
  { title: t('staff_advances_index.table.risk'), key: 'risk_level' },
  { title: t('staff_advances_index.table.status'), key: 'status' },
  { title: '', key: 'actions', sortable: false },
])

const statusItems = computed (()=>[
  { title: t('staff_advances_index.filters.statuses.pending'), value: 'pending' },
  { title: t('staff_advances_index.filters.statuses.approved'), value: 'approved' },
  { title: t('staff_advances_index.filters.statuses.rejected'), value: 'rejected' },
  { title: t('staff_advances_index.filters.statuses.disbursed'), value: 'disbursed' },
  { title: t('staff_advances_index.filters.statuses.settled'), value: 'settled' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <h1 class="text-h5 font-weight-bold">{{ t('staff_advances_index.page_title') }}</h1>
      <VBtn color="info" prepend-icon="tabler-plus" :href="route('staff-loans.advances.create')">
        {{ t('staff_advances_index.request_advance') }}
      </VBtn>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="search"
              :label="t('staff_advances_index.filters.search_label')"
              prepend-inner-icon="tabler-search"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect
              v-model="statusFilter"
              :label="t('staff_advances_index.filters.status_label')"
              :items="statusItems"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VDataTableServer
        :items="advances.data"
        :items-length="advances.total"
        :headers="headers"
        hide-default-footer
      >
        <template #item.amount_requested="{ item }">R{{ Number(item.amount_requested).toLocaleString() }}</template>
        <template #item.percentage_of_net="{ item }">{{ item.percentage_of_net ? item.percentage_of_net + '%' : '—' }}</template>
        <template #item.risk_level="{ item }">
          <VChip v-if="item.risk_level" size="x-small" :color="riskColor(item.risk_level)" variant="tonal">{{ item.risk_level }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip size="small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn size="x-small" variant="text" icon="tabler-eye" :href="route('staff-loans.advances.show', item.uuid)" />
        </template>
      </VDataTableServer>
    </VCard>
  </div>
</template>
