<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import GrowerShell, { type Brand, type Me } from './GrowerShell.vue'
import { money, num } from '../Components/agri'

defineOptions({ layout: null })
defineProps<{
  brand: Brand; me: Me
  contract: null | { id: string; reference: string; product: string; expected_quantity: number; delivered: number; unit: string }
  deliveries: Array<{ id: string; date: string; contract: string | null; unit: string | null; lot: string | null; delivered: number; rejected: number; accepted: number;
    grade: string | null; price: number; value: number; settlement: string | null }>
}>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})
</script>

<template>
  <GrowerShell :brand="brand" :me="me" :title="contract ? `${contract.reference} · ${contract.product}` : k('my_deliveries')">
    <p v-if="contract" class="text-body-2 mb-4">{{ k('delivered_of', { done: num(contract.delivered), expected: num(contract.expected_quantity), unit: contract.unit }) }}</p>
    <VCard>
      <VTable density="compact">
        <thead><tr><th>{{ k('date') }}</th><th>{{ k('lot') }}</th><th class="text-end">{{ k('mass') }}</th><th class="text-end">{{ k('deductions') }}</th>
          <th>{{ k('grade') }}</th><th class="text-end">{{ k('value') }}</th><th>{{ k('settled') }}</th></tr></thead>
        <tbody>
          <tr v-for="d in deliveries" :key="d.id">
            <td class="text-no-wrap">{{ d.date }}<div class="text-caption">{{ d.contract }}</div></td>
            <td>{{ d.lot ?? '—' }}</td>
            <td class="text-end text-no-wrap">{{ num(d.delivered) }} {{ d.unit }}</td>
            <td class="text-end">{{ num(d.rejected) }}</td>
            <td>{{ d.grade ?? '—' }}</td>
            <td class="text-end text-no-wrap">{{ money(d.value) }}<div class="text-caption">{{ money(d.price) }} / {{ d.unit }}</div></td>
            <td>{{ d.settlement ?? '' }}</td>
          </tr>
          <tr v-if="!deliveries.length"><td colspan="7" class="text-center text-medium-emphasis py-4">{{ k('no_deliveries') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </GrowerShell>
</template>
