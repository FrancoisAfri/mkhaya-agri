<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface LoanType { id: number; name: string }
interface Loan {
  id: number; uuid: string; reference_number: string; employee: string; loan_type: string
  amount_requested: number; outstanding_balance: number; term_months: number
  monthly_installment: number; status: string; risk_level: string
  affordability_score: number; next_payment_date: string; created_at: string
}
interface Page<T> { data: T[]; total: number; current_page: number; per_page: number; last_page: number }

const props = defineProps<{ loans: Page<Loan>; filters: Record<string, string>; loanTypes: LoanType[] }>()

const search  = ref(props.filters.search ?? '')
const statusFilter  = ref(props.filters.status ?? '')

watch([search, statusFilter], () => {
  router.get(route('staff-loans.loans.index'), { search: search.value, status: statusFilter.value }, { preserveState: true, replace: true })
})

const statusColor = (s: string) => ({ pending: 'warning', under_review: 'info', approved: 'success', rejected: 'error', active: 'primary', closed: 'grey', defaulted: 'error' }[s] ?? 'grey')
const riskColor   = (r: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'error' }[r] ?? 'grey')

const headers = computed(()=> [
  { title: t('staff_loans_index.table.reference'), key: 'reference_number' },
  { title: t('staff_loans_index.table.employee'), key: 'employee' },
  { title: t('staff_loans_index.table.type'), key: 'loan_type' },
  { title: t('staff_loans_index.table.requested'), key: 'amount_requested' },
  { title: t('staff_loans_index.table.outstanding'), key: 'outstanding_balance' },
  { title: t('staff_loans_index.table.term'), key: 'term_months' },
  { title: t('staff_loans_index.table.monthly'), key: 'monthly_installment' },
  { title: t('staff_loans_index.table.risk'), key: 'risk_level' },
  { title: t('staff_loans_index.table.status'), key: 'status' },
  { title: '', key: 'actions', sortable: false },
])

const statusItems =  computed(()=>[
  { title: t('staff_loans_index.filters.statuses.pending'), value: 'pending' },
  { title: t('staff_loans_index.filters.statuses.under_review'), value: 'under_review' },
  { title: t('staff_loans_index.filters.statuses.approved'), value: 'approved' },
  { title: t('staff_loans_index.filters.statuses.rejected'), value: 'rejected' },
  { title: t('staff_loans_index.filters.statuses.active'), value: 'active' },
  { title: t('staff_loans_index.filters.statuses.closed'), value: 'closed' },
  { title: t('staff_loans_index.filters.statuses.defaulted'), value: 'defaulted' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <h1 class="text-h5 font-weight-bold">{{ t('staff_loans_index.page_title') }}</h1>
      <VBtn color="primary" prepend-icon="tabler-plus" :href="route('staff-loans.loans.create')">
        {{ t('staff_loans_index.apply_for_loan') }}
      </VBtn>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="search"
              :label="t('staff_loans_index.filters.search_label')"
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
              :label="t('staff_loans_index.filters.status_label')"
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
        :items="loans.data"
        :items-length="loans.total"
        :headers="headers"
        hide-default-footer
      >
        <template #item.amount_requested="{ item }">R{{ Number(item.amount_requested).toLocaleString() }}</template>
        <template #item.outstanding_balance="{ item }">R{{ Number(item.outstanding_balance).toLocaleString() }}</template>
        <template #item.monthly_installment="{ item }">{{ item.monthly_installment ? 'R'+Number(item.monthly_installment).toLocaleString() : '-' }}</template>
        <template #item.term_months="{ item }">{{ item.term_months }}m</template>
        <template #item.risk_level="{ item }">
          <VChip v-if="item.risk_level" size="x-small" :color="riskColor(item.risk_level)" variant="tonal">{{ item.risk_level }}</VChip>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #item.status="{ item }">
          <VChip size="small" :color="statusColor(item.status)" variant="tonal">{{ item.status.replace('_', ' ') }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn size="x-small" variant="text" icon="tabler-eye" :href="route('staff-loans.loans.show', item.uuid)" />
        </template>
      </VDataTableServer>
    </VCard>
  </div>
</template>
