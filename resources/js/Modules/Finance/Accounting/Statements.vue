<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Col { key: string; label: string; align: string }
const props = defineProps<{
  tabs: { value: string; label: string }[]
  active: string
  range: { from: string; to: string }
  report: { tab: string; title: string; columns: Col[]; rows: any[]; totals: any | null }
}>()

const rng = ref({ ...props.range })
const money = (v: any) => isNaN(Number(v)) || v === '' || v === null ? v : Number(v).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const cell = (row: any, c: Col) => c.align === 'right' ? money(row[c.key]) : (row[c.key] ?? '')

function go(tab: string) { router.get(route('finance.accounting.statements'), { tab, from: rng.value.from, to: rng.value.to }, { preserveState: false }) }
function apply() { go(props.active) }
function download(format: string) { window.open(route('finance.accounting.statements.export', { tab: props.active, from: rng.value.from, to: rng.value.to, format }), '_blank') }
function print() { window.print() }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-3 flex-wrap gap-3 no-print">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_reporting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.financial_statements') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.statements_sub') }}</div>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VTextField v-model="rng.from" type="date" density="compact" variant="outlined" hide-details style="max-width: 160px" />
        <VTextField v-model="rng.to" type="date" density="compact" variant="outlined" hide-details style="max-width: 160px" />
        <VBtn color="primary" prepend-icon="tabler-refresh" @click="apply">{{ t('acc.apply') }}</VBtn>
        <VBtn color="error" variant="tonal" prepend-icon="tabler-file-type-pdf" @click="download('pdf')">PDF</VBtn>
        <VBtn color="success" variant="tonal" prepend-icon="tabler-file-spreadsheet" @click="download('xlsx')">Excel</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-printer" @click="print">{{ t('acc.print') }}</VBtn>
      </div>
    </div>

    <VCard border>
      <VTabs :model-value="active" show-arrows density="compact" class="no-print" @update:model-value="go($event as string)">
        <VTab v-for="t in tabs" :key="t.value" :value="t.value">{{ t.label }}</VTab>
      </VTabs>
      <VDivider />
      <div class="pa-4">
        <h3 class="text-subtitle-1 mb-1">{{ report.title }}</h3>
        <div class="text-caption text-medium-emphasis mb-3">{{ range.from }} → {{ range.to }}</div>
        <div style="overflow-x: auto">
          <VTable density="compact">
            <thead>
              <tr><th v-for="c in report.columns" :key="c.key" :class="c.align === 'right' ? 'text-right' : ''">{{ c.label }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in report.rows" :key="i"
                  :class="[row.group ? 'group-row' : '', row.subtotal ? 'subtotal-row' : '']">
                <td v-for="c in report.columns" :key="c.key" :class="c.align === 'right' ? 'text-right' : ''">{{ cell(row, c) }}</td>
              </tr>
              <tr v-if="!report.rows.length"><td :colspan="report.columns.length" class="text-center text-medium-emphasis py-6">{{ t('acc.no_data_period') }}</td></tr>
            </tbody>
            <tfoot v-if="report.totals">
              <tr class="totals-row">
                <td v-for="c in report.columns" :key="c.key" :class="c.align === 'right' ? 'text-right' : ''">{{ cell(report.totals, c) }}</td>
              </tr>
            </tfoot>
          </VTable>
        </div>
      </div>
    </VCard>
  </div>
</template>

<style scoped>
.group-row td { font-weight: 700; text-transform: uppercase; font-size: 0.72rem; color: rgb(var(--v-theme-primary)); padding-top: 10px; }
.subtotal-row td { font-weight: 600; background: rgba(var(--v-theme-primary), 0.04); }
.totals-row td { font-weight: 700; border-top: 2px solid rgba(var(--v-border-color), 0.3); }
@media print { .no-print { display: none !important; } }
</style>
