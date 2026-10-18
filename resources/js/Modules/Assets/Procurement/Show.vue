<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type PurchaseRequest = {
  uuid: string
  request_number: string
  item_description: string
  quantity: number
  estimated_unit_cost: string
  estimated_total_cost: string
  actual_unit_cost: string | null
  actual_total_cost: string | null
  justification: string
  priority: { value: string; label: string; color: string }
  status: { value: string; label: string; color: string }
  vendor_name: string | null
  vendor_reference: string | null
  delivery_notes: string | null
  delivered_at: string | null
  submitted_at: string | null
  requestedBy: { name: string } | null
  category: { name: string } | null
  items: { id: number; asset_number: string | null; serial_number: string | null; asset: { uuid: string; name: string } | null }[]
  created_at: string
}

const props = defineProps<{
  purchase_request: PurchaseRequest
}>()

const pr = props.purchase_request
const showDeliverDialog = ref(false)
const confirmDialog = ref()

const deliverForm = useForm({
  actual_unit_cost:  null as number | null,
  delivered_at:      '',
  vendor_name:       pr.vendor_name ?? '',
  vendor_reference:  pr.vendor_reference ?? '',
  delivery_notes:    '',
})

function submitDeliver() {
  deliverForm.post(route('assets.procurement.deliver', pr.uuid), {
    onSuccess: () => { showDeliverDialog.value = false },
  })
}

function submitPR() {
  confirmDialog.value.open(
    { title: t('assets_procurement_show.confirm.submit_title'), message: t('assets_procurement_show.confirm.submit_message'), confirmText: t('assets_procurement_show.confirm.submit_text'), confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => router.post(route('assets.procurement.submit', pr.uuid)),
  )
}

function createAssets() {
  confirmDialog.value.open(
    { title: t('assets_procurement_show.confirm.create_assets_title'), message: t('assets_procurement_show.confirm.create_assets_message', { quantity: pr.quantity }), confirmText: t('assets_procurement_show.confirm.create_assets_text'), confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => router.post(route('assets.procurement.create-assets', pr.uuid)),
  )
}

function deletePR() {
  confirmDialog.value.open(
    { title: t('assets_procurement_show.confirm.delete_title'), message: t('assets_procurement_show.confirm.delete_message'), confirmText: t('assets_procurement_show.confirm.delete_text'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('assets.procurement.destroy', pr.uuid)),
  )
}

function formatCurrency(val: string | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 2 }).format(Number(val))
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <VBreadcrumbs :items="[
          { title: t('assets_procurement_show.breadcrumbs.procurement'), href: route('assets.procurement.index') },
          { title: pr.request_number },
        ]" class="pa-0 mb-1" />
        <h4 class="text-h4 font-weight-bold">{{ pr.request_number }}</h4>
      </div>
      <div class="d-flex gap-2 align-center flex-wrap">
        <VChip :color="pr.priority.color">{{ pr.priority.label }}</VChip>
        <VChip :color="pr.status.color">{{ pr.status.label }}</VChip>
        <VBtn v-if="pr.status.value === 'draft'" variant="tonal" color="primary" size="small" @click="submitPR">{{ t('assets_procurement_show.actions.submit') }}</VBtn>
        <VBtn v-if="['approved','ordered'].includes(pr.status.value)" variant="tonal" color="success" size="small" @click="showDeliverDialog = true">{{ t('assets_procurement_show.actions.mark_delivered') }}</VBtn>
        <VBtn v-if="pr.status.value === 'delivered' && !pr.items.length" variant="tonal" color="info" size="small" @click="createAssets">{{ t('assets_procurement_show.actions.create_assets') }}</VBtn>
        <VBtn v-if="pr.status.value === 'draft'" variant="tonal" color="error" size="small" @click="deletePR">{{ t('assets_procurement_show.actions.delete') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="5">
        <VCard elevation="0" border class="mb-4">
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-3">{{ t('assets_procurement_show.request_details') }}</p>
            <div class="d-flex flex-column gap-2 text-body-2">
              <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.description') }}:</span> {{ pr.item_description }}</div>
              <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.category') }}:</span> {{ pr.category?.name ?? '—' }}</div>
              <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.quantity') }}:</span> {{ pr.quantity }}</div>
              <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.est_unit') }}:</span> {{ formatCurrency(pr.estimated_unit_cost) }}</div>
              <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.est_total') }}:</span> {{ formatCurrency(pr.estimated_total_cost) }}</div>
              <template v-if="pr.actual_total_cost">
                <VDivider />
                <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.actual_unit') }}:</span> {{ formatCurrency(pr.actual_unit_cost) }}</div>
                <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.actual_total') }}:</span> {{ formatCurrency(pr.actual_total_cost) }}</div>
              </template>
              <VDivider />
              <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.requested_by') }}:</span> {{ pr.requestedBy?.name ?? '—' }}</div>
              <div><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.submitted') }}:</span> {{ formatDate(pr.submitted_at) }}</div>
              <div v-if="pr.vendor_name"><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.vendor') }}:</span> {{ pr.vendor_name }}</div>
              <div v-if="pr.delivered_at"><span class="text-medium-emphasis">{{ t('assets_procurement_show.details.delivered') }}:</span> {{ formatDate(pr.delivered_at) }}</div>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border>
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-2">{{ t('assets_procurement_show.justification') }}</p>
            <p class="text-body-2 mb-0">{{ pr.justification }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard elevation="0" border>
          <VCardText>
            <p class="text-subtitle-2 text-medium-emphasis mb-3">{{ t('assets_procurement_show.created_assets') }} ({{ pr.items.length }} / {{ pr.quantity }})</p>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('assets_procurement_show.assets_table.asset') }}</th>
                  <th>{{ t('assets_procurement_show.assets_table.asset_number') }}</th>
                  <th>{{ t('assets_procurement_show.assets_table.serial_number') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in pr.items" :key="item.id" class="cursor-pointer" @click="item.asset && router.visit(route('assets.show', item.asset.uuid))">
                  <td>{{ item.asset?.name ?? '—' }}</td>
                  <td>{{ item.asset_number ?? '—' }}</td>
                  <td>{{ item.serial_number ?? '—' }}</td>
                </tr>
                <tr v-if="!pr.items.length">
                  <td colspan="3" class="text-center text-medium-emphasis py-4">{{ t('assets_procurement_show.no_assets') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="showDeliverDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-6 pb-0">{{ t('assets_procurement_show.deliver_dialog.title') }}</VCardTitle>
        <VCardText class="pa-6">
          <VForm @submit.prevent="submitDeliver">
            <VRow>
              <VCol cols="12" sm="6">
                <VTextField v-model.number="deliverForm.actual_unit_cost" :label="t('assets_procurement_show.deliver_dialog.actual_unit_cost')" type="number" step="0.01" />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model="deliverForm.delivered_at" :label="t('assets_procurement_show.deliver_dialog.delivery_date')" type="date" :error-messages="deliverForm.errors.delivered_at" />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model="deliverForm.vendor_name" :label="t('assets_procurement_show.deliver_dialog.vendor_name')" />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model="deliverForm.vendor_reference" :label="t('assets_procurement_show.deliver_dialog.vendor_reference')" />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="deliverForm.delivery_notes" :label="t('assets_procurement_show.deliver_dialog.delivery_notes')" rows="3" />
              </VCol>
            </VRow>
            <div class="d-flex gap-3 mt-4">
              <VBtn type="submit" color="success" :loading="deliverForm.processing">{{ t('assets_procurement_show.deliver_dialog.confirm') }}</VBtn>
              <VBtn variant="text" @click="showDeliverDialog = false">{{ t('assets_procurement_show.deliver_dialog.cancel') }}</VBtn>
            </div>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
