<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ReconRow {
  id: number; uuid: string; float_name: string | null; reconciled_by: string | null
  period_start: string; period_end: string; variance: number; has_shortage: boolean
  status: string; submitted_at: string | null; approved_at: string | null
}

const props = defineProps<{
  reconciliations: { data: ReconRow[]; links: any; meta: any }
  filters: { status?: string; float_id?: number | null }
  floats: { id: number; name: string }[]
}>()

const statusFilter  = ref(props.filters.status ?? '')
const floatId = ref(props.filters.float_id ?? '')
const apply   = () => router.get(route('petty-cash.reconciliations.index'), { status: statusFilter.value, float_id: floatId.value }, { preserveState: true, replace: true })

const statusColor = (s: string) => ({ draft: 'grey', submitted: 'blue', approved: 'success', rejected: 'error' })[s] ?? 'grey'

const headers = [
  { title: t('petty_cash_reconciliations_index.table.float'),         key: 'float_name' },
  { title: t('petty_cash_reconciliations_index.table.reconciled_by'), key: 'reconciled_by' },
  { title: t('petty_cash_reconciliations_index.table.period'),        key: 'period_start' },
  { title: t('petty_cash_reconciliations_index.table.variance'),      key: 'variance' },
  { title: t('petty_cash_reconciliations_index.table.status'),        key: 'status' },
  { title: t('petty_cash_reconciliations_index.table.submitted'),     key: 'submitted_at' },
  { title: '',                                                         key: 'actions', sortable: false },
]

const statusItems = [
  { title: t('petty_cash_reconciliations_index.filters.statuses.all'), value: '' },
  { title: t('petty_cash_reconciliations_index.filters.statuses.draft'), value: 'draft' },
  { title: t('petty_cash_reconciliations_index.filters.statuses.submitted'), value: 'submitted' },
  { title: t('petty_cash_reconciliations_index.filters.statuses.approved'), value: 'approved' },
  { title: t('petty_cash_reconciliations_index.filters.statuses.rejected'), value: 'rejected' },
]

const floatItems = [
  { id: '', name: t('petty_cash_reconciliations_index.filters.all_floats') },
  ...props.floats,
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_reconciliations_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_reconciliations_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" :href="route('petty-cash.reconciliations.create')">
        {{ t('petty_cash_reconciliations_index.new_reconciliation') }}
      </VBtn>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="d-flex ga-3 flex-wrap pa-3">
        <VSelect
          v-model="statusFilter"
          :items="statusItems"
          item-title="title"
          item-value="value"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
          :label="t('petty_cash_reconciliations_index.filters.status_label')"
          @update:model-value="apply"
        />
        <VSelect
          v-model="floatId"
          :items="floatItems"
          item-title="name"
          item-value="id"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:200px"
          :label="t('petty_cash_reconciliations_index.filters.float_label')"
          @update:model-value="apply"
        />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="reconciliations.data" :items-per-page="-1" hide-default-footer>
        <template #item.period_start="{ item }">{{ item.period_start }} to {{ item.period_end }}</template>
        <template #item.variance="{ item }">
          <span :class="item.has_shortage ? 'text-error font-weight-bold' : item.variance > 0 ? 'text-success' : ''">
            {{ item.variance > 0 ? '+' : '' }}R{{ item.variance }}
            <VIcon v-if="item.has_shortage" color="error" size="14">tabler-alert-triangle</VIcon>
          </span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ item.status }}</VChip>
        </template>
        <template #item.submitted_at="{ item }">
          {{ item.submitted_at ? item.submitted_at.substring(0, 10) : '—' }}
        </template>
        <template #item.actions="{ item }">
          <VBtn icon size="small" variant="text" :href="route('petty-cash.reconciliations.show', item.uuid)">
            <VIcon>tabler-eye</VIcon>
          </VBtn>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
