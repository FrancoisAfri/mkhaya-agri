<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import { label, statusColor } from './useTax'

const route = ziggyRoute

type Rule = {
  uuid: string; tax_code: string; name: string; version: number; tax_type: string; tax_type_id: number; entity_id: number | null; entity: string
  treatment: string; direction: string; method: string; rate: string; rounding: string; precision: number
  control_account_id: number | null; return_field: string | null; reporting_class: string | null
  effective_from: string; effective_to: string | null; status: string; legal_reference: string | null; change_reason: string | null
  approval_comment: string | null; simulation: { passed: boolean; ran_at: string; cases: Array<{ amount: string; inclusive: boolean; expected_tax: string | null; tax: string | null; status: string; passed: boolean }> } | null
  created_by: string | null; approved_by: string | null; published_at: string | null; in_force: boolean
}

const props = defineProps<{
  rules: Rule[]
  taxTypes: Array<{ id: number; code: string; name: string }>
  businesses: Array<{ id: number; name: string }>
  business: number | null
  accountingEnabled: boolean
  accounts: Array<{ id: number; code: string; name: string }>
  financeCodes: string[]
  options: { treatments: string[]; directions: string[]; rounding: string[] }
  can: { draft: boolean; approve: boolean }
  me: number
}>()

const confirm = ref()
const statusFilter = ref<string | null>(null)
const shown = computed(() => props.rules.filter(r => !statusFilter.value || r.status === statusFilter.value))
const pending = computed(() => props.rules.filter(r => r.status === 'pending_approval').length)

// ── Draft / edit ────────────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Rule | null>(null)
const blank = () => ({
  tax_type_id: props.taxTypes.find(t => t.code === 'VAT')?.id ?? null as number | null, tax_code: '', entity_id: null as number | null,
  name: '', treatment: 'standard', direction: 'output', method: 'percentage', rate: '15', rounding: 'half_up', precision: 2,
  control_account_id: null as number | null, return_field: '', reporting_class: '', effective_from: '', effective_to: '',
  legal_reference: '', change_reason: '',
})
const form = useForm(blank())

function openNew(from?: Rule) {
  editing.value = null
  form.defaults(blank()).reset()
  if (from) {
    // A new version of an existing code: same code and scope, new dates and values.
    Object.assign(form, { tax_type_id: from.tax_type_id, tax_code: from.tax_code, entity_id: from.entity_id, name: from.name,
      treatment: from.treatment, direction: from.direction, method: from.method, rate: from.rate, rounding: from.rounding,
      precision: from.precision, control_account_id: from.control_account_id, return_field: from.return_field ?? '',
      reporting_class: from.reporting_class ?? '', legal_reference: from.legal_reference ?? '' })
  }
  form.clearErrors()
  dialog.value = true
}

function openEdit(r: Rule) {
  editing.value = r
  Object.assign(form, { ...r, return_field: r.return_field ?? '', reporting_class: r.reporting_class ?? '', effective_to: r.effective_to ?? '',
    legal_reference: r.legal_reference ?? '', change_reason: r.change_reason ?? '' })
  form.clearErrors()
  dialog.value = true
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  const clean = (d: any) => ({ ...d, effective_to: d.effective_to || null, return_field: d.return_field || null, reporting_class: d.reporting_class || null })
  if (editing.value) {
    form.transform(({ tax_type_id, tax_code, entity_id, ...rest }) => clean(rest)).patch(route('tax.rules.update', editing.value.uuid), done)
  } else {
    form.transform(clean).post(route('tax.rules.store'), done)
  }
}

// ── Simulate (TAX-RULE-005) ─────────────────────────────────────────────────
const simDialog = ref(false)
const simTarget = ref<Rule | null>(null)
const simForm = useForm({ cases: [] as Array<{ amount: string; inclusive: boolean; expected_tax: string }> })
function openSim(r: Rule) {
  simTarget.value = r
  simForm.cases = r.simulation?.cases?.map(c => ({ amount: c.amount, inclusive: c.inclusive, expected_tax: c.expected_tax ?? '' }))
    ?? [{ amount: '1000.00', inclusive: false, expected_tax: '' }, { amount: '1150.00', inclusive: true, expected_tax: '' }, { amount: '0.33', inclusive: false, expected_tax: '' }]
  simDialog.value = true
}
function runSim() {
  if (!simTarget.value) return
  simForm.transform(d => ({ cases: d.cases.map(c => ({ ...c, expected_tax: c.expected_tax === '' ? null : c.expected_tax })) }))
    .post(route('tax.rules.simulate', simTarget.value.uuid), { preserveScroll: true, onSuccess: () => { simDialog.value = false } })
}

// ── Submit / approve / reject / retire ──────────────────────────────────────
const actDialog = ref(false)
const act = ref<{ rule: Rule; kind: 'submit' | 'approve' | 'reject' | 'retire' } | null>(null)
const actForm = useForm({ reason: '', comment: '', close_previous: true, from: '' })
function openAct(rule: Rule, kind: 'submit' | 'approve' | 'reject' | 'retire') {
  act.value = { rule, kind }
  actForm.reset()
  actDialog.value = true
}
function doAct() {
  if (!act.value) return
  const { rule, kind } = act.value
  const opts = { preserveScroll: true, onSuccess: () => { actDialog.value = false } }
  if (kind === 'submit') actForm.transform(d => ({ reason: d.reason })).post(route('tax.rules.submit', rule.uuid), opts)
  if (kind === 'approve') actForm.transform(d => ({ comment: d.comment || null, close_previous: d.close_previous })).post(route('tax.rules.approve', rule.uuid), opts)
  if (kind === 'reject') actForm.transform(d => ({ comment: d.comment })).post(route('tax.rules.reject', rule.uuid), opts)
  if (kind === 'retire') actForm.transform(d => ({ from: d.from, reason: d.reason })).post(route('tax.rules.retire', rule.uuid), opts)
}

function importCodes() {
  confirm.value.open({ title: 'Draft rules from Finance tax codes', message: 'Creates a draft rule for each active Finance tax code that has none. Nothing is in force until each draft is simulated and approved.', confirmText: 'Create drafts', confirmColor: 'primary', icon: 'tabler-file-import' },
    () => router.post(route('tax.rules.import'), {}, { preserveScroll: true }))
}

const headers = [
  { title: 'Code', key: 'tax_code' }, { title: 'Version', key: 'version' }, { title: 'Treatment', key: 'treatment' },
  { title: 'Rate', key: 'rate', align: 'end' as const }, { title: 'In force', key: 'effective_from' },
  { title: 'VAT201 field', key: 'return_field' }, { title: 'Status', key: 'status' }, { title: '', key: 'actions', sortable: false, align: 'end' as const },
]
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirm" />
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Tax rules</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Every rate and treatment is a version with its own dates. A published version is never edited — a change is a new version, tested and approved.
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.draft" variant="tonal" prepend-icon="tabler-file-import" @click="importCodes">Draft from Finance codes</VBtn>
        <VBtn v-if="can.draft" variant="tonal" prepend-icon="tabler-calculator" @click="router.post(route('tax.rules.draft_income_tax'), {}, { preserveScroll: true })">Draft income tax rates</VBtn>
        <VBtn v-if="can.draft" color="primary" prepend-icon="tabler-plus" @click="openNew()">New rule</VBtn>
      </div>
    </div>

    <VAlert v-if="pending && can.approve" type="info" variant="tonal" density="compact" class="mb-3">
      {{ pending }} rule version(s) are waiting for approval.
    </VAlert>

    <VCard border>
      <VCardText class="pb-0 d-flex gap-2 flex-wrap">
        <VChip v-for="s in [null, 'draft', 'pending_approval', 'published', 'retired', 'rejected']" :key="String(s)"
               :color="statusFilter === s ? 'primary' : undefined" :variant="statusFilter === s ? 'tonal' : 'outlined'" @click="statusFilter = s">
          {{ s ? label(s) : 'All' }}
        </VChip>
      </VCardText>
      <VDataTable :headers="headers" :items="shown" item-value="uuid" :items-per-page="50" density="comfortable" :group-by="[{ key: 'tax_code' }]">
        <template #group-header="{ item, columns, toggleGroup, isGroupOpen }">
          <tr>
            <td :colspan="columns.length" class="bg-surface-light">
              <VBtn size="small" variant="text" :icon="isGroupOpen(item) ? 'tabler-chevron-down' : 'tabler-chevron-right'" @click="toggleGroup(item)" />
              <span class="font-weight-medium">{{ item.value }}</span>
              <VBtn v-if="can.draft" size="x-small" variant="text" class="ms-2"
                    @click="openNew(rules.find(r => r.tax_code === item.value && r.status === 'published') ?? rules.find(r => r.tax_code === item.value))">New version</VBtn>
            </td>
          </tr>
        </template>
        <template #item.tax_code="{ item }">
          {{ item.name }}<div class="text-caption text-disabled">{{ item.tax_type }} · {{ item.entity }} · {{ label(item.direction) }}</div>
        </template>
        <template #item.version="{ item }">v{{ item.version }}</template>
        <template #item.treatment="{ item }">{{ label(item.treatment) }}</template>
        <template #item.rate="{ item }">{{ item.method === 'percentage' ? `${item.rate}%` : item.method === 'fixed' ? item.rate : '—' }}</template>
        <template #item.effective_from="{ item }">
          {{ item.effective_from }} → {{ item.effective_to ?? 'open' }}
          <VChip v-if="item.in_force" size="x-small" color="success" class="ms-1" label>in force</VChip>
        </template>
        <template #item.return_field="{ item }">{{ item.return_field ?? '—' }}</template>
        <template #item.status="{ item }">
          <VChip size="small" :color="statusColor[item.status]" label>{{ label(item.status) }}</VChip>
          <div v-if="item.simulation" class="text-caption" :class="item.simulation.passed ? 'text-success' : 'text-error'">
            simulation {{ item.simulation.passed ? 'passed' : 'failed' }}
          </div>
          <div v-if="item.approval_comment && item.status === 'rejected'" class="text-caption text-error">{{ item.approval_comment }}</div>
        </template>
        <template #item.actions="{ item }">
          <template v-if="can.draft && ['draft', 'rejected'].includes(item.status)">
            <VBtn size="small" variant="text" icon="tabler-edit" @click="openEdit(item)" />
            <VBtn size="small" variant="text" @click="openSim(item)">Simulate</VBtn>
            <VBtn size="small" variant="tonal" color="primary" :disabled="!item.simulation?.passed" @click="openAct(item, 'submit')">Submit</VBtn>
          </template>
          <template v-if="can.approve && item.status === 'pending_approval'">
            <VBtn size="small" variant="text" color="error" @click="openAct(item, 'reject')">Reject</VBtn>
            <VBtn size="small" variant="tonal" color="success" @click="openAct(item, 'approve')">Approve & publish</VBtn>
          </template>
          <VBtn v-if="can.approve && item.status === 'published'" size="small" variant="text" @click="openAct(item, 'retire')">Retire</VBtn>
        </template>
        <template #no-data>
          <div class="pa-6 text-medium-emphasis">No rules yet. Start from your Finance tax codes, or create one.</div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Draft dialog -->
    <VDialog v-model="dialog" max-width="820">
      <VCard :title="editing ? `Edit draft ${editing.tax_code} v${editing.version}` : 'New rule version'">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4"><VSelect v-model="form.tax_type_id" :items="taxTypes" item-title="code" item-value="id" label="Tax" :disabled="!!editing" /></VCol>
            <VCol cols="12" md="4"><VCombobox v-model="form.tax_code" :items="financeCodes" label="Tax code" :disabled="!!editing" :error-messages="form.errors.tax_code" hint="The code on Finance documents" persistent-hint /></VCol>
            <VCol v-if="businesses.length > 1" cols="12" md="4"><VSelect v-model="form.entity_id" :items="[{ id: null, name: 'All businesses' }, ...businesses]" item-title="name" item-value="id" label="Applies to" :disabled="!!editing" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.name" label="Name" :error-messages="form.errors.name" /></VCol>
            <VCol cols="12" md="3"><VSelect v-model="form.treatment" :items="options.treatments.map(v => ({ title: label(v), value: v }))" label="Treatment" /></VCol>
            <VCol cols="12" md="3"><VSelect v-model="form.direction" :items="options.directions.map(v => ({ title: label(v), value: v }))" label="Direction" /></VCol>
            <VCol cols="12" md="3"><VSelect v-model="form.method" :items="[{ title: 'Percentage', value: 'percentage' }, { title: 'Fixed amount', value: 'fixed' }, { title: 'No tax', value: 'none' }]" label="Method" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="form.rate" label="Rate" :suffix="form.method === 'percentage' ? '%' : ''" :error-messages="form.errors.rate" /></VCol>
            <VCol cols="12" md="3"><VSelect v-model="form.rounding" :items="options.rounding.map(v => ({ title: label(v), value: v }))" label="Rounding" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="form.precision" type="number" min="0" max="4" label="Decimal places" /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="form.control_account_id" :items="accounts" :item-title="(a: any) => `${a.code} ${a.name}`" item-value="id" label="GL control account" clearable /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="form.return_field" label="VAT201 field" hint="e.g. 1, 2, 3, 14, 15" persistent-hint /></VCol>
            <VCol cols="12" md="3"><VTextField v-model="form.reporting_class" label="Reporting class" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.effective_from" type="date" label="Effective from" :error-messages="form.errors.effective_from" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.effective_to" type="date" label="Effective to (optional)" :error-messages="form.errors.effective_to" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.legal_reference" label="Legal or policy reference" rows="1" auto-grow /></VCol>
            <VCol cols="12"><VTextarea v-model="form.change_reason" label="Why this version" rows="1" auto-grow /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" @click="save">Save draft</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Simulation dialog -->
    <VDialog v-model="simDialog" max-width="760">
      <VCard v-if="simTarget" :title="`Simulate ${simTarget.tax_code} v${simTarget.version}`">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">Runs the draft as if it were published, without publishing it. Give the expected VAT to make a case a pass/fail check.</p>
          <VTable density="compact">
            <thead><tr><th>Amount</th><th>Includes VAT</th><th>Expected VAT</th><th>Result</th><th /></tr></thead>
            <tbody>
              <tr v-for="(c, i) in simForm.cases" :key="i">
                <td><VTextField v-model="c.amount" density="compact" hide-details style="max-inline-size: 140px" /></td>
                <td><VCheckbox v-model="c.inclusive" density="compact" hide-details /></td>
                <td><VTextField v-model="c.expected_tax" density="compact" hide-details placeholder="optional" style="max-inline-size: 140px" /></td>
                <td>
                  <template v-if="simTarget.simulation?.cases?.[i]">
                    {{ simTarget.simulation.cases[i].tax ?? '—' }}
                    <VIcon :icon="simTarget.simulation.cases[i].passed ? 'tabler-check' : 'tabler-x'" :color="simTarget.simulation.cases[i].passed ? 'success' : 'error'" size="16" />
                  </template>
                </td>
                <td><VBtn icon="tabler-trash" size="x-small" variant="text" @click="simForm.cases.splice(i, 1)" /></td>
              </tr>
            </tbody>
          </VTable>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="simForm.cases.push({ amount: '', inclusive: false, expected_tax: '' })">Add case</VBtn>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="simDialog = false">Close</VBtn><VBtn color="primary" :loading="simForm.processing" :disabled="!simForm.cases.length" @click="runSim">Run</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Submit / approve / reject / retire -->
    <VDialog v-model="actDialog" max-width="560">
      <VCard v-if="act" :title="`${label(act.kind)} ${act.rule.tax_code} v${act.rule.version}`">
        <VCardText>
          <template v-if="act.kind === 'submit'">
            <VTextarea v-model="actForm.reason" label="What changed and why (for the approver)" rows="3" auto-grow :error-messages="actForm.errors.reason" />
          </template>
          <template v-else-if="act.kind === 'approve'">
            <VAlert v-if="act.rule.created_by" type="info" variant="tonal" density="compact" class="mb-3">Drafted by {{ act.rule.created_by }}. {{ act.rule.change_reason }}</VAlert>
            <p class="text-body-2">Publishing puts this version in force from {{ act.rule.effective_from }}. Calculations already made keep the version they used.</p>
            <VCheckbox v-model="actForm.close_previous" label="End the version currently in force the day before" density="compact" />
            <VTextarea v-model="actForm.comment" label="Approval comment (optional)" rows="2" auto-grow />
          </template>
          <template v-else-if="act.kind === 'reject'">
            <VTextarea v-model="actForm.comment" label="Why it is going back" rows="3" auto-grow :error-messages="actForm.errors.comment" />
          </template>
          <template v-else>
            <VTextField v-model="actForm.from" type="date" label="No longer applies from" :error-messages="actForm.errors.from" />
            <VTextarea v-model="actForm.reason" label="Reason" rows="2" auto-grow :error-messages="actForm.errors.reason" />
          </template>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="actDialog = false">Cancel</VBtn>
          <VBtn :color="act.kind === 'reject' ? 'error' : 'primary'" :loading="actForm.processing" @click="doAct">{{ label(act.kind) }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
