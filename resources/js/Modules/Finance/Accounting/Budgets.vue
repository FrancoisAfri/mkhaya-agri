<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Budget { id: number; name: string; fiscal_year: number; period_type: string; status: string; lines_count: number }
interface VarRow { account_id: number; code: string; name: string; type: string; budget: number; actual: number; variance: number; variance_pct: number | null; favourable: boolean }
const props = defineProps<{
  budgets: Budget[]; selected: number | null
  detail: { id: number; name: string; fiscal_year: number; status: string; period_type: string; notes: string | null; lines: any[] } | null
  variance: { rows: VarRow[]; totals: { budget: number; actual: number; variance: number; through_month: number } } | null
  through: number
  accounts: { id: number; code: string; label: string; type: string }[]
}>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { draft: 'warning', approved: 'info', locked: 'success' }
const months = ['—', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

function selectBudget(id: number) { router.get(route('finance.accounting.budgets'), { budget: id, through: props.through }, { preserveState: false }) }
function setThrough(m: number) { router.get(route('finance.accounting.budgets'), { budget: props.selected, through: m }, { preserveState: false }) }
function setStatus(s: string) { if (props.detail) useForm({ status: s }).post(route('finance.accounting.budgets.status', props.detail.id), { preserveScroll: true }) }

// Editor
const dialog = ref(false)
const editingId = ref<number | null>(null)
const form = useForm<any>({ name: '', fiscal_year: new Date().getFullYear(), period_type: 'annual', notes: '', lines: [] as any[] })
function openNew() { editingId.value = null; form.reset(); form.fiscal_year = new Date().getFullYear(); form.period_type = 'annual'; form.lines = [{ account_id: null, period_no: 0, amount: null }]; dialog.value = true }
function openEdit() {
  if (!props.detail) return
  editingId.value = props.detail.id
  form.name = props.detail.name; form.fiscal_year = props.detail.fiscal_year; form.period_type = props.detail.period_type; form.notes = props.detail.notes ?? ''
  form.lines = props.detail.lines.length ? props.detail.lines.map(l => ({ ...l })) : [{ account_id: null, period_no: 0, amount: null }]
  dialog.value = true
}
function addLine() { form.lines.push({ account_id: null, period_no: 0, amount: null }) }
function removeLine(i: number) { form.lines.splice(i, 1) }
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editingId.value) form.patch(route('finance.accounting.budgets.update', editingId.value), opts)
  else form.post(route('finance.accounting.budgets.store'), opts)
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_planning') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.budgets_word') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.budgets_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('finance.accounting.budgets.create'))">{{ t('acc.new_budget') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="3">
        <VCard border>
          <VList density="compact" class="py-0">
            <VListItem v-for="b in budgets" :key="b.id" :active="b.id === selected" color="primary" @click="selectBudget(b.id)">
              <VListItemTitle class="text-body-2">{{ b.name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ b.fiscal_year }} · {{ b.lines_count }} {{ t('acc.lines_word') }}
                <VChip size="x-small" :color="statusColor[b.status]" variant="tonal" class="ms-1 text-capitalize">{{ b.status }}</VChip>
              </VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!budgets.length"><VListItemTitle class="text-center text-medium-emphasis py-4">{{ t('acc.no_budgets') }}</VListItemTitle></VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="9">
        <VCard v-if="detail && variance" border>
          <VCardTitle class="d-flex align-center flex-wrap gap-2">
            <span>{{ detail.name }} · {{ detail.fiscal_year }}</span>
            <VChip size="small" :color="statusColor[detail.status]" variant="tonal" class="text-capitalize">{{ detail.status }}</VChip>
            <VSpacer />
            <VSelect :model-value="through" :items="months.map((m, i) => ({ title: i === 12 ? t('acc.full_year') : t('acc.through_month', { m }), value: i })).slice(1)" density="compact" variant="outlined" hide-details style="max-width: 190px" @update:model-value="setThrough" />
            <VBtn size="small" variant="text" icon="tabler-edit" :disabled="detail.status === 'locked'" @click="router.visit(route('finance.accounting.budgets.edit', detail.id))" />
          </VCardTitle>
          <VCardText class="pt-0">
            <div class="d-flex gap-2 mb-3">
              <VBtn v-if="detail.status === 'draft'" size="small" color="info" variant="tonal" @click="setStatus('approved')">{{ t('acc.approve') }}</VBtn>
              <VBtn v-if="detail.status === 'approved'" size="small" color="success" variant="tonal" @click="setStatus('locked')">{{ t('acc.lock') }}</VBtn>
              <VBtn v-if="detail.status !== 'draft'" size="small" variant="text" @click="setStatus('draft')">{{ t('acc.reopen_budget') }}</VBtn>
            </div>
            <VTable density="compact">
              <thead><tr><th>{{ t('acc.account') }}</th><th class="text-right">{{ t('acc.budget_col') }}</th><th class="text-right">{{ t('acc.actual') }}</th><th class="text-right">{{ t('acc.variance') }}</th><th class="text-right">%</th></tr></thead>
              <tbody>
                <tr v-for="r in variance.rows" :key="r.account_id">
                  <td class="text-caption">{{ r.code }} · {{ r.name }}</td>
                  <td class="text-right">{{ money(r.budget) }}</td>
                  <td class="text-right">{{ money(r.actual) }}</td>
                  <td class="text-right" :class="r.favourable ? 'text-success' : 'text-error'">{{ money(r.variance) }}</td>
                  <td class="text-right text-caption" :class="r.favourable ? 'text-success' : 'text-error'">{{ r.variance_pct != null ? r.variance_pct + '%' : '—' }}</td>
                </tr>
                <tr v-if="!variance.rows.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('acc.no_budget_lines') }}</td></tr>
              </tbody>
              <tfoot v-if="variance.rows.length">
                <tr class="font-weight-bold">
                  <td>{{ t('acc.total') }}</td>
                  <td class="text-right">{{ money(variance.totals.budget) }}</td>
                  <td class="text-right">{{ money(variance.totals.actual) }}</td>
                  <td class="text-right" :class="variance.totals.variance >= 0 ? 'text-success' : 'text-error'">{{ money(variance.totals.variance) }}</td>
                  <td></td>
                </tr>
              </tfoot>
            </VTable>
          </VCardText>
        </VCard>
        <VCard v-else border><VCardText class="text-center text-medium-emphasis py-8">{{ t('acc.select_create_budget') }}</VCardText></VCard>
      </VCol>
    </VRow>

    <!-- Editor dialog -->
    <VDialog v-model="dialog" max-width="760" persistent>
      <VCard>
        <VCardTitle>{{ editingId ? t('acc.edit_budget') : t('acc.new_budget') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.name" :label="t('acc.budget_name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
            <VTextField v-model.number="form.fiscal_year" type="number" :label="t('acc.fiscal_year_label')" variant="outlined" density="comfortable" style="max-width: 140px" />
            <VSelect v-model="form.period_type" :items="[{ title: t('acc.annual'), value: 'annual' }, { title: t('acc.monthly'), value: 'monthly' }]" :label="t('acc.type')" variant="outlined" density="comfortable" style="max-width: 150px" />
          </div>
          <VTable density="compact">
            <thead><tr><th>{{ t('acc.account') }}</th><th style="width: 110px">{{ t('acc.period') }}</th><th style="width: 160px" class="text-right">{{ t('acc.amount') }}</th><th style="width: 48px"></th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in form.lines" :key="i">
                <td><VSelect v-model="l.account_id" :items="accounts.map(a => ({ title: a.label, value: a.id }))" density="compact" variant="outlined" hide-details /></td>
                <td><VSelect v-model.number="l.period_no" :items="months.map((m, idx) => ({ title: idx === 0 ? t('acc.year_word') : m, value: idx }))" density="compact" variant="outlined" hide-details /></td>
                <td><VTextField v-model.number="l.amount" type="number" prefix="R" density="compact" variant="outlined" hide-details /></td>
                <td><VBtn size="x-small" variant="text" color="error" icon="tabler-x" @click="removeLine(i)" /></td>
              </tr>
            </tbody>
          </VTable>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="addLine">{{ t('acc.add_line') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">{{ t('acc.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
