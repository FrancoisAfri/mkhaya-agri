<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Schedule = {
  uuid: string; depreciation_method: { value: string; label: string }
  useful_life_years: number; purchase_cost: string; salvage_value: string | null
  total_periods: number; generated_at: string; is_active: boolean
  asset: { uuid: string; name: string; asset_tag: string | null } | null
  generatedBy: { name: string } | null
  latestPostedEntry: { period_sequence: number; closing_book_value: string } | null
}

const props = defineProps<{
  schedules: { data: Schedule[]; meta: { current_page: number; last_page: number; total: number }; links: { prev: string | null; next: string | null } }
}>()

function formatCurrency(val: string | null) { if (val == null) return '—'; return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(Number(val)) }
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_depreciation_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_depreciation_index.description') }}</p>
      </div>
    </div>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_depreciation_index.table.asset') }}</th>
            <th>{{ t('assets_depreciation_index.table.method') }}</th>
            <th class="text-right">{{ t('assets_depreciation_index.table.purchase_cost') }}</th>
            <th class="text-right">{{ t('assets_depreciation_index.table.salvage') }}</th>
            <th class="text-center">{{ t('assets_depreciation_index.table.periods') }}</th>
            <th class="text-center">{{ t('assets_depreciation_index.table.posted') }}</th>
            <th class="text-right">{{ t('assets_depreciation_index.table.book_value') }}</th>
            <th>{{ t('assets_depreciation_index.table.generated') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="schedule in schedules.data" :key="schedule.uuid" class="cursor-pointer" @click="schedule.asset && router.visit(route('assets.depreciation.show', schedule.asset.uuid))">
            <td><p class="mb-0 font-weight-medium">{{ schedule.asset?.name ?? '—' }}</p><p class="text-caption text-medium-emphasis mb-0">{{ schedule.asset?.asset_tag }}</p></td>
            <td>{{ schedule.depreciation_method.label }}</td>
            <td class="text-right">{{ formatCurrency(schedule.purchase_cost) }}</td>
            <td class="text-right">{{ formatCurrency(schedule.salvage_value) }}</td>
            <td class="text-center">{{ schedule.total_periods }}</td>
            <td class="text-center">{{ schedule.latestPostedEntry?.period_sequence ?? 0 }}</td>
            <td class="text-right">{{ schedule.latestPostedEntry ? formatCurrency(schedule.latestPostedEntry.closing_book_value) : formatCurrency(schedule.purchase_cost) }}</td>
            <td>{{ formatDate(schedule.generated_at) }}</td>
          </tr>
          <tr v-if="!schedules.data.length"><td colspan="8" class="text-center text-medium-emphasis py-8">{{ t('assets_depreciation_index.empty') }}</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="schedules.meta.last_page > 1" class="d-flex justify-center gap-2 pt-2">
        <VBtn size="small" variant="tonal" :disabled="!schedules.links.prev" @click="router.visit(schedules.links.prev!)">{{ t('assets_depreciation_index.pagination.prev') }}</VBtn>
        <span class="text-body-2 align-self-center">{{ t('assets_depreciation_index.pagination.page', { current: schedules.meta.current_page, last: schedules.meta.last_page }) }}</span>
        <VBtn size="small" variant="tonal" :disabled="!schedules.links.next" @click="router.visit(schedules.links.next!)">{{ t('assets_depreciation_index.pagination.next') }}</VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
