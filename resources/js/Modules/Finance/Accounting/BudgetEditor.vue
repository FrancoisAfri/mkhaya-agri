<script lang="ts" setup>
import { reactive, ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Account { id: number; code: string; name: string; type: string; section: string }
const props = defineProps<{
  budget: { id: number; name: string; fiscal_year: number; status: string } | null
  accounts: Account[]
  grid: Record<string, Record<string, number>>
  year: number
}>()

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const SECTIONS = computed(() => [
  { key: 'income', label: t('acc.income'), total: t('acc.total_income') },
  { key: 'cost_of_sales', label: t('acc.cost_of_sales'), total: t('acc.total_cost_of_sales') },
  { key: 'other_income', label: t('acc.other_income'), total: t('acc.total_other_income') },
  { key: 'expenses', label: t('acc.expenses_word'), total: t('acc.total_expenses') },
])

const name = ref(props.budget?.name ?? '')
const fiscalYear = ref(props.budget?.fiscal_year ?? props.year)

// cells[accountId][month 1-12] = number
const cells = reactive<Record<number, Record<number, number | null>>>({})
for (const a of props.accounts) {
  cells[a.id] = {}
  for (let m = 1; m <= 12; m++) cells[a.id][m] = props.grid?.[a.id]?.[m] ?? props.grid?.[String(a.id)]?.[String(m)] ?? null
}

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const accountsIn = (section: string) => props.accounts.filter(a => a.section === section)
const val = (id: number, m: number) => Number(cells[id]?.[m] ?? 0) || 0

const rowTotal = (id: number) => { let t = 0; for (let m = 1; m <= 12; m++) t += val(id, m); return t }
const sectionMonth = (section: string, m: number) => accountsIn(section).reduce((s, a) => s + val(a.id, m), 0)
const sectionTotal = (section: string) => accountsIn(section).reduce((s, a) => s + rowTotal(a.id), 0)

const grossMonth = (m: number) => sectionMonth('income', m) - sectionMonth('cost_of_sales', m)
const grossTotal = () => sectionTotal('income') - sectionTotal('cost_of_sales')
const netMonth = (m: number) => grossMonth(m) + sectionMonth('other_income', m) - sectionMonth('expenses', m)
const netTotal = () => grossTotal() + sectionTotal('other_income') - sectionTotal('expenses')

const grand = computed(() => ({ income: sectionTotal('income'), cos: sectionTotal('cost_of_sales'), gross: grossTotal(), other: sectionTotal('other_income'), exp: sectionTotal('expenses'), net: netTotal() }))

const form = useForm<any>({})
function save() {
  const lines: any[] = []
  for (const a of props.accounts) {
    for (let m = 1; m <= 12; m++) {
      const v = Number(cells[a.id][m])
      if (v) lines.push({ account_id: a.id, period_no: m, amount: v })
    }
  }
  form.transform(() => ({ name: name.value, fiscal_year: fiscalYear.value, period_type: 'monthly', lines, _editor: true }))
  if (props.budget) form.patch(route('finance.accounting.budgets.update', props.budget.id), { preserveScroll: true })
  else form.post(route('finance.accounting.budgets.store'), { preserveScroll: true })
}
function cancel() { router.visit(route('finance.accounting.budgets')) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-3 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_planning') }}</div>
        <h2 class="text-h5 mb-0">{{ budget ? t('acc.edit_budget') : t('acc.new_budget') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.budget_editor_sub') }}</div>
      </div>
      <div class="d-flex align-center gap-3">
        <VTextField v-model="name" :label="t('acc.budget_name')" density="compact" variant="outlined" hide-details style="min-width: 220px" :error="!!form.errors.name" />
        <VTextField v-model.number="fiscalYear" type="number" :label="t('acc.fiscal_year_label')" density="compact" variant="outlined" hide-details style="max-width: 130px" />
        <VBtn variant="text" @click="cancel">{{ t('acc.cancel') }}</VBtn>
        <VBtn color="primary" :loading="form.processing" :disabled="!name" prepend-icon="tabler-device-floppy" @click="save">{{ t('acc.save_budget') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-3">
      <VCol cols="6" sm="4" md="2"><VCard border><VCardText class="py-2"><div class="text-caption text-medium-emphasis">{{ t('acc.income') }}</div><div class="text-subtitle-1">{{ money(grand.income) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="2"><VCard border><VCardText class="py-2"><div class="text-caption text-medium-emphasis">{{ t('acc.cost_of_sales') }}</div><div class="text-subtitle-1">{{ money(grand.cos) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="2"><VCard border><VCardText class="py-2"><div class="text-caption text-medium-emphasis">{{ t('acc.gross_profit') }}</div><div class="text-subtitle-1 text-success">{{ money(grand.gross) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="2"><VCard border><VCardText class="py-2"><div class="text-caption text-medium-emphasis">{{ t('acc.other_income') }}</div><div class="text-subtitle-1">{{ money(grand.other) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="2"><VCard border><VCardText class="py-2"><div class="text-caption text-medium-emphasis">{{ t('acc.expenses_word') }}</div><div class="text-subtitle-1 text-error">{{ money(grand.exp) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="2"><VCard border><VCardText class="py-2"><div class="text-caption text-medium-emphasis">{{ t('acc.net_profit') }}</div><div class="text-subtitle-1" :class="grand.net >= 0 ? 'text-success' : 'text-error'">{{ money(grand.net) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border>
      <div style="overflow-x: auto">
        <table class="budget-grid">
          <thead>
            <tr>
              <th class="sticky-col acc-head">{{ t('acc.account') }}</th>
              <th v-for="m in MONTHS" :key="m" class="text-right">{{ m }} {{ fiscalYear }}</th>
              <th class="text-right total-col">{{ t('acc.total') }}</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(sec, si) in SECTIONS" :key="sec.key">
              <tr class="section-row"><td class="sticky-col" :colspan="1">{{ sec.label }}</td><td :colspan="13"></td></tr>
              <tr v-for="a in accountsIn(sec.key)" :key="a.id">
                <td class="sticky-col acc-name">{{ a.code }} · {{ a.name }}</td>
                <td v-for="m in 12" :key="m" class="cell">
                  <input v-model.number="cells[a.id][m]" type="number" class="cell-input" placeholder="0" />
                </td>
                <td class="text-right total-col">{{ money(rowTotal(a.id)) }}</td>
              </tr>
              <tr v-if="!accountsIn(sec.key).length"><td class="sticky-col acc-name text-medium-emphasis">{{ t('acc.no_accounts_dash') }}</td><td colspan="13"></td></tr>
              <tr class="subtotal-row">
                <td class="sticky-col">{{ sec.total }}</td>
                <td v-for="m in 12" :key="m" class="text-right">{{ money(sectionMonth(sec.key, m)) }}</td>
                <td class="text-right total-col">{{ money(sectionTotal(sec.key)) }}</td>
              </tr>
              <!-- Gross profit after cost of sales -->
              <tr v-if="sec.key === 'cost_of_sales'" class="profit-row">
                <td class="sticky-col">{{ t('acc.gross_profit_row') }}</td>
                <td v-for="m in 12" :key="m" class="text-right">{{ money(grossMonth(m)) }}</td>
                <td class="text-right total-col">{{ money(grossTotal()) }}</td>
              </tr>
            </template>
            <tr class="profit-row net">
              <td class="sticky-col">{{ t('acc.net_profit_loss') }}</td>
              <td v-for="m in 12" :key="m" class="text-right">{{ money(netMonth(m)) }}</td>
              <td class="text-right total-col">{{ money(netTotal()) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </VCard>
  </div>
</template>

<style scoped>
.budget-grid { border-collapse: collapse; width: 100%; font-size: 0.8rem; white-space: nowrap; }
.budget-grid th, .budget-grid td { padding: 4px 8px; border-bottom: 1px solid rgba(var(--v-border-color), 0.08); }
.budget-grid thead th { position: sticky; top: 0; background: rgb(var(--v-theme-surface)); z-index: 2; font-weight: 600; }
.sticky-col { position: sticky; left: 0; background: rgb(var(--v-theme-surface)); z-index: 1; min-width: 220px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
.acc-head { z-index: 3 !important; }
.acc-name { font-weight: 400; }
.section-row td { font-weight: 700; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.04em; padding-top: 10px; color: rgb(var(--v-theme-primary)); }
.subtotal-row td { font-weight: 600; background: rgba(var(--v-theme-primary), 0.04); }
.profit-row td { font-weight: 700; background: rgba(var(--v-theme-success), 0.06); }
.profit-row.net td { background: rgba(var(--v-theme-primary), 0.08); }
.total-col { font-weight: 600; border-left: 1px solid rgba(var(--v-border-color), 0.12); }
.cell { padding: 2px 4px; }
.cell-input { width: 84px; text-align: right; border: 1px solid rgba(var(--v-border-color), 0.25); border-radius: 4px; padding: 3px 6px; background: transparent; color: inherit; font-size: 0.8rem; }
.cell-input:focus { outline: none; border-color: rgb(var(--v-theme-primary)); }
</style>
