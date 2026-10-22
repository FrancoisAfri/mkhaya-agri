<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import GrowerShell, { type Brand, type Me } from './GrowerShell.vue'
import { money, num } from '../Components/agri'

defineOptions({ layout: null })
defineProps<{
  brand: Brand; me: Me
  advances: { total: number; recovered: number; balance: number
    rows: Array<{ id: string; date: string; kind: string; contract: string | null; item: string | null; quantity: number | null; value: number; recovered: number; balance: number }> }
}>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})
</script>

<template>
  <GrowerShell :brand="brand" :me="me" :title="k('my_advances')">
    <VRow class="mb-2">
      <VCol cols="4"><VCard><VCardText><div class="text-caption">{{ k('advanced') }}</div><div class="text-h6">{{ money(advances.total) }}</div></VCardText></VCard></VCol>
      <VCol cols="4"><VCard><VCardText><div class="text-caption">{{ k('recovered') }}</div><div class="text-h6">{{ money(advances.recovered) }}</div></VCardText></VCard></VCol>
      <VCol cols="4"><VCard><VCardText><div class="text-caption">{{ k('balance') }}</div><div class="text-h6">{{ money(advances.balance) }}</div></VCardText></VCard></VCol>
    </VRow>
    <VCard>
      <VTable density="compact">
        <thead><tr><th>{{ k('date') }}</th><th>{{ k('issued') }}</th><th class="text-end">{{ k('value') }}</th><th class="text-end">{{ k('balance') }}</th></tr></thead>
        <tbody>
          <tr v-for="a in advances.rows" :key="a.id">
            <td class="text-no-wrap">{{ a.date }}<div class="text-caption">{{ a.contract }}</div></td>
            <td>{{ a.kind === 'cash' ? k('cash') : (a.item ?? k('inputs')) }}<template v-if="a.quantity"> × {{ num(a.quantity) }}</template></td>
            <td class="text-end">{{ money(a.value) }}</td>
            <td class="text-end">{{ money(a.balance) }}</td>
          </tr>
          <tr v-if="!advances.rows.length"><td colspan="4" class="text-center text-medium-emphasis py-4">{{ k('no_advances') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </GrowerShell>
</template>
