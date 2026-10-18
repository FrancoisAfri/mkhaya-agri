<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface SimulationItem {
  id: number; uuid: string; employee_id: number | null; employee_name: string | null
  department_id: number | null; current_value: number; simulated_value: number
  difference: number; percentage_change: number; item_type: string; notes: string | null
}
interface BaseRun {
  uuid: string; ref_number: string; name: string
  period_start: string; period_end: string; total_net: number
}
interface Creator { id: number; name: string }
interface Simulation {
  id: number; uuid: string; name: string; description: string | null
  simulation_type: string; status: string; notes: string | null
  parameters: Record<string, any> | null
  total_current_cost: number; total_simulated_cost: number
  total_difference: number; percentage_change: number
  base_run_id: number | null; created_at: string; updated_at: string
  created_by: Creator | null; base_run: BaseRun | null; items: SimulationItem[]
}

defineProps<{ simulation: Simulation }>()

const fmt = (n: number) => `R ${Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const fmtDate = (d: string) => new Date(d).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })

const typeLabel = (type: string) => t(`payroll_simulations_create.simulation_types.${type}`)

const typeColor = (type: string) => ({
  salary_increase: 'primary', bonus: 'success', new_hire: 'teal',
  overtime: 'orange', allowance: 'info', union_increase: 'deep-purple', annual_budget: 'brown',
}[type] ?? 'grey')

const statusColor = (s: string) => ({ draft: 'secondary', saved: 'success', archived: 'info' }[s] ?? 'secondary')

const diffColor = (d: number) => d > 0 ? 'text-error' : d < 0 ? 'text-success' : ''
const pctColor  = (p: number) => p > 5 ? 'error' : p > 0 ? 'warning' : 'success'
const pctFmt    = (v: number) => `${v > 0 ? '+' : ''}${Number(v).toFixed(2)}%`
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 1500px;">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the simulations" class="row-action" size="small" variant="text" @click="router.visit(route('payroll.simulations.index'))" />
      <div class="flex-grow-1">
        <h4 class="text-h4">{{ simulation.name }}</h4>
        <p class="text-medium-emphasis mb-0">
          {{ typeLabel(simulation.simulation_type) }}
          <span v-if="simulation.created_by"> · {{ t('payroll_simulations_show.created_by', { name: simulation.created_by.name, date: fmtDate(simulation.created_at) }) }}</span>
        </p>
      </div>
      <VChip :color="typeColor(simulation.simulation_type)" variant="tonal" class="me-1">
        {{ typeLabel(simulation.simulation_type) }}
      </VChip>
      <VChip :color="statusColor(simulation.status)" variant="tonal" class="text-capitalize">
        {{ simulation.status }}
      </VChip>
    </div>

    <VRow class="mb-5">
      <VCol cols="12" sm="3">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('payroll_simulations_show.summary.current_cost') }}</p>
            <h5 class="text-h5">{{ fmt(simulation.total_current_cost) }}</h5>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="3">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('payroll_simulations_show.summary.simulated_cost') }}</p>
            <h5 class="text-h5 text-primary">{{ fmt(simulation.total_simulated_cost) }}</h5>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="3">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('payroll_simulations_show.summary.difference') }}</p>
            <h5 class="text-h5" :class="diffColor(simulation.total_difference)">
              {{ simulation.total_difference > 0 ? '+' : '' }}{{ fmt(simulation.total_difference) }}
            </h5>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="3">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('payroll_simulations_show.summary.change') }}</p>
            <VChip size="small" :color="pctColor(simulation.percentage_change)" variant="tonal" class="text-h6 font-weight-bold">
              {{ pctFmt(simulation.percentage_change) }}
            </VChip>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-5">
        <p class="text-caption text-medium-emphasis mb-2">{{ t('payroll_simulations_show.cost_comparison') }}</p>
        <div class="mb-3">
          <div class="d-flex justify-space-between mb-1">
            <span class="text-body-2">{{ t('payroll_simulations_show.current') }}</span>
            <span class="text-body-2 font-weight-semibold">{{ fmt(simulation.total_current_cost) }}</span>
          </div>
          <VProgressLinear :model-value="100" color="secondary" height="10" rounded />
        </div>
        <div>
          <div class="d-flex justify-space-between mb-1">
            <span class="text-body-2">{{ t('payroll_simulations_show.simulated') }}</span>
            <span class="text-body-2 font-weight-semibold text-primary">{{ fmt(simulation.total_simulated_cost) }}</span>
          </div>
          <VProgressLinear
            :model-value="simulation.total_current_cost > 0 ? (simulation.total_simulated_cost / simulation.total_current_cost) * 100 : 100"
            :color="simulation.total_difference >= 0 ? 'error' : 'success'"
            height="10" rounded
          />
        </div>
      </VCardText>
    </VCard>

    <VCard v-if="simulation.base_run" elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('payroll_simulations_show.base_run') }}</VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <VRow dense>
          <VCol cols="6" sm="3">
            <p class="text-caption text-medium-emphasis mb-0">{{ t('payroll_simulations_show.base_run_ref') }}</p>
            <p class="text-body-2 font-weight-semibold">{{ simulation.base_run.ref_number }}</p>
          </VCol>
          <VCol cols="6" sm="3">
            <p class="text-caption text-medium-emphasis mb-0">{{ t('payroll_simulations_show.base_run_name') }}</p>
            <p class="text-body-2">{{ simulation.base_run.name }}</p>
          </VCol>
          <VCol cols="6" sm="3">
            <p class="text-caption text-medium-emphasis mb-0">{{ t('payroll_simulations_show.base_run_period') }}</p>
            <p class="text-body-2">{{ simulation.base_run.period_start }} – {{ simulation.base_run.period_end }}</p>
          </VCol>
          <VCol cols="6" sm="3">
            <p class="text-caption text-medium-emphasis mb-0">{{ t('payroll_simulations_show.base_run_net') }}</p>
            <p class="text-body-2 font-weight-semibold text-success">{{ fmt(simulation.base_run.total_net) }}</p>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard v-if="simulation.parameters && Object.keys(simulation.parameters).length" elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('payroll_simulations_show.parameters_title') }}</VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <div class="d-flex flex-wrap gap-2">
          <VChip v-for="(val, key) in simulation.parameters" :key="key" size="small" color="blue-grey" variant="tonal">
            <span class="font-weight-medium text-capitalize">{{ String(key).replace(/_/g, ' ') }}:</span>&nbsp;{{ val }}
          </VChip>
        </div>
      </VCardText>
    </VCard>

    <VCard v-if="simulation.description || simulation.notes" elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('payroll_simulations_show.notes_title') }}</VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <p v-if="simulation.description" class="text-body-2 mb-2">{{ simulation.description }}</p>
        <p v-if="simulation.notes" class="text-body-2 text-medium-emphasis mb-0" style="white-space: pre-line;">{{ simulation.notes }}</p>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
        {{ t('payroll_simulations_show.cost_breakdown') }}
        <span class="text-caption text-medium-emphasis font-weight-regular ms-2">{{ t('payroll_simulations_show.items_count', { count: simulation.items.length }) }}</span>
      </VCardTitle>
      <VDivider />
      <p v-if="!simulation.items.length" class="text-center text-medium-emphasis text-body-2 py-8">
        {{ t('payroll_simulations_show.no_items') }}
      </p>
      <VDataTable v-else :items="simulation.items"
        :headers="[
          { title: t('payroll_simulations_show.table.employee_subject'), key: 'employee_name', sortable: false },
          { title: t('payroll_simulations_show.table.current'), key: 'current_value', sortable: false },
          { title: t('payroll_simulations_show.table.simulated'), key: 'simulated_value', sortable: false },
          { title: t('payroll_simulations_show.table.difference'), key: 'difference', sortable: false },
          { title: t('payroll_simulations_show.table.change'), key: 'percentage_change', sortable: false },
          { title: t('payroll_simulations_show.table.notes'), key: 'notes', sortable: false },
        ]"
        density="comfortable" hide-default-footer :items-per-page="-1"
      >
        <template #item.employee_name="{ item }">
          <span class="text-body-2 font-weight-medium">
            {{ item.employee_name ?? (item.employee_id ? `#${item.employee_id}` : t('payroll_simulations_show.all_employees')) }}
          </span>
        </template>
        <template #item.current_value="{ item }">
          <span class="text-body-2">{{ fmt(item.current_value) }}</span>
        </template>
        <template #item.simulated_value="{ item }">
          <span class="text-body-2 font-weight-medium text-primary">{{ fmt(item.simulated_value) }}</span>
        </template>
        <template #item.difference="{ item }">
          <span class="text-body-2 font-weight-medium" :class="diffColor(item.difference)">
            {{ item.difference > 0 ? '+' : '' }}{{ fmt(item.difference) }}
          </span>
        </template>
        <template #item.percentage_change="{ item }">
          <VChip size="x-small" :color="pctColor(item.percentage_change)" variant="tonal">
            {{ pctFmt(item.percentage_change) }}
          </VChip>
        </template>
        <template #item.notes="{ item }">
          <span class="text-caption text-medium-emphasis" style="max-width: 280px; white-space: normal; display: block;">
            {{ item.notes ?? '—' }}
          </span>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
