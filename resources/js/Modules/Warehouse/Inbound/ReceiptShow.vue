<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  receipt: Record<string, any>
  lines: Array<Record<string, any>>
  discrepancies: Array<{ uuid: string; kind: string; expected: number | null; actual: number | null; within_tolerance: boolean; detail: string; status: string; resolution: string | null; resolved_by: string | null }>
  attachments: Array<{ uuid: string; kind: string; original_name: string; mime: string; size: number }>
  putaway: Array<{ id: string; number: string; item: string; qty: number; status: string; suggested: string | null; to: string | null }>
  can: { exceptions: boolean; receive: boolean; returns: boolean }
}>()

const decideForm = useForm({ decision: 'approve', note: '' })
function decide(decision: string) {
  decideForm.decision = decision
  decideForm.post(route('warehouse.inbound.receipts.decide', props.receipt.id), { preserveScroll: true })
}

const resolving = ref<string | null>(null)
const resolveForm = useForm({ decision: 'RESOLVE', resolution: '' })
function resolve() {
  if (!resolving.value) return
  resolveForm.post(route('warehouse.inbound.discrepancies.resolve', resolving.value), { preserveScroll: true, onSuccess: () => { resolving.value = null } })
}

const upload = useForm({ file: null as File | null, kind: 'DOCUMENT' })
function attach() {
  upload.post(route('warehouse.inbound.receipts.attach', props.receipt.id), { preserveScroll: true, forceFormData: true, onSuccess: () => upload.reset() })
}

const statusColor = (s: string) => ({ OPEN: 'error', ACCEPTED: 'success', RESOLVED: 'success', REJECTED: 'secondary' } as Record<string, string>)[s]
const print = () => window.print()
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption no-print"><a :href="route('warehouse.inbound.receipts.index')">{{ t('warehouse_management.receipts.title') }}</a></div>
        <h4 class="text-h4">{{ receipt.number }}</h4>
        <div class="text-body-2 text-medium-emphasis">
          {{ receipt.supplier }} · {{ receipt.po ? t('warehouse_management.receipts.against', { po: receipt.po }) : t('warehouse_management.receipts.no_order') }}
          · {{ receipt.warehouse }} · {{ receipt.received_at ? new Date(receipt.received_at).toLocaleString() : '' }} · {{ receipt.received_by }}
        </div>
        <div class="text-body-2 text-medium-emphasis">
          <template v-if="receipt.delivery_note_ref">{{ t('warehouse_management.receive.delivery_note') }}: {{ receipt.delivery_note_ref }}</template>
          <template v-if="receipt.vehicle_reg"> · {{ t('warehouse_management.receive.vehicle') }}: {{ receipt.vehicle_reg }}</template>
        </div>
      </div>
      <div class="d-flex gap-2 no-print">
        <VBtn variant="tonal" prepend-icon="tabler-printer" @click="print">{{ t('warehouse_management.labels.print') }}</VBtn>
        <VBtn v-if="can.returns && receipt.posted_at" variant="tonal" color="error" prepend-icon="tabler-truck-return" :href="route('warehouse.inbound.returns.index', { grn: receipt.id })">{{ t('warehouse_management.receipts.return_goods') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />

    <VAlert v-if="receipt.approval_status === 'PENDING'" type="warning" variant="tonal" class="mb-4">
      {{ t('warehouse_management.receipts.pending_explain', { reason: receipt.non_po_reason }) }}
      <div v-if="can.exceptions && !receipt.mine" class="d-flex gap-2 mt-2 align-center no-print">
        <VTextField v-model="decideForm.note" density="compact" hide-details :label="t('warehouse_management.adjustments.note')" />
        <VBtn color="success" :loading="decideForm.processing" @click="decide('approve')">{{ t('warehouse_management.adjustments.approve') }}</VBtn>
        <VBtn color="error" variant="tonal" :loading="decideForm.processing" @click="decide('reject')">{{ t('warehouse_management.adjustments.reject') }}</VBtn>
      </div>
      <div v-else-if="receipt.mine" class="text-caption mt-1">{{ t('warehouse_management.receipts.not_yours') }}</div>
    </VAlert>
    <VAlert v-if="receipt.inspection_status === 'PENDING'" type="info" variant="tonal" class="mb-4">{{ t('warehouse_management.receipts.inspection_pending') }}</VAlert>

    <VCard border class="mb-4">
      <VCardTitle>{{ t('warehouse_management.receipts.lines') }}</VCardTitle>
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead><tr><th>{{ t('warehouse_management.movements.item') }}</th><th class="text-end">{{ t('warehouse_management.expected.ordered') }}</th><th class="text-end">{{ t('warehouse_management.receive.accepted') }}</th>
            <th class="text-end">{{ t('warehouse_management.receive.rejected') }}</th><th class="text-end">{{ t('warehouse_management.receive.damaged') }}</th><th class="text-end">{{ t('warehouse_management.receive.missing') }}</th>
            <th>{{ t('warehouse_management.receive.lot') }}</th><th>{{ t('warehouse_management.receipts.went_to') }}</th></tr></thead>
          <tbody>
            <tr v-for="l in lines" :key="l.id">
              <td>{{ l.name }}<div v-if="l.item" class="text-caption">{{ l.item }}</div></td>
              <td class="text-end">{{ l.ordered ?? '—' }}</td>
              <td class="text-end">{{ l.accepted }}<div v-if="l.base_qty !== null && l.base_qty !== l.accepted" class="text-caption">= {{ l.base_qty }} {{ l.uom }}</div></td>
              <td class="text-end">{{ l.rejected }}</td>
              <td class="text-end" :class="{ 'text-error': l.damaged > 0 }">{{ l.damaged }}</td>
              <td class="text-end" :class="{ 'text-error': l.missing > 0 }">{{ l.missing }}</td>
              <td class="text-caption">{{ l.lot ?? '' }}<div v-if="l.expires_on">{{ t('warehouse_management.stock.expires', { date: l.expires_on }) }}</div><div v-if="l.serials.length">{{ l.serials.join(', ') }}</div></td>
              <td class="text-caption">
                <template v-if="l.posted">{{ l.bin ?? t('warehouse_management.stock.no_bin') }} · {{ t(`warehouse_management.stock_state.${l.stock_status}`) }}</template>
                <template v-else-if="l.item">{{ t('warehouse_management.receipts.not_posted') }}</template>
                <template v-else>{{ t('warehouse_management.receipts.not_stock') }}</template>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard border class="mb-4">
          <VCardTitle>{{ t('warehouse_management.receipts.discrepancies') }}</VCardTitle>
          <VCardText v-if="!discrepancies.length" class="text-medium-emphasis">{{ t('warehouse_management.receipts.no_discrepancies') }}</VCardText>
          <VList v-else density="compact">
            <VListItem v-for="d in discrepancies" :key="d.uuid">
              <VListItemTitle>
                <VChip size="x-small" label class="me-1">{{ t(`warehouse_management.discrepancy_kind.${d.kind}`) }}</VChip>
                <VChip size="x-small" :color="statusColor(d.status)" variant="tonal">{{ t(`warehouse_management.discrepancy_status.${d.status}`) }}</VChip>
              </VListItemTitle>
              <VListItemSubtitle>{{ d.detail }}<template v-if="d.resolution"> — {{ d.resolution }}<template v-if="d.resolved_by"> ({{ d.resolved_by }})</template></template></VListItemSubtitle>
              <template v-if="d.status === 'OPEN' && can.exceptions" #append>
                <VBtn size="small" variant="tonal" class="no-print" @click="resolving = d.uuid; resolveForm.reset()">{{ t('warehouse_management.receipts.settle') }}</VBtn>
              </template>
            </VListItem>
          </VList>
        </VCard>
        <VCard border>
          <VCardTitle>{{ t('warehouse_management.receipts.putaway') }}</VCardTitle>
          <VCardText v-if="!putaway.length" class="text-medium-emphasis">{{ t('warehouse_management.receipts.no_putaway') }}</VCardText>
          <VTable v-else density="compact">
            <tbody><tr v-for="p in putaway" :key="p.id"><td>{{ p.number }}</td><td>{{ p.item }}</td><td class="text-end">{{ p.qty }}</td>
              <td>{{ p.to ?? p.suggested ?? t('warehouse_management.receipts.no_suggestion') }}</td><td><VChip size="x-small" variant="tonal">{{ t(`warehouse_management.task_status.${p.status}`) }}</VChip></td></tr></tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="5">
        <VCard border>
          <VCardTitle>{{ t('warehouse_management.receipts.attachments') }}</VCardTitle>
          <VList v-if="attachments.length" density="compact">
            <VListItem v-for="a in attachments" :key="a.uuid" :prepend-icon="a.kind === 'PHOTO' ? 'tabler-photo' : 'tabler-file'" :title="a.original_name"
                       :href="route('warehouse.inbound.receipts.download', [receipt.id, a.uuid])" :subtitle="`${Math.round(a.size / 1024)} KB`" />
          </VList>
          <VCardText v-if="can.receive" class="no-print">
            <VFileInput :model-value="upload.file ? [upload.file] : []" density="compact" accept=".pdf,image/*" :label="t('warehouse_management.receipts.add_file')"
                        @update:model-value="(f: any) => upload.file = Array.isArray(f) ? f[0] ?? null : f" />
            <div class="d-flex gap-2">
              <VSelect v-model="upload.kind" :items="['DOCUMENT', 'PHOTO'].map(k => ({ value: k, title: t(`warehouse_management.attachment_kind.${k}`) }))" density="compact" hide-details />
              <VBtn color="primary" :disabled="!upload.file" :loading="upload.processing" @click="attach">{{ t('warehouse_management.receipts.upload') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="!!resolving" max-width="480" @update:model-value="v => { if (!v) resolving = null }">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.receipts.settle') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="resolveForm.decision" :items="[{ value: 'RESOLVE', title: t('warehouse_management.receipts.accept_as_is') }, { value: 'REJECT', title: t('warehouse_management.receipts.reject_with_supplier') }]" />
          <VTextarea v-model="resolveForm.resolution" rows="2" auto-grow :label="t('warehouse_management.receipts.resolution')" :error-messages="resolveForm.errors.resolution" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="resolving = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="resolveForm.processing" @click="resolve">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
@media print { .no-print { display: none !important; } }
</style>
