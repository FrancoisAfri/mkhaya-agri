<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'
import EvidencePanel from './components/EvidencePanel.vue'

const route = ziggyRoute

type Item = {
  id: number; side: string; reference: string | null; description: string | null; source: string; target: string; difference: string
  state: string; class: string | null; explanation: string | null; explained_by: string | null; links: Record<string, unknown> | null
  assigned_to: number | null; assignee: string | null
}

const props = defineProps<{
  reconciliation: {
    uuid: string; type: string; period: string | null; period_uuid: string | null; entity: string | null; version: number; status: string
    source_total: string; target_total: string; difference: string; tolerance: string; matched: number; unmatched: number; exceptions: number
    prepared_by: string | null; prepared_by_id: number | null; signed_off_by: string | null; signed_off_at: string | null; created_at: string | null
    summary: { by_class: Record<string, { count: number; amount: string }>; over_tolerance: number } | null
    type_key: string
    // Each type records its own diagnostics: control accounts (VAT), payroll accounts, document or liability counts.
    diagnostics: Record<string, any> | null
    sign_off_comment: string | null; cutoff_at: string | null
    evidence: Array<{ id: number; title: string; added: string | null; url: string }>
  }
  items: Item[]
  can: { reconcile: boolean; sign_off: boolean; reopen: boolean }
  me: number
  people: Array<{ id: number; name: string }>
}>()

const r = computed(() => props.reconciliation)

/** What the two totals are, per reconciliation type (source = what tax expects, target = what it is checked against). */
const sides = computed(() => ({
  vat_register_to_gl: { source: 'VAT register (net)', target: 'Control accounts (net)' },
  payroll_to_gl: { source: 'Payroll taxes', target: 'Ledger accounts' },
  register_completeness: { source: 'VAT register', target: 'Finance documents' },
  liabilities_to_payments: { source: 'Liabilities owed', target: 'Payments allocated' },
}[r.value.type_key] ?? { source: 'Source', target: 'Target' }))

const compared = computed(() => {
  const d = r.value.diagnostics ?? {}
  switch (r.value.type_key) {
    case 'vat_register_to_gl': return `Output ${d.control_accounts?.output?.join(', ') || '—'} · Input ${d.control_accounts?.input?.join(', ') || '—'}`
    case 'payroll_to_gl': return Object.entries(d.accounts ?? {}).map(([k, v]) => `${k.replace('_', ' ')} ${v ?? 'not posted'}`).join(' · ') || '—'
    case 'register_completeness': return `${d.documents ?? 0} Finance document(s)`
    case 'liabilities_to_payments': return `${d.liabilities ?? 0} liabilit(ies) · ${d.unallocated_payments ?? 0} unallocated payment(s)`
    default: return '—'
  }
})
const needsExplaining = computed(() => props.items.filter(i => !['matched', 'merged'].includes(i.state) && Math.abs(Number(i.difference)) > Number(r.value.tolerance) && !i.explanation).length)
const stateFilter = ref<string | null>(null)
const shown = computed(() => props.items.filter(i => !stateFilter.value || i.state === stateFilter.value))
const ownRun = computed(() => r.value.prepared_by_id === props.me)

const explainDialog = ref(false)
const target = ref<Item | null>(null)
const explainForm = useForm({ explanation: '', class: null as string | null })
function openExplain(i: Item) {
  target.value = i
  explainForm.explanation = i.explanation ?? ''
  explainForm.class = i.class
  explainDialog.value = true
}
function explain() {
  if (!target.value) return
  explainForm.post(route('tax.reconciliations.explain', target.value.id), { preserveScroll: true, onSuccess: () => { explainDialog.value = false } })
}

// ── Manual match (§12.2 step 6) and assignment (step 8) ─────────────────────
const matchDialog = ref(false)
const matchFrom = ref<Item | null>(null)
const matchForm = useForm({ with: null as number | null, rationale: '' })
const candidates = computed(() => props.items.filter(i => matchFrom.value && i.id !== matchFrom.value.id
  && !['matched', 'merged'].includes(i.state) && i.side !== matchFrom.value.side))
function openMatch(i: Item) {
  matchFrom.value = i
  matchForm.reset()
  matchDialog.value = true
}
function doMatch() {
  if (!matchFrom.value) return
  matchForm.post(route('tax.reconciliations.match', matchFrom.value.id), { preserveScroll: true, onSuccess: () => { matchDialog.value = false } })
}
function assign(i: Item, userId: number | null) {
  router.post(route('tax.reconciliations.assign', i.id), { user_id: userId }, { preserveScroll: true })
}

const signDialog = ref(false)
const signForm = useForm({ comment: '' })
function signOff() {
  signForm.post(route('tax.reconciliations.sign_off', r.value.uuid), { preserveScroll: true, onSuccess: () => { signDialog.value = false } })
}
const reopenDialog = ref(false)
const reopenForm = useForm({ reason: '' })
function reopen() {
  reopenForm.post(route('tax.reconciliations.reopen', r.value.uuid), { preserveScroll: true, onSuccess: () => { reopenDialog.value = false } })
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('tax.reconciliations.index'))">Reconciliations</VBtn>

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">{{ r.type }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          <a v-if="r.period_uuid" href="#" @click.prevent="router.get(route('tax.periods.show', r.period_uuid))">{{ r.period }}</a> · {{ r.entity }} · version {{ r.version }}
          · cutoff {{ r.cutoff_at }} · run by {{ r.prepared_by }}
        </p>
      </div>
      <div class="d-flex gap-2 align-center">
        <VChip :color="statusColor[r.status]" label>{{ label(r.status) }}</VChip>
        <VBtn v-if="can.sign_off && r.status === 'completed'" color="success" variant="tonal" :disabled="ownRun || needsExplaining > 0" @click="signDialog = true">Sign off</VBtn>
        <VBtn v-if="can.reopen && r.status === 'signed_off'" color="warning" variant="text" @click="reopenDialog = true">Reopen</VBtn>
      </div>
    </div>

    <VAlert v-if="r.status === 'completed' && ownRun && can.sign_off" type="info" variant="tonal" density="compact" class="mb-3">You ran this reconciliation, so someone else signs it off.</VAlert>
    <VAlert v-if="r.status === 'completed' && needsExplaining" type="warning" variant="tonal" density="compact" class="mb-3">
      {{ needsExplaining }} difference(s) above {{ money(r.tolerance) }} need an explanation before sign-off.
    </VAlert>
    <VAlert v-if="r.status === 'signed_off'" type="success" variant="tonal" density="compact" class="mb-3">
      Signed off by {{ r.signed_off_by }} on {{ r.signed_off_at }}<span v-if="r.sign_off_comment"> — {{ r.sign_off_comment }}</span>. This record will not change.
    </VAlert>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ sides.source }}</div><div class="text-h6">{{ money(r.source_total) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ sides.target }}</div><div class="text-h6">{{ money(r.target_total) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">Difference</div><div class="text-h6" :class="Math.abs(Number(r.difference)) > Number(r.tolerance) ? 'text-error' : 'text-success'">{{ money(r.difference) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">Compared</div>
        <div class="text-body-2">{{ compared }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border class="mb-4"><VCardText>
      <EvidencePanel type="reconciliations" :uuid="r.uuid" :items="r.evidence" :can-add="can.reconcile" hint="Attach workings, statements or correcting-journal support." />
    </VCardText></VCard>

    <VCard border>
      <VCardText class="d-flex gap-2 flex-wrap pb-0">
        <VChip v-for="s in [null, 'exception', 'unmatched', 'partial', 'matched', 'merged']" :key="String(s)" :color="stateFilter === s ? 'primary' : undefined"
               :variant="stateFilter === s ? 'tonal' : 'outlined'" @click="stateFilter = s">
          {{ s ? label(s) : 'All' }} · {{ s ? items.filter(i => i.state === s).length : items.length }}
        </VChip>
      </VCardText>
      <VTable density="compact">
        <thead><tr><th>Item</th><th class="text-right">Register</th><th class="text-right">Ledger</th><th class="text-right">Difference</th><th>Match</th><th>Explanation</th><th /></tr></thead>
        <tbody>
          <tr v-for="i in shown" :key="i.id">
            <td>{{ i.reference }}<div class="text-caption text-medium-emphasis">{{ i.description }}</div></td>
            <td class="text-right">{{ money(i.source) }}</td>
            <td class="text-right">{{ money(i.target) }}</td>
            <td class="text-right" :class="Number(i.difference) !== 0 ? 'text-error' : ''">{{ money(i.difference) }}</td>
            <td>
              <VChip size="x-small" :color="statusColor[i.state]" label>{{ label(i.state) }}</VChip>
              <div v-if="i.class" class="text-caption text-medium-emphasis">{{ i.class }}</div>
            </td>
            <td class="text-caption" style="max-inline-size: 320px">{{ i.explanation ?? '—' }}<div v-if="i.explained_by" class="text-disabled">{{ i.explained_by }}</div>
              <div v-if="i.assignee" class="text-info">Assigned to {{ i.assignee }}</div></td>
            <td class="text-right text-no-wrap">
              <template v-if="can.reconcile && r.status === 'completed' && !['matched', 'merged'].includes(i.state)">
                <VBtn v-if="i.side !== 'both'" size="x-small" variant="text" @click="openMatch(i)">Match</VBtn>
                <VMenu>
                  <template #activator="{ props: m }"><VBtn v-bind="m" size="x-small" variant="text">Assign</VBtn></template>
                  <VList density="compact" max-height="300">
                    <VListItem title="Nobody" @click="assign(i, null)" />
                    <VListItem v-for="p in people" :key="p.id" :title="p.name" :active="p.id === i.assigned_to" @click="assign(i, p.id)" />
                  </VList>
                </VMenu>
                <VBtn size="x-small" variant="tonal" @click="openExplain(i)">{{ i.explanation ? 'Edit' : 'Explain' }}</VBtn>
              </template>
            </td>
          </tr>
          <tr v-if="!shown.length"><td colspan="7" class="text-center text-medium-emphasis py-4">Nothing in this view.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="explainDialog" max-width="560">
      <VCard v-if="target" :title="`Explain ${target.reference}`">
        <VCardText>
          <VSelect v-model="explainForm.class" :items="[{ title: 'Timing', value: 'timing' }, { title: 'Rounding', value: 'rounding' }, { title: 'Missing transaction', value: 'missing' }, { title: 'Genuine discrepancy', value: 'genuine' }]"
                   label="Kind of difference" class="mb-2" />
          <VTextarea v-model="explainForm.explanation" label="Explanation and any follow-up" rows="3" auto-grow :error-messages="explainForm.errors.explanation" />
          <p class="text-caption text-medium-emphasis">Nothing is posted or written off from here. A correcting journal is raised in Finance, with its own approval.</p>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="explainDialog = false">Cancel</VBtn><VBtn color="primary" :loading="explainForm.processing" @click="explain">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="matchDialog" max-width="640">
      <VCard v-if="matchFrom" :title="`Match ${matchFrom.reference}`">
        <VCardText>
          <p class="text-body-2">{{ matchFrom.description }} · {{ money(matchFrom.side === 'target' ? matchFrom.target : matchFrom.source) }}</p>
          <VRadioGroup v-model="matchForm.with">
            <VRadio v-for="c in candidates" :key="c.id" :value="c.id">
              <template #label>{{ c.reference }} · {{ money(c.side === 'target' ? c.target : c.source) }} <span class="text-caption text-medium-emphasis ms-1">{{ c.description }}</span></template>
            </VRadio>
          </VRadioGroup>
          <p v-if="!candidates.length" class="text-caption text-medium-emphasis">No unmatched item on the other side.</p>
          <VTextarea v-model="matchForm.rationale" label="Why they belong together" rows="2" auto-grow :error-messages="matchForm.errors.rationale" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="matchDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="matchForm.processing" :disabled="!matchForm.with || matchForm.rationale.trim().length < 10" @click="doMatch">Match</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="signDialog" max-width="520">
      <VCard title="Sign off reconciliation">
        <VCardText>
          <p class="text-body-2">Signing off fixes this version as the reconciliation relied on for {{ r.period }}. It cannot be edited afterwards.</p>
          <VTextarea v-model="signForm.comment" label="Comment (optional)" rows="2" auto-grow />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="signDialog = false">Cancel</VBtn><VBtn color="success" :loading="signForm.processing" @click="signOff">Sign off</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="reopenDialog" max-width="520">
      <VCard title="Reopen reconciliation">
        <VCardText>
          <VTextarea v-model="reopenForm.reason" label="Reason for reopening" rows="3" auto-grow :error-messages="reopenForm.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="reopenDialog = false">Cancel</VBtn><VBtn color="warning" :loading="reopenForm.processing" :disabled="reopenForm.reason.trim().length < 10" @click="reopen">Reopen</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
