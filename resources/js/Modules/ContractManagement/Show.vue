<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Milestone = { id: number; milestone: string; due_date: string | null; status: string }
type Obligation = { id: number; description: string; owner_name: string | null; due_date: string | null; status: string; source: string }
type Review = { id: number; reviewer_name: string | null; comment: string | null; status: string; feedback: string | null; complexity_rating: string | null }
type History = { from_status: string | null; to_status: string; changed_at: string | null; notes: string | null }
type SignatureSigner = { name: string; email: string; status: string | null; signed_at: string | null }
type Signature = { status: string | null; uuid: string; sent_at: string | null; signers: SignatureSigner[] }
type ContractAi = {
  status: string; summary: string | null
  parties: { name: string; role: string }[] | null
  key_dates: { label: string; date: string }[] | null
  obligations: { description: string; owner: string | null; due_date: string | null }[] | null
  payment_terms: string | null; termination: string | null; renewal_terms: string | null
  risk_score: number | null; compliance_score: number | null; complexity_score: number | null
  risk_factors: string[] | null; key_points: string[] | null; recommendations: string[] | null
  renewal_recommendation: string | null; renewal_brief: string | null
  analyzed_at: string | null; error: string | null
}

const props = defineProps<{
  contract: {
    id: number; contract_number: string; title: string; description: string | null
    contract_status: string; approval_status: string; payment_status: string
    contract_value: number | null; final_contract_value: number | null
    periodicity: string | null; start_date: string | null; end_date: string | null
    type: string | null; category: string | null; file_path: string | null; file_name: string | null
    signature_status: string | null; currency: string | null
    governing_law: string | null; jurisdiction: string | null
    our_recommendation: string | null; rejection_reason: string | null
    milestones: Milestone[]; reviews: Review[]; status_histories: History[]; obligations: Obligation[]
  }
  signature: Signature | null
  ai: ContractAi | null
  statuses: string[]
}>()

// ── Obligations ────────────────────────────────────────────────────────────────
const obForm = ref({ description: '', owner_name: '', due_date: '' })
function addObligation() {
  router.post(route('contracts.obligations.store', props.contract.uuid), obForm.value, {
    preserveScroll: true, onSuccess: () => (obForm.value = { description: '', owner_name: '', due_date: '' }),
  })
}
function setObligationStatus(id: number, status: string) {
  router.patch(route('contracts.obligations.update', [props.contract.uuid, id]), { status }, { preserveScroll: true })
}
function delObligation(id: number) {
  router.delete(route('contracts.obligations.destroy', [props.contract.uuid, id]), { preserveScroll: true })
}
const obStatusColor: Record<string, string> = {
  open: 'grey', in_progress: 'info', met: 'success', breached: 'error', waived: 'warning',
}
const obStatuses = ['open', 'in_progress', 'met', 'breached', 'waived']

// ── Clause / playbook check ──────────────────────────────────────────────────────
const clauseChecking = ref(false)
function runClauseCheck() {
  clauseChecking.value = true
  router.post(route('contracts.analyze-clauses', props.contract.uuid), {}, {
    onFinish: () => (clauseChecking.value = false),
  })
}

// ── AI analysis ────────────────────────────────────────────────────────────────
const analyzing = ref(false)
function analyze() {
  analyzing.value = true
  router.post(route('contracts.analyze', props.contract.uuid), {}, {
    preserveScroll: true,
    onFinish: () => (analyzing.value = false),
  })
}
function scoreColor(v: number | null, invert = false) {
  if (v == null) return 'grey'
  const good = invert ? v <= 33 : v >= 67
  const mid = v > 33 && v < 67
  return good ? 'success' : (mid ? 'warning' : 'error')
}

const statusDialog = ref(false)
const statusForm = ref({ status: props.contract.contract_status, notes: '' })
const confirmDialog = ref()

function money(v: number | null) {
  if (v == null) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: props.contract.currency || 'ZAR' }).format(v)
  } catch {
    return `${props.contract.currency || ''} ${v}`.trim()
  }
}

const renewalBriefing = ref(false)
function draftRenewal() {
  renewalBriefing.value = true
  router.post(route('contracts.renewal-brief', props.contract.uuid), {}, {
    preserveScroll: true, onFinish: () => (renewalBriefing.value = false),
  })
}
function changeStatus() {
  router.patch(route('contracts.status', props.contract.uuid), statusForm.value, {
    preserveScroll: true, onSuccess: () => (statusDialog.value = false),
  })
}
function publish() { router.post(route('contracts.publish', props.contract.uuid), {}, { preserveScroll: true }) }
function terminate() {
  confirmDialog.value.open(
    {
      title: t('contracts_show.confirm.terminate_title'),
      message: t('contracts_show.confirm.terminate_message'),
      confirmText: t('contracts_show.confirm.terminate_text'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle'
    },
    () => { router.post(route('contracts.terminate', props.contract.uuid), {}, { preserveScroll: true }) },
  )
}
function renew() { router.post(route('contracts.renew', props.contract.uuid), {}, { preserveScroll: true }) }

const statusColor: Record<string, string> = {
  draft: 'grey', published: 'info', pending: 'warning', active: 'success',
  expired: 'error', renewed: 'primary', terminated: 'error', cancelled: 'grey',
}

// ── E-signature ──────────────────────────────────────────────────────────────
const signDialog = ref(false)
const signForm = ref({ signers: [{ name: '', email: '' }] })
const signing = ref(false)

const signatureColor: Record<string, string> = {
  sent: 'info', in_progress: 'warning', completed: 'success',
  voided: 'error', expired: 'error', draft: 'grey',
}

function addSigner() { signForm.value.signers.push({ name: '', email: '' }) }
function removeSigner(i: number) {
  if (signForm.value.signers.length > 1) signForm.value.signers.splice(i, 1)
}
function sendForSignature() {
  signing.value = true
  router.post(route('contracts.sign', props.contract.uuid), signForm.value, {
    preserveScroll: true,
    onSuccess: () => {
      signDialog.value = false
      signForm.value = { signers: [{ name: '', email: '' }] }
    },
    onFinish: () => (signing.value = false),
  })
}
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('contracts.index'))" />
        <div>
          <h4 class="text-h4 mb-0">{{ contract.title }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ contract.contract_number }}</p>
        </div>
        <VChip :color="statusColor[contract.contract_status]" variant="tonal">{{ contract.contract_status }}</VChip>
        <VChip v-if="contract.signature_status" :color="signatureColor[signature?.status ?? contract.signature_status] || 'grey'"
               variant="tonal" prepend-icon="tabler-signature">
          {{ t('contracts_show.signature_status', { status: signature?.status ?? contract.signature_status }) }}
        </VChip>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="contract.file_name" variant="tonal" color="success" prepend-icon="tabler-download"
              :href="route('contracts.document', contract.uuid)" target="_blank">{{ t('contracts_show.document') }}</VBtn>
        <VBtn v-if="contract.file_name" color="primary" variant="tonal" prepend-icon="tabler-signature"
              @click="signDialog = true">
          {{ contract.signature_status ? t('contracts_show.re_send_for_signature') : t('contracts_show.send_for_signature') }}
        </VBtn>
        <VBtn color="purple" variant="tonal" prepend-icon="tabler-sparkles" :loading="analyzing" @click="analyze">
          {{ ai ? t('contracts_show.re_analyze_with_ai') : t('contracts_show.analyze_with_ai') }}
        </VBtn>
        <VBtn color="deep-purple" variant="tonal" prepend-icon="tabler-shield-check" :loading="clauseChecking" @click="runClauseCheck">
          {{ t('contracts_show.playbook_check') }}
        </VBtn>
        <VBtn color="teal" variant="tonal" prepend-icon="tabler-refresh" :loading="renewalBriefing" @click="draftRenewal">
          {{ t('contracts_show.renewal_brief_ai') }}
        </VBtn>
        <VBtn v-if="contract.contract_status === 'draft'" color="primary" variant="tonal" @click="publish">{{ t('contracts_show.publish') }}</VBtn>
        <VBtn variant="tonal" @click="statusDialog = true">{{ t('contracts_show.change_status') }}</VBtn>
        <VBtn variant="tonal" color="primary" @click="renew">{{ t('contracts_show.renew') }}</VBtn>
        <VBtn variant="tonal" color="error" @click="terminate">{{ t('contracts_show.terminate') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard v-if="ai && ai.status === 'completed'" border class="mb-4" color="purple-lighten-5">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span><VIcon icon="tabler-sparkles" class="me-2" />{{ t('contracts_show.ai_intelligence') }}</span>
            <span class="text-caption text-medium-emphasis">{{ ai.analyzed_at }}</span>
          </VCardTitle>
          <VCardText>
            <div class="d-flex gap-3 mb-4 flex-wrap">
              <VChip :color="scoreColor(ai.risk_score, true)" variant="flat">{{ t('contracts_show.risk') }}: {{ ai.risk_score ?? '—' }}</VChip>
              <VChip :color="scoreColor(ai.compliance_score)" variant="flat">{{ t('contracts_show.compliance') }}: {{ ai.compliance_score ?? '—' }}</VChip>
              <VChip :color="scoreColor(ai.complexity_score, true)" variant="flat">{{ t('contracts_show.complexity') }}: {{ ai.complexity_score ?? '—' }}</VChip>
            </div>
            <div v-if="ai.summary" class="mb-3"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.summary') }}</div>{{ ai.summary }}</div>

            <div v-if="ai.key_points?.length" class="mb-3">
              <div class="text-caption text-medium-emphasis">{{ t('contracts_show.key_points') }}</div>
              <ul class="ms-4"><li v-for="(point, i) in ai.key_points" :key="i">{{ point }}</li></ul>
            </div>
            <div v-if="ai.risk_factors?.length" class="mb-3">
              <div class="text-caption text-medium-emphasis">{{ t('contracts_show.risk_factors') }}</div>
              <VChip v-for="(risk, i) in ai.risk_factors" :key="i" size="small" color="error" variant="tonal" class="me-1 mb-1">{{ risk }}</VChip>
            </div>
            <div v-if="ai.recommendations?.length" class="mb-3">
              <div class="text-caption text-medium-emphasis">{{ t('contracts_show.recommendations') }}</div>
              <ul class="ms-4"><li v-for="(rec, i) in ai.recommendations" :key="i">{{ rec }}</li></ul>
            </div>
            <VRow>
              <VCol v-if="ai.payment_terms" cols="12" md="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.payment_terms') }}</div>{{ ai.payment_terms }}</VCol>
              <VCol v-if="ai.termination" cols="12" md="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.termination_terms') }}</div>{{ ai.termination }}</VCol>
              <VCol v-if="ai.renewal_terms" cols="12"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.renewal_terms') }}</div>{{ ai.renewal_terms }}</VCol>
            </VRow>
            <div v-if="ai.obligations?.length" class="mt-2">
              <div class="text-caption text-medium-emphasis mb-1">{{ t('contracts_show.obligations_ai') }}</div>
              <VTable density="compact">
                <thead><tr><th>{{ t('contracts_show.obligation') }}</th><th>{{ t('contracts_show.owner') }}</th><th>{{ t('contracts_show.due') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(obl, i) in ai.obligations" :key="i">
                    <td>{{ obl.description }}</td><td>{{ obl.owner ?? '—' }}</td><td>{{ obl.due_date ?? '—' }}</td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </VCardText>
        </VCard>
        <VAlert v-else-if="ai && (ai.status === 'processing' || ai.status === 'queued')" type="info" variant="tonal" class="mb-4" icon="tabler-clock">
          <div class="d-flex align-center gap-2">
            <VProgressCircular indeterminate size="18" width="2" />
            {{ t('contracts_show.ai_processing') }}
          </div>
        </VAlert>
        <VAlert v-else-if="ai && ai.status === 'failed'" type="error" variant="tonal" class="mb-4">
          {{ t('contracts_show.ai_failed') }} {{ ai.error }}
        </VAlert>

        <VCard v-if="ai && ai.renewal_brief" border class="mb-4" color="teal-lighten-5">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span><VIcon icon="tabler-refresh" class="me-2" />{{ t('contracts_show.renewal_brief') }}</span>
            <VChip v-if="ai.renewal_recommendation" :color="ai.renewal_recommendation === 'exit' ? 'error' : (ai.renewal_recommendation === 'renegotiate' ? 'warning' : 'success')" variant="flat" class="text-capitalize">
              {{ t(`contracts_show.renewal_recommendation.${ai.renewal_recommendation}`) }}
            </VChip>
          </VCardTitle>
          <VCardText style="white-space: pre-wrap">{{ ai.renewal_brief }}</VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle>{{ t('contracts_show.details.title') }}</VCardTitle>
          <VCardText>
            <VRow>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.type') }}</div>{{ contract.type ?? '—' }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.category') }}</div>{{ contract.category ?? '—' }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.value') }}</div>{{ money(contract.contract_value) }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.final_value') }}</div>{{ money(contract.final_contract_value) }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.start') }}</div>{{ contract.start_date ?? '—' }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.end') }}</div>{{ contract.end_date ?? '—' }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.periodicity') }}</div>{{ contract.periodicity ?? '—' }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.payment') }}</div>{{ contract.payment_status }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.governing_law') }}</div>{{ contract.governing_law ?? '—' }}</VCol>
              <VCol cols="6"><div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.jurisdiction') }}</div>{{ contract.jurisdiction ?? '—' }}</VCol>
            </VRow>
            <div v-if="contract.description" class="mt-4">
              <div class="text-caption text-medium-emphasis">{{ t('contracts_show.details.contract_body') }}</div>
              <div class="contract-body" v-html="contract.description" />
            </div>
            <div v-if="contract.rejection_reason" class="mt-4 text-error">
              <div class="text-caption">{{ t('contracts_show.details.rejection_reason') }}</div>{{ contract.rejection_reason }}
            </div>
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle>{{ t('contracts_show.milestones.title') }}</VCardTitle>
          <VTable>
            <thead><tr><th>{{ t('contracts_show.milestones.milestone') }}</th><th>{{ t('contracts_show.milestones.due') }}</th><th>{{ t('contracts_show.milestones.status') }}</th></tr></thead>
            <tbody>
              <tr v-if="!contract.milestones.length"><td colspan="3" class="text-center text-medium-emphasis py-4">{{ t('contracts_show.milestones.no_milestones') }}</td></tr>
              <tr v-for="milestone in contract.milestones" :key="milestone.id">
                <td>{{ milestone.milestone }}</td><td>{{ milestone.due_date ?? '—' }}</td>
                <td><VChip size="small" variant="tonal">{{ milestone.status }}</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('contracts_show.obligations.title') }}</span>
            <span class="text-caption text-medium-emphasis">{{ t('contracts_show.obligations.tracked', { count: contract.obligations.length }) }}</span>
          </VCardTitle>
          <VTable>
            <thead><tr><th>{{ t('contracts_show.obligations.obligation') }}</th><th>{{ t('contracts_show.obligations.owner') }}</th><th>{{ t('contracts_show.obligations.due') }}</th><th>{{ t('contracts_show.obligations.status') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-if="!contract.obligations.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('contracts_show.obligations.no_obligations') }}</td></tr>
              <tr v-for="obligation in contract.obligations" :key="obligation.id">
                <td>
                  {{ obligation.description }}
                  <VChip v-if="obligation.source === 'ai'" size="x-small" color="purple" variant="tonal" class="ms-1">{{ t('contracts_show.obligations.ai_tag') }}</VChip>
                </td>
                <td>{{ obligation.owner_name ?? '—' }}</td>
                <td>{{ obligation.due_date ?? '—' }}</td>
                <td>
                  <VSelect :model-value="obligation.status" :items="obStatuses" density="compact" variant="plain" hide-details
                    style="min-width:130px" @update:model-value="(status: string) => setObligationStatus(obligation.id, status)">
                    <template #selection="{ item }">
                      <VChip size="small" :color="obStatusColor[item.value]" variant="tonal">{{ item.value }}</VChip>
                    </template>
                  </VSelect>
                </td>
                <td><VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="delObligation(obligation.id)" /></td>
              </tr>
            </tbody>
          </VTable>
          <VCardText>
            <VRow class="align-center">
              <VCol cols="12" md="5"><VTextField v-model="obForm.description" :label="t('contracts_show.obligations.new_obligation')" density="compact" variant="outlined" hide-details /></VCol>
              <VCol cols="6" md="3"><VTextField v-model="obForm.owner_name" :label="t('contracts_show.obligations.owner_field')" density="compact" variant="outlined" hide-details /></VCol>
              <VCol cols="6" md="2"><VTextField v-model="obForm.due_date" :label="t('contracts_show.obligations.due_field')" type="date" density="compact" variant="outlined" hide-details /></VCol>
              <VCol cols="12" md="2"><VBtn color="primary" block :disabled="!obForm.description" @click="addObligation">{{ t('contracts_show.obligations.add') }}</VBtn></VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardTitle>{{ t('contracts_show.reviews.title') }}</VCardTitle>
          <VTable>
            <thead><tr><th>{{ t('contracts_show.reviews.reviewer') }}</th><th>{{ t('contracts_show.reviews.comment') }}</th><th>{{ t('contracts_show.reviews.complexity') }}</th><th>{{ t('contracts_show.reviews.status') }}</th></tr></thead>
            <tbody>
              <tr v-if="!contract.reviews.length"><td colspan="4" class="text-center text-medium-emphasis py-4">{{ t('contracts_show.reviews.no_reviews') }}</td></tr>
              <tr v-for="review in contract.reviews" :key="review.id">
                <td>{{ review.reviewer_name ?? '—' }}</td><td>{{ review.comment ?? '—' }}</td>
                <td>{{ review.complexity_rating ?? '—' }}</td>
                <td><VChip size="small" variant="tonal">{{ review.status }}</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard v-if="signature" border class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('contracts_show.e_signature.title') }}</span>
            <VChip size="small" :color="signatureColor[signature.status ?? ''] || 'grey'" variant="tonal">
              {{ signature.status }}
            </VChip>
          </VCardTitle>
          <VCardText>
            <div v-if="signature.sent_at" class="text-caption text-medium-emphasis mb-2">
              {{ t('contracts_show.e_signature.sent', { date: signature.sent_at }) }}
            </div>
            <VTable density="compact">
              <thead><tr><th>{{ t('contracts_show.e_signature.signer') }}</th><th>{{ t('contracts_show.e_signature.status') }}</th><th>{{ t('contracts_show.e_signature.signed') }}</th></tr></thead>
              <tbody>
                <tr v-for="(signer, i) in signature.signers" :key="i">
                  <td>
                    <div class="font-weight-medium">{{ signer.name }}</div>
                    <div class="text-caption text-medium-emphasis">{{ signer.email }}</div>
                  </td>
                  <td><VChip size="x-small" variant="tonal">{{ signer.status }}</VChip></td>
                  <td class="text-caption">{{ signer.signed_at ?? '—' }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardTitle>{{ t('contracts_show.status_history.title') }}</VCardTitle>
          <VCardText>
            <div v-if="!contract.status_histories.length" class="text-medium-emphasis">{{ t('contracts_show.status_history.no_history') }}</div>
            <VTimeline v-else side="end" density="compact" truncate-line="both">
              <VTimelineItem v-for="(history, i) in contract.status_histories" :key="i" size="x-small" :dot-color="statusColor[history.to_status] || 'primary'">
                <div class="font-weight-medium">{{ history.from_status ? history.from_status + ' → ' : '' }}{{ history.to_status }}</div>
                <div class="text-caption text-medium-emphasis">{{ history.changed_at ?? '' }}</div>
                <div v-if="history.notes" class="text-caption">{{ history.notes }}</div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="statusDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('contracts_show.dialog.change_status_title') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="statusForm.status" :items="statuses" :label="t('contracts_show.dialog.status')" variant="outlined" class="mb-3" />
          <VTextarea v-model="statusForm.notes" :label="t('contracts_show.dialog.notes')" variant="outlined" rows="3" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="statusDialog = false">{{ t('contracts_show.dialog.cancel') }}</VBtn><VBtn color="primary" @click="changeStatus">{{ t('contracts_show.dialog.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="signDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('contracts_show.e_signature.send_title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('contracts_show.e_signature.send_description') }}
          </p>
          <div v-for="(signer, i) in signForm.signers" :key="i" class="d-flex gap-2 mb-2 align-center">
            <VTextField v-model="signer.name" :label="t('contracts_show.e_signature.name')" variant="outlined" density="compact" hide-details />
            <VTextField v-model="signer.email" :label="t('contracts_show.e_signature.email')" type="email" variant="outlined" density="compact" hide-details />
            <VBtn icon="tabler-trash" variant="text" color="error" size="small"
                  :disabled="signForm.signers.length === 1" @click="removeSigner(i)" />
          </div>
          <VBtn variant="text" size="small" prepend-icon="tabler-plus" @click="addSigner">{{ t('contracts_show.e_signature.add_signer') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" :disabled="signing" @click="signDialog = false">{{ t('contracts_show.e_signature.cancel') }}</VBtn>
          <VBtn color="primary" :loading="signing" @click="sendForSignature">{{ t('contracts_show.e_signature.send') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
