<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; number: string; po: string | null; source: string; supplier: string | null; received_at: string | null; delivery_note_ref: string | null; approval_status: string; discrepancy_status: string; inspection_status: string; posted: boolean; value: number }

defineProps<{
  receipts: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: Record<string, string>
  counts: { open_discrepancies: number; pending_approval: number }
}>()
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.receipts.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.receipts.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VChip :variant="filters.discrepancy ? 'flat' : 'tonal'" color="error" @click="router.get(route('warehouse.inbound.receipts.index'), filters.discrepancy ? {} : { discrepancy: 1 })">
          {{ t('warehouse_management.receipts.open_discrepancies', { n: counts.open_discrepancies }) }}
        </VChip>
        <VChip :variant="filters.pending ? 'flat' : 'tonal'" color="warning" @click="router.get(route('warehouse.inbound.receipts.index'), filters.pending ? {} : { pending: 1 })">
          {{ t('warehouse_management.receipts.pending_approval', { n: counts.pending_approval }) }}
        </VChip>
      </div>
    </div>
    <VCard border>
      <VCardText v-if="!receipts.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.receipts.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="comfortable">
          <thead>
            <tr><th>{{ t('warehouse_management.receipts.grn') }}</th><th>{{ t('warehouse_management.expected.order') }}</th><th>{{ t('warehouse_management.products.supplier') }}</th>
              <th>{{ t('warehouse_management.receipts.received') }}</th><th>{{ t('warehouse_management.locations.status') }}</th><th class="text-end">{{ t('warehouse_management.movements.value') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in receipts.data" :key="r.id">
              <td><a :href="route('warehouse.inbound.receipts.show', r.id)" class="font-weight-medium">{{ r.number }}</a><div v-if="r.delivery_note_ref" class="text-caption text-medium-emphasis">{{ r.delivery_note_ref }}</div></td>
              <td>{{ r.po ?? t('warehouse_management.receipts.no_order') }}</td>
              <td>{{ r.supplier }}</td>
              <td class="text-no-wrap">{{ r.received_at ? new Date(r.received_at).toLocaleString() : '' }}</td>
              <td class="d-flex gap-1 flex-wrap align-center" style="min-height: 48px">
                <VChip v-if="r.approval_status === 'PENDING'" size="x-small" color="warning" variant="tonal">{{ t('warehouse_management.receipts.awaiting_approval') }}</VChip>
                <VChip v-if="r.discrepancy_status === 'OPEN'" size="x-small" color="error" variant="tonal">{{ t('warehouse_management.receipts.discrepancy') }}</VChip>
                <VChip v-if="r.inspection_status === 'PENDING'" size="x-small" color="info" variant="tonal">{{ t('warehouse_management.receipts.in_inspection') }}</VChip>
                <VChip v-if="r.posted" size="x-small" color="success" variant="tonal">{{ t('warehouse_management.receipts.posted') }}</VChip>
              </td>
              <td class="text-end">{{ r.value.toFixed(2) }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardActions v-if="receipts.links.length > 3" class="justify-center flex-wrap">
        <VBtn v-for="(l, i) in receipts.links" :key="i" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
