<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Month = { id: string; year: number; month: number; amount: string; cumulative: string }

const props = defineProps<{
  months: Month[]
  total: string
}>()

function money(amount: string | null | undefined) {
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(Number(amount))
  } catch {
    return String(amount)
  }
}

/** The client's sheet labels months by name and year — "March 2026", not "2026-03". */
function monthLabel(year: number, month: number) {
  return new Date(year, month - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.cards.invoice_cashflow.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t('project_management.cards.invoice_cashflow.subtitle') }}
      </p>
    </div>

    <VCard border>
      <VTable v-if="props.months.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.invoicing.col_month') }}</th>
            <!-- VAT-inclusive, as the client's own cashflow sheet has it. -->
            <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_amount_incl_vat') }}</th>
            <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_cumulative') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.months" :key="row.id">
            <td class="text-no-wrap">{{ monthLabel(row.year, row.month) }}</td>
            <td class="text-end text-no-wrap">{{ money(row.amount) }}</td>
            <td class="text-end text-no-wrap text-medium-emphasis">{{ money(row.cumulative) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <th>{{ t('project_management.invoicing.col_total') }}</th>
            <th class="text-end text-no-wrap">{{ money(props.total) }}</th>
            <th />
          </tr>
        </tfoot>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.invoicing.no_cashflow') }}
      </VCardText>
    </VCard>
  </div>
</template>
