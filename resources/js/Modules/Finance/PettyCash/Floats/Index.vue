<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface FloatRow {
  id: number; uuid: string; name: string; custodian: string
  current_balance: number; float_limit: number; utilization_pct: number
  needs_replenishment: boolean; status: string; requests_count: number
  reconciliations_count: number; last_reconciled_at: string | null
}

const props = defineProps<{
  floats: { data: FloatRow[]; links: any; meta: any }
  filters: { search?: string; status?: string }
}>()

const search = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')

const applyFilters = () => router.get(route('petty-cash.floats.index'), { search: search.value, status: statusFilter.value }, { preserveState: true, replace: true })

const fmt = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const statusColor = (s: string) => ({ active: 'success', inactive: 'grey', suspended: 'error' })[s] ?? 'grey'
const utilizationColor = (p: number) => p >= 90 ? 'error' : p >= 70 ? 'warning' : 'success'

const headers = [
  { title: t('petty_cash_floats_index.table.float_name'),    key: 'name' },
  { title: t('petty_cash_floats_index.table.custodian'),     key: 'custodian' },
  { title: t('petty_cash_floats_index.table.balance'),       key: 'current_balance' },
  { title: t('petty_cash_floats_index.table.limit'),         key: 'float_limit' },
  { title: t('petty_cash_floats_index.table.utilization'),   key: 'utilization_pct' },
  { title: t('petty_cash_floats_index.table.status'),        key: 'status' },
  { title: t('petty_cash_floats_index.table.requests'),      key: 'requests_count' },
  { title: t('petty_cash_floats_index.table.last_recon'),    key: 'last_reconciled_at' },
  { title: '',                                                key: 'actions', sortable: false },
]

const statusItems = [
  { title: t('petty_cash_floats_index.filters.statuses.all'), value: '' },
  { title: t('petty_cash_floats_index.filters.statuses.active'), value: 'active' },
  { title: t('petty_cash_floats_index.filters.statuses.inactive'), value: 'inactive' },
  { title: t('petty_cash_floats_index.filters.statuses.suspended'), value: 'suspended' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_floats_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_floats_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" :href="route('petty-cash.floats.create')">
        {{ t('petty_cash_floats_index.new_float') }}
      </VBtn>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="d-flex ga-3 flex-wrap pa-3">
        <VTextField
          v-model="search"
          :placeholder="t('petty_cash_floats_index.filters.search_placeholder')"
          prepend-inner-icon="tabler-search"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:280px"
          @keyup.enter="applyFilters"
          clearable
          @click:clear="() => { search = ''; applyFilters() }"
        />
        <VSelect
          v-model="statusFilter"
          :items="statusItems"
          item-title="title"
          item-value="value"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width:160px"
          @update:model-value="applyFilters"
        />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="floats.data" :items-per-page="-1" hide-default-footer>
        <template #item.current_balance="{ item }">
          <span :class="item.needs_replenishment ? 'text-error font-weight-bold' : ''">
            {{ fmt(item.current_balance) }}
            <VIcon v-if="item.needs_replenishment" color="error" size="16" class="ml-1">tabler-battery</VIcon>
          </span>
        </template>
        <template #item.float_limit="{ item }">{{ fmt(item.float_limit) }}</template>
        <template #item.utilization_pct="{ item }">
          <VProgressLinear :model-value="item.utilization_pct" :color="utilizationColor(item.utilization_pct)" height="8" rounded />
          <span class="text-caption">{{ item.utilization_pct }}%</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ item.status }}</VChip>
        </template>
        <template #item.last_reconciled_at="{ item }">
          <span v-if="item.last_reconciled_at">{{ item.last_reconciled_at }}</span>
          <span v-else class="text-medium-emphasis text-caption">{{ t('petty_cash_floats_index.never') }}</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn icon size="small" variant="text" :href="route('petty-cash.floats.show', item.uuid)">
            <VIcon>tabler-eye</VIcon>
          </VBtn>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
