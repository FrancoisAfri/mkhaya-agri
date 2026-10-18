<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'
import { ncrStatusColor, severityColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

type Action = {
  id: string; type: string; description: string; owner: string | null; owner_id: number | null; due_date: string | null
  status: string; is_overdue: boolean; completed_at: string | null; completion_notes: string | null; can_complete: boolean
}

const props = defineProps<{
  ncr: {
    id: string; number: string; source: string; status: string; severity: string; defect_code: string | null; description: string
    item_code: string | null; item_name: string | null; lot: string | null; owner: string | null; owner_id: number | null
    due_date: string | null; is_overdue: boolean; created_at: string | null; containment: string | null; root_cause: string | null
    qty_affected: number | null; effectiveness: string | null; effectiveness_notes: string | null; verified_at: string | null
    void_reason: string | null; inspection: { id: string; number: string } | null; order: { id: string; number: string } | null
    supplier: string | null; purchase_order: string | null; sheq: { reference: string; status: string } | null
    actions: Action[]
  }
  repeats: Array<{ id: string; number: string; status: string; created_at: string | null }>
  people: Array<{ id: number; name: string }>
  documents_panel: {
    available: boolean; relation: string | null; record: string | number | null; can_upload: boolean; count: number
    documents: Array<{ uuid: string; title: string; category: string | null; expiry_date: string | null; is_expired: boolean; uploaded_at: string | null }>
  }
  can: { update: boolean; verify: boolean; sheq: boolean }
}>()

const busy = ref(false)
const post = (name: string, data: Record<string, unknown> = {}, done?: () => void) => {
  busy.value = true
  router.post(route(`manufacturing.quality.ncrs.${name}`, props.ncr.id), data, {
    preserveScroll: true, onSuccess: () => done?.(), onFinish: () => { busy.value = false },
  })
}

const details = useForm({
  description: props.ncr.description, defect_code: props.ncr.defect_code ?? '', severity: props.ncr.severity,
  containment: props.ncr.containment ?? '', root_cause: props.ncr.root_cause ?? '', owner_id: props.ncr.owner_id,
  due_date: props.ncr.due_date ?? '', qty_affected: props.ncr.qty_affected,
})
function saveDetails() {
  details.transform(d => ({ ...d, due_date: d.due_date || null, defect_code: d.defect_code || null }))
    .patch(route('manufacturing.quality.ncrs.update', props.ncr.id), { preserveScroll: true })
}

const actionDialog = ref(false)
const action = useForm({ type: 'CORRECTIVE', description: '', owner_id: null as number | null, due_date: '' })
function saveAction() {
  action.transform(d => ({ ...d, due_date: d.due_date || null }))
    .post(route('manufacturing.quality.ncrs.actions.store', props.ncr.id), { preserveScroll: true, onSuccess: () => { actionDialog.value = false; action.reset() } })
}

const completing = ref<Action | null>(null)
const completionNotes = ref('')
function submitComplete() {
  busy.value = true
  router.post(route('manufacturing.quality.ncrs.actions.complete', { uuid: props.ncr.id, actionUuid: completing.value!.id }), { notes: completionNotes.value || null }, {
    preserveScroll: true, onSuccess: () => { completing.value = null; completionNotes.value = '' }, onFinish: () => { busy.value = false },
  })
}

const verifyDialog = ref(false)
const verifyForm = ref({ effectiveness: 'EFFECTIVE', notes: '' })
const voidDialog = ref(false)
const voidReason = ref('')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0" :href="route('manufacturing.quality.ncrs.index')">{{ t('manufacturing.quality.ncrs_title') }}</VBtn>
        <h4 class="text-h4">{{ ncr.number }}</h4>
        <div class="text-body-2 text-medium-emphasis">{{ t(`manufacturing.quality.source.${ncr.source}`) }}<template v-if="ncr.item_code"> · {{ ncr.item_code }} — {{ ncr.item_name }}</template><template v-if="ncr.lot"> · {{ ncr.lot }}</template></div>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <VChip size="small" label variant="tonal" :color="ncrStatusColor(ncr.status)">{{ t(`manufacturing.quality.ncr_status.${ncr.status}`) }}</VChip>
          <VChip size="small" label variant="tonal" :color="severityColor(ncr.severity)">{{ t(`manufacturing.quality.severity.${ncr.severity}`) }}</VChip>
          <VChip v-if="ncr.is_overdue" size="small" label color="error">{{ t('manufacturing.orders.overdue') }}</VChip>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn v-if="can.update && ['ACTION', 'INVESTIGATING'].includes(ncr.status)" color="purple" :loading="busy" @click="post('submit')">{{ t('manufacturing.quality.submit_verification') }}</VBtn>
        <VBtn v-if="can.verify" color="success" @click="verifyDialog = true">{{ t('manufacturing.quality.verify') }}</VBtn>
        <VBtn v-if="can.sheq" variant="tonal" :loading="busy" @click="post('sheq')">{{ t('manufacturing.quality.raise_in_sheq') }}</VBtn>
        <VBtn v-if="can.update" variant="text" color="error" @click="voidDialog = true">{{ t('manufacturing.quality.void') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="ncr.status === 'CLOSED'" type="success" variant="tonal" class="mb-4">{{ t('manufacturing.quality.closed_effective', { notes: ncr.effectiveness_notes ?? '' }) }}</VAlert>
    <VAlert v-else-if="ncr.effectiveness === 'NOT_EFFECTIVE'" type="warning" variant="tonal" class="mb-4">{{ t('manufacturing.quality.not_effective', { notes: ncr.effectiveness_notes ?? '' }) }}</VAlert>
    <VAlert v-if="ncr.status === 'VOID'" type="info" variant="tonal" class="mb-4">{{ ncr.void_reason }}</VAlert>

    <VRow>
      <VCol cols="12" md="8">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.quality.investigation') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.quality.investigation_hint') }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VRow dense>
              <VCol cols="12"><VTextarea v-model="details.description" rows="2" auto-grow :readonly="!can.update" :label="t('manufacturing.quality.description')" /></VCol>
              <VCol cols="12" sm="4"><VTextField v-model="details.defect_code" :readonly="!can.update" :label="t('manufacturing.quality.defect_code')" /></VCol>
              <VCol cols="12" sm="4"><VSelect v-model="details.severity" :readonly="!can.update" :items="['MINOR', 'MAJOR', 'CRITICAL'].map(s => ({ title: t(`manufacturing.quality.severity.${s}`), value: s }))" :label="t('manufacturing.quality.severity_label')" /></VCol>
              <VCol cols="12" sm="4"><VTextField v-model.number="details.qty_affected" type="number" step="any" :readonly="!can.update" :label="t('manufacturing.quality.qty_affected')" /></VCol>
              <VCol cols="12"><VTextarea v-model="details.containment" rows="2" auto-grow :readonly="!can.update" :label="t('manufacturing.quality.containment')" /></VCol>
              <VCol cols="12"><VTextarea v-model="details.root_cause" rows="3" auto-grow :readonly="!can.update" :label="t('manufacturing.quality.root_cause')" /></VCol>
              <VCol cols="12" sm="6"><VAutocomplete v-model="details.owner_id" :items="people" item-value="id" item-title="name" clearable :readonly="!can.update" :label="t('manufacturing.quality.owner')" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model="details.due_date" type="date" :readonly="!can.update" :label="t('manufacturing.orders.due')" /></VCol>
            </VRow>
          </VCardText>
          <VCardActions v-if="can.update"><VSpacer /><VBtn color="primary" :loading="details.processing" @click="saveDetails">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
        </VCard>

        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.quality.actions') }}</VCardTitle>
            <VCardSubtitle>{{ t('manufacturing.quality.actions_hint') }}</VCardSubtitle>
            <template #append><VBtn v-if="can.update" size="small" color="primary" prepend-icon="tabler-plus" @click="actionDialog = true">{{ t('manufacturing.quality.add_action') }}</VBtn></template>
          </VCardItem>
          <VList lines="two">
            <VListItem v-for="a in ncr.actions" :key="a.id">
              <VListItemTitle class="d-flex gap-2 align-center flex-wrap">
                <VChip size="x-small" label>{{ t(`manufacturing.quality.action_type.${a.type}`) }}</VChip>
                <span>{{ a.description }}</span>
              </VListItemTitle>
              <VListItemSubtitle>
                {{ a.owner ?? '—' }} · <span :class="{ 'text-error': a.is_overdue }">{{ a.due_date ?? '—' }}</span>
                <template v-if="a.status === 'DONE'"> · {{ t('manufacturing.quality.done_at', { at: a.completed_at ? new Date(a.completed_at).toLocaleDateString() : '' }) }}<span v-if="a.completion_notes"> — {{ a.completion_notes }}</span></template>
              </VListItemSubtitle>
              <template #append>
                <VChip v-if="a.status === 'DONE'" size="small" color="success" label>{{ t('manufacturing.quality.done') }}</VChip>
                <VBtn v-else-if="a.can_complete" size="small" variant="tonal" color="success" @click="completing = a">{{ t('manufacturing.quality.mark_done') }}</VBtn>
              </template>
            </VListItem>
            <VListItem v-if="!ncr.actions.length" :title="t('manufacturing.quality.no_actions')" />
          </VList>
        </VCard>

        <RecordDocuments v-bind="documents_panel" :can-upload="documents_panel.can_upload" :title="t('manufacturing.quality.evidence')" />
      </VCol>

      <VCol cols="12" md="4">
        <VCard border class="mb-4">
          <VList density="compact">
            <VListItem v-if="ncr.inspection" :title="t('manufacturing.quality.inspection')">
              <template #subtitle><a :href="route('manufacturing.quality.inspections.show', ncr.inspection.id)">{{ ncr.inspection.number }}</a></template>
            </VListItem>
            <VListItem v-if="ncr.order" :title="t('manufacturing.orders.number')">
              <template #subtitle><a :href="route('manufacturing.production.orders.show', ncr.order.id)">{{ ncr.order.number }}</a></template>
            </VListItem>
            <VListItem v-if="ncr.supplier" :title="t('manufacturing.quality.supplier')" :subtitle="ncr.supplier" />
            <VListItem v-if="ncr.purchase_order" :title="t('manufacturing.quality.purchase_order')" :subtitle="ncr.purchase_order" />
            <VListItem v-if="ncr.sheq" :title="t('manufacturing.quality.sheq_ncr')" :subtitle="`${ncr.sheq.reference} · ${ncr.sheq.status}`" />
            <VListItem :title="t('manufacturing.quality.raised')" :subtitle="ncr.created_at ?? '—'" />
          </VList>
        </VCard>
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">{{ t('manufacturing.quality.repeats', { n: repeats.length }) }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.quality.repeats_hint') }}</VCardSubtitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="r in repeats" :key="r.id" :href="route('manufacturing.quality.ncrs.show', r.id)" :title="r.number" :subtitle="`${r.created_at} · ${t(`manufacturing.quality.ncr_status.${r.status}`)}`" />
            <VListItem v-if="!repeats.length" :subtitle="ncr.defect_code ? t('manufacturing.quality.no_repeats') : t('manufacturing.quality.no_code')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="actionDialog" max-width="560">
      <VCard :title="t('manufacturing.quality.add_action')">
        <VCardText>
          <VRow dense>
            <VCol cols="12"><VSelect v-model="action.type" :items="['CONTAINMENT', 'CORRECTIVE', 'PREVENTIVE'].map(k => ({ title: t(`manufacturing.quality.action_type.${k}`), value: k }))" :label="t('manufacturing.quality.kind_label')" /></VCol>
            <VCol cols="12"><VTextarea v-model="action.description" rows="2" auto-grow :error-messages="action.errors.description" :label="t('manufacturing.quality.description')" /></VCol>
            <VCol cols="12" sm="6"><VAutocomplete v-model="action.owner_id" :items="people" item-value="id" item-title="name" clearable :label="t('manufacturing.quality.owner')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="action.due_date" type="date" :label="t('manufacturing.orders.due')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="actionDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="action.processing" :disabled="!action.description.trim()" @click="saveAction">{{ t('manufacturing.common.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="completing !== null" max-width="480" @update:model-value="v => { if (!v) completing = null }">
      <VCard v-if="completing" :title="t('manufacturing.quality.mark_done')" :subtitle="completing.description">
        <VCardText><VTextarea v-model="completionNotes" rows="2" auto-grow :label="t('manufacturing.quality.what_was_done')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="completing = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" @click="submitComplete">{{ t('manufacturing.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="verifyDialog" max-width="520">
      <VCard :title="t('manufacturing.quality.verify')" :subtitle="t('manufacturing.quality.verify_hint')">
        <VCardText>
          <VRadioGroup v-model="verifyForm.effectiveness">
            <VRadio :label="t('manufacturing.quality.effective')" value="EFFECTIVE" color="success" />
            <VRadio :label="t('manufacturing.quality.not_effective_option')" value="NOT_EFFECTIVE" color="warning" />
          </VRadioGroup>
          <VTextarea v-model="verifyForm.notes" rows="2" auto-grow :label="t('manufacturing.quality.evidence_notes')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="verifyDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" @click="post('verify', verifyForm, () => { verifyDialog = false })">{{ t('manufacturing.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="voidDialog" max-width="480">
      <VCard :title="t('manufacturing.quality.void')">
        <VCardText><VTextarea v-model="voidReason" rows="2" auto-grow :label="t('manufacturing.orders.reason')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="voidDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="error" :loading="busy" :disabled="!voidReason.trim()" @click="post('void', { reason: voidReason }, () => { voidDialog = false })">{{ t('manufacturing.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
