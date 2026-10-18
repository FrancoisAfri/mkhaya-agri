<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface StmtLine { id: number; date: string; description: string; reference: string | null; amount: number; status: string }
interface BookEntry { id: number; journal_no: string; date: string; description: string | null; amount: number }
interface Summary { unmatched_statement: number; statement_total: number; unreconciled_book: number; matched_pct: number }
interface Rule { id: number; name: string; priority: number; match_type: string; match_value: string; direction: string; allocation_type: string; contra_account_id: number | null; party_id: number | null; is_active: boolean; bank_account_id: number | null; target: string }
type Opt = { id: number; name: string }
const props = defineProps<{
  bank: { id: number; name: string; currency: string; account: string | null }
  statementLines: StmtLine[]
  bookEntries: BookEntry[]
  summary: Summary
  suggestions: Record<number, { rule: string; target: string }>
  contraAccounts: { id: number; code: string; label: string }[]
  customers: Opt[]
  suppliers: Opt[]
  rules: Rule[]
  fileTypes: { value: string; label: string }[]
  dateFormats: { value: string; label: string }[]
}>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const confirm = ref()

// ── Matching statement ↔ book ────────────────────────────────────────────────
const selected = ref<StmtLine | null>(null)
function selectLine(l: StmtLine) { selected.value = selected.value?.id === l.id ? null : l }
const rankedBook = computed(() => {
  const list = [...props.bookEntries]
  if (!selected.value) return list
  const amt = selected.value.amount
  return list.sort((a, b) => Number(Math.abs(b.amount - amt) < 0.01) - Number(Math.abs(a.amount - amt) < 0.01))
})
const isCandidate = (e: BookEntry) => !!selected.value && Math.abs(e.amount - selected.value.amount) < 0.01

const matchForm = useForm<any>({ statement_line_id: null, journal_line_id: null })
function match(entry: BookEntry) {
  if (!selected.value) return
  matchForm.statement_line_id = selected.value.id
  matchForm.journal_line_id = entry.id
  matchForm.post(route('finance.accounting.banking.match', props.bank.id), { preserveScroll: true, onSuccess: () => { selected.value = null } })
}

const autoForm = useForm({})
function autoMatch() { autoForm.post(route('finance.accounting.banking.auto-match', props.bank.id), { preserveScroll: true }) }
function applyRules() { useForm({}).post(route('finance.accounting.banking.apply-rules', props.bank.id), { preserveScroll: true }) }

// ── Import ───────────────────────────────────────────────────────────────────
const importDialog = ref(false)
const importForm = useForm<any>({ file: null as File | null, file_type: 'auto', date_format: 'auto' })
function submitImport() {
  importForm.post(route('finance.accounting.banking.import', props.bank.id), {
    preserveScroll: true, forceFormData: true, onSuccess: () => { importDialog.value = false; importForm.reset() },
  })
}

// ── Allocation (account / customer / supplier) ───────────────────────────────
const allocDialog = ref(false)
const allocLine = ref<StmtLine | null>(null)
const allocForm = useForm<any>({ type: 'account', contra: '', party_id: null, description: '', remember: false, match_value: '' })
function openAlloc(l: StmtLine) {
  allocLine.value = l
  allocForm.reset()
  allocForm.type = l.amount >= 0 ? 'customer' : 'supplier'   // inflow → receipt, outflow → payment
  allocForm.description = l.description
  allocForm.match_value = keyword(l.description)   // a sensible default text to match future lines on
  allocDialog.value = true
}
// Pick a distinctive chunk of the narration (drop trailing dates/refs) to match on.
function keyword(desc: string): string {
  return (desc || '').replace(/\s+\d[\d/\-]{4,}.*$/, '').trim().slice(0, 60) || (desc || '').trim().slice(0, 60)
}
function submitAlloc() {
  allocForm.post(route('finance.accounting.banking.allocate', allocLine.value!.id), {
    preserveScroll: true, onSuccess: () => { allocDialog.value = false; if (selected.value?.id === allocLine.value?.id) selected.value = null },
  })
}

// ── Rules manager ────────────────────────────────────────────────────────────
const rulesDialog = ref(false)
const ruleEditing = ref<Rule | null>(null)
const ruleForm = useForm<any>({ name: '', bank_account_id: null, priority: 100, match_type: 'contains', match_value: '', direction: 'any', allocation_type: 'account', contra_account_id: null, party_id: null, is_active: true })
function newRule() { ruleEditing.value = null; ruleForm.reset(); ruleForm.priority = 100; ruleForm.match_type = 'contains'; ruleForm.direction = 'any'; ruleForm.allocation_type = 'account'; ruleForm.is_active = true }
function editRule(r: Rule) {
  ruleEditing.value = r
  ruleForm.name = r.name; ruleForm.bank_account_id = r.bank_account_id; ruleForm.priority = r.priority
  ruleForm.match_type = r.match_type; ruleForm.match_value = r.match_value; ruleForm.direction = r.direction
  ruleForm.allocation_type = r.allocation_type; ruleForm.contra_account_id = r.contra_account_id; ruleForm.party_id = r.party_id; ruleForm.is_active = r.is_active
}
function saveRule() {
  const opts = { preserveScroll: true, onSuccess: () => newRule() }
  if (ruleEditing.value) ruleForm.patch(route('finance.accounting.banking.rules.update', ruleEditing.value.id), opts)
  else ruleForm.post(route('finance.accounting.banking.rules.store'), opts)
}
function deleteRule(r: Rule) {
  confirm.value.open(
    { title: t('acc.delete_rule'), message: t('acc.delete_rule_confirm', { name: r.name }), confirmText: t('acc.delete'), confirmColor: 'error', icon: 'tabler-trash' },
    () => useForm({}).delete(route('finance.accounting.banking.rules.destroy', r.id), { preserveScroll: true }),
  )
}
const rulePartyOptions = computed(() => ruleForm.allocation_type === 'supplier' ? props.suppliers : props.customers)
const allocPartyOptions = computed(() => allocForm.type === 'supplier' ? props.suppliers : props.customers)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.banking') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.reconcile') }} — {{ bank.name }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.bank_gl') }}: {{ bank.account || '—' }} · {{ bank.currency }}</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="text" prepend-icon="tabler-arrow-left" @click="router.visit(route('finance.accounting.banking'))">{{ t('acc.accounts_nav') }}</VBtn>
        <VBtn variant="text" prepend-icon="tabler-adjustments" @click="rulesDialog = true">{{ t('acc.rules') }}</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-upload" @click="importDialog = true">{{ t('acc.import_statement') }}</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-bolt" :disabled="!rules.length || !statementLines.length" @click="applyRules">{{ t('acc.apply_rules') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-wand" :loading="autoForm.processing" :disabled="!statementLines.length || !bookEntries.length" @click="autoMatch">{{ t('acc.auto_match') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="(matchForm.errors as any).reconcile" type="error" variant="tonal" density="compact" class="mb-3">{{ (matchForm.errors as any).reconcile }}</VAlert>

    <div class="d-flex gap-2 mb-4 flex-wrap">
      <VChip variant="tonal" color="primary">{{ t('acc.pct_reconciled', { pct: summary.matched_pct }) }}</VChip>
      <VChip variant="tonal" color="warning">{{ t('acc.lines_to_clear', { n: summary.unmatched_statement }) }}</VChip>
      <VChip variant="tonal">{{ t('acc.book_unmatched', { n: summary.unreconciled_book }) }}</VChip>
    </div>

    <VRow>
      <!-- Statement side -->
      <VCol cols="12" md="6">
        <VCard border>
          <VCardTitle class="text-subtitle-1 d-flex align-center">
            <VIcon icon="tabler-file-invoice" size="18" class="me-2" />{{ t('acc.bank_statement') }}
            <VSpacer />
            <span class="text-caption text-medium-emphasis">{{ t('acc.click_to_match') }}</span>
          </VCardTitle>
          <VDivider />
          <VList density="compact" class="py-0">
            <template v-for="l in statementLines" :key="l.id">
              <VListItem :active="selected?.id === l.id" color="primary" @click="selectLine(l)">
                <template #prepend>
                  <VIcon :icon="selected?.id === l.id ? 'tabler-circle-dot' : 'tabler-circle'" size="18" class="me-2" />
                </template>
                <VListItemTitle class="text-body-2">{{ l.description || '—' }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  {{ l.date }}<template v-if="l.reference"> · {{ l.reference }}</template>
                  <VChip v-if="suggestions[l.id]" size="x-small" color="info" variant="tonal" class="ms-1" prepend-icon="tabler-bulb">
                    {{ suggestions[l.id].rule }} → {{ suggestions[l.id].target }}
                  </VChip>
                </VListItemSubtitle>
                <template #append>
                  <div class="text-right">
                    <div class="font-weight-medium" :class="l.amount >= 0 ? 'text-success' : 'text-error'">{{ money(l.amount) }}</div>
                    <VBtn size="x-small" variant="text" density="comfortable" @click.stop="openAlloc(l)">{{ t('acc.allocate') }}</VBtn>
                  </div>
                </template>
              </VListItem>
              <VDivider />
            </template>
            <VListItem v-if="!statementLines.length">
              <VListItemTitle class="text-center text-medium-emphasis py-4">{{ t('acc.all_reconciled') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Book side -->
      <VCol cols="12" md="6">
        <VCard border>
          <VCardTitle class="text-subtitle-1 d-flex align-center">
            <VIcon icon="tabler-book" size="18" class="me-2" />{{ t('acc.book_entries') }}
            <VSpacer />
            <span v-if="selected" class="text-caption text-primary">{{ t('acc.matching_amt', { amount: money(selected.amount) }) }}</span>
          </VCardTitle>
          <VDivider />
          <VList density="compact" class="py-0">
            <template v-for="e in rankedBook" :key="e.id">
              <VListItem :class="isCandidate(e) ? 'bg-primary-lighten-5' : ''">
                <VListItemTitle class="text-body-2">{{ e.description || e.journal_no }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ e.date }} · {{ e.journal_no }}</VListItemSubtitle>
                <template #append>
                  <div class="text-right">
                    <div class="font-weight-medium" :class="e.amount >= 0 ? 'text-success' : 'text-error'">{{ money(e.amount) }}</div>
                    <VBtn size="x-small" :color="isCandidate(e) ? 'primary' : undefined" :variant="isCandidate(e) ? 'flat' : 'tonal'"
                          density="comfortable" :disabled="!selected || matchForm.processing" @click="match(e)">{{ t('acc.match') }}</VBtn>
                  </div>
                </template>
              </VListItem>
              <VDivider />
            </template>
            <VListItem v-if="!bookEntries.length">
              <VListItemTitle class="text-center text-medium-emphasis py-4">{{ t('acc.no_ledger_entries') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Import dialog -->
    <VDialog v-model="importDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('acc.import_statement') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="importForm.file_type" :items="fileTypes.map(f => ({ title: f.label, value: f.value }))" :label="t('acc.file_type')" variant="outlined" density="comfortable" />
            <VSelect v-model="importForm.date_format" :items="dateFormats.map(f => ({ title: f.label, value: f.value }))" :label="t('acc.date_format')" variant="outlined" density="comfortable" style="max-width: 200px" />
          </div>
          <VFileInput v-model="importForm.file" :label="t('acc.stmt_file')" accept=".csv,.txt,.xls,.xlsx,.ofx,.qfx,.qif" variant="outlined" density="comfortable" prepend-icon="tabler-file-spreadsheet" :error-messages="importForm.errors.file" />
          <div class="text-caption text-medium-emphasis mt-1">{{ t('acc.import_stmt_help') }}</div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="importDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="importForm.processing" :disabled="!importForm.file" @click="submitImport">{{ t('acc.import') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Allocation dialog -->
    <VDialog v-model="allocDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('acc.allocate_line') }}</VCardTitle>
        <VCardText>
          <div class="text-body-2 mb-3">
            {{ allocLine?.date }} — <strong>{{ money(allocLine?.amount || 0) }}</strong> {{ (allocLine?.amount || 0) >= 0 ? t('acc.received') : t('acc.paid') }} · {{ allocLine?.description }}
          </div>
          <VBtnToggle v-model="allocForm.type" mandatory divided density="comfortable" color="primary" class="mb-4">
            <VBtn value="account" prepend-icon="tabler-list-tree">{{ t('acc.account') }}</VBtn>
            <VBtn value="customer" prepend-icon="tabler-user">{{ t('acc.customer') }}</VBtn>
            <VBtn value="supplier" prepend-icon="tabler-truck">{{ t('acc.supplier') }}</VBtn>
          </VBtnToggle>

          <VSelect v-if="allocForm.type === 'account'" v-model="allocForm.contra" :items="contraAccounts.map(a => ({ title: a.label, value: a.code }))" :label="t('acc.contra_account')" variant="outlined" density="comfortable" class="mb-3" :error-messages="(allocForm.errors as any).contra || (allocForm.errors as any).reconcile" />
          <VSelect v-else v-model="allocForm.party_id" :items="allocPartyOptions.map(p => ({ title: p.name, value: p.id }))" :label="allocForm.type === 'supplier' ? t('acc.supplier') : t('acc.customer')" variant="outlined" density="comfortable" class="mb-3" :error-messages="(allocForm.errors as any).party_id || (allocForm.errors as any).reconcile" />

          <VTextField v-model="allocForm.description" :label="t('acc.description')" variant="outlined" density="comfortable" />
          <div v-if="allocForm.type !== 'account'" class="text-caption text-medium-emphasis mt-1">
            {{ allocForm.type === 'supplier' ? t('acc.posts_ap') : t('acc.posts_ar') }}
          </div>

          <!-- Remember this allocation as a rule for future transactions -->
          <VDivider class="my-3" />
          <VCheckbox v-model="allocForm.remember" :label="t('acc.remember_rule')" density="compact" hide-details color="primary" />
          <div v-if="allocForm.remember" class="mt-1">
            <VTextField v-model="allocForm.match_value" :label="t('acc.match_text')" :hint="t('acc.match_hint')" persistent-hint variant="outlined" density="comfortable" prepend-inner-icon="tabler-search" />
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="allocDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="allocForm.processing" :disabled="allocForm.type === 'account' ? !allocForm.contra : !allocForm.party_id" @click="submitAlloc">{{ t('acc.post_reconcile') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Rules manager -->
    <VDialog v-model="rulesDialog" max-width="900">
      <VCard>
        <VCardTitle class="d-flex align-center">
          {{ t('acc.bank_rules') }} <VSpacer />
          <VBtn size="small" variant="text" icon="tabler-x" @click="rulesDialog = false" />
        </VCardTitle>
        <VCardText>
          <div class="text-caption text-medium-emphasis mb-3">{{ t('acc.rules_help') }}</div>
          <VTable density="compact" class="mb-4">
            <thead><tr><th>{{ t('acc.name') }}</th><th>{{ t('acc.when_description') }}</th><th>{{ t('acc.direction') }}</th><th>{{ t('acc.allocate_to') }}</th><th class="text-center">{{ t('acc.active') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="r in rules" :key="r.id">
                <td>{{ r.name }}</td>
                <td class="text-caption">{{ r.match_type }} “{{ r.match_value }}”</td>
                <td class="text-caption text-capitalize">{{ r.direction }}</td>
                <td class="text-caption text-capitalize">{{ r.allocation_type }}: {{ r.target }}</td>
                <td class="text-center"><VIcon :icon="r.is_active ? 'tabler-check' : 'tabler-minus'" :color="r.is_active ? 'success' : 'disabled'" size="18" /></td>
                <td class="text-right"><VBtn size="x-small" variant="text" icon="tabler-edit" @click="editRule(r)" /><VBtn size="x-small" variant="text" color="error" icon="tabler-trash" @click="deleteRule(r)" /></td>
              </tr>
              <tr v-if="!rules.length"><td colspan="6" class="text-center text-medium-emphasis py-3">{{ t('acc.no_rules') }}</td></tr>
            </tbody>
          </VTable>

          <VDivider class="mb-3" />
          <div class="text-subtitle-2 mb-2">{{ ruleEditing ? t('acc.edit_rule') : t('acc.new_rule') }}</div>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VTextField v-model="ruleForm.name" :label="t('acc.rule_name')" variant="outlined" density="compact" style="min-width: 200px" :error-messages="ruleForm.errors.name" />
            <VSelect v-model="ruleForm.match_type" :items="[{title:t('acc.contains'),value:'contains'},{title:t('acc.equals'),value:'equals'},{title:t('acc.starts_with'),value:'starts'},{title:t('acc.regex'),value:'regex'}]" :label="t('acc.match_label')" variant="outlined" density="compact" style="max-width: 150px" />
            <VTextField v-model="ruleForm.match_value" :label="t('acc.text_pattern')" variant="outlined" density="compact" style="min-width: 180px" :error-messages="ruleForm.errors.match_value" />
            <VSelect v-model="ruleForm.direction" :items="[{title:t('acc.any'),value:'any'},{title:t('acc.money_in'),value:'in'},{title:t('acc.money_out'),value:'out'}]" :label="t('acc.direction')" variant="outlined" density="compact" style="max-width: 140px" />
          </div>
          <div class="d-flex gap-3 mb-3 flex-wrap align-center">
            <VBtnToggle v-model="ruleForm.allocation_type" mandatory divided density="compact" color="primary">
              <VBtn value="account" size="small">{{ t('acc.account') }}</VBtn>
              <VBtn value="customer" size="small">{{ t('acc.customer') }}</VBtn>
              <VBtn value="supplier" size="small">{{ t('acc.supplier') }}</VBtn>
            </VBtnToggle>
            <VSelect v-if="ruleForm.allocation_type === 'account'" v-model="ruleForm.contra_account_id" :items="contraAccounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.account')" variant="outlined" density="compact" style="min-width: 240px" :error-messages="ruleForm.errors.contra_account_id" />
            <VSelect v-else v-model="ruleForm.party_id" :items="rulePartyOptions.map(p => ({ title: p.name, value: p.id }))" :label="ruleForm.allocation_type === 'supplier' ? t('acc.supplier') : t('acc.customer')" variant="outlined" density="compact" style="min-width: 240px" :error-messages="ruleForm.errors.party_id" />
            <VTextField v-model.number="ruleForm.priority" type="number" :label="t('acc.priority')" variant="outlined" density="compact" style="max-width: 110px" />
            <VSwitch v-model="ruleForm.is_active" :label="t('acc.active')" color="primary" hide-details density="compact" />
          </div>
          <div class="d-flex gap-2">
            <VBtn color="primary" size="small" :loading="ruleForm.processing" :disabled="!ruleForm.name || !ruleForm.match_value" @click="saveRule">{{ ruleEditing ? t('acc.update_rule') : t('acc.add_rule') }}</VBtn>
            <VBtn v-if="ruleEditing" size="small" variant="text" @click="newRule">{{ t('acc.cancel_edit') }}</VBtn>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
