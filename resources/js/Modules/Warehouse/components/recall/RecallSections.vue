<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import type { RecallPayload } from './types'

const { t } = useI18n()
const route = ziggyRoute
defineProps<RecallPayload & { printing?: boolean }>()
const when = (s: string | null) => s ? new Date(s).toLocaleString() : '—'
</script>

<template>
  <div class="d-flex gap-3 mb-4 flex-wrap">
    <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.recalls.here') }}</div><div class="text-h5">{{ impact.totals.here }}</div></VCard>
    <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.recalls.held') }}</div><div class="text-h5">{{ impact.totals.held }}</div></VCard>
    <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.recalls.shipped') }}</div><div class="text-h5">{{ impact.totals.shipped }}</div></VCard>
    <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.recalls.customers') }}</div><div class="text-h5">{{ impact.customers.length }}</div></VCard>
  </div>

  <VCard v-if="impact.queue.length && !printing" border class="mb-4" :title="t('warehouse_management.recalls.queue')">
    <VList density="compact">
      <VListItem v-for="(q, i) in impact.queue" :key="i" :prepend-icon="({ HOLD: 'tabler-hand-stop', TRANSIT: 'tabler-truck', NOTIFY: 'tabler-phone', PRODUCTION: 'tabler-building-factory' } as Record<string, string>)[q.kind]">
        <VListItemTitle class="text-wrap">{{ q.text }}</VListItemTitle>
      </VListItem>
    </VList>
  </VCard>

  <VCard border class="mb-4" :title="t('warehouse_management.recalls.lots_title')">
    <div class="overflow-x-auto">
      <VTable density="compact">
        <thead><tr><th>{{ t('warehouse_management.recalls.lot') }}</th><th class="text-end">{{ t('warehouse_management.recalls.at_open') }}</th><th class="text-end">{{ t('warehouse_management.recalls.held') }}</th>
          <th class="text-end">{{ t('warehouse_management.recalls.released') }}</th><th class="text-end">{{ t('warehouse_management.recalls.disposed') }}</th><th class="text-end">{{ t('warehouse_management.recalls.retrieved') }}</th><th /></tr></thead>
        <tbody>
          <tr v-if="!lots.length"><td colspan="7" class="text-medium-emphasis">{{ t('warehouse_management.recalls.no_lots') }}</td></tr>
          <tr v-for="l in lots" :key="l.id">
            <td>{{ l.item }} {{ l.lot }}<span v-if="l.depth" class="text-caption"> · {{ t('warehouse_management.recalls.downstream', { n: l.depth }) }}</span></td>
            <td class="text-end">{{ l.in_stock_at_open }}</td><td class="text-end">{{ l.held }}</td><td class="text-end">{{ l.released }}</td>
            <td class="text-end">{{ l.disposed }}</td><td class="text-end">{{ l.retrieved }}</td>
            <td class="text-end text-no-wrap"><slot name="lot-actions" :lot="l" /></td>
          </tr>
        </tbody>
      </VTable>
    </div>
  </VCard>

  <VRow>
    <VCol cols="12" :md="printing ? 12 : 6">
      <VCard border class="mb-4" :title="t('warehouse_management.recalls.where')">
        <VCardText v-if="!impact.stock.length" class="text-medium-emphasis">{{ t('warehouse_management.recalls.nothing_here') }}</VCardText>
        <VTable v-else density="compact"><tbody>
          <tr v-for="(s, i) in impact.stock" :key="i">
            <td>{{ s.warehouse }} {{ s.bin ?? '' }}<VChip v-if="s.in_transit" size="x-small" color="info" class="ms-1">{{ t('warehouse_management.recalls.in_transit') }}</VChip></td>
            <td>{{ s.item }} {{ s.lot ?? '' }}</td><td class="text-end">{{ s.qty }} {{ s.uom }}</td>
            <td><VChip size="x-small" :color="s.held ? 'warning' : 'error'" variant="tonal">{{ t(`warehouse_management.stock_state.${s.status}`) }}</VChip></td>
          </tr>
        </tbody></VTable>
      </VCard>
      <VCard v-if="impact.production.length" border class="mb-4" :title="t('warehouse_management.recalls.production')">
        <VTable density="compact"><tbody><tr v-for="p in impact.production" :key="p.order"><td>{{ p.order }}</td><td>{{ p.status }}</td><td class="text-end">{{ p.qty }}</td></tr></tbody></VTable>
      </VCard>
    </VCol>
    <VCol cols="12" :md="printing ? 12 : 6">
      <VCard border class="mb-4" :title="t('warehouse_management.recalls.customers_title')">
        <VCardText v-if="!impact.customers.length" class="text-medium-emphasis">{{ t('warehouse_management.recalls.no_customers') }}</VCardText>
        <VTable v-else density="compact"><tbody>
          <tr v-for="c in impact.customers" :key="c.customer"><td>{{ c.customer }}<div class="text-caption">{{ c.shipments.join(', ') }}</div></td><td class="text-end">{{ c.qty }}</td>
            <td><VChip size="x-small" :color="c.notified ? 'success' : 'warning'" variant="tonal">{{ c.notified ? t('warehouse_management.recalls.notified') : t('warehouse_management.recalls.not_notified') }}</VChip></td></tr>
        </tbody></VTable>
      </VCard>
      <VCard border class="mb-4" :title="t('warehouse_management.recalls.log')">
        <VList density="compact">
          <VListItem v-for="(a, i) in actions" :key="i">
            <VListItemTitle class="text-wrap">{{ t(`warehouse_management.recall_action.${a.kind}`) }}<span v-if="a.qty !== null"> · {{ a.qty }}</span><span v-if="a.reference"> · {{ a.reference }}</span></VListItemTitle>
            <VListItemSubtitle class="text-wrap">{{ when(a.at) }}<span v-if="a.notes"> · {{ a.notes }}</span></VListItemSubtitle>
          </VListItem>
        </VList>
      </VCard>
      <VCard v-if="evidence.length" border :title="t('warehouse_management.recalls.evidence')">
        <VList density="compact"><VListItem v-for="e in evidence" :key="e.id" :href="printing ? undefined : route('warehouse.losses.evidence.download', e.id)">
          <VListItemTitle>{{ e.name }}</VListItemTitle><VListItemSubtitle>{{ e.by }} · {{ when(e.at) }}</VListItemSubtitle></VListItem></VList>
      </VCard>
    </VCol>
  </VRow>
</template>
