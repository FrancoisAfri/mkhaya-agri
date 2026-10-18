<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface RequestRow {
  id: number; uuid: string; reference_number: string
  requestor: string | null; custodian: string | null; float_name: string | null
  purpose: string; category: string | null; amount_requested: number
  amount_issued: number | null; status: string; risk_level: string | null
  receipt_submitted: boolean; receipt_overdue: boolean; requested_at: string | null
}

const props = defineProps<{
  requests: { data: RequestRow[]; links: any; meta: any }
  filters: { status?: string; float_id?: number | null; search?: string }
  floats: { id: number; name: string }[]
}>()

const search   = ref(props.filters.search ?? '')
const statusFilter   = ref(props.filters.status ?? '')
const floatId  = ref(props.filters.float_id ?? '')

const apply = () => router.get(route('petty-cash.requests.index'), { search: search.value, status: statusFilter.value, float_id: floatId.value }, { preserveState: true, replace: true })

const fmt = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const statusColor = (s: string) => ({
  draft: 'grey', pending: 'blue', approved: 'teal', rejected: 'error',
  issued: 'orange', settled: 'success', cancelled: 'grey',
})[s] ?? 'grey'

const riskColor = (r: string | null) => ({
  low: 'success', medium: 'warning', high: 'deep-orange', critical: 'error',
})[r ?? ''] ?? 'grey'

const headers =  computed(()=>[
  { title: t('petty_cash_requests_index.table.reference'),    key: 'reference_number' },
  { title: t('petty_cash_requests_index.table.requestor'),    key: 'requestor' },
  { title: t('petty_cash_requests_index.table.float'),        key: 'float_name' },
  { title: t('petty_cash_requests_index.table.purpose'),      key: 'purpose' },
  { title: t('petty_cash_requests_index.table.amount'),       key: 'amount_requested' },
  { title: t('petty_cash_requests_index.table.status'),       key: 'status' },
  { title: t('petty_cash_requests_index.table.risk'),         key: 'risk_level' },
  { title: t('petty_cash_requests_index.table.receipt'),      key: 'receipt_submitted' },
  { title: '',                                                 key: 'actions', sortable: false },
])

const statusOptions =  computed(()=> [
  { title: t('petty_cash_requests_index.filters.statuses.all'), value: '' },
  { title: t('petty_cash_requests_index.filters.statuses.pending'), value: 'pending' },
  { title: t('petty_cash_requests_index.filters.statuses.approved'), value: 'approved' },
  { title: t('petty_cash_requests_index.filters.statuses.issued'), value: 'issued' },
  { title: t('petty_cash_requests_index.filters.statuses.settled'), value: 'settled' },
  { title: t('petty_cash_requests_index.filters.statuses.rejected'), value: 'rejected' },
])

const floatOptions =  computed(()=> [
  { id: '', name: t('petty_cash_requests_index.filters.all_floats') },
  ...props.floats,
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_requests_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_requests_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" :href="route('petty-cash.requests.create')">
        {{ t('petty_cash_requests_index.new_request') }}
      </VBtn>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="d-flex ga-3 flex-wrap pa-3">
        <VTextField
          v-model="search"
          :placeholder="t('petty_cash_requests_index.filters.search_placeholder')"
          prepend-inner-icon="tabler-search"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:280px"
          @keyup.enter="apply"
          clearable
          @click:clear="() => { search=''; apply() }"
        />
        <VSelect
          v-model="statusFilter"
          :items="statusOptions"
          item-title="title"
          item-value="value"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
          :label="t('petty_cash_requests_index.filters.status_label')"
          @update:model-value="apply"
        />
        <VSelect
          v-model="floatId"
          :items="floatOptions"
          item-title="name"
          item-value="id"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:200px"
          :label="t('petty_cash_requests_index.filters.float_label')"
          @update:model-value="apply"
        />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="requests.data" :items-per-page="-1" hide-default-footer>
        <template #item.amount_requested="{ item }">{{ fmt(item.amount_requested) }}</template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ item.status }}</VChip>
        </template>
        <template #item.risk_level="{ item }">
          <VChip v-if="item.risk_level" :color="riskColor(item.risk_level)" size="x-small" variant="tonal">{{ item.risk_level }}</VChip>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #item.receipt_submitted="{ item }">
          <VIcon v-if="item.receipt_submitted" color="success" size="18">tabler-circle-check</VIcon>
          <VIcon v-else-if="item.receipt_overdue" color="error" size="18">tabler-alert-circle</VIcon>
          <VIcon v-else color="grey" size="18">tabler-clock</VIcon>
        </template>
        <template #item.actions="{ item }">
          <VBtn icon size="small" variant="text" :href="route('petty-cash.requests.show', item.uuid)">
            <VIcon>tabler-eye</VIcon>
          </VBtn>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
