<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'
import EvidencePanel from './components/EvidencePanel.vue'

const route = ziggyRoute

type Allocation = { id: number; amount: string; note: string | null; reversed: boolean; reversal_reason: string | null; at: string | null; payment: { number: string; date: string; reference: string | null } | null }
type Liability = {
  uuid: string; tax_type: string; entity: string | null; period: string | null; return: { uuid: string; reference: string } | null
  source: string; description: string; original: string; adjustments: string; paid: string; outstanding: string
  due_date: string | null; status: string; overdue: boolean; allocations: Allocation[]
  evidence: Array<{ id: number; title: string; added: string | null; url: string }>
}

const props = defineProps<{
  liabilities: Liability[]
  filters: { status: string | null }
  taxTypes: Array<{ id: number; code: string; name: string }>
  can: { allocate: boolean; record: boolean }
  businesses: Array<{ id: number; name: string }>
  business: number | null
}>()

function status(s: string | null) {
  router.get(route('tax.liabilities.index'), { ...(s ? { status: s } : {}), ...(props.business ? { business: props.business } : {}) }, { preserveState: true })
}
const open = ref<string | null>(null)

// ── Allocate a Finance payment ──────────────────────────────────────────────
const allocDialog = ref(false)
const target = ref<Liability | null>(null)
const candidates = ref<Array<{ id: number; number: string; date: string; amount: string; available: string; reference: string | null; party: string | null }>>([])
const loading = ref(false)
const allocForm = useForm({ payment_id: null as number | null, amount: '', note: '' })
async function openAllocate(l: Liability) {
  target.value = l
  allocForm.reset()
  allocForm.amount = l.outstanding
  allocDialog.value = true
  loading.value = true
  try {
    const res = await fetch(route('tax.liabilities.candidates', l.uuid), { headers: { Accept: 'application/json' } })
    candidates.value = res.ok ? await res.json() : []
  } finally {
    loading.value = false
  }
}
function allocate() {
  if (!target.value) return
  allocForm.post(route('tax.liabilities.allocate', target.value.uuid), { preserveScroll: true, onSuccess: () => { allocDialog.value = false } })
}

const reverseDialog = ref(false)
const reverseTarget = ref<Allocation | null>(null)
const reverseForm = useForm({ reason: '' })
function doReverse() {
  if (!reverseTarget.value) return
  reverseForm.post(route('tax.allocations.reverse', reverseTarget.value.id), { preserveScroll: true, onSuccess: () => { reverseDialog.value = false } })
}

// ── Record an assessment, interest or penalty ───────────────────────────────
const recordDialog = ref(false)
const recordForm = useForm({ tax_type_id: null as number | null, entity_id: props.business, source: 'assessment', description: '', amount: '', due_date: '', notes: '' })
function record() {
  recordForm.transform(d => ({ ...d, due_date: d.due_date || null })).post(route('tax.liabilities.store'), { preserveScroll: true, onSuccess: () => { recordDialog.value = false } })
}
function exportCsv() {
  window.location.href = route('tax.liabilities.export', props.business ? { business: props.business } : {})
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Liabilities and payments</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          What is owed to SARS, from approved returns and assessments. Payments are made and recorded in Finance; here they are allocated to what they settle.
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-download" @click="exportCsv">Export CSV</VBtn>
        <VBtn v-if="can.record" color="primary" prepend-icon="tabler-plus" @click="recordDialog = true">Record assessment</VBtn>
      </div>
    </div>

    <VCard border>
      <VCardText class="d-flex flex-wrap gap-2 pb-0 align-center">
        <BusinessFilter :businesses="businesses" :business="business" />
        <VChip v-for="s in [null, 'outstanding', 'paid', 'overpaid', 'refund_due']" :key="String(s)" :color="filters.status === s ? 'primary' : undefined"
               :variant="filters.status === s ? 'tonal' : 'outlined'" @click="status(s)">{{ s ? label(s) : 'All' }}</VChip>
      </VCardText>
      <VTable density="comfortable">
        <thead><tr><th /><th>Liability</th><th class="text-right">Owed</th><th class="text-right">Paid</th><th class="text-right">Outstanding</th><th>Due</th><th>Status</th><th /></tr></thead>
        <tbody>
          <template v-for="l in liabilities" :key="l.uuid">
            <tr>
              <td><VBtn size="x-small" variant="text" :icon="open === l.uuid ? 'tabler-chevron-down' : 'tabler-chevron-right'" @click="open = open === l.uuid ? null : l.uuid" /></td>
              <td>{{ l.description }}
                <div class="text-caption text-disabled">{{ l.tax_type }} · {{ l.entity }} · {{ label(l.source) }}
                  <a v-if="l.return" href="#" @click.prevent="router.get(route('tax.returns.show', l.return.uuid))"> · {{ l.return.reference }}</a></div>
              </td>
              <td class="text-right">{{ money(Number(l.original) + Number(l.adjustments)) }}</td>
              <td class="text-right">{{ money(l.paid) }}</td>
              <td class="text-right font-weight-medium" :class="l.overdue ? 'text-error' : ''">{{ money(l.outstanding) }}</td>
              <td :class="l.overdue ? 'text-error' : ''">{{ l.due_date ?? '—' }}<span v-if="l.overdue" class="text-caption"> · overdue</span></td>
              <td><VChip size="small" :color="l.status === 'paid' ? 'success' : l.status === 'overpaid' ? 'warning' : l.overdue ? 'error' : statusColor[l.status]" label>{{ label(l.status) }}</VChip></td>
              <td class="text-right"><VBtn v-if="can.allocate && ['open', 'part_paid'].includes(l.status)" size="x-small" variant="tonal" @click="openAllocate(l)">Allocate payment</VBtn></td>
            </tr>
            <tr v-if="open === l.uuid">
              <td colspan="8" class="bg-surface-light">
                <div class="text-overline">Payments allocated</div>
                <div v-if="!l.allocations.length" class="text-caption text-medium-emphasis pb-2">None yet.</div>
                <div v-for="a in l.allocations" :key="a.id" class="d-flex align-center gap-2 text-body-2 pb-1" :class="a.reversed ? 'text-disabled text-decoration-line-through' : ''">
                  {{ money(a.amount) }} · {{ a.payment?.number }} ({{ a.payment?.date }}{{ a.payment?.reference ? ', ' + a.payment.reference : '' }}) · {{ a.at }}
                  <span v-if="a.note" class="text-caption">· {{ a.note }}</span>
                  <span v-if="a.reversed" class="text-caption text-error">reversed: {{ a.reversal_reason }}</span>
                  <VBtn v-if="can.allocate && !a.reversed" size="x-small" variant="text" color="warning" @click="reverseTarget = a; reverseForm.reset(); reverseDialog = true">Reverse</VBtn>
                </div>
                <EvidencePanel type="liabilities" :uuid="l.uuid" :items="l.evidence" :can-add="can.allocate || can.record" class="pb-2"
                               :hint="l.source === 'assessment' || l.source === 'penalty' || l.source === 'interest' ? 'Attach the SARS notice this was recorded from.' : undefined" />
              </td>
            </tr>
          </template>
          <tr v-if="!liabilities.length"><td colspan="8" class="text-center text-medium-emphasis py-6">No liabilities. They are created when a return is approved, or recorded from a SARS notice.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="allocDialog" max-width="640">
      <VCard v-if="target" :title="`Allocate a payment · ${target.description}`">
        <VCardText>
          <p class="text-body-2">Outstanding {{ money(target.outstanding) }}. Choose a payment already recorded in Finance.</p>
          <VProgressLinear v-if="loading" indeterminate class="mb-2" />
          <VRadioGroup v-model="allocForm.payment_id" :error-messages="allocForm.errors.payment_id">
            <VRadio v-for="c in candidates" :key="c.id" :value="c.id">
              <template #label>{{ c.number }} · {{ c.date }} · {{ c.party }} · {{ money(c.amount) }} <span class="text-caption text-medium-emphasis ms-1">({{ money(c.available) }} unallocated{{ c.reference ? ', ' + c.reference : '' }})</span></template>
            </VRadio>
          </VRadioGroup>
          <p v-if="!loading && !candidates.length" class="text-caption text-medium-emphasis">No recorded Finance payment has money left to allocate. Record the SARS payment in Finance first.</p>
          <VTextField v-model="allocForm.amount" label="Amount" :error-messages="allocForm.errors.amount" />
          <VTextField v-model="allocForm.note" label="Note (e.g. PRN)" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="allocDialog = false">Cancel</VBtn><VBtn color="primary" :loading="allocForm.processing" :disabled="!allocForm.payment_id" @click="allocate">Allocate</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="reverseDialog" max-width="480">
      <VCard title="Reverse allocation">
        <VCardText><VTextarea v-model="reverseForm.reason" label="Reason" rows="2" auto-grow :error-messages="reverseForm.errors.reason" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="reverseDialog = false">Cancel</VBtn><VBtn color="warning" :loading="reverseForm.processing" @click="doReverse">Reverse</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="recordDialog" max-width="560">
      <VCard title="Record an assessment, interest or penalty">
        <VCardText>
          <VSelect v-model="recordForm.tax_type_id" :items="taxTypes" :item-title="(t: any) => `${t.code} — ${t.name}`" item-value="id" label="Tax" :error-messages="recordForm.errors.tax_type_id" />
          <VSelect v-if="businesses.length > 1" v-model="recordForm.entity_id" :items="businesses" item-title="name" item-value="id" label="Business" />
          <VSelect v-model="recordForm.source" :items="[{ title: 'Assessment', value: 'assessment' }, { title: 'Interest', value: 'interest' }, { title: 'Penalty', value: 'penalty' }]" label="Kind" />
          <VTextField v-model="recordForm.description" label="Description (e.g. ITA34 assessment 2025)" :error-messages="recordForm.errors.description" />
          <VTextField v-model="recordForm.amount" label="Amount (negative if SARS owes a refund)" :error-messages="recordForm.errors.amount" />
          <VTextField v-model="recordForm.due_date" type="date" label="Due date" />
          <VTextarea v-model="recordForm.notes" label="Notes" rows="2" auto-grow />
          <p class="text-caption text-medium-emphasis">Record from the SARS notice, and file the notice in Document Management against the liability.</p>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="recordDialog = false">Cancel</VBtn><VBtn color="primary" :loading="recordForm.processing" @click="record">Record</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
