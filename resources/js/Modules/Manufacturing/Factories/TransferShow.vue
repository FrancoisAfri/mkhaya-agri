<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  transfer: { id: string; number: string; status: string; from: string | null; to: string | null; from_warehouse: string | null; to_warehouse: string | null
    reason: string | null; discrepancy: string | null; needed_by: string | null; shipped_at: string | null; received_at: string | null
    lines: Array<{ id: number; item: string | null; name: string | null; requested: number; shipped: number; received: number }> }
  can: { manage: boolean; at_source: boolean; at_destination: boolean }
}>()

const x = props.transfer
const qty = ref<Record<number, number>>(Object.fromEntries(x.lines.map(l => [l.id, x.status === 'SHIPPED' ? l.shipped : l.requested])))
const note = ref('')
const act = (step: string) => router.post(route('manufacturing.multi.transfers.act', { uuid: x.id, step }),
  { qty: qty.value, discrepancy: note.value || null, reason: note.value || null }, { preserveScroll: true })
const num = (n: number) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn variant="text" icon="tabler-arrow-left" :href="route('manufacturing.multi.transfers.index')" />
      <div>
        <h4 class="text-h4">{{ x.number }}</h4>
        <div class="text-body-2 text-medium-emphasis">{{ x.from }} ({{ x.from_warehouse }}) → {{ x.to }} ({{ x.to_warehouse }})<span v-if="x.needed_by"> · {{ t('manufacturing.multi.needed_by') }} {{ x.needed_by }}</span></div>
      </div>
      <VSpacer /><VChip label>{{ t(`manufacturing.multi.statuses.${x.status}`) }}</VChip>
    </div>
    <VAlert v-if="x.status === 'SHIPPED'" type="info" variant="tonal" density="compact" class="mb-3">{{ t('manufacturing.multi.in_transit') }}</VAlert>
    <VAlert v-if="x.discrepancy" type="warning" variant="tonal" density="compact" class="mb-3">{{ x.discrepancy }}</VAlert>
    <div v-if="x.reason" class="text-body-2 mb-3" style="white-space: pre-line">{{ x.reason }}</div>
    <VCard border class="mb-4">
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.multi.item') }}</th><th class="text-end">{{ t('manufacturing.multi.requested') }}</th>
          <th class="text-end">{{ t('manufacturing.multi.shipped') }}</th><th class="text-end">{{ t('manufacturing.multi.received') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="l in x.lines" :key="l.id">
            <td>{{ l.item }}<div class="text-caption text-medium-emphasis">{{ l.name }}</div></td>
            <td class="text-end">{{ num(l.requested) }}</td><td class="text-end">{{ num(l.shipped) }}</td><td class="text-end">{{ num(l.received) }}</td>
            <td style="width: 140px"><VTextField v-if="can.manage && ['APPROVED', 'SHIPPED'].includes(x.status)" v-model.number="qty[l.id]" type="number" density="compact" hide-details /></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
    <VCard v-if="can.manage && !['RECEIVED', 'CANCELLED'].includes(x.status)" border>
      <VCardText class="d-flex gap-2 flex-wrap align-center">
        <VTextField v-model="note" hide-details :label="t('manufacturing.multi.note')" style="min-width: 260px" />
        <VBtn v-if="x.status === 'REQUESTED' && can.at_source" color="primary" @click="act('approve')">{{ t('manufacturing.multi.approve') }}</VBtn>
        <VBtn v-if="x.status === 'APPROVED' && can.at_source" color="primary" @click="act('ship')">{{ t('manufacturing.multi.ship') }}</VBtn>
        <VBtn v-if="x.status === 'SHIPPED' && can.at_destination" color="success" @click="act('receive')">{{ t('manufacturing.multi.receive') }}</VBtn>
        <VBtn v-if="['REQUESTED', 'APPROVED'].includes(x.status)" color="error" variant="text" :disabled="!note" @click="act('cancel')">{{ t('manufacturing.multi.cancel_transfer') }}</VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
