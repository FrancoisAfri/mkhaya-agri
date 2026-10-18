<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Move = { item: string; from: string; to: string; qty: number; picks: number; metres_saved_per_month: number; why: string }
type P = { id: string; status: string; moves: Move[]; simulation: Record<string, any> | null; warehouse: string; by: string | null; mine: boolean; at: string; note: string | null }
const props = defineProps<{ proposals: P[]; warehouses: Array<{ id: string; title: string }>; review_only_up_to: number; can: { supervise: boolean; approve: boolean } }>()
const warehouse = ref(props.warehouses[0]?.id ?? null)
const act = (action: string, extra: Record<string, unknown> = {}) => router.post(route('warehouse.work.slotting.act'), { action, ...extra }, { preserveScroll: true })
const color = (s: string) => ({ DRAFT: 'secondary', SIMULATED: 'info', APPROVED: 'primary', APPLIED: 'success', REJECTED: 'error' } as Record<string, string>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.work.slotting_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.work.slotting_subtitle', { n: review_only_up_to }) }}</p>
      </div>
      <div v-if="can.supervise" class="d-flex gap-2">
        <VSelect v-model="warehouse" :items="warehouses" item-value="id" density="compact" hide-details style="min-width: 220px" />
        <VBtn color="primary" @click="act('propose', { warehouse })">{{ t('warehouse_management.work.propose') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VCardText v-if="!proposals.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.work.no_proposals') }}</VCardText>
    <VCard v-for="p in proposals" :key="p.id" border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ p.warehouse }} · {{ t('warehouse_management.work.moves', { n: p.moves.length }) }} <VChip size="small" :color="color(p.status)" class="ms-1">{{ t(`warehouse_management.slotting_status.${p.status}`) }}</VChip></VCardTitle>
        <VCardSubtitle>{{ p.by }} · {{ new Date(p.at).toLocaleString() }}<span v-if="p.note"> · {{ p.note }}</span></VCardSubtitle>
        <template #append>
          <div class="d-flex gap-1">
            <VBtn v-if="can.supervise && ['DRAFT', 'SIMULATED'].includes(p.status)" size="small" variant="tonal" @click="act('simulate', { proposal: p.id })">{{ t('warehouse_management.work.simulate') }}</VBtn>
            <template v-if="can.approve && !p.mine && ['DRAFT', 'SIMULATED'].includes(p.status)">
              <VBtn size="small" color="success" variant="tonal" @click="act('approve', { proposal: p.id })">{{ t('warehouse_management.adjustments.approve') }}</VBtn>
              <VBtn size="small" variant="text" @click="act('reject', { proposal: p.id })">{{ t('warehouse_management.adjustments.reject') }}</VBtn>
            </template>
            <VBtn v-if="can.supervise && p.status === 'APPROVED'" size="small" color="primary" @click="act('apply', { proposal: p.id })">{{ t('warehouse_management.work.apply') }}</VBtn>
          </div>
        </template>
      </VCardItem>
      <VCardText v-if="p.simulation" class="text-body-2">
        {{ t('warehouse_management.work.sim_result', { now: p.simulation.metres_per_order_now?.p50, then: p.simulation.metres_per_order_proposed?.p50, pct: p.simulation.saving_pct }) }}
        <div class="text-caption">{{ t('warehouse_management.work.sim_range', { lo: p.simulation.metres_per_order_proposed?.p10, hi: p.simulation.metres_per_order_proposed?.p90 }) }}</div>
        <div v-for="a in p.simulation.assumptions ?? []" :key="a" class="text-caption">· {{ a }}</div>
      </VCardText>
      <VTable density="compact"><tbody>
        <tr v-for="m in p.moves" :key="m.item + m.from"><td>{{ m.item }}</td><td>{{ m.from }} → {{ m.to }}</td><td class="text-end">{{ m.qty }}</td>
          <td class="text-caption">{{ m.why }}</td><td class="text-end text-caption">{{ t('warehouse_management.work.metres_month', { m: m.metres_saved_per_month }) }}</td></tr>
      </tbody></VTable>
    </VCard>
  </div>
</template>
