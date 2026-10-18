<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { money, statusColor } from './money'

const { t } = useI18n()
const route = ziggyRoute

type Line = { id: number; category: string; reference: string; quantity: number; uom: string | null; rate: number; amount: number; basis: string }

const props = defineProps<{
  standard: {
    id: string; version: number; status: string; product: string; costing_qty: number; unit_cost: number; total_cost: number
    material: number; labour: number; machine: number; overhead: number; subcontract: number; effective_from: string | null
    bom: { id: string; revision: number } | null; routing: { id: string; revision: number } | null
    expected_output: number; labour_minutes: number; machine_minutes: number; overhead_percent: number | null
    warnings: string[]; approved_at: string | null; lines: Line[]
  }
  currency: string
  can: { approve: boolean; recalculate: boolean }
}>()

const groups = computed(() => ['MATERIAL', 'LABOUR', 'MACHINE', 'OVERHEAD', 'SUBCONTRACT']
  .map(c => ({ category: c, lines: props.standard.lines.filter(l => l.category === c) }))
  .filter(g => g.lines.length))
const perUnit = (n: number) => props.standard.expected_output > 0 ? n / props.standard.expected_output : 0
const approve = () => router.post(route('manufacturing.costing.standards.approve', props.standard.id), {}, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-start justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <VBtn variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-1 px-0" :href="route('manufacturing.costing.standards.index')">{{ t('manufacturing.costing.standards_title') }}</VBtn>
        <h4 class="text-h4">{{ standard.product }} · v{{ standard.version }}</h4>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <VChip size="small" label :color="statusColor(standard.status)">{{ t(`manufacturing.status.${standard.status}`) }}</VChip>
          <VChip v-if="standard.bom" size="small" label variant="outlined" :href="route('manufacturing.engineering.boms.show', standard.bom.id)">BOM rev {{ standard.bom.revision }}</VChip>
          <VChip v-if="standard.routing" size="small" label variant="outlined" :href="route('manufacturing.engineering.routings.show', standard.routing.id)">{{ t('manufacturing.routing.revision_n', { n: standard.routing.revision }) }}</VChip>
        </div>
      </div>
      <VBtn v-if="can.approve" color="success" prepend-icon="tabler-check" @click="approve">{{ t('manufacturing.common.approve') }}</VBtn>
    </div>

    <VAlert v-for="(w, i) in standard.warnings" :key="i" type="warning" variant="tonal" density="compact" class="mb-2">{{ w }}</VAlert>
    <VAlert v-if="standard.status === 'APPROVED'" type="info" variant="tonal" class="mb-4">{{ t('manufacturing.costing.approved_locked') }}</VAlert>

    <VRow class="mb-1">
      <VCol cols="6" md="2"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.costing.unit_cost') }}</div><div class="text-h5">{{ money(standard.unit_cost) }}</div><div class="text-caption">{{ currency }}</div></VCardText></VCard></VCol>
      <VCol v-for="k in ['material', 'labour', 'machine', 'overhead', 'subcontract']" :key="k" cols="6" md="2">
        <VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t(`manufacturing.costing.${k}`) }}</div><div class="text-h6">{{ money(perUnit((standard as any)[k])) }}</div><div class="text-caption">{{ money((standard as any)[k]) }} {{ t('manufacturing.costing.per_lot') }}</div></VCardText></VCard>
      </VCol>
    </VRow>
    <p class="text-body-2 text-medium-emphasis">{{ t('manufacturing.costing.output_line', { qty: standard.costing_qty, out: standard.expected_output, labour: (standard.labour_minutes / 60).toFixed(2), machine: (standard.machine_minutes / 60).toFixed(2) }) }}</p>

    <VCard v-for="g in groups" :key="g.category" border class="mb-4">
      <VCardItem><VCardTitle>{{ t(`manufacturing.costing.category.${g.category}`) }}</VCardTitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.costing.what') }}</th><th class="text-end">{{ t('manufacturing.orders.quantity') }}</th><th class="text-end">{{ t('manufacturing.costing.rate') }}</th><th class="text-end">{{ t('manufacturing.costing.amount') }}</th><th>{{ t('manufacturing.costing.basis') }}</th></tr></thead>
        <tbody>
          <tr v-for="l in g.lines" :key="l.id">
            <td>{{ l.reference }}</td><td class="text-end">{{ l.quantity }} {{ l.uom ?? '' }}</td>
            <td class="text-end">{{ g.category === 'OVERHEAD' ? `${(l.rate * 100).toFixed(2)}%` : money(l.rate) }}</td>
            <td class="text-end">{{ money(l.amount) }}</td><td class="text-caption text-medium-emphasis">{{ l.basis }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
