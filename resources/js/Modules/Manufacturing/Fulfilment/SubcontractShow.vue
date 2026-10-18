<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RecordDocuments from '@/Modules/DocumentManagement/Components/RecordDocuments.vue'
import { subcontractStatusColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  subcontract: {
    id: string; number: string; status: string; order: { id: string; number: string } | null; operation: string | null; supplier: string | null
    qty: number; sent: number; received: number; lost: number; outstanding: number; unit_price: number; due_date: string | null; overdue: boolean
    item: string | null; item_name: string | null; notes: string | null; dispatch_reference: string | null; sent_at: string | null
    purchase_request: string | null; cost: number; currency: string | null
  }
  materials: Array<{ id: number; item: string | null; name: string | null; planned: number; sent: number; returned: number; at_supplier: number }>
  receipts: Array<{ qty: number; supplier_reference: string | null; received_at: string | null
    inspection: { id: string; number: string; result: string; status: string; disposition: string | null } | null }>
  can: { manage: boolean }
  documents_panel: { available: boolean; relation: string | null; record: string | number | null; can_upload: boolean; count: number; documents: Array<Record<string, any>> }
}>()

const s = props.subcontract
const num = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 })
const money = (n: number) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const sendForm = useForm({ dispatch_reference: '', materials: Object.fromEntries(props.materials.map(m => [m.id, m.planned])) as Record<number, number> })
const receiveForm = useForm({ qty: props.subcontract.outstanding as number | null, supplier_reference: '', lot_number: '' })
const closeReason = ref('')
const cancelReason = ref('')
const returnQty = ref<Record<number, number | null>>({})

const send = () => sendForm.post(route('manufacturing.fulfilment.subcontracts.send', s.id), { preserveScroll: true })
const receive = () => receiveForm.post(route('manufacturing.fulfilment.subcontracts.receive', s.id), { preserveScroll: true })
const close = () => router.post(route('manufacturing.fulfilment.subcontracts.close', s.id), { reason: closeReason.value || null }, { preserveScroll: true })
const cancel = () => router.post(route('manufacturing.fulfilment.subcontracts.cancel', s.id), { reason: cancelReason.value }, { preserveScroll: true })
const giveBack = (id: number) => router.post(route('manufacturing.fulfilment.subcontracts.return', { uuid: s.id, material: id }), { qty: returnQty.value[id] }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center flex-wrap gap-3 mb-4">
      <VBtn variant="text" icon="tabler-arrow-left" :href="route('manufacturing.fulfilment.subcontracts.index')" />
      <div>
        <h4 class="text-h4">{{ s.number }}</h4>
        <div class="text-body-2 text-medium-emphasis">
          {{ s.supplier }} ·
          <a v-if="s.order" :href="route('manufacturing.production.orders.show', s.order.id)">{{ s.order.number }}</a>
          · {{ s.operation ?? t('manufacturing.fulfilment.whole_order') }} · {{ s.item }} {{ s.item_name }}
        </div>
      </div>
      <VSpacer />
      <VChip label :color="subcontractStatusColor(s.status)">{{ t(`manufacturing.fulfilment.sco_status.${s.status}`) }}</VChip>
    </div>

    <VRow class="mb-1">
      <VCol cols="6" md="2"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.qty') }}</div><div class="text-h6">{{ num(s.qty) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.received') }}</div><div class="text-h6">{{ num(s.received) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.outstanding') }}</div><div class="text-h6">{{ num(s.outstanding) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.lost') }}</div><div class="text-h6">{{ num(s.lost) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.due') }}</div><div class="text-h6" :class="{ 'text-error': s.overdue }">{{ s.due_date ?? '—' }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="2"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.cost') }}</div><div class="text-h6">{{ s.currency }} {{ money(s.cost) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border class="mb-4">
      <VCardText class="text-body-2">
        <div>{{ t('manufacturing.fulfilment.unit_price') }}: {{ s.currency }} {{ money(s.unit_price) }}</div>
        <div>{{ t('manufacturing.fulfilment.purchase_request') }}: {{ s.purchase_request ?? '—' }} <span class="text-medium-emphasis">— {{ t('manufacturing.fulfilment.pr_hint') }}</span></div>
        <div v-if="s.dispatch_reference">{{ t('manufacturing.fulfilment.dispatch_reference') }}: {{ s.dispatch_reference }}</div>
        <div v-if="s.notes" class="mt-1" style="white-space: pre-line">{{ s.notes }}</div>
      </VCardText>
    </VCard>

    <VCard border class="mb-4">
      <VCardItem><VCardTitle>{{ t('manufacturing.fulfilment.materials') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.fulfilment.materials_hint') }}</VCardSubtitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.fulfilment.product') }}</th><th class="text-end">{{ t('manufacturing.fulfilment.planned') }}</th>
          <th class="text-end">{{ t('manufacturing.fulfilment.sent') }}</th><th class="text-end">{{ t('manufacturing.fulfilment.returned') }}</th>
          <th class="text-end">{{ t('manufacturing.fulfilment.at_supplier') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="m in materials" :key="m.id">
            <td>{{ m.item }}<div class="text-caption text-medium-emphasis">{{ m.name }}</div></td>
            <td class="text-end">
              <VTextField v-if="s.status === 'DRAFT' && can.manage" v-model.number="sendForm.materials[m.id]" type="number" density="compact" hide-details style="max-width: 120px; margin-left: auto" />
              <span v-else>{{ num(m.planned) }}</span>
            </td>
            <td class="text-end">{{ num(m.sent) }}</td>
            <td class="text-end">{{ num(m.returned) }}</td>
            <td class="text-end">{{ num(m.at_supplier) }}</td>
            <td class="text-end">
              <div v-if="can.manage && m.at_supplier > 0" class="d-flex gap-1 justify-end">
                <VTextField v-model.number="returnQty[m.id]" type="number" density="compact" hide-details style="max-width: 100px" />
                <VBtn size="small" variant="tonal" :disabled="!returnQty[m.id]" @click="giveBack(m.id)">{{ t('manufacturing.fulfilment.return') }}</VBtn>
              </div>
            </td>
          </tr>
          <tr v-if="!materials.length"><td colspan="6" class="text-center text-medium-emphasis py-4">{{ t('manufacturing.fulfilment.no_materials') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard border class="mb-4">
      <VCardItem><VCardTitle>{{ t('manufacturing.fulfilment.receipts') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.fulfilment.receipts_hint') }}</VCardSubtitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.fulfilment.received_at') }}</th><th class="text-end">{{ t('manufacturing.fulfilment.qty') }}</th>
          <th>{{ t('manufacturing.fulfilment.supplier_reference') }}</th><th>{{ t('manufacturing.fulfilment.inspection') }}</th></tr></thead>
        <tbody>
          <tr v-for="(r, i) in receipts" :key="i">
            <td>{{ r.received_at?.slice(0, 16).replace('T', ' ') }}</td><td class="text-end">{{ num(r.qty) }}</td><td>{{ r.supplier_reference ?? '—' }}</td>
            <td>
              <a v-if="r.inspection" :href="route('manufacturing.quality.inspections.show', r.inspection.id)">{{ r.inspection.number }}</a>
              <span v-if="r.inspection" class="text-caption text-medium-emphasis"> · {{ r.inspection.disposition ?? r.inspection.result }}</span>
              <span v-else>—</span>
            </td>
          </tr>
          <tr v-if="!receipts.length"><td colspan="4" class="text-center text-medium-emphasis py-4">{{ t('manufacturing.fulfilment.no_receipts') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard v-if="can.manage" border>
      <VCardText>
        <div v-if="s.status === 'DRAFT'" class="d-flex flex-wrap gap-3 align-center">
          <VTextField v-model="sendForm.dispatch_reference" :label="t('manufacturing.fulfilment.dispatch_reference')" hide-details style="max-width: 260px" />
          <VBtn color="primary" :loading="sendForm.processing" @click="send">{{ t('manufacturing.fulfilment.send') }}</VBtn>
          <VSpacer />
          <VTextField v-model="cancelReason" :label="t('manufacturing.fulfilment.cancel_reason')" hide-details style="max-width: 260px" />
          <VBtn color="error" variant="tonal" :disabled="!cancelReason" @click="cancel">{{ t('manufacturing.fulfilment.cancel') }}</VBtn>
        </div>
        <div v-else-if="s.status === 'SENT' || s.status === 'PART_RECEIVED'" class="d-flex flex-wrap gap-3 align-center">
          <VTextField v-model.number="receiveForm.qty" type="number" :label="t('manufacturing.fulfilment.qty')" hide-details style="max-width: 140px" :error-messages="receiveForm.errors.qty" />
          <VTextField v-model="receiveForm.supplier_reference" :label="t('manufacturing.fulfilment.supplier_reference')" hide-details style="max-width: 220px" />
          <VTextField v-if="!s.operation" v-model="receiveForm.lot_number" :label="t('manufacturing.fulfilment.lot_number')" hide-details style="max-width: 180px" />
          <VBtn color="primary" :loading="receiveForm.processing" @click="receive">{{ t('manufacturing.fulfilment.receive') }}</VBtn>
          <div v-if="s.operation" class="text-caption text-medium-emphasis w-100">{{ t('manufacturing.fulfilment.receive_operation_hint') }}</div>
        </div>
        <div v-if="s.status === 'RECEIVED' || s.status === 'PART_RECEIVED'" class="d-flex flex-wrap gap-3 align-center mt-3">
          <VTextField v-model="closeReason" :label="t('manufacturing.fulfilment.close_reason')" hide-details style="max-width: 320px" />
          <VBtn variant="tonal" @click="close">{{ t('manufacturing.fulfilment.close_order') }}</VBtn>
        </div>
      </VCardText>
    </VCard>
    <RecordDocuments v-if="documents_panel.available" v-bind="documents_panel" :can-upload="documents_panel.can_upload" :title="t('manufacturing.documents.title')" class="mt-4" />
  </div>
</template>
