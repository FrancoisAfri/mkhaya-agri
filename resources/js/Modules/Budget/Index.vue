<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Budget = {
  id: number; code: string; name: string; financial_year: string | null
  category: string | null; expenditure_type: string | null
  scope_type: string | null; scope_label: string | null; currency: string | null
  status: string; allocated: number; available: number
  reserved: number; committed: number; actual: number
}

const props = defineProps<{
  budgets: { data: Budget[]; links: any[]; meta: any }
  filters: {
    search?: string; status?: string; financial_year_id?: number
    expenditure_type?: string; scope_type?: string
  }
  financialYears: { id: number; name: string }[]
  statuses: string[]
}>()

const confirmDialog = ref()
const search = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')
const fyFilter = ref(props.filters.financial_year_id ?? null)
const typeFilter = ref(props.filters.expenditure_type ?? '')
const scopeFilter = ref(props.filters.scope_type ?? '')

const expenditureTypes = ['capex', 'opex']
const scopeTypes = ['department', 'project', 'category', 'general']

let timeoutId: ReturnType<typeof setTimeout>

function applyFilters() {
  clearTimeout(timeoutId)
  timeoutId = setTimeout(() => {
    router.get(route('budgets.index'), {
      search: search.value || undefined,
      status: statusFilter.value || undefined,
      financial_year_id: fyFilter.value || undefined,
      expenditure_type: typeFilter.value || undefined,
      scope_type: scopeFilter.value || undefined,
    }, { preserveState: true, replace: true })
  }, 300)
}

function destroy(budget: Budget) {
  confirmDialog.value.open(
    {
      title: t('budgets_index.confirm.title'),
      message: t('budgets_index.confirm.message', { code: budget.code }),
      confirmText: t('budgets_index.confirm.confirm_text'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => router.delete(route('budgets.destroy', budget.uuid), { preserveScroll: true }),
  )
}

function money(v: number | null, currency?: string | null) {
  return v == null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency: currency ?? 'ZAR' }).format(v)
}
const statusColor: Record<string, string> = {
  draft: 'grey', pending: 'warning', approved: 'success', active: 'success',
  closed: 'grey', rejected: 'error',
}

const statusItems = [
  { title: '', value: '' },
  ...props.statuses.map(s => ({ title: s, value: s })),
]

const typeItems = [
  { title: '', value: '' },
  ...expenditureTypes.map(type => ({ title: type, value: type })),
]

const scopeItems = [
  { title: '', value: '' },
  ...scopeTypes.map(scope => ({ title: scope, value: scope })),
]
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-wallet" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('budgets_index.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('budgets_index.description') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.get(route('budgets.create'))">
        {{ t('budgets_index.new_budget') }}
      </VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="search"
          density="compact"
          variant="outlined"
          hide-details
          :placeholder="t('budgets_index.filters.search_placeholder')"
          prepend-inner-icon="tabler-search"
          style="max-width:240px"
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
          :label="t('budgets_index.filters.status_label')"
          style="max-width:170px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="fyFilter"
          :items="financialYears"
          item-title="name"
          item-value="id"
          clearable
          density="compact"
          variant="outlined"
          hide-details
          :label="t('budgets_index.filters.financial_year_label')"
          style="max-width:190px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="typeFilter"
          :items="typeItems"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('budgets_index.filters.type_label')"
          style="max-width:150px"
          @update:model-value="applyFilters"
        />
        <VSelect
          v-model="scopeFilter"
          :items="scopeItems"
          density="compact"
          variant="outlined"
          hide-details
          :label="t('budgets_index.filters.scope_label')"
          style="max-width:160px"
          @update:model-value="applyFilters"
        />
        <VBtn variant="tonal" @click="applyFilters">{{ t('budgets_index.filters.filter') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('budgets_index.table.code') }}</th>
            <th>{{ t('budgets_index.table.name') }}</th>
            <th>{{ t('budgets_index.table.fy') }}</th>
            <th>{{ t('budgets_index.table.type') }}</th>
            <th>{{ t('budgets_index.table.scope') }}</th>
            <th class="text-right">{{ t('budgets_index.table.allocated') }}</th>
            <th class="text-right">{{ t('budgets_index.table.reserved') }}</th>
            <th class="text-right">{{ t('budgets_index.table.committed') }}</th>
            <th class="text-right">{{ t('budgets_index.table.actual') }}</th>
            <th class="text-right">{{ t('budgets_index.table.available') }}</th>
            <th>{{ t('budgets_index.table.status') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!budgets.data.length">
            <td colspan="12" class="text-center text-medium-emphasis py-8">{{ t('budgets_index.no_budgets') }}</td>
          </tr>
          <tr v-for="budget in budgets.data" :key="budget.id">
            <td class="font-weight-medium" style="cursor:pointer" @click="router.get(route('budgets.show', budget.uuid))">
              {{ budget.code }}
            </td>
            <td>{{ budget.name }}</td>
            <td>{{ budget.financial_year ?? '—' }}</td>
            <td>
              <VChip v-if="budget.expenditure_type" size="small" variant="tonal">{{ budget.expenditure_type }}</VChip>
              <span v-else>—</span>
            </td>
            <td>{{ budget.scope_label ?? budget.scope_type ?? '—' }}</td>
            <td class="text-right">{{ money(budget.allocated, budget.currency) }}</td>
            <td class="text-right">{{ money(budget.reserved, budget.currency) }}</td>
            <td class="text-right">{{ money(budget.committed, budget.currency) }}</td>
            <td class="text-right">{{ money(budget.actual, budget.currency) }}</td>
            <td class="text-right" :class="budget.available < 0 ? 'text-error font-weight-medium' : ''">
              {{ money(budget.available, budget.currency) }}
            </td>
            <td>
              <VChip size="small" :color="statusColor[budget.status]" variant="tonal">{{ budget.status }}</VChip>
            </td>
            <td class="text-right">
              <VBtn icon="tabler-eye" size="small" variant="text" @click="router.get(route('budgets.show', budget.uuid))" />
              <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="destroy(budget)" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
