<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import RowMenu from '../Components/RowMenu.vue'
import { human, money, num, today } from '../Components/agri'

const props = defineProps<{
  farm: null | { id: string; name: string; currency: string }
  farms: Array<{ id: string; name: string }>
  period: { from: string; to: string }
  report: any; summary: any[]; transactions: any[]; budget: any[]
  options: { enterprises: Array<{ value: string; title: string }>; expense_categories: string[]; sale_categories: string[]; budget_categories: string[]; seasons: Array<{ id: number; name: string }> }
  can: { manage: boolean }
}>()
const dialog = ref<null | string>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const from = ref(props.period.from)
const to = ref(props.period.to)
const apply = () => router.get(route('agri.finance.index'), { farm: props.farm?.id, from: from.value, to: to.value }, { preserveState: false })
const cur = computed(() => props.farm?.currency ?? 'ZAR')
const m = (n: number | null | undefined) => money(n, cur.value)
function reverse(t: any) {
  const reason = prompt('Why is this transaction being reversed? The original and the reversal both stay in the books.')
  if (reason) router.post(route('agri.finance.transactions.reverse', t.id), { reason }, { preserveScroll: true })
}
const removeBudget = (b: any) => confirm('Remove this budget line?') && router.delete(route('agri.finance.budgets.destroy', b.id), { preserveScroll: true })
const exportUrl = computed(() => route('agri.finance.export', { farm: props.farm?.id, from: from.value, to: to.value }))
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Farm Finance</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Income, costs and margin per farm, crop and herd — read from the company ledger, so it always agrees with the books.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
        <VTextField v-model="from" type="date" label="From" density="compact" hide-details style="max-width: 160px" />
        <VTextField v-model="to" type="date" label="To" density="compact" hide-details style="max-width: 160px" />
        <VBtn variant="tonal" @click="apply">Apply</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-chart-dots" :href="route('agri.finance.intelligence.home', { farm: farm?.id, from, to })">Budgets, cash flow &amp; reports</VBtn>
        <VMenu v-if="farm">
          <template #activator="{ props: p }"><VBtn v-bind="p" color="primary" prepend-icon="tabler-plus">Record</VBtn></template>
          <VList density="compact">
            <VListItem v-if="can.manage" prepend-icon="tabler-receipt" title="Expense" @click="dialog = 'expense'" />
            <VListItem v-if="can.manage" prepend-icon="tabler-cash" title="Sale" @click="dialog = 'sale'" />
            <VListItem v-if="can.manage" prepend-icon="tabler-target" title="Budget line" @click="dialog = 'budget'" />
            <VListItem prepend-icon="tabler-download" title="Export profitability (CSV)" :href="exportUrl" />
          </VList>
        </VMenu>
      </div>
    </div>

    <template v-if="report">
      <VRow class="mb-2">
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Income</div><div class="text-h5">{{ m(report.totals.income) }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Costs (ledger)</div><div class="text-h5">{{ m(report.totals.costs) }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Gross margin</div><div class="text-h5" :class="report.totals.gross_margin < 0 ? 'text-error' : 'text-success'">{{ m(report.totals.gross_margin) }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">After labour &amp; machine time</div><div class="text-h5">{{ m(report.totals.margin_after_estimates) }}</div>
          <div class="text-caption">{{ m(report.totals.estimated_costs) }} estimated, not posted</div></VCardText></VCard></VCol>
      </VRow>

      <VCard title="Profitability by enterprise" class="mb-4">
        <VTable density="comfortable">
          <thead><tr><th>Enterprise</th><th class="text-end">Size</th><th class="text-end">Income</th><th class="text-end">Costs</th><th class="text-end">Gross margin</th><th class="text-end">Per ha</th><th class="text-end">Budget</th><th class="text-end">Variance</th></tr></thead>
          <tbody>
            <tr v-for="e in report.enterprises" :key="e.key">
              <td><VIcon :icon="e.kind === 'crop' ? 'tabler-plant' : e.kind === 'livestock' ? 'tabler-paw' : 'tabler-building-warehouse'" size="18" class="me-1" />{{ e.name }}</td>
              <td class="text-end">{{ e.hectares ? num(e.hectares) + ' ha' : e.head !== null ? num(e.head, 0) + ' head' : '' }}</td>
              <td class="text-end">{{ m(e.income) }}</td><td class="text-end">{{ m(e.costs) }}</td>
              <td class="text-end font-weight-medium" :class="e.gross_margin < 0 ? 'text-error' : ''">{{ m(e.gross_margin) }}</td>
              <td class="text-end">{{ e.margin_per_ha !== null ? m(e.margin_per_ha) : '—' }}</td>
              <td class="text-end">{{ e.budget_costs ? m(e.budget_costs) : '—' }}</td>
              <td class="text-end" :class="(e.budget_variance ?? 0) < 0 ? 'text-error' : 'text-success'">{{ e.budget_variance !== null ? m(e.budget_variance) : '—' }}</td>
            </tr>
            <tr v-if="!report.enterprises.length"><td colspan="8" class="text-center text-medium-emphasis py-6">Nothing posted against this farm in the period.</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <VRow>
      <VCol cols="12" md="7">
        <VCard title="Farm transactions">
          <VTable density="compact">
            <thead><tr><th>Date</th><th>Description</th><th>Category</th><th class="text-end">Amount</th><th /></tr></thead>
            <tbody>
              <tr v-for="t in transactions" :key="t.id">
                <td>{{ t.date }}</td>
                <td :class="t.reversed ? 'text-decoration-line-through text-disabled' : ''">{{ t.description }}
                  <div class="text-caption">{{ t.counterparty }}<template v-if="t.quantity"> · {{ num(t.quantity) }} {{ t.unit }}</template><template v-if="t.from_store"> · from store</template><template v-if="t.journal_id"> · posted</template></div>
                  <VChip v-if="t.reversed" size="x-small" color="secondary" class="mt-1">Reversed: {{ t.reversal_reason }}</VChip></td>
                <td>{{ human(t.category) }}</td>
                <td class="text-end" :class="t.reversed ? 'text-disabled' : t.kind === 'sale' ? 'text-success' : ''">{{ t.kind === 'sale' ? '+' : '−' }}{{ m(t.amount) }}</td>
                <td class="text-end"><RowMenu :actions="[{ title: 'Reverse (entered in error)', icon: 'tabler-arrow-back-up', color: 'error', show: can.manage && !t.reversed && !t.from_store, onClick: () => reverse(t) }]" /></td>
              </tr>
              <tr v-if="!transactions.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No farm expenses or sales entered here. Stock used, fuel, services and livestock sales post themselves.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard title="Budget" class="mb-4">
          <VList v-if="budget.length" density="compact">
            <VListItem v-for="b in budget" :key="b.id" :title="`${human(b.category)}${b.description ? ' — ' + b.description : ''}`" :subtitle="`${b.period_start} → ${b.period_end}`">
              <template #append><span class="me-2">{{ m(b.amount) }}</span><VBtn v-if="can.manage" icon size="x-small" variant="text" @click="removeBudget(b)"><VIcon icon="tabler-x" /></VBtn></template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">No budget lines. Crop plans and herds can also carry a budget of their own.</VCardText>
        </VCard>
        <VCard title="All farms (period)">
          <VList density="compact">
            <VListItem v-for="s in summary" :key="s.id" :title="s.farm" :href="route('agri.finance.index', { farm: s.id, from: period.from, to: period.to })">
              <template #append><span :class="s.gross_margin < 0 ? 'text-error' : ''">{{ m(s.gross_margin) }}</span></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <template v-if="farm">
      <FormDialog :model-value="dialog === 'expense'" title="Farm expense" :url="route('agri.finance.transactions.store')" :values="{ farm_id: farm.id, kind: 'expense', transacted_on: today(), settled_by: 'bank' }"
        :fields="[
          { key: 'description', label: 'Description', required: true, cols: 12 },
          { key: 'category', label: 'Category', type: 'select', required: true, items: options.expense_categories },
          { key: 'enterprise', label: 'Crop or herd', type: 'select', items: options.enterprises, hint: 'Blank = the whole farm' },
          { key: 'amount', label: 'Amount', type: 'number', required: true, cols: 4 },
          { key: 'transacted_on', label: 'Date', type: 'date', required: true, cols: 4 },
          { key: 'settled_by', label: 'Paid', type: 'select', items: [{ title: 'From the bank', value: 'bank' }, { title: 'Owed to the supplier', value: 'payable' }], cols: 4 },
          { key: 'counterparty', label: 'Supplier' }, { key: 'reference', label: 'Invoice / reference' },
        ]" intro="Posts to the company ledger with this farm (and crop or herd) on the line." @update:model-value="close" />
      <FormDialog :model-value="dialog === 'sale'" title="Farm sale" :url="route('agri.finance.transactions.store')" :values="{ farm_id: farm.id, kind: 'sale', transacted_on: today(), settled_by: 'receivable', category: 'produce' }"
        :fields="[
          { key: 'description', label: 'Description', required: true, cols: 12 },
          { key: 'category', label: 'What was sold', type: 'select', required: true, items: options.sale_categories },
          { key: 'enterprise', label: 'Crop or herd', type: 'select', items: options.enterprises },
          { key: 'quantity', label: 'Quantity', type: 'number', cols: 3 }, { key: 'unit', label: 'Unit', cols: 3 },
          { key: 'amount', label: 'Amount', type: 'number', required: true, cols: 3 },
          { key: 'transacted_on', label: 'Date', type: 'date', required: true, cols: 3 },
          { key: 'settled_by', label: 'Paid', type: 'select', items: [{ title: 'Received in the bank', value: 'bank' }, { title: 'Owed by the buyer', value: 'receivable' }] },
          { key: 'counterparty', label: 'Buyer' },
        ]" @update:model-value="close" />
      <FormDialog :model-value="dialog === 'budget'" title="Budget line" :url="route('agri.finance.budgets.store')"
        :values="{ farm_id: farm.id, period_start: period.from, period_end: `${new Date().getFullYear()}-12-31` }"
        :fields="[
          { key: 'category', label: 'Category', type: 'select', required: true, items: options.budget_categories },
          { key: 'enterprise', label: 'Crop or herd', type: 'select', items: options.enterprises },
          { key: 'amount', label: 'Amount', type: 'number', required: true }, { key: 'description', label: 'Description' },
          { key: 'period_start', label: 'From', type: 'date', required: true }, { key: 'period_end', label: 'To', type: 'date', required: true },
        ]" @update:model-value="close" />
    </template>
  </div>
</template>
