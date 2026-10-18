<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  order: { id: string; number: string; status: string } | null
  records: Array<{ id: number; event: string; treatment: string; amount: number; qty: number | null; basis: string | null
    order: { id: string; number: string } | null; reference: string | null; movement: { id: string; type: string; date: string } | null
    journal: string | null; booked_on: string | null; is_reversal: boolean; reversible: boolean }>
  can: { reverse: boolean }
}>()

const search = ref('')
const find = () => router.get(route('manufacturing.accounting.transactions'), search.value ? { order: search.value } : {}, { preserveState: true })
const reversing = ref<string | null>(null)
const reason = ref('')
const reverse = () => router.post(route('manufacturing.accounting.movements.reverse', reversing.value), { reason: reason.value }, { preserveScroll: true, onSuccess: () => { reversing.value = null; reason.value = '' } })
const money = (n: number) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.accounting.tx_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.accounting.tx_subtitle') }}</p>
    </div>
    <VCard border class="mb-4">
      <VCardText class="d-flex gap-2">
        <VTextField v-model="search" :placeholder="t('manufacturing.accounting.order_number')" hide-details style="max-width: 260px" @keyup.enter="find" />
        <VBtn variant="tonal" @click="find">{{ t('manufacturing.accounting.show') }}</VBtn>
        <span v-if="order" class="align-self-center ms-2">{{ order.number }} · {{ order.status }}</span>
      </VCardText>
    </VCard>
    <VCard border>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.accounting.booked') }}</th><th>{{ t('manufacturing.accounting.event') }}</th><th>{{ t('manufacturing.accounting.order') }}</th>
          <th class="text-end">{{ t('manufacturing.accounting.qty') }}</th><th class="text-end">{{ t('manufacturing.accounting.amount') }}</th>
          <th>{{ t('manufacturing.accounting.source') }}</th><th>{{ t('manufacturing.accounting.journal') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="r in records" :key="r.id" :class="{ 'text-medium-emphasis': r.is_reversal }">
            <td>{{ r.booked_on }}</td>
            <td>{{ t(`manufacturing.accounting.events.${r.event}`) }} <VChip size="x-small" label>{{ t(`manufacturing.accounting.treatments.${r.treatment}`) }}</VChip>
              <div v-if="r.basis" class="text-caption">{{ r.basis }}</div></td>
            <td><a v-if="r.order" :href="route('manufacturing.production.orders.show', r.order.id)">{{ r.order.number }}</a><span v-else>—</span></td>
            <td class="text-end">{{ r.qty ?? '—' }}</td><td class="text-end">{{ money(r.amount) }}</td>
            <td class="text-caption">{{ r.movement ? `${r.movement.type} · ${r.movement.date}` : (r.reference ?? '—') }}</td>
            <td class="text-caption">{{ r.journal ?? (r.treatment === 'POST' ? t('manufacturing.accounting.not_posted') : '—') }}</td>
            <td class="text-end"><VBtn v-if="can.reverse && r.reversible && r.movement" size="small" variant="text" color="error" @click="reversing = r.movement.id">{{ t('manufacturing.accounting.reverse') }}</VBtn></td>
          </tr>
          <tr v-if="!records.length"><td colspan="8" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.accounting.no_records') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <VDialog :model-value="!!reversing" max-width="480" @update:model-value="v => { if (!v) reversing = null }">
      <VCard>
        <VCardItem><VCardTitle>{{ t('manufacturing.accounting.reverse_title') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.accounting.reverse_hint') }}</VCardSubtitle></VCardItem>
        <VCardText><VTextarea v-model="reason" rows="2" :label="t('manufacturing.accounting.reason')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="reversing = null">{{ t('manufacturing.accounting.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :disabled="!reason" @click="reverse">{{ t('manufacturing.accounting.reverse') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
