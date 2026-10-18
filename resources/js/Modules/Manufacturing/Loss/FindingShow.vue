<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'
import { confidenceColor, lossStatusColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  finding: {
    id: string; number: string; category: string; status: string; subject_type: string; subject: string
    period_from: string | null; period_to: string | null; measure: string; unit: string | null; actual: number; baseline: number | null
    baseline_basis: string; evidence: Array<{ type: string; reference: string | null; date: string | null; qty: number | null; value: number | null; source?: string }>
    estimated_impact: number; confidence: string; impact_basis: string; recommended_action: string
    owner_id: number | null; owner: string | null; due_date: string | null; overdue: boolean; escalation_level: number
    investigation_notes: string | null; verified_loss: number | null; verification_evidence: string | null; dismissal_reason: string | null
    corrective_action: string | null; result_after_action: string | null; realised_saving: number | null; saving_evidence: string | null
    requires_approval: boolean; approved_at: string | null; approval_notes: string | null; currency: string
  }
  history: Array<{ action: string; from: string | null; to: string | null; notes: string | null; detail: Record<string, unknown> | null; actor: string | null; at: string | null }>
  people: Array<{ id: number; name: string }>
  can: { investigate: boolean; approve: boolean }
  documents_panel: { available: boolean; relation: string | null; record: string | number | null; can_upload: boolean; count: number; documents: Array<Record<string, any>> }
}>()

const x = props.finding
const money = (n: number | null | undefined) => n == null ? '—' : `${x.currency} ${Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const num = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 })
const busy = ref(false)
const form = ref({ owner_id: x.owner_id, due_date: x.due_date ?? '', notes: '', amount: null as number | null, evidence: '', reason: '',
  action: x.corrective_action ?? '', result: '', saving: null as number | null, saving_evidence: '', approval_notes: '' })
const act = (step: string, data: Record<string, unknown> = {}) => router.post(route('manufacturing.loss.findings.act', { uuid: x.id, step }), data,
  { preserveScroll: true, onStart: () => { busy.value = true }, onFinish: () => { busy.value = false } })
const open = ['DETECTED', 'INVESTIGATING', 'VERIFIED', 'ACTIONED'].includes(x.status)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center flex-wrap gap-3 mb-4">
      <VBtn variant="text" icon="tabler-arrow-left" :href="route('manufacturing.loss.findings.index')" />
      <div>
        <h4 class="text-h4">{{ x.number }} · {{ t(`manufacturing.loss.category.${x.category}`) }}</h4>
        <div class="text-body-2 text-medium-emphasis">{{ x.subject }} · {{ x.period_from }} – {{ x.period_to }}</div>
      </div>
      <VSpacer />
      <VChip v-if="x.escalation_level" color="error" label>{{ t('manufacturing.loss.escalated_level', { n: x.escalation_level }) }}</VChip>
      <VChip label :color="lossStatusColor(x.status)">{{ t(`manufacturing.loss.status.${x.status}`) }}</VChip>
    </div>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.loss.what_was_seen') }}</VCardTitle></VCardItem>
          <VCardText>
            <VRow dense>
              <VCol cols="12" md="4"><div class="text-caption text-medium-emphasis">{{ x.measure }}</div><div class="text-h6">{{ num(x.actual) }} {{ x.unit }}</div></VCol>
              <VCol cols="12" md="4"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.loss.baseline') }}</div><div class="text-h6">{{ num(x.baseline) }} {{ x.unit }}</div></VCol>
              <VCol cols="12" md="4"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.loss.estimated_impact') }}</div><div class="text-h6">{{ money(x.estimated_impact) }}</div>
                <VChip size="x-small" label :color="confidenceColor(x.confidence)">{{ t(`manufacturing.loss.confidence.${x.confidence}`) }}</VChip></VCol>
            </VRow>
            <div class="text-body-2 mt-3"><span class="text-medium-emphasis">{{ t('manufacturing.loss.baseline_basis') }}:</span> {{ x.baseline_basis }}</div>
            <div class="text-body-2"><span class="text-medium-emphasis">{{ t('manufacturing.loss.impact_basis') }}:</span> {{ x.impact_basis }}</div>
            <VAlert type="info" variant="tonal" density="compact" class="mt-3"><span class="font-weight-medium">{{ t('manufacturing.loss.recommended') }}:</span> {{ x.recommended_action }}</VAlert>
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.loss.evidence') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.loss.evidence_hint') }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.loss.source') }}</th><th>{{ t('manufacturing.loss.reference') }}</th><th>{{ t('manufacturing.loss.date') }}</th>
              <th class="text-end">{{ t('manufacturing.loss.qty') }}</th><th class="text-end">{{ t('manufacturing.loss.value') }}</th></tr></thead>
            <tbody>
              <tr v-for="(e, i) in x.evidence" :key="i"><td>{{ e.type }}</td><td>{{ e.reference ?? '—' }}</td><td>{{ e.date ?? '—' }}</td>
                <td class="text-end">{{ num(e.qty) }}</td><td class="text-end">{{ num(e.value) }}</td></tr>
              <tr v-if="!x.evidence.length"><td colspan="5" class="text-center text-medium-emphasis py-4">—</td></tr>
            </tbody>
          </VTable>
        </VCard>

        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.loss.history') }}</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="(h, i) in history" :key="i">
              <VListItemTitle>{{ t(`manufacturing.loss.action.${h.action}`) }} <span v-if="h.to" class="text-medium-emphasis">→ {{ t(`manufacturing.loss.status.${h.to}`) }}</span></VListItemTitle>
              <VListItemSubtitle>{{ h.at?.slice(0, 16).replace('T', ' ') }} · {{ h.actor ?? t('manufacturing.loss.system') }}</VListItemSubtitle>
              <div v-if="h.notes" class="text-body-2" style="white-space: pre-line">{{ h.notes }}</div>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="5">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.loss.investigation') }}</VCardTitle></VCardItem>
          <VCardText class="text-body-2">
            <div><span class="text-medium-emphasis">{{ t('manufacturing.loss.owner') }}:</span> {{ x.owner ?? '—' }}</div>
            <div :class="{ 'text-error': x.overdue }"><span class="text-medium-emphasis">{{ t('manufacturing.loss.due') }}:</span> {{ x.due_date ?? '—' }}</div>
            <div v-if="x.investigation_notes" class="mt-2" style="white-space: pre-line">{{ x.investigation_notes }}</div>
            <div v-if="x.verified_loss !== null" class="mt-2"><span class="text-medium-emphasis">{{ t('manufacturing.loss.verified_loss') }}:</span> <span class="text-error">{{ money(x.verified_loss) }}</span>
              <div class="text-caption" style="white-space: pre-line">{{ x.verification_evidence }}</div></div>
            <div v-if="x.dismissal_reason" class="mt-2"><span class="text-medium-emphasis">{{ t('manufacturing.loss.dismissed_because') }}:</span> {{ x.dismissal_reason }}</div>
            <div v-if="x.corrective_action" class="mt-2"><span class="text-medium-emphasis">{{ t('manufacturing.loss.corrective_action') }}:</span> {{ x.corrective_action }}</div>
            <div v-if="x.result_after_action" class="mt-2"><span class="text-medium-emphasis">{{ t('manufacturing.loss.result') }}:</span> {{ x.result_after_action }}</div>
            <div v-if="x.realised_saving" class="mt-2"><span class="text-medium-emphasis">{{ t('manufacturing.loss.saving_claimed') }}:</span> <span class="text-success">{{ money(x.realised_saving) }}</span>
              <div class="text-caption" style="white-space: pre-line">{{ x.saving_evidence }}</div></div>
            <VAlert v-if="x.requires_approval" :type="x.approved_at ? 'success' : 'warning'" variant="tonal" density="compact" class="mt-3">
              {{ x.approved_at ? t('manufacturing.loss.approved_on', { date: x.approved_at.slice(0, 10) }) : t('manufacturing.loss.needs_approval') }}
            </VAlert>
          </VCardText>
        </VCard>

        <VCard v-if="can.investigate && open" border class="mb-4">
          <VCardText>
            <div class="d-flex gap-2 flex-wrap align-center mb-3">
              <VAutocomplete v-model="form.owner_id" :items="people" item-title="name" item-value="id" :label="t('manufacturing.loss.owner')" hide-details style="min-width: 200px" />
              <VTextField v-model="form.due_date" type="date" :label="t('manufacturing.loss.due')" hide-details style="max-width: 170px" />
              <VBtn variant="tonal" :disabled="busy || !form.owner_id" @click="act('assign', { owner_id: form.owner_id, due_date: form.due_date || null })">{{ t('manufacturing.loss.assign') }}</VBtn>
            </div>
            <VTextarea v-model="form.notes" rows="2" :label="t('manufacturing.loss.notes')" hide-details class="mb-2" />
            <div class="d-flex gap-2 mb-4">
              <VBtn v-if="x.status === 'DETECTED'" color="primary" :disabled="busy" @click="act('investigate', { notes: form.notes || null })">{{ t('manufacturing.loss.start') }}</VBtn>
              <VBtn variant="tonal" :disabled="busy || !form.notes" @click="act('note', { notes: form.notes })">{{ t('manufacturing.loss.add_note') }}</VBtn>
            </div>

            <template v-if="x.status === 'INVESTIGATING'">
              <div class="text-subtitle-2 mb-1">{{ t('manufacturing.loss.verify_title') }}</div>
              <VTextField v-model.number="form.amount" type="number" :label="t('manufacturing.loss.verified_amount')" hide-details class="mb-2" />
              <VTextarea v-model="form.evidence" rows="2" :label="t('manufacturing.loss.verification_evidence')" hide-details class="mb-2" />
              <VBtn color="error" :disabled="busy || !form.amount || !form.evidence" class="mb-4" @click="act('verify', { amount: form.amount, evidence: form.evidence })">{{ t('manufacturing.loss.verify') }}</VBtn>
            </template>
            <template v-if="x.status === 'DETECTED' || x.status === 'INVESTIGATING'">
              <div class="text-subtitle-2 mb-1">{{ t('manufacturing.loss.dismiss_title') }}</div>
              <VTextarea v-model="form.reason" rows="2" :label="t('manufacturing.loss.dismiss_reason')" hide-details class="mb-2" />
              <VBtn variant="tonal" :disabled="busy || !form.reason" @click="act('dismiss', { reason: form.reason })">{{ t('manufacturing.loss.dismiss') }}</VBtn>
            </template>
            <template v-if="x.status === 'VERIFIED'">
              <div class="text-subtitle-2 mb-1">{{ t('manufacturing.loss.corrective_action') }}</div>
              <VTextarea v-model="form.action" rows="2" hide-details class="mb-2" />
              <VBtn variant="tonal" :disabled="busy || !form.action" class="mb-4" @click="act('plan', { action: form.action, due_date: form.due_date || null })">{{ t('manufacturing.loss.save_action') }}</VBtn>
              <template v-if="x.corrective_action">
                <div class="text-subtitle-2 mb-1">{{ t('manufacturing.loss.result_title') }}</div>
                <VTextarea v-model="form.result" rows="2" :label="t('manufacturing.loss.result')" hide-details class="mb-2" />
                <VTextField v-model.number="form.saving" type="number" :label="t('manufacturing.loss.saving')" :hint="t('manufacturing.loss.saving_hint')" persistent-hint class="mb-2" />
                <VTextarea v-if="form.saving" v-model="form.saving_evidence" rows="2" :label="t('manufacturing.loss.saving_evidence')" hide-details class="mb-2" />
                <VBtn color="primary" :disabled="busy || !form.result" @click="act('result', { result: form.result, saving: form.saving, saving_evidence: form.saving_evidence || null })">{{ t('manufacturing.loss.record_result') }}</VBtn>
              </template>
            </template>
            <VBtn v-if="x.status === 'ACTIONED'" color="success" :disabled="busy || (x.requires_approval && !x.approved_at)" @click="act('close')">{{ t('manufacturing.loss.close') }}</VBtn>
          </VCardText>
        </VCard>

        <VCard v-if="can.approve && x.requires_approval && !x.approved_at && (x.status === 'VERIFIED' || x.status === 'ACTIONED')" border>
          <VCardItem><VCardTitle>{{ t('manufacturing.loss.approval') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.loss.approval_hint') }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VTextarea v-model="form.approval_notes" rows="2" :label="t('manufacturing.loss.notes')" hide-details class="mb-2" />
            <VBtn color="success" :disabled="busy" @click="act('approve', { notes: form.approval_notes || null })">{{ t('manufacturing.loss.approve') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
    <RecordDocuments v-if="documents_panel.available" v-bind="documents_panel" :can-upload="documents_panel.can_upload" :title="t('manufacturing.documents.title')" class="mt-4" />
  </div>
</template>
