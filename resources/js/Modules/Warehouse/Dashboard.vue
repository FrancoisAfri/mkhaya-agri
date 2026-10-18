<script setup lang="ts">
import { computed } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Warehouse = {
  id: string; code: string; name: string; type: string; is_wms_managed: boolean; is_default: boolean
  bins: number; qty: number; unbinned: number; held: number; items: number
}

const props = defineProps<{
  warehouses: Warehouse[]
  attention: { blocked: number; restricted: number; warehouses_without_bins: number }
  queues: Array<{ key: string; route: string; params: Record<string, unknown>; tone: string; n: number }>
  recent_changes: Array<{ action: string; warehouse: string | null; location: string | null; reason: string | null; at: string | null }>
  today: { available: number; expected_today: number; dispatched_today: number; delivered_today: number; counts: { open: number; lines: number; counted: number } }
  critical: Array<{ key: string; route: string; n: number }>
}>()

const totals = computed(() => ({
  warehouses: props.warehouses.length,
  bins: props.warehouses.reduce((a, w) => a + w.bins, 0),
  managed: props.warehouses.filter(w => w.is_wms_managed).length,
  unbinned: props.warehouses.reduce((a, w) => a + w.unbinned, 0),
}))

const fmt = (n: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(n)
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.dashboard.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.dashboard.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-robot" :href="route('warehouse.assistant.index')">{{ t('warehouse_management.cards.assistant.title') }}</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-building-warehouse" :href="route('warehouse.layout.warehouses.index')">
          {{ t('warehouse_management.cards.warehouses.title') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-layout-grid" :href="route('warehouse.layout.locations.index')">
          {{ t('warehouse_management.cards.locations.title') }}
        </VBtn>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol v-for="k in (['warehouses', 'bins', 'managed', 'unbinned'] as const)" :key="k" cols="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t(`warehouse_management.dashboard.kpi.${k}`) }}</div>
            <div class="text-h5 font-weight-medium" style="font-variant-numeric: tabular-nums">{{ fmt(totals[k]) }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <div class="mb-4" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px">
      <VCard v-for="qu in queues" :key="qu.key" border :href="route(qu.route, qu.params)" :color="qu.n ? qu.tone : undefined" :variant="qu.n ? 'tonal' : 'outlined'">
        <VCardText>
          <div class="text-caption">{{ t(`warehouse_management.dashboard.queue.${qu.key}`) }}</div>
          <div class="text-h5" style="font-variant-numeric: tabular-nums">{{ qu.n }}</div>
        </VCardText>
      </VCard>
    </div>

    <VAlert v-for="c in critical.filter(c => c.n > 0)" :key="c.key" type="error" variant="tonal" density="compact" class="mb-2">
      <a :href="route(c.route)" class="text-error">{{ t(`warehouse_management.dashboard.critical.${c.key}`, { n: c.n }) }}</a>
    </VAlert>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('warehouse_management.dashboard.today.available') }}</div>
        <div class="text-h5" style="font-variant-numeric: tabular-nums">{{ fmt(today.available) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('warehouse_management.dashboard.today.inbound') }}</div>
        <div class="text-h5" style="font-variant-numeric: tabular-nums">{{ today.expected_today }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('warehouse_management.dashboard.today.dispatches') }}</div>
        <div class="text-h5" style="font-variant-numeric: tabular-nums">{{ today.dispatched_today }} <span class="text-body-2">{{ t('warehouse_management.dashboard.today.delivered', { n: today.delivered_today }) }}</span></div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border :href="route('warehouse.counts.index')"><VCardText><div class="text-caption text-medium-emphasis">{{ t('warehouse_management.dashboard.today.counts', { n: today.counts.open }) }}</div>
        <div class="text-h5" style="font-variant-numeric: tabular-nums">{{ today.counts.lines ? Math.round(today.counts.counted / today.counts.lines * 100) : 0 }}%</div>
        <VProgressLinear :model-value="today.counts.lines ? today.counts.counted / today.counts.lines * 100 : 0" color="primary" rounded /></VCardText></VCard></VCol>
    </VRow>

    <VAlert v-if="attention.warehouses_without_bins" type="warning" variant="tonal" class="mb-3">
      {{ t('warehouse_management.dashboard.no_bins', { n: attention.warehouses_without_bins }) }}
    </VAlert>
    <VAlert v-if="totals.unbinned > 0" type="info" variant="tonal" class="mb-3">
      {{ t('warehouse_management.dashboard.unbinned', { qty: fmt(totals.unbinned) }) }}
    </VAlert>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard border>
          <VCardTitle>{{ t('warehouse_management.dashboard.by_warehouse') }}</VCardTitle>
          <VCardText v-if="!warehouses.length" class="text-medium-emphasis">
            {{ t('warehouse_management.dashboard.none') }}
          </VCardText>
          <VTable v-else density="comfortable">
            <thead>
              <tr>
                <th>{{ t('warehouse_management.common.warehouse') }}</th>
                <th>{{ t('warehouse_management.common.type') }}</th>
                <th class="text-end">{{ t('warehouse_management.dashboard.bins') }}</th>
                <th class="text-end">{{ t('warehouse_management.dashboard.items') }}</th>
                <th class="text-end">{{ t('warehouse_management.dashboard.on_hand') }}</th>
                <th class="text-end">{{ t('warehouse_management.dashboard.held') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="w in warehouses" :key="w.id">
                <td>
                  <a :href="route('warehouse.layout.locations.index', { warehouse: w.id })">{{ w.code }}</a>
                  — {{ w.name }}
                  <VChip v-if="w.is_wms_managed" size="x-small" color="primary" variant="tonal" class="ms-1">{{ t('warehouse_management.common.managed') }}</VChip>
                  <VChip v-if="w.is_default" size="x-small" variant="tonal" class="ms-1">{{ t('warehouse_management.common.default') }}</VChip>
                </td>
                <td>{{ t(`warehouse_management.type.${w.type}`) }}</td>
                <td class="text-end">{{ w.bins }}</td>
                <td class="text-end">{{ w.items }}</td>
                <td class="text-end" style="font-variant-numeric: tabular-nums">{{ fmt(w.qty) }}</td>
                <td class="text-end" :class="{ 'text-warning': w.held > 0 }" style="font-variant-numeric: tabular-nums">{{ fmt(w.held) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="4">
        <VCard border class="mb-4">
          <VCardTitle>{{ t('warehouse_management.dashboard.attention') }}</VCardTitle>
          <VList density="compact">
            <VListItem prepend-icon="tabler-ban" :title="t('warehouse_management.dashboard.blocked_bins')">
              <template #append><VChip size="small" :color="attention.blocked ? 'error' : undefined" variant="tonal">{{ attention.blocked }}</VChip></template>
            </VListItem>
            <VListItem prepend-icon="tabler-clock-pause" :title="t('warehouse_management.dashboard.restricted_bins')">
              <template #append><VChip size="small" :color="attention.restricted ? 'warning' : undefined" variant="tonal">{{ attention.restricted }}</VChip></template>
            </VListItem>
          </VList>
        </VCard>
        <VCard border>
          <VCardTitle class="d-flex justify-space-between align-center">
            {{ t('warehouse_management.dashboard.recent') }}
            <VBtn size="small" variant="text" :href="route('warehouse.layout.history')">{{ t('warehouse_management.common.all') }}</VBtn>
          </VCardTitle>
          <VCardText v-if="!recent_changes.length" class="text-medium-emphasis">{{ t('warehouse_management.history.none') }}</VCardText>
          <VList v-else density="compact">
            <VListItem v-for="(c, i) in recent_changes" :key="i"
                       :title="`${t(`warehouse_management.action.${c.action}`)} · ${c.location ?? c.warehouse}`"
                       :subtitle="[c.reason, when(c.at)].filter(Boolean).join(' · ')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
