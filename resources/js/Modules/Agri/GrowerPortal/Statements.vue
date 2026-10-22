<script setup lang="ts">
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import GrowerShell, { type Brand, type Me } from './GrowerShell.vue'
import { human, money, statusColor } from '../Components/agri'

defineOptions({ layout: null })
defineProps<{
  brand: Brand; me: Me
  summary: { advanced: number; recovered: number; advances_outstanding: number; collected_value: number; to_pay: number }
  settlements: Array<{ id: string; reference: string; date: string; gross: number; advances_recovered: number; net: number; status: string }>
}>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})
</script>

<template>
  <GrowerShell :brand="brand" :me="me" :title="k('my_statements')">
    <VCard class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol cols="6" sm="3"><div class="text-caption">{{ k('delivered_value') }}</div><div class="text-h6">{{ money(summary.collected_value) }}</div></VCol>
          <VCol cols="6" sm="3"><div class="text-caption">{{ k('advanced') }}</div><div class="text-h6">{{ money(summary.advanced) }}</div></VCol>
          <VCol cols="6" sm="3"><div class="text-caption">{{ k('recovered') }}</div><div class="text-h6">{{ money(summary.recovered) }}</div></VCol>
          <VCol cols="6" sm="3"><div class="text-caption">{{ k('to_pay') }}</div><div class="text-h6">{{ money(summary.to_pay) }}</div></VCol>
        </VRow>
      </VCardText>
      <VCardActions><VBtn color="primary" variant="tonal" prepend-icon="tabler-file-download" :href="route('grower.portal.statements.pdf')" target="_blank">{{ k('download_statement') }}</VBtn></VCardActions>
    </VCard>
    <VCard :title="k('settlements')">
      <VTable density="compact">
        <thead><tr><th>{{ k('reference') }}</th><th class="text-end">{{ k('gross') }}</th><th class="text-end">{{ k('advances_recovered') }}</th><th class="text-end">{{ k('net') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="s in settlements" :key="s.id">
            <td class="text-no-wrap">{{ s.reference }}<div class="text-caption">{{ s.date }} · <VChip size="x-small" :color="statusColor(s.status)">{{ human(s.status) }}</VChip></div></td>
            <td class="text-end">{{ money(s.gross) }}</td><td class="text-end">{{ money(s.advances_recovered) }}</td><td class="text-end font-weight-medium">{{ money(s.net) }}</td>
            <td class="text-end"><VBtn size="small" variant="text" icon="tabler-file-download" :href="route('grower.portal.statements.pdf', s.id)" target="_blank" :aria-label="k('download_statement')" /></td>
          </tr>
          <tr v-if="!settlements.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ k('no_settlements') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </GrowerShell>
</template>
