<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'
import { orderColor } from './statusColor'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  waves: Array<{ id: string; number: string; strategy: string; status: string; zone: string | null; warehouse: string; tasks: number; open: number }>
  ready: Array<Record<string, any>>
  strategies: string[]
  view: string
  can: { pick: boolean }
}>()

const selected = ref<string[]>([])
const strategy = ref('BATCH')
function release() {
  router.post(route('warehouse.outbound.waves.store'), { orders: selected.value, strategy: strategy.value }, { preserveScroll: true, onSuccess: () => { selected.value = [] } })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.picking.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.picking.subtitle') }}</p>
    </div>
    <RefusalAlert />
    <VRow>
      <VCol cols="12" lg="6">
        <VCard border>
          <VCardTitle>{{ t('warehouse_management.picking.ready') }}</VCardTitle>
          <VCardText v-if="!ready.length" class="text-medium-emphasis">{{ t('warehouse_management.picking.none_ready') }}</VCardText>
          <VList v-else density="compact">
            <VListItem v-for="o in ready" :key="o.id">
              <template #prepend><VCheckbox v-model="selected" :value="o.id" density="compact" hide-details :aria-label="o.number" /></template>
              <VListItemTitle>{{ o.number }} · {{ o.customer }} <VChip size="x-small" :color="orderColor(o.status)" variant="tonal">{{ t(`warehouse_management.order_status.${o.status}`) }}</VChip></VListItemTitle>
              <VListItemSubtitle>{{ o.warehouse }} · {{ t('warehouse_management.transfers.priority') }} {{ o.priority }}<template v-if="o.sla_due_at"> · {{ t('warehouse_management.outbound.due') }} {{ new Date(o.sla_due_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) }}</template></VListItemSubtitle>
            </VListItem>
          </VList>
          <VCardActions v-if="can.pick && ready.length">
            <VSelect v-model="strategy" :items="strategies.map(s => ({ value: s, title: t(`warehouse_management.strategy.${s}`) }))" density="compact" hide-details style="max-width: 200px" />
            <VSpacer />
            <VBtn color="primary" :disabled="!selected.length" @click="release">{{ t('warehouse_management.picking.release', { n: selected.length }) }}</VBtn>
          </VCardActions>
          <VCardText class="text-caption text-medium-emphasis">{{ t(`warehouse_management.strategy_hint.${strategy}`) }}</VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" lg="6">
        <VCard border>
          <VCardTitle class="d-flex justify-space-between align-center">{{ t('warehouse_management.picking.lists') }}
            <VBtn size="small" variant="text" @click="router.get(route('warehouse.outbound.waves.index'), { view: view === 'done' ? 'open' : 'done' })">{{ view === 'done' ? t('warehouse_management.putaway.view.open') : t('warehouse_management.putaway.view.done') }}</VBtn></VCardTitle>
          <VCardText v-if="!waves.length" class="text-medium-emphasis">{{ t('warehouse_management.picking.no_lists') }}</VCardText>
          <VList v-else density="compact">
            <VListItem v-for="w in waves" :key="w.id" :href="route('warehouse.outbound.waves.show', w.id)"
                       :title="`${w.number} · ${t(`warehouse_management.strategy.${w.strategy}`)}${w.zone ? ' · ' + w.zone : ''}`"
                       :subtitle="t('warehouse_management.picking.progress', { done: w.tasks - w.open, total: w.tasks, warehouse: w.warehouse })">
              <template #append><VChip size="x-small" variant="tonal" :color="w.status === 'DONE' ? 'success' : 'primary'">{{ t(`warehouse_management.wave_status.${w.status}`) }}</VChip></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
