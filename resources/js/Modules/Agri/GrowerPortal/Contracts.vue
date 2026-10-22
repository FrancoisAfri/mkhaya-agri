<script setup lang="ts">
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import GrowerShell, { type Brand, type Me } from './GrowerShell.vue'
import { human, money, num, statusColor } from '../Components/agri'

defineOptions({ layout: null })
defineProps<{
  brand: Brand; me: Me
  contracts: Array<{ id: string; reference: string; product: string; crop: string | null; hectares: number | null; expected_quantity: number; unit: string; price: number;
    grades: Array<{ grade: string; price: number }>; advance_limit: number; status: string; delivered: number; delivered_value: number }>
}>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})
</script>

<template>
  <GrowerShell :brand="brand" :me="me" :title="k('my_contracts')">
    <VCard v-for="c in contracts" :key="c.id" class="mb-4">
      <VCardItem>
        <VCardTitle class="d-flex flex-wrap align-center ga-2">{{ c.reference }} · {{ c.product }}
          <VChip size="x-small" :color="statusColor(c.status)">{{ human(c.status) }}</VChip></VCardTitle>
        <VCardSubtitle>{{ c.crop ?? '' }}<template v-if="c.hectares"> · {{ num(c.hectares) }} ha</template></VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VRow dense>
          <VCol cols="6" sm="3"><div class="text-caption">{{ k('expected') }}</div><div>{{ num(c.expected_quantity) }} {{ c.unit }}</div></VCol>
          <VCol cols="6" sm="3"><div class="text-caption">{{ k('delivered') }}</div><div>{{ num(c.delivered) }} {{ c.unit }}</div></VCol>
          <VCol cols="6" sm="3"><div class="text-caption">{{ k('base_price') }}</div><div>{{ money(c.price) }} / {{ c.unit }}</div></VCol>
          <VCol cols="6" sm="3"><div class="text-caption">{{ k('advance_limit') }}</div><div>{{ money(c.advance_limit) }}</div></VCol>
        </VRow>
        <VTable v-if="c.grades.length" density="compact" class="mt-3">
          <thead><tr><th>{{ k('grade') }}</th><th class="text-end">{{ k('price_per', { unit: c.unit }) }}</th></tr></thead>
          <tbody><tr v-for="g in c.grades" :key="g.grade"><td>{{ g.grade }}</td><td class="text-end">{{ money(g.price) }}</td></tr></tbody>
        </VTable>
      </VCardText>
      <VCardActions><VBtn variant="text" :href="route('grower.portal.contracts.show', c.id)">{{ k('see_deliveries') }}</VBtn></VCardActions>
    </VCard>
    <VAlert v-if="!contracts.length" type="info" variant="tonal">{{ k('no_contracts') }}</VAlert>
  </GrowerShell>
</template>
