<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'

const route = ziggyRoute

type Line = { id: number; section: string; account_code: string | null; description: string; amount: string; source: string; reason: string | null; reference: string | null }

const props = defineProps<{
  computation: {
    uuid: string; label: string; kind: string; number: number | null; version: number; status: string; entity: string | null
    period: string | null; period_uuid: string | null; year_start: string; year_end: string; basis: string | null
    lines: Line[]; figures: Record<string, string>; rate: string | null; basic_amount: string | null
    validation: { errors?: string[]; warnings?: string[] } | null; snapshot: Record<string, unknown> | null
    prepared_by: string | null; reviewed_by: string | null; approved_by: string | null; review_comment: string | null
    ids: { prepared_by: number | null; reviewed_by: number | null }; documents: Array<{ id: number; title: string; url?: string }>
  }
  sections: Record<string, string>
  position: { estimates: Array<{ number: number; estimate: string; tax: string; payable: string }>; provisional_payable: string; provisional_paid: string
    computed_tax: string | null; assessed: string | null; outstanding: string; difference_to_assessment: string | null }
  versions: Array<{ uuid: string; version: number; status: string; amount_payable: string }>
  can: { prepare: boolean; review: boolean; approve: boolean }
  me: number
}>()

const c = computed(() => props.computation)
const editable = computed(() => props.can.prepare && c.value.status === 'prepared')
const mine = computed(() => [c.value.ids.prepared_by, c.value.ids.reviewed_by].includes(props.me))
const bySection = computed(() => {
  const g: Record<string, Line[]> = {}
  for (const l of c.value.lines) (g[l.section] ??= []).push(l)
  return g
})
const showLedger = ref(false)

const lineDialog = ref(false)
const lineForm = useForm({ section: 'add_back', amount: '', description: '', reason: '', reference: '' })
function addLine() {
  lineForm.post(route('tax.income.lines.store', c.value.uuid), { preserveScroll: true, onSuccess: () => { lineDialog.value = false; lineForm.reset() } })
}
function removeLine(l: Line) {
  const reason = window.prompt('Why is this adjustment being removed?')
  if (reason) router.delete(route('tax.income.lines.destroy', [c.value.uuid, l.id]), { data: { reason }, preserveScroll: true })
}

const act = ref<'review' | 'return' | 'approve' | null>(null)
const actForm = useForm({ comment: '' })
function doAct() {
  const opts = { preserveScroll: true, onSuccess: () => { act.value = null } }
  if (act.value === 'approve') actForm.transform(d => ({ comment: d.comment || null })).post(route('tax.income.approve', c.value.uuid), opts)
  else actForm.transform(d => ({ pass: act.value === 'review', comment: d.comment })).post(route('tax.income.review', c.value.uuid), opts)
}

const evidenceForm = useForm({ file: null as File | null, title: '' })
const evidenceDialog = ref(false)
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('tax.income.index'))">Income tax</VBtn>

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">{{ c.label }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ c.entity }} · {{ c.year_start }} → {{ c.year_end }} · version {{ c.version }} · basis {{ label(c.basis) }}</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VChip :color="statusColor[c.status]" label>{{ label(c.status) }}</VChip>
        <VBtn v-if="editable" color="primary" variant="text" prepend-icon="tabler-plus" @click="lineDialog = true">Add adjustment</VBtn>
        <VBtn v-if="editable" color="primary" variant="tonal" @click="router.post(route('tax.income.send', c.uuid), {}, { preserveScroll: true })">Send for review</VBtn>
        <template v-if="c.status === 'under_review' && !c.reviewed_by && can.review">
          <VBtn color="warning" variant="text" @click="act = 'return'">Send back</VBtn>
          <VBtn color="primary" variant="tonal" :disabled="mine" @click="act = 'review'">Pass review</VBtn>
        </template>
        <VBtn v-if="c.status === 'under_review' && c.reviewed_by && can.approve" color="success" variant="tonal" :disabled="mine" @click="act = 'approve'">Approve</VBtn>
        <VBtn v-if="c.period_uuid && can.prepare && c.status !== 'approved'" variant="text" @click="router.post(route('tax.income.prepare', c.period_uuid))">Re-prepare</VBtn>
      </div>
    </div>

    <VAlert v-if="c.validation?.errors?.length" type="error" variant="tonal" class="mb-3"><ul class="ps-4"><li v-for="e in c.validation.errors" :key="e">{{ e }}</li></ul></VAlert>
    <VAlert v-if="c.validation?.warnings?.length" type="warning" variant="tonal" class="mb-3"><ul class="ps-4"><li v-for="w in c.validation.warnings" :key="w">{{ w }}</li></ul></VAlert>

    <VRow>
      <VCol cols="12" md="8">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">From accounting profit to tax</VCardTitle></VCardItem>
          <VTable density="compact">
            <tbody>
              <tr>
                <td>
                  Accounting profit
                  <VBtn v-if="bySection.profit?.length > 1" size="x-small" variant="text" @click="showLedger = !showLedger">{{ showLedger ? 'hide' : 'show' }} {{ bySection.profit.length }} accounts</VBtn>
                </td>
                <td class="text-right font-weight-medium">{{ money(c.figures.accounting_profit) }}</td>
              </tr>
              <tr v-for="l in (showLedger ? bySection.profit : [])" :key="l.id" class="text-caption text-medium-emphasis"><td class="ps-8">{{ l.description }}</td><td class="text-right">{{ money(l.amount) }}</td></tr>
              <template v-for="s in ['add_back', 'deduction', 'exempt', 'allowance', 'loss_set_off']" :key="s">
                <tr v-if="bySection[s]?.length"><td class="text-overline pt-3" colspan="2">{{ sections[s] }}</td></tr>
                <tr v-for="l in bySection[s] ?? []" :key="l.id">
                  <td class="ps-6">{{ l.description }}
                    <span v-if="l.reference" class="text-caption text-disabled"> · {{ l.reference }}</span>
                    <div v-if="l.reason" class="text-caption text-medium-emphasis">{{ l.reason }}</div>
                    <VBtn v-if="editable && l.source === 'manual'" size="x-small" variant="text" color="error" @click="removeLine(l)">Remove</VBtn>
                  </td>
                  <td class="text-right">{{ s === 'add_back' ? '+' : '−' }} {{ money(l.amount) }}</td>
                </tr>
              </template>
              <tr class="font-weight-bold"><td>Taxable income{{ Number(c.figures.taxable_income) < 0 ? ' (assessed loss)' : '' }}</td><td class="text-right">{{ money(c.figures.taxable_income) }}</td></tr>
              <tr><td>Tax at {{ c.rate ?? '—' }}%</td><td class="text-right">{{ money(c.figures.tax) }}</td></tr>
              <tr v-for="l in bySection.credit ?? []" :key="l.id"><td>{{ l.description }}</td><td class="text-right">− {{ money(l.amount) }}</td></tr>
              <tr v-if="c.kind === 'provisional' && c.number === 1"><td class="text-caption text-medium-emphasis" colspan="2">First period: half of the year's estimated tax.</td></tr>
              <tr class="font-weight-bold text-h6"><td>{{ Number(c.figures.amount_payable) >= 0 ? 'Payable' : 'Refundable' }}</td><td class="text-right">{{ money(Math.abs(Number(c.figures.amount_payable))) }}</td></tr>
            </tbody>
          </VTable>
          <VCardText v-if="c.basic_amount" class="text-caption text-medium-emphasis">Basic amount (last assessed taxable income): {{ money(c.basic_amount) }}</VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">The year so far</VCardTitle><VCardSubtitle>Estimates, payments, computation and assessment</VCardSubtitle></VCardItem>
          <VCardText class="text-body-2">
            <div v-for="e in position.estimates" :key="e.number" class="d-flex justify-space-between"><span>IRP6 ({{ e.number }}) estimate</span><span>{{ money(e.payable) }}</span></div>
            <div class="d-flex justify-space-between"><span>Provisional tax paid</span><span>{{ money(position.provisional_paid) }}</span></div>
            <div class="d-flex justify-space-between"><span>Tax per approved computation</span><span>{{ position.computed_tax === null ? '—' : money(position.computed_tax) }}</span></div>
            <div class="d-flex justify-space-between"><span>Assessed by SARS</span><span>{{ position.assessed === null ? 'not yet' : money(position.assessed) }}</span></div>
            <div v-if="position.difference_to_assessment !== null" class="d-flex justify-space-between" :class="Number(position.difference_to_assessment) !== 0 ? 'text-error' : ''"><span>Assessment vs computation</span><span>{{ money(position.difference_to_assessment) }}</span></div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between font-weight-medium"><span>Still outstanding</span><span>{{ money(position.outstanding) }}</span></div>
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Evidence</VCardTitle>
            <template #append><VBtn v-if="can.prepare && c.status !== 'superseded'" size="x-small" variant="text" icon="tabler-upload" @click="evidenceDialog = true" /></template></VCardItem>
          <VList density="compact">
            <VListItem v-for="d in c.documents" :key="d.id" :title="d.title" prepend-icon="tabler-file" :href="d.url" target="_blank" />
            <VListItem v-if="!c.documents.length" class="text-caption text-medium-emphasis">Attach the trial balance, asset schedule and SARS assessment here.</VListItem>
          </VList>
        </VCard>

        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Who did what</VCardTitle></VCardItem>
          <VCardText class="text-body-2">
            <div>Prepared: {{ c.prepared_by ?? '—' }}</div><div>Reviewed: {{ c.reviewed_by ?? '—' }}</div>
            <div v-if="c.review_comment" class="text-caption text-medium-emphasis">{{ c.review_comment }}</div>
            <div>Approved: {{ c.approved_by ?? '—' }}</div>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">Versions</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="v in versions" :key="v.uuid" :active="v.uuid === c.uuid" @click="router.get(route('tax.income.show', v.uuid))"
                       :title="`v${v.version} · ${money(v.amount_payable)}`" :subtitle="label(v.status)" />
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="lineDialog" max-width="560">
      <VCard title="Add an adjustment">
        <VCardText>
          <VSelect v-model="lineForm.section" :items="['add_back', 'deduction', 'exempt', 'allowance'].map(s => ({ title: sections[s], value: s }))" label="Section" />
          <VTextField v-model="lineForm.description" label="Description" :error-messages="lineForm.errors.description" />
          <VTextField v-model="lineForm.amount" label="Amount" :error-messages="lineForm.errors.amount" />
          <VTextField v-model="lineForm.reference" label="Section of the Act (e.g. s11(e), s12C, s23(g))" />
          <VTextarea v-model="lineForm.reason" label="Reason / basis" rows="2" auto-grow :error-messages="lineForm.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="lineDialog = false">Cancel</VBtn><VBtn color="primary" :loading="lineForm.processing" @click="addLine">Add</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="act !== null" max-width="520" @update:model-value="v => { if (!v) act = null }">
      <VCard :title="act === 'approve' ? 'Approve the computation' : act === 'review' ? 'Pass to the approver' : 'Send back to the preparer'">
        <VCardText>
          <p v-if="act === 'approve'" class="text-body-2">Approval fixes this version{{ c.kind === 'cit' ? ', carries the assessed loss forward' : '' }} and records {{ money(c.figures.amount_payable) }} as owed to SARS.</p>
          <VTextarea v-model="actForm.comment" :label="act === 'approve' ? 'Comment (optional)' : 'Comment'" rows="3" auto-grow :error-messages="actForm.errors.comment" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="act = null">Cancel</VBtn><VBtn color="primary" :loading="actForm.processing" @click="doAct">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="evidenceDialog" max-width="480">
      <VCard title="Attach evidence">
        <VCardText>
          <VFileInput label="File" density="compact" @update:model-value="(f: any) => evidenceForm.file = Array.isArray(f) ? f[0] : f" />
          <VTextField v-model="evidenceForm.title" label="What it is (e.g. ITA34 2025)" density="compact" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="evidenceDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="evidenceForm.processing" :disabled="!evidenceForm.file || !evidenceForm.title"
                @click="evidenceForm.post(route('tax.income.evidence', c.uuid), { forceFormData: true, preserveScroll: true, onSuccess: () => { evidenceDialog = false } })">Upload</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
