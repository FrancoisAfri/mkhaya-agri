<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type StatusRow = { status: string; count: number; value: number }
type RecentRow = {
  id: number; contract_number: string; title: string; contract_status: string
  contract_value: number | null; end_date: string | null; type: string | null
}

defineProps<{
  stats: {
    total: number; total_value: number; active: number; published: number
    expiring_30_days: number; status_breakdown: StatusRow[]
  }
  recent: RecentRow[]
}>()

function money(v: number | null) {
  return v == null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(v)
}

const statusColor: Record<string, string> = {
  draft: 'grey', published: 'info', pending: 'warning', active: 'success',
  expired: 'error', renewed: 'primary', terminated: 'error', cancelled: 'grey',
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-file-text" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('contracts_dashboard.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_dashboard.description') }}</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-list" @click="router.get(route('contracts.index'))">
          {{ t('contracts_dashboard.contracts') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="router.get(route('contracts.create'))">
          {{ t('contracts_dashboard.new_contract') }}
        </VBtn>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('contracts_dashboard.kpis.total_contracts') }}</div>
            <div class="text-h4">{{ stats.total }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('contracts_dashboard.kpis.total_value') }}</div>
            <div class="text-h5">{{ money(stats.total_value) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('contracts_dashboard.kpis.active') }}</div>
            <div class="text-h4">{{ stats.active }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('contracts_dashboard.kpis.expiring_30_days') }}</div>
            <div class="text-h4">{{ stats.expiring_30_days }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="5">
        <VCard border>
          <VCardTitle>{{ t('contracts_dashboard.status_breakdown.title') }}</VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_dashboard.status_breakdown.status') }}</th>
                <th class="text-right">{{ t('contracts_dashboard.status_breakdown.count') }}</th>
                <th class="text-right">{{ t('contracts_dashboard.status_breakdown.value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!stats.status_breakdown.length">
                <td colspan="3" class="text-center text-medium-emphasis py-6">{{ t('contracts_dashboard.status_breakdown.no_data') }}</td>
              </tr>
              <tr v-for="status in stats.status_breakdown" :key="status.status">
                <td>
                  <VChip size="small" :color="statusColor[status.status]" variant="tonal">{{ status.status }}</VChip>
                </td>
                <td class="text-right">{{ status.count }}</td>
                <td class="text-right">{{ money(status.value) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="7">
        <VCard border>
          <VCardTitle>{{ t('contracts_dashboard.recent_contracts.title') }}</VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_dashboard.recent_contracts.number') }}</th>
                <th>{{ t('contracts_dashboard.recent_contracts.title_col') }}</th>
                <th>{{ t('contracts_dashboard.recent_contracts.status') }}</th>
                <th class="text-right">{{ t('contracts_dashboard.recent_contracts.value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!recent.length">
                <td colspan="4" class="text-center text-medium-emphasis py-6">{{ t('contracts_dashboard.recent_contracts.no_contracts') }}</td>
              </tr>
              <tr v-for="contract in recent" :key="contract.id" style="cursor:pointer" @click="router.get(route('contracts.show', contract.uuid))">
                <td class="font-weight-medium">{{ contract.contract_number }}</td>
                <td>{{ contract.title }}</td>
                <td>
                  <VChip size="small" :color="statusColor[contract.contract_status]" variant="tonal">{{ contract.contract_status }}</VChip>
                </td>
                <td class="text-right">{{ money(contract.contract_value) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
