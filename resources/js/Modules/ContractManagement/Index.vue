<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Contract = {
  id: number; contract_number: string; title: string; contract_status: string
  approval_status: string; contract_value: number | null
  start_date: string | null; end_date: string | null; type: string | null; category: string | null
  risk_score: number | null
}

const props = defineProps<{
  contracts: { data: Contract[]; links: any[] }
  filters: { search?: string; status?: string; type?: number; category?: number }
  types: { id: number; name: string }[]
  categories: { id: number; name: string }[]
  statuses: string[]
}>()

const search = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')
const typeFilter = ref(props.filters.type ?? null)
const categoryFilter = ref(props.filters.category ?? null)
const confirmDialog = ref()

let timeoutId: ReturnType<typeof setTimeout>

function applyFilters() {
  clearTimeout(timeoutId)
  timeoutId = setTimeout(() => {
    router.get(route('contracts.index'), {
      search: search.value || undefined,
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      category: categoryFilter.value || undefined,
    }, { preserveState: true, replace: true })
  }, 300)
}

function destroy(contract: Contract) {
  confirmDialog.value.open(
    {
      title: t('contracts_index.confirm.title'),
      message: t('contracts_index.confirm.message', { number: contract.contract_number }),
      confirmText: t('contracts_index.confirm.confirm_text'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { router.delete(route('contracts.destroy', contract.uuid), { preserveScroll: true }) },
  )
}

function money(v: number | null) {
  return v == null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(v)
}

const statusColor: Record<string, string> = {
  draft: 'grey', published: 'info', pending: 'warning', active: 'success',
  expired: 'error', renewed: 'primary', terminated: 'error', cancelled: 'grey',
}

function riskColor(v: number | null) {
  if (v == null) return 'grey'
  return v >= 67 ? 'error' : (v >= 34 ? 'warning' : 'success')
}

const statusItems = [
  { title: '', value: '' },
  ...props.statuses.map(s => ({ title: s, value: s })),
]
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-file-text" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('contracts_index.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_index.description') }}</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-trash" @click="router.get(route('contracts.trash'))">
          {{ t('contracts_index.trash') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="router.get(route('contracts.create'))">
          {{ t('contracts_index.new_contract') }}
        </VBtn>
      </div>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          :placeholder="t('contracts_index.filters.search_placeholder')"
          prepend-inner-icon="tabler-search"
          style="max-width:260px"
          @keyup.enter="applyFilters"
          clearable
          @click:clear="applyFilters"
        />
        <VSelect
          v-model="statusFilter"
          :items="statusItems"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('contracts_index.filters.status_label')"
          style="max-width:180px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="typeFilter"
          :items="types"
          item-title="name"
          item-value="id"
          clearable
          density="compact"
          variant="outlined"
          hide-details
          :label="t('contracts_index.filters.type_label')"
          style="max-width:180px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="categoryFilter"
          :items="categories"
          item-title="name"
          item-value="id"
          clearable
          density="compact"
          variant="outlined"
          hide-details
          :label="t('contracts_index.filters.category_label')"
          style="max-width:180px"
          @update:model-value="applyFilters"
        />
        <VBtn variant="tonal" @click="applyFilters">{{ t('contracts_index.filters.filter') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('contracts_index.table.number') }}</th>
            <th>{{ t('contracts_index.table.title') }}</th>
            <th>{{ t('contracts_index.table.type') }}</th>
            <th>{{ t('contracts_index.table.status') }}</th>
            <th>{{ t('contracts_index.table.ai_risk') }}</th>
            <th class="text-right">{{ t('contracts_index.table.value') }}</th>
            <th>{{ t('contracts_index.table.end') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!contracts.data.length">
            <td colspan="8" class="text-center text-medium-emphasis py-8">{{ t('contracts_index.no_contracts') }}</td>
          </tr>
          <tr v-for="contract in contracts.data" :key="contract.id">
            <td class="font-weight-medium" style="cursor:pointer" @click="router.get(route('contracts.show', contract.uuid))">
              {{ contract.contract_number }}
            </td>
            <td>{{ contract.title }}</td>
            <td>{{ contract.type ?? '—' }}</td>
            <td>
              <VChip size="small" :color="statusColor[contract.contract_status]" variant="tonal">
                {{ contract.contract_status }}
              </VChip>
            </td>
            <td>
              <VChip v-if="contract.risk_score != null" size="small" :color="riskColor(contract.risk_score)" variant="flat">
                {{ contract.risk_score }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-right">{{ money(contract.contract_value) }}</td>
            <td>{{ contract.end_date ?? '—' }}</td>
            <td class="text-right">
              <VBtn icon="tabler-eye" size="small" variant="text" @click="router.get(route('contracts.show', contract.uuid))" />
              <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="destroy(contract)" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
