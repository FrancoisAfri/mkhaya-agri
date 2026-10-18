<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

export type Movement = {
  id: string; type: string; date: string; recorded_at: string | null; occurred_at: string | null
  item: string; item_name: string; item_id: string; warehouse: string | null; to_warehouse: string | null
  bin: string | null; to_bin: string | null; lot: string | null; status: string | null; to_status: string | null
  qty: number; uom: string; txn: string | null; value: number; running_balance: number
  reason_code: string | null; reference: string | null; source: { label: string; url: string | null } | null
  journal_id: number | null; created_by: string | null; approved_by: string | null; approval_reference: string | null
  reversal_of: string | null; reversed_by: string | null
}

defineProps<{
  movements: { data: Movement[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  showItem?: boolean
}>()

const fmt = (n: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(n)
const money = (n: number) => new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)
</script>

<template>
  <div>
    <div v-if="!movements.data.length" class="text-center text-medium-emphasis py-8">{{ t('warehouse_management.movements.none') }}</div>
    <div v-else style="overflow-x: auto">
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('warehouse_management.movements.date') }}</th>
            <th>{{ t('warehouse_management.movements.type') }}</th>
            <th v-if="showItem">{{ t('warehouse_management.movements.item') }}</th>
            <th>{{ t('warehouse_management.movements.where') }}</th>
            <th class="text-end">{{ t('warehouse_management.movements.qty') }}</th>
            <th class="text-end">{{ t('warehouse_management.movements.balance') }}</th>
            <th class="text-end">{{ t('warehouse_management.movements.value') }}</th>
            <th>{{ t('warehouse_management.movements.why') }}</th>
            <th>{{ t('warehouse_management.movements.trace') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in movements.data" :key="m.id">
            <td class="text-no-wrap">
              {{ m.date }}
              <div v-if="m.occurred_at" class="text-caption text-medium-emphasis">{{ t('warehouse_management.movements.occurred', { at: new Date(m.occurred_at).toLocaleString() }) }}</div>
              <div v-if="m.recorded_at" class="text-caption text-medium-emphasis">{{ t('warehouse_management.movements.recorded', { at: new Date(m.recorded_at).toLocaleString() }) }}</div>
            </td>
            <td><VChip size="small" label variant="tonal">{{ m.type }}</VChip></td>
            <td v-if="showItem"><a :href="route('warehouse.stock.show', m.item_id)">{{ m.item }}</a></td>
            <td class="text-caption">
              {{ m.warehouse }}<template v-if="m.bin"> / {{ m.bin }}</template>
              <template v-if="m.to_warehouse || m.to_bin"> → {{ m.to_warehouse ?? m.warehouse }}<template v-if="m.to_bin"> / {{ m.to_bin }}</template></template>
              <div v-if="m.lot">{{ t('warehouse_management.movements.lot', { lot: m.lot }) }}</div>
              <div v-if="m.status && m.status !== 'AVAILABLE' || m.to_status">{{ m.status }}<template v-if="m.to_status"> → {{ m.to_status }}</template></div>
            </td>
            <td class="text-end text-no-wrap" style="font-variant-numeric: tabular-nums" :class="m.qty < 0 ? 'text-error' : 'text-success'">
              {{ m.qty > 0 ? '+' : '' }}{{ fmt(m.qty) }} {{ m.uom }}
              <div v-if="m.txn" class="text-caption text-medium-emphasis">{{ t('warehouse_management.movements.as', { txn: m.txn }) }}</div>
            </td>
            <td class="text-end" style="font-variant-numeric: tabular-nums">{{ fmt(m.running_balance) }}</td>
            <td class="text-end" style="font-variant-numeric: tabular-nums">{{ money(m.value) }}</td>
            <td class="text-caption">
              <VChip v-if="m.reason_code" size="x-small" label class="me-1">{{ m.reason_code }}</VChip>{{ m.reference }}
              <div v-if="m.created_by">{{ t('warehouse_management.movements.by', { who: m.created_by }) }}</div>
              <div v-if="m.approved_by">{{ t('warehouse_management.movements.approved', { who: m.approved_by, ref: m.approval_reference ?? '' }) }}</div>
            </td>
            <td class="text-caption">
              <div v-if="m.source"><a v-if="m.source.url" :href="m.source.url">{{ m.source.label }}</a><span v-else>{{ m.source.label }}</span></div>
              <div v-if="m.journal_id"><a :href="route('finance.accounting.journals', { search: m.journal_id })">{{ t('warehouse_management.movements.journal', { id: m.journal_id }) }}</a></div>
              <div v-if="m.reversal_of" class="text-warning">{{ t('warehouse_management.movements.reverses') }}</div>
              <div v-if="m.reversed_by" class="text-warning">{{ t('warehouse_management.movements.reversed') }}</div>
            </td>
          </tr>
        </tbody>
      </VTable>
    </div>
    <div v-if="movements.links.length > 3" class="d-flex justify-center flex-wrap mt-2">
      <VBtn v-for="(l, i) in movements.links" :key="i" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url"
            @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
    </div>
  </div>
</template>
