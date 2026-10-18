<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'

const route = ziggyRoute

type Line = { field: string; label: string; kind: string; calculated: string; adjustment: string; final: string; adjustment_reason: string | null; explanation: string | null; source_count: number }

const props = defineProps<{
  taxReturn: {
    uuid: string; reference: string; form: string; tax_type: string; period: string | null; period_uuid: string | null; entity: string | null
    version: number; purpose: string; status: string; amount_payable: string; submission_status: string; submission_reference: string | null
    payment_status: string; due_date: string | null; lines: Line[]
    validation: { errors?: string[]; warnings?: string[]; comparison?: Array<{ field: string; label: string; submitted: string; now: string }>; checked_at?: string } | null
    snapshot: { lines: number; taken_at: string } | null
    prepared_by: string | null; prepared_at: string | null; reviewed_by: string | null; reviewed_at: string | null; review_comment: string | null
    approved_by: string | null; approved_at: string | null; approval_comment: string | null
    submission_method: string | null; submission_response: string | null; submitted_at: string | null
    ids: { prepared_by: number | null; reviewed_by: number | null }; form_label: string
  }
  evidence: Array<{ key: string; label: string; required_for: string | null; documents: Array<{ id: number; title: string }> }>
  versions: Array<{ uuid: string; version: number; status: string; purpose: string; submission_status: string; amount_payable: string }>
  can: { prepare: boolean; review: boolean; approve: boolean }
  me: number
}>()

const r = computed(() => props.taxReturn)
const errors = computed(() => r.value.validation?.errors ?? [])
const warnings = computed(() => r.value.validation?.warnings ?? [])
const mine = computed(() => [r.value.ids.prepared_by, r.value.ids.reviewed_by].includes(props.me))

// ── Adjust / explain ────────────────────────────────────────────────────────
const lineDialog = ref(false)
const line = ref<Line | null>(null)
const adjustForm = useForm({ field: '', amount: '', reason: '' })
const explainForm = useForm({ field: '', explanation: '' })
function openLine(l: Line) {
  line.value = l
  adjustForm.reset(); explainForm.reset()
  adjustForm.field = explainForm.field = l.field
  adjustForm.amount = l.adjustment !== '0.00' ? l.adjustment : ''
  explainForm.explanation = l.explanation ?? ''
  lineDialog.value = true
}
const editable = computed(() => props.can.prepare && r.value.status === 'prepared')

// ── Drill-down to the register lines behind a field (§3.2) ──────────────────
const sourcesDialog = ref(false)
const sourcesFor = ref<Line | null>(null)
const sources = ref<{ people_hidden: boolean; rows: Array<{ uuid: string; date: string; type: string; document: string | null; party: string | null; code: string | null; amount: string; version: number; changed_since: boolean }> } | null>(null)
async function openSources(l: Line) {
  sourcesFor.value = l
  sources.value = null
  sourcesDialog.value = true
  const res = await fetch(route('tax.returns.sources', [r.value.uuid, l.field]), { headers: { Accept: 'application/json' } })
  sources.value = res.ok ? await res.json() : { people_hidden: false, rows: [] }
}

// ── Workflow actions ────────────────────────────────────────────────────────
type Act = 'review' | 'return' | 'approve' | 'submission' | 'outcome'
const actDialog = ref(false)
const act = ref<Act>('review')
const actForm = useForm({ comment: '', outcome: 'submitted', method: 'manual_efiling', reference: '', note: '', response: '', checked_efiling: false })
function openAct(a: Act) {
  act.value = a
  actForm.reset()
  actForm.outcome = a === 'outcome' ? 'accepted' : 'submitted'
  actDialog.value = true
}
function doAct() {
  const opts = { preserveScroll: true, onSuccess: () => { actDialog.value = false } }
  const u = r.value.uuid
  if (act.value === 'review' || act.value === 'return') actForm.transform(d => ({ pass: act.value === 'review', comment: d.comment })).post(route('tax.returns.review', u), opts)
  if (act.value === 'approve') actForm.transform(d => ({ comment: d.comment || null })).post(route('tax.returns.approve', u), opts)
  if (act.value === 'submission') actForm.transform(d => ({ outcome: d.outcome, method: d.method, reference: d.reference || null, note: d.note || null, checked_efiling: d.checked_efiling })).post(route('tax.returns.submission', u), opts)
  if (act.value === 'outcome') actForm.transform(d => ({ outcome: d.outcome, reference: d.reference, response: d.response || null })).post(route('tax.returns.outcome', u), opts)
}
const actTitle: Record<Act, string> = { review: 'Pass to the approver', return: 'Send back to the preparer', approve: 'Approve the return', submission: 'Record the submission', outcome: 'Record SARS\'s outcome' }

// ── Evidence (filed in Document Management) ─────────────────────────────────
const evidenceForm = useForm({ key: '', file: null as File | null, title: '' })
const evidenceDialog = ref(false)
function openEvidence(key: string) {
  evidenceForm.reset()
  evidenceForm.key = key
  evidenceDialog.value = true
}
function upload() {
  evidenceForm.post(route('tax.returns.evidence', r.value.uuid), { forceFormData: true, preserveScroll: true, onSuccess: () => { evidenceDialog.value = false } })
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('tax.returns.index'))">Tax returns</VBtn>

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">{{ r.reference }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ r.form_label }} · {{ r.entity }} ·
          <a v-if="r.period_uuid" href="#" @click.prevent="router.get(route('tax.periods.show', r.period_uuid))">{{ r.period }}</a>
          <span v-if="r.purpose === 'amendment'"> · amendment</span> · due {{ r.due_date ?? '—' }}
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VChip :color="statusColor[r.status]" label>{{ label(r.status) }}</VChip>
        <VChip label :color="r.submission_status === 'unknown' ? 'error' : undefined">SARS: {{ label(r.submission_status) }}</VChip>
        <VBtn v-if="editable" variant="text" @click="router.post(route('tax.returns.validate', r.uuid), {}, { preserveScroll: true })">Run checks</VBtn>
        <VBtn v-if="editable" color="primary" variant="tonal" @click="router.post(route('tax.returns.send', r.uuid), {}, { preserveScroll: true })">Send for review</VBtn>
        <template v-if="r.status === 'under_review' && !r.reviewed_by && can.review">
          <VBtn color="warning" variant="text" @click="openAct('return')">Send back</VBtn>
          <VBtn color="primary" variant="tonal" :disabled="mine" @click="openAct('review')">Pass review</VBtn>
        </template>
        <VBtn v-if="r.status === 'under_review' && r.reviewed_by && can.approve" color="success" variant="tonal" :disabled="mine" @click="openAct('approve')">Approve</VBtn>
        <VMenu v-if="['approved', 'submitted', 'accepted'].includes(r.status)">
          <template #activator="{ props: m }"><VBtn v-bind="m" variant="text" prepend-icon="tabler-file-download">eFiling capture sheet</VBtn></template>
          <VList density="compact">
            <VListItem title="PDF" :href="route('tax.returns.capture', r.uuid)" />
            <VListItem title="CSV" :href="route('tax.returns.capture', [r.uuid, { format: 'csv' }])" />
          </VList>
        </VMenu>
        <VBtn v-if="['approved'].includes(r.status) && can.approve" color="primary" variant="tonal" @click="openAct('submission')">Record submission</VBtn>
        <VBtn v-if="r.status === 'submitted' && can.approve" color="primary" variant="tonal" @click="openAct('outcome')">Record SARS outcome</VBtn>
      </div>
    </div>

    <VAlert v-if="mine && ['under_review'].includes(r.status)" type="info" variant="tonal" density="compact" class="mb-3">
      You prepared or reviewed this return, so the next step is someone else's.
    </VAlert>
    <VAlert v-if="r.submission_status === 'unknown'" type="error" variant="tonal" density="compact" class="mb-3">
      The submission outcome is unknown. Check eFiling before trying again — re-filing blindly can create a duplicate submission.
    </VAlert>
    <VAlert v-if="errors.length" type="error" variant="tonal" class="mb-3">
      <div class="font-weight-medium mb-1">Checks that fail</div>
      <ul class="ps-4"><li v-for="e in errors" :key="e">{{ e }}</li></ul>
    </VAlert>
    <VAlert v-if="warnings.length" type="warning" variant="tonal" class="mb-3">
      <ul class="ps-4"><li v-for="w in warnings" :key="w">{{ w }}</li></ul>
    </VAlert>

    <VRow>
      <VCol cols="12" md="8">
        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">Return fields</VCardTitle>
            <VCardSubtitle>Drawn from {{ r.snapshot?.lines ?? 0 }} register line(s) at {{ r.snapshot?.taken_at }}. Adjusted values keep the calculated value beside them.</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead><tr><th>Field</th><th>Description</th><th class="text-right">Calculated</th><th class="text-right">Adjustment</th><th class="text-right">On the return</th><th /></tr></thead>
            <tbody>
              <tr v-for="l in r.lines" :key="l.field" :class="l.kind === 'total' ? 'font-weight-bold' : ''">
                <td>{{ l.field }}</td>
                <td>{{ l.label }}
                  <div v-if="l.adjustment_reason" class="text-caption text-warning">Adjusted: {{ l.adjustment_reason }}</div>
                  <div v-if="l.explanation" class="text-caption text-medium-emphasis">{{ l.explanation }}</div>
                  <a v-if="l.kind !== 'total' && l.source_count" href="#" class="text-caption" @click.prevent="openSources(l)">{{ l.source_count }} source line(s)</a>
                  <div v-else-if="l.kind !== 'total'" class="text-caption text-disabled">no source lines</div>
                </td>
                <td class="text-right">{{ money(l.calculated) }}</td>
                <td class="text-right" :class="l.adjustment !== '0.00' ? 'text-warning' : 'text-disabled'">{{ l.adjustment !== '0.00' ? money(l.adjustment) : '—' }}</td>
                <td class="text-right">{{ money(l.final) }}</td>
                <td class="text-right"><VBtn v-if="editable && l.kind !== 'total'" size="x-small" variant="text" @click="openLine(l)">Adjust / explain</VBtn></td>
              </tr>
            </tbody>
          </VTable>
          <VCardText class="d-flex justify-end text-h6">
            {{ Number(r.amount_payable) >= 0 ? 'Payable' : 'Refundable' }}: {{ money(Math.abs(Number(r.amount_payable))) }}
            <span class="text-caption ms-2 align-self-center" :class="['approved', 'submitted', 'accepted'].includes(r.status) ? 'text-success' : 'text-warning'">
              ({{ ['approved', 'submitted', 'accepted'].includes(r.status) ? 'approved' : 'draft' }})
            </span>
          </VCardText>
        </VCard>

        <VCard v-if="r.validation?.comparison?.length" border>
          <VCardItem><VCardTitle class="text-body-1">Against the version already submitted</VCardTitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>Field</th><th class="text-right">Submitted</th><th class="text-right">Now</th></tr></thead>
            <tbody><tr v-for="c in r.validation.comparison" :key="c.field"><td>{{ c.field }} {{ c.label }}</td><td class="text-right">{{ money(c.submitted) }}</td><td class="text-right">{{ money(c.now) }}</td></tr></tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Evidence</VCardTitle><VCardSubtitle>Filed in Document Management</VCardSubtitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="e in evidence" :key="e.key">
              <VListItemTitle class="text-body-2">
                <VIcon :icon="e.documents.length ? 'tabler-circle-check' : 'tabler-circle'" :color="e.documents.length ? 'success' : e.required_for ? 'warning' : undefined" size="16" />
                {{ e.label }}
              </VListItemTitle>
              <VListItemSubtitle>
                <span v-if="e.required_for">Required to {{ e.required_for === 'approve' ? 'approve' : 'record acceptance' }}</span>
                <span v-for="d in e.documents" :key="d.id" class="d-block">{{ d.title }}</span>
              </VListItemSubtitle>
              <template #append><VBtn v-if="can.prepare && r.status !== 'superseded'" size="x-small" variant="text" icon="tabler-upload" @click="openEvidence(e.key)" /></template>
            </VListItem>
          </VList>
        </VCard>

        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Who did what</VCardTitle></VCardItem>
          <VCardText class="text-body-2">
            <div>Prepared: {{ r.prepared_by ?? '—' }} <span class="text-caption text-disabled">{{ r.prepared_at }}</span></div>
            <div>Reviewed: {{ r.reviewed_by ?? '—' }} <span class="text-caption text-disabled">{{ r.reviewed_at }}</span>
              <div v-if="r.review_comment" class="text-caption text-medium-emphasis">{{ r.review_comment }}</div></div>
            <div>Approved: {{ r.approved_by ?? '—' }} <span class="text-caption text-disabled">{{ r.approved_at }}</span></div>
            <div v-if="r.submission_reference" class="mt-2">SARS reference: {{ r.submission_reference }} <span class="text-caption text-disabled">{{ r.submitted_at }}</span></div>
            <div v-if="r.submission_response" class="text-caption text-medium-emphasis" style="white-space: pre-line">{{ r.submission_response }}</div>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Versions</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="v in versions" :key="v.uuid" :active="v.uuid === r.uuid" @click="router.get(route('tax.returns.show', v.uuid))">
              <VListItemTitle class="text-body-2">v{{ v.version }} · {{ label(v.purpose) }} · {{ money(v.amount_payable) }}</VListItemTitle>
              <VListItemSubtitle>{{ label(v.status) }} · SARS {{ label(v.submission_status) }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="sourcesDialog" max-width="900">
      <VCard v-if="sourcesFor" :title="`Field ${sourcesFor.field}: ${sourcesFor.label}`">
        <VCardText>
          <VProgressLinear v-if="!sources" indeterminate />
          <template v-else>
            <p v-if="sources.people_hidden" class="text-caption text-medium-emphasis">Employee names are hidden: they need the "view payroll tax" permission.</p>
            <VTable density="compact">
              <thead><tr><th>Date</th><th>Document</th><th>Party</th><th>Code</th><th class="text-right">Amount</th><th>Version</th></tr></thead>
              <tbody>
                <tr v-for="s in sources.rows" :key="s.uuid">
                  <td>{{ s.date }}</td><td>{{ s.document }}<div class="text-caption text-disabled">{{ label(s.type) }}</div></td>
                  <td>{{ s.party ?? '—' }}</td><td>{{ s.code }}</td><td class="text-right">{{ money(s.amount) }}</td>
                  <td>v{{ s.version }}<span v-if="s.changed_since" class="text-caption text-warning"> · changed since</span></td>
                </tr>
              </tbody>
            </VTable>
          </template>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="sourcesDialog = false">Close</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="lineDialog" max-width="560">
      <VCard v-if="line" :title="`Field ${line.field}: ${line.label}`">
        <VCardText>
          <p class="text-body-2">Calculated from the register: <strong>{{ money(line.calculated) }}</strong></p>
          <VTextField v-model="adjustForm.amount" label="Adjustment (+/−)" :error-messages="adjustForm.errors.amount" />
          <VTextarea v-model="adjustForm.reason" label="Reason for the adjustment" rows="2" auto-grow :error-messages="adjustForm.errors.reason" />
          <div class="d-flex justify-end mb-4">
            <VBtn color="warning" variant="tonal" :loading="adjustForm.processing" :disabled="!adjustForm.amount || adjustForm.reason.trim().length < 10"
                  @click="adjustForm.post(route('tax.returns.adjust', r.uuid), { preserveScroll: true, onSuccess: () => { lineDialog = false } })">Save adjustment</VBtn>
          </div>
          <VDivider class="mb-4" />
          <VTextarea v-model="explainForm.explanation" label="Explanation for the reviewer (no change to the value)" rows="2" auto-grow />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="lineDialog = false">Close</VBtn>
          <VBtn color="primary" :loading="explainForm.processing" :disabled="explainForm.explanation.trim().length < 3"
                @click="explainForm.post(route('tax.returns.explain', r.uuid), { preserveScroll: true, onSuccess: () => { lineDialog = false } })">Save explanation</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="actDialog" max-width="560">
      <VCard :title="actTitle[act]">
        <VCardText>
          <template v-if="act === 'submission'">
            <VSelect v-model="actForm.outcome" :items="[{ title: 'Submitted — SARS reference received', value: 'submitted' }, { title: 'Sent — waiting for acknowledgement', value: 'pending' }, { title: 'Outcome unknown (e.g. eFiling timed out)', value: 'unknown' }]" label="What happened" />
            <VSelect v-model="actForm.method" :items="[{ title: 'Manual eFiling', value: 'manual_efiling' }, { title: 'Integration', value: 'integration' }]" label="How" />
            <VTextField v-model="actForm.reference" label="SARS reference" :hint="actForm.outcome === 'submitted' ? 'Required' : 'If you have one'" persistent-hint />
            <VTextarea v-model="actForm.note" label="Note" rows="2" auto-grow class="mt-2" />
            <VCheckbox v-if="r.submission_status === 'unknown' && actForm.outcome !== 'submitted'" v-model="actForm.checked_efiling" density="compact"
                       label="I checked eFiling: the earlier attempt was not received" />
          </template>
          <template v-else-if="act === 'outcome'">
            <VBtnToggle v-model="actForm.outcome" mandatory variant="outlined" divided density="compact" class="mb-3">
              <VBtn value="accepted">Accepted</VBtn><VBtn value="rejected">Rejected</VBtn>
            </VBtnToggle>
            <VTextField v-model="actForm.reference" label="Acknowledgement / rejection reference" :error-messages="actForm.errors.reference" />
            <VTextarea v-model="actForm.response" label="SARS's response" rows="3" auto-grow />
            <p v-if="actForm.outcome === 'accepted'" class="text-caption text-medium-emphasis">The SARS receipt must be attached as evidence first.</p>
          </template>
          <template v-else>
            <p v-if="act === 'approve'" class="text-body-2">Approving fixes the figures, creates the {{ money(r.amount_payable) }} liability and the SARS submission record. Changes after this are an amendment.</p>
            <VTextarea v-model="actForm.comment" :label="act === 'approve' ? 'Comment (optional)' : 'Comment'" rows="3" auto-grow :error-messages="actForm.errors.comment" />
          </template>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="actDialog = false">Cancel</VBtn><VBtn color="primary" :loading="actForm.processing" @click="doAct">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="evidenceDialog" max-width="520">
      <VCard title="Attach evidence">
        <VCardText>
          <p class="text-caption text-medium-emphasis">Stored in Document Management (file checks and malware scanning apply) and linked to this return. Evidence is added, never replaced; a correction is an amendment.</p>
          <VFileInput label="File" density="compact" @update:model-value="(f: any) => evidenceForm.file = Array.isArray(f) ? f[0] : f" :error-messages="evidenceForm.errors.file" />
          <VTextField v-model="evidenceForm.title" label="Title (optional)" density="compact" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="evidenceDialog = false">Cancel</VBtn><VBtn color="primary" :loading="evidenceForm.processing" :disabled="!evidenceForm.file" @click="upload">Upload</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
