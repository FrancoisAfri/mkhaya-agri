<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'
import RecallSections from '../components/recall/RecallSections.vue'
import type { RecallPayload } from '../components/recall/types'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<RecallPayload & { can: { recall: boolean } }>()
const open = props.recall.status === 'OPEN'

const lotAction = ref<{ id: number; lot: string | null; outstanding: number; action: 'release' | 'dispose' } | null>(null)
const lotForm = useForm({ action: '', recall_lot_id: 0, qty: 0, reason: '' })
function startLot(l: { id: number; lot: string | null; outstanding: number }, action: 'release' | 'dispose') {
  lotAction.value = { ...l, action }
  Object.assign(lotForm, { action, recall_lot_id: l.id, qty: l.outstanding, reason: '' })
}
const record = useForm({ action: 'record', kind: 'CUSTOMER_NOTIFIED', reference: '', reason: '', qty: null as number | null, recall_lot_id: null as number | null })
const recording = ref(false)
const closing = ref(false)
const closeNote = ref('')
const upload = useForm({ action: 'evidence', file: null as File | null, reason: '' })
function attach(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (!f) return
  upload.file = f
  upload.post(route('warehouse.recalls.act', props.recall.id), { preserveScroll: true, forceFormData: true })
}
const post = (body: Record<string, unknown>) => router.post(route('warehouse.recalls.act', props.recall.id), body, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.recalls.index')">{{ t('warehouse_management.recalls.title') }}</a></div>
        <h4 class="text-h4">{{ recall.number }} · {{ recall.title }}
          <VChip size="small" :color="open ? 'error' : 'success'" variant="tonal">{{ t(`warehouse_management.recall_status.${recall.status}`) }}</VChip></h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t(`warehouse_management.recall_class.${recall.severity}`) }} · {{ t(`warehouse_management.recall_scope.${recall.scope}`) }}<span v-if="recall.item"> · {{ recall.item }}</span>
          <span v-if="recall.serial_from"> · {{ recall.serial_from }} – {{ recall.serial_to }}</span></p>
        <p class="text-body-2 mb-0">{{ recall.reason }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-printer" :href="route('warehouse.recalls.report', recall.id)" target="_blank">{{ t('warehouse_management.recalls.report') }}</VBtn>
        <template v-if="open && can.recall">
          <VBtn color="error" @click="post({ action: 'hold' })">{{ t('warehouse_management.recalls.hold_all') }}</VBtn>
          <VBtn variant="tonal" @click="recording = true">{{ t('warehouse_management.recalls.record') }}</VBtn>
          <label class="v-btn v-btn--variant-tonal" style="cursor: pointer"><VIcon icon="tabler-paperclip" size="16" class="me-1" />{{ t('warehouse_management.recalls.add_evidence') }}
            <input type="file" hidden @change="attach"></label>
          <VBtn variant="text" color="success" @click="closing = true">{{ t('warehouse_management.recalls.close') }}</VBtn>
        </template>
      </div>
    </div>
    <RefusalAlert />

    <RecallSections :recall="recall" :lots="lots" :actions="actions" :impact="impact" :evidence="evidence">
      <template #lot-actions="{ lot }">
        <template v-if="open && can.recall && lot.outstanding > 0">
          <VBtn size="small" variant="text" @click="startLot(lot, 'release')">{{ t('warehouse_management.recalls.release') }}</VBtn>
          <VBtn size="small" variant="text" color="error" @click="startLot(lot, 'dispose')">{{ t('warehouse_management.recalls.dispose') }}</VBtn>
        </template>
      </template>
    </RecallSections>

    <VDialog :model-value="lotAction !== null" max-width="440" @update:model-value="v => !v && (lotAction = null)">
      <VCard v-if="lotAction" :title="t(`warehouse_management.recalls.${lotAction.action}_title`, { lot: lotAction.lot ?? '' })">
        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model.number="lotForm.qty" type="number" min="0" :max="lotAction.outstanding" :label="t('warehouse_management.quality.qty')" />
          <VTextarea v-model="lotForm.reason" rows="2" :label="t('warehouse_management.common.reason')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="lotAction = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="lotForm.post(route('warehouse.recalls.act', recall.id), { preserveScroll: true, onSuccess: () => lotAction = null })">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog v-model="recording" max-width="480">
      <VCard :title="t('warehouse_management.recalls.record')">
        <VCardText class="d-flex flex-column gap-3">
          <VSelect v-model="record.kind" :items="['CUSTOMER_NOTIFIED', 'REGULATOR_NOTIFIED', 'RETRIEVED', 'NOTE'].map(k => ({ value: k, title: t(`warehouse_management.recall_action.${k}`) }))" />
          <VTextField v-if="record.kind !== 'NOTE'" v-model="record.reference" :label="t('warehouse_management.recalls.who')" :hint="t('warehouse_management.recalls.who_hint')" persistent-hint />
          <template v-if="record.kind === 'RETRIEVED'">
            <VSelect v-model="record.recall_lot_id" :items="lots.map(l => ({ value: l.id, title: `${l.item} ${l.lot ?? ''}` }))" :label="t('warehouse_management.recalls.lot')" />
            <VTextField v-model.number="record.qty" type="number" min="0" :label="t('warehouse_management.quality.qty')" />
          </template>
          <VTextarea v-model="record.reason" rows="2" :label="t('warehouse_management.counts.investigation')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="recording = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="record.post(route('warehouse.recalls.act', recall.id), { preserveScroll: true, onSuccess: () => { recording = false; record.reset() } })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog v-model="closing" max-width="480">
      <VCard :title="t('warehouse_management.recalls.close')">
        <VCardText><p class="text-body-2">{{ t('warehouse_management.recalls.close_hint') }}</p><VTextarea v-model="closeNote" rows="3" :label="t('warehouse_management.recalls.conclusion')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="closing = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="success" @click="post({ action: 'close', reason: closeNote }); closing = false">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
