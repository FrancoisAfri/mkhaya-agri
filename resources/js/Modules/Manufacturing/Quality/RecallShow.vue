<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'

const { t } = useI18n()
const route = ziggyRoute

type Lot = { id: number; lot: string | null; lot_uuid: string | null; item: string; depth: number; in_stock_at_open: number; dispatched_at_open: number
  held: number; released: number; disposed: number; retrieved: number; on_hold: number; in_stock_now: number }

const props = defineProps<{
  recall: { id: string; number: string; title: string; reason: string; severity: string; status: string; origin_lot: string | null
    origin_lot_id: string | null; opened_at: string | null; closed_at: string | null; closure_notes: string | null }
  lots: Lot[]
  actions: Array<{ id: string; kind: string; lot: string | null; qty: number | null; reference: string | null; notes: string | null; acted_at: string | null; actor: string | null }>
  documents_panel: {
    available: boolean; relation: string | null; record: string | number | null; can_upload: boolean; count: number
    documents: Array<{ uuid: string; title: string; category: string | null; expiry_date: string | null; is_expired: boolean; uploaded_at: string | null }>
  }
  can: { manage: boolean }
}>()

const busy = ref(false)
const post = (name: string, data: Record<string, unknown>, done?: () => void) => {
  busy.value = true
  router.post(route(`manufacturing.quality.recalls.${name}`, props.recall.id), data, {
    preserveScroll: true, onSuccess: () => done?.(), onFinish: () => { busy.value = false },
  })
}

const totals = computed(() => ({
  in_stock: props.lots.reduce((a, l) => a + l.in_stock_at_open, 0),
  dispatched: props.lots.reduce((a, l) => a + l.dispatched_at_open, 0),
  on_hold: props.lots.reduce((a, l) => a + l.on_hold, 0),
}))

// One dialog for release, dispose and the recorded actions.
const acting = ref<null | { mode: 'release' | 'dispose' | 'record'; lot: Lot | null }>(null)
const form = ref({ kind: 'CUSTOMER_NOTIFIED', qty: null as number | null, reference: '', notes: '' })
function openAct(mode: 'release' | 'dispose' | 'record', lot: Lot | null = null, kind = 'CUSTOMER_NOTIFIED') {
  acting.value = { mode, lot }
  form.value = { kind, qty: lot && mode !== 'record' ? lot.on_hold : null, reference: '', notes: '' }
}
function submit() {
  const a = acting.value!
  const done = () => { acting.value = null }
  if (a.mode === 'record') post('actions.store', { kind: form.value.kind, recall_lot: a.lot?.id ?? null, qty: form.value.qty, reference: form.value.reference || null, notes: form.value.notes || null }, done)
  else post(a.mode, { recall_lot: a.lot!.id, qty: form.value.qty, notes: form.value.notes }, done)
}

const closing = ref(false)
const closeNotes = ref('')
const when = (s: string | null) => s ? new Date(s).toLocaleString() : '—'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0" :href="route('manufacturing.quality.recalls.index')">{{ t('manufacturing.trace.recalls') }}</VBtn>
        <h4 class="text-h4">{{ recall.number }} · {{ recall.title }}</h4>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <VChip size="small" label :color="recall.status === 'OPEN' ? 'error' : 'success'">{{ t(`manufacturing.trace.status.${recall.status}`) }}</VChip>
          <VChip size="small" label variant="outlined">{{ t(`manufacturing.trace.severity.${recall.severity}`) }}</VChip>
          <VChip v-if="recall.origin_lot" size="small" label variant="tonal" :href="route('manufacturing.quality.traceability', { lot: recall.origin_lot_id })">{{ t('manufacturing.trace.origin_lot') }}: {{ recall.origin_lot }}</VChip>
        </div>
      </div>
      <div v-if="can.manage" class="d-flex gap-2 flex-wrap">
        <VBtn color="warning" prepend-icon="tabler-hand-stop" :loading="busy" @click="post('hold', {})">{{ t('manufacturing.trace.hold_all') }}</VBtn>
        <VBtn variant="tonal" @click="openAct('record', null, 'CUSTOMER_NOTIFIED')">{{ t('manufacturing.trace.record_action') }}</VBtn>
        <VBtn variant="tonal" color="success" @click="closing = true">{{ t('manufacturing.trace.close') }}</VBtn>
      </div>
    </div>

    <VAlert type="error" variant="tonal" class="mb-4">{{ recall.reason }}</VAlert>
    <VAlert v-if="recall.status === 'CLOSED'" type="success" variant="tonal" class="mb-4">{{ t('manufacturing.trace.closed_on', { at: when(recall.closed_at) }) }} — {{ recall.closure_notes }}</VAlert>

    <VRow class="mb-1">
      <VCol cols="12" sm="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.trace.in_stock_at_open') }}</div><div class="text-h5">{{ totals.in_stock }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.trace.dispatched_at_open') }}</div><div class="text-h5 text-error">{{ totals.dispatched }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.trace.on_hold') }}</div><div class="text-h5 text-warning">{{ totals.on_hold }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border class="mb-4">
      <VCardItem><VCardTitle>{{ t('manufacturing.trace.affected_lots') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.trace.affected_hint') }}</VCardSubtitle></VCardItem>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('manufacturing.quality.lot') }}</th><th>{{ t('manufacturing.trace.item') }}</th>
            <th class="text-end">{{ t('manufacturing.trace.in_stock_at_open') }}</th><th class="text-end">{{ t('manufacturing.trace.dispatched_at_open') }}</th>
            <th class="text-end">{{ t('manufacturing.trace.on_hold') }}</th><th class="text-end">{{ t('manufacturing.trace.retrieved') }}</th>
            <th class="text-end">{{ t('manufacturing.trace.disposed') }}</th><th class="text-end">{{ t('manufacturing.trace.released') }}</th><th v-if="can.manage" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in lots" :key="l.id">
            <td :style="{ paddingInlineStart: `${16 + l.depth * 16}px` }"><a :href="route('manufacturing.quality.traceability', { lot: l.lot_uuid })">{{ l.lot }}</a></td>
            <td>{{ l.item }}</td>
            <td class="text-end">{{ l.in_stock_at_open }}</td>
            <td class="text-end" :class="{ 'text-error font-weight-medium': l.dispatched_at_open > 0 }">{{ l.dispatched_at_open }}</td>
            <td class="text-end">{{ l.on_hold }}</td>
            <td class="text-end">{{ l.retrieved }}</td>
            <td class="text-end">{{ l.disposed }}</td>
            <td class="text-end">{{ l.released }}</td>
            <td v-if="can.manage" class="text-end text-no-wrap">
              <VBtn v-if="l.on_hold > 0" size="x-small" variant="tonal" color="error" class="me-1" @click="openAct('dispose', l)">{{ t('manufacturing.trace.dispose') }}</VBtn>
              <VBtn v-if="l.on_hold > 0" size="x-small" variant="tonal" color="success" class="me-1" @click="openAct('release', l)">{{ t('manufacturing.trace.release') }}</VBtn>
              <VBtn v-if="l.dispatched_at_open > 0" size="x-small" variant="tonal" @click="openAct('record', l, 'RETRIEVED')">{{ t('manufacturing.trace.retrieved') }}</VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VRow>
      <VCol cols="12" md="7">
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.trace.actions') }}</VCardTitle></VCardItem>
          <VTimeline density="compact" side="end" class="pa-4" truncate-line="both">
            <VTimelineItem v-for="a in actions" :key="a.id" size="x-small" :dot-color="a.kind === 'HOLD' ? 'warning' : a.kind === 'DISPOSED' ? 'error' : 'primary'">
              <div class="d-flex justify-space-between flex-wrap gap-2">
                <span class="font-weight-medium">{{ t(`manufacturing.trace.action.${a.kind}`) }}<span v-if="a.lot"> · {{ a.lot }}</span><span v-if="a.qty !== null"> · {{ a.qty }}</span></span>
                <span class="text-caption text-medium-emphasis">{{ when(a.acted_at) }}<span v-if="a.actor"> · {{ a.actor }}</span></span>
              </div>
              <div v-if="a.reference" class="text-body-2">{{ a.reference }}</div>
              <div v-if="a.notes" class="text-body-2 text-medium-emphasis">{{ a.notes }}</div>
            </VTimelineItem>
          </VTimeline>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <RecordDocuments v-bind="documents_panel" :can-upload="documents_panel.can_upload" :title="t('manufacturing.trace.evidence')" />
      </VCol>
    </VRow>

    <VDialog :model-value="acting !== null" max-width="520" @update:model-value="v => { if (!v) acting = null }">
      <VCard v-if="acting" :title="acting.mode === 'record' ? t('manufacturing.trace.record_action') : t(`manufacturing.trace.${acting.mode}`)"
             :subtitle="acting.lot ? `${acting.lot.lot} · ${acting.lot.item}` : ''">
        <VCardText>
          <VSelect v-if="acting.mode === 'record'" v-model="form.kind" class="mb-2"
                   :items="['CUSTOMER_NOTIFIED', 'REGULATOR_NOTIFIED', 'RETRIEVED', 'NOTE'].map(k => ({ title: t(`manufacturing.trace.action.${k}`), value: k }))"
                   :label="t('manufacturing.quality.kind_label')" />
          <VSelect v-if="acting.mode === 'record' && form.kind === 'RETRIEVED' && !acting.lot" v-model="acting.lot" :items="lots" item-title="lot" return-object class="mb-2" :label="t('manufacturing.quality.lot')" />
          <VTextField v-if="acting.mode !== 'record' || form.kind === 'RETRIEVED'" v-model.number="form.qty" type="number" min="0" step="any" class="mb-2" :label="t('manufacturing.orders.quantity')" />
          <VTextField v-if="acting.mode === 'record' && form.kind !== 'NOTE'" v-model="form.reference" class="mb-2"
                      :label="form.kind === 'RETRIEVED' ? t('manufacturing.trace.retrieved_from') : t('manufacturing.trace.who_notified')" />
          <VTextarea v-model="form.notes" rows="2" auto-grow :label="acting.mode === 'record' ? t('manufacturing.orders.notes') : t('manufacturing.orders.reason')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="acting = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="busy" :disabled="acting.mode !== 'record' && !form.notes.trim()" @click="submit">{{ t('manufacturing.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="closing" max-width="520">
      <VCard :title="t('manufacturing.trace.close')" :subtitle="t('manufacturing.trace.close_hint')">
        <VCardText><VTextarea v-model="closeNotes" rows="3" auto-grow :label="t('manufacturing.trace.conclusion')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="closing = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" :disabled="!closeNotes.trim()" @click="post('close', { notes: closeNotes }, () => { closing = false })">{{ t('manufacturing.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
