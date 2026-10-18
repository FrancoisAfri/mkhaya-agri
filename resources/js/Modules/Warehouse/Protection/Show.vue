<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  finding: Record<string, any>
  events: Array<{ action: string; from: string | null; to: string | null; by: string | null; notes: string | null; at: string }>
  people: Array<{ id: number; title: string }>
  priorities: string[]
  can: { approve: boolean }
}>()

const f = computed(() => props.finding)
const step = ref<string | null>(null)
const form = useForm({ action: '', owner_id: null as number | null, due_date: '', notes: '', amount: null as number | null, saving: null as number | null, evidence: '', priority: props.finding.priority })
function open(action: string) { form.reset(); form.action = action; step.value = action }
function submit() { form.transform(d => ({ ...d, due_date: d.due_date || null })).post(route('warehouse.protection.act', f.value.id), { preserveScroll: true, onSuccess: () => { step.value = null } }) }
const quick = (action: string) => router.post(route('warehouse.protection.act', f.value.id), { action }, { preserveScroll: true })
const money = (v: number | null) => v === null || v === undefined ? '—' : v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const inState = (s: string[]) => s.includes(f.value.status)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.protection.index')">{{ t('warehouse_management.protection.title') }}</a></div>
        <h4 class="text-h4">{{ f.number }} · {{ t(`warehouse_management.finding_category.${f.category}`) }}
          <VChip size="small" variant="tonal">{{ t(`warehouse_management.finding_status.${f.status}`) }}</VChip></h4>
        <p class="text-body-2 mb-0">{{ f.subject }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn v-if="inState(['DETECTED', 'INVESTIGATING', 'VERIFIED', 'ACTIONED'])" variant="tonal" @click="open('assign')">{{ t('warehouse_management.protection.assign') }}</VBtn>
        <VBtn v-if="inState(['DETECTED'])" color="primary" @click="quick('investigate')">{{ t('warehouse_management.protection.start') }}</VBtn>
        <VBtn v-if="inState(['INVESTIGATING'])" color="error" variant="tonal" @click="open('verify')">{{ t('warehouse_management.protection.verify') }}</VBtn>
        <VBtn v-if="inState(['DETECTED', 'INVESTIGATING'])" variant="tonal" @click="open('dismiss')">{{ t('warehouse_management.protection.dismiss') }}</VBtn>
        <VBtn v-if="inState(['VERIFIED']) && !f.corrective_action" color="primary" variant="tonal" @click="open('plan')">{{ t('warehouse_management.protection.plan') }}</VBtn>
        <VBtn v-if="inState(['VERIFIED']) && f.corrective_action" color="primary" variant="tonal" @click="open('result')">{{ t('warehouse_management.protection.result') }}</VBtn>
        <VBtn v-if="can.approve && f.requires_approval && !f.approved_by && inState(['VERIFIED', 'ACTIONED'])" color="success" @click="open('approve')">{{ t('warehouse_management.adjustments.approve') }}</VBtn>
        <VBtn v-if="inState(['ACTIONED'])" color="success" variant="tonal" @click="quick('close')">{{ t('warehouse_management.protection.close') }}</VBtn>
        <VBtn variant="text" @click="open('note')">{{ t('warehouse_management.protection.note') }}</VBtn>
        <VBtn variant="text" @click="open('priority')">{{ t('warehouse_management.protection.priority') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VRow>
      <VCol cols="12" md="7">
        <VCard border class="mb-4" :title="t('warehouse_management.protection.why_flagged')">
          <VCardText>
            <p class="text-body-1 mb-2">{{ f.measure }}: <strong>{{ f.actual }}</strong><span v-if="f.baseline !== null"> {{ t('warehouse_management.protection.against', { b: f.baseline }) }}</span> <span v-if="f.unit">{{ f.unit }}</span></p>
            <p class="text-body-2 mb-2">{{ f.basis }}</p>
            <p class="text-caption mb-2">{{ t('warehouse_management.protection.meaning') }}: {{ f.meaning }}</p>
            <div v-if="f.rule" class="text-caption mb-2">{{ t('warehouse_management.protection.rule_then', { threshold: f.rule.threshold, count: f.rule.min_count, days: f.rule.lookback_days }) }}</div>
            <div class="text-caption">{{ t('warehouse_management.protection.period', { from: f.period_from, to: f.period_to }) }}</div>
          </VCardText>
        </VCard>
        <VCard border class="mb-4" :title="t('warehouse_management.protection.evidence')">
          <VCardText><pre class="text-caption" style="white-space: pre-wrap">{{ JSON.stringify(f.evidence, null, 2) }}</pre></VCardText>
        </VCard>
        <VCard border :title="t('warehouse_management.protection.log')">
          <VList density="compact">
            <VListItem v-for="(e, i) in events" :key="i">
              <VListItemTitle class="text-wrap">{{ t(`warehouse_management.finding_action.${e.action}`) }}<span v-if="e.by"> · {{ e.by }}</span></VListItemTitle>
              <VListItemSubtitle class="text-wrap">{{ new Date(e.at).toLocaleString() }}<span v-if="e.notes"> · {{ e.notes }}</span></VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard border class="mb-4" :title="t('warehouse_management.protection.money')">
          <VList density="compact">
            <VListItem :title="t('warehouse_management.protection.estimate')" :subtitle="`${money(f.estimated_impact)} · ${t(`warehouse_management.confidence.${f.confidence}`)}`" />
            <VListItem :title="t('warehouse_management.protection.estimate_basis')" :subtitle="f.impact_basis" class="text-wrap" />
            <VListItem :title="t('warehouse_management.protection.verified')" :subtitle="f.verified_loss === null ? '—' : `${money(f.verified_loss)} (${f.verified_by})`" />
            <VListItem v-if="f.saving !== null" :title="t('warehouse_management.protection.saving')" :subtitle="`${money(f.saving)}${f.approved_by ? '' : ' · ' + t('warehouse_management.protection.awaiting_approval')}`" />
          </VList>
        </VCard>
        <VCard border :title="t('warehouse_management.protection.findings')">
          <VList density="compact">
            <VListItem :title="t('warehouse_management.protection.recommended')" :subtitle="f.recommended_action" class="text-wrap" />
            <VListItem v-if="f.verification_evidence" :title="t('warehouse_management.protection.proof')" :subtitle="f.verification_evidence" />
            <VListItem v-if="f.dismissal_reason" :title="t('warehouse_management.protection.dismissed_because')" :subtitle="f.dismissal_reason" />
            <VListItem v-if="f.corrective_action" :title="t('warehouse_management.protection.corrective')" :subtitle="f.corrective_action" />
            <VListItem v-if="f.result" :title="t('warehouse_management.protection.after')" :subtitle="f.result" />
            <VListItem v-if="f.notes" :title="t('warehouse_management.protection.notes')"><div class="text-caption" style="white-space: pre-wrap">{{ f.notes }}</div></VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="step !== null" max-width="520" @update:model-value="v => !v && (step = null)">
      <VCard v-if="step" :title="t(`warehouse_management.finding_step.${step}`)">
        <VCardText class="d-flex flex-column gap-3">
          <VSelect v-if="step === 'assign'" v-model="form.owner_id" :items="people" item-value="id" :label="t('warehouse_management.protection.owner')" />
          <VTextField v-if="['assign', 'plan'].includes(step)" v-model="form.due_date" type="date" :label="t('warehouse_management.protection.due')" />
          <VSelect v-if="step === 'priority'" v-model="form.priority" :items="priorities.map(p => ({ value: p, title: t(`warehouse_management.priority.${p}`) }))" />
          <VTextField v-if="step === 'verify'" v-model.number="form.amount" type="number" min="0" :label="t('warehouse_management.protection.verified')" />
          <VTextField v-if="step === 'result'" v-model.number="form.saving" type="number" min="0" :label="t('warehouse_management.protection.saving')" />
          <VTextarea v-if="['verify', 'result'].includes(step)" v-model="form.evidence" rows="2" :label="step === 'verify' ? t('warehouse_management.protection.proof') : t('warehouse_management.protection.saving_evidence')" />
          <VTextarea v-if="!['assign', 'priority'].includes(step)" v-model="form.notes" rows="3" :label="t(`warehouse_management.finding_step_note.${step}`)" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="step = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="submit">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
