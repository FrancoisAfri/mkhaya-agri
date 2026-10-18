<script setup lang="ts">
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Simulation {
  id: number; uuid: string; name: string; simulation_type: string
  total_current_cost: number; total_simulated_cost: number
  difference: number; percentage_change: number
  status: string; created_at: string
}
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

defineProps<{
  simulations: Page<Simulation>
}>()

const typeColor = (type: string) => ({
  salary_increase: 'primary', bonus: 'success', new_hire: 'teal',
  overtime: 'orange', allowance: 'info', union_increase: 'deep-purple', annual_budget: 'brown',
}[type] ?? 'grey')

const statusColor = (s: string) => ({ draft: 'grey', completed: 'success', failed: 'error' }[s] ?? 'grey')
const fmt = (n: number) => 'R' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

function formatDate(date: string): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit' })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_simulations_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_simulations_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" :href="route('payroll.simulations.create')">
        {{ t('payroll_simulations_index.new_simulation') }}
      </VBtn>
    </div>

    <VCard elevation="0" border>
      <VDataTableServer
        :items="simulations.data"
        :items-length="simulations.total"
        :headers="[
          { title: t('payroll_simulations_index.table.name'), key: 'name' },
          { title: t('payroll_simulations_index.table.type'), key: 'simulation_type' },
          { title: t('payroll_simulations_index.table.current_cost'), key: 'total_current_cost' },
          { title: t('payroll_simulations_index.table.simulated_cost'), key: 'total_simulated_cost' },
          { title: t('payroll_simulations_index.table.difference'), key: 'difference' },
          { title: t('payroll_simulations_index.table.change'), key: 'percentage_change' },
          { title: t('payroll_simulations_index.table.status'), key: 'status' },
          { title: t('payroll_simulations_index.table.created'), key: 'created_at' },
        ]"
        hide-default-footer
      >
        <template #item.simulation_type="{ item }">
          <VChip size="x-small" :color="typeColor(item.simulation_type)" variant="tonal">
            {{ item.simulation_type.replace(/_/g, ' ') }}
          </VChip>
        </template>
        <template #item.total_current_cost="{ item }">
          {{ fmt(item.total_current_cost) }}
        </template>
        <template #item.total_simulated_cost="{ item }">
          <span class="font-weight-medium">{{ fmt(item.total_simulated_cost) }}</span>
        </template>
        <template #item.difference="{ item }">
          <span :class="item.difference > 0 ? 'text-error' : 'text-success'" class="font-weight-medium">
            {{ item.difference > 0 ? '+' : '' }}{{ fmt(item.difference) }}
          </span>
        </template>
        <template #item.percentage_change="{ item }">
          <VChip size="x-small" :color="item.percentage_change < 0 ? 'success' : 'error'" variant="tonal">
            {{ item.percentage_change > 0 ? '+' : '' }}{{ Number(item.percentage_change).toFixed(1) }}%
          </VChip>
        </template>
        <template #item.status="{ item }">
          <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
        </template>
        <template #item.created_at="{ item }">
          <span class="text-caption">{{ formatDate(item.created_at) }}</span>
        </template>
      </VDataTableServer>
    </VCard>
  </div>
</template>
