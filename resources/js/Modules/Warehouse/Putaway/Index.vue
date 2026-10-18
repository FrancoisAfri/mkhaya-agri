<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Task = { id: string; number: string; status: string; priority: number; warehouse: string; item: string; item_name: string; uom: string; lot: string | null; stock_status: string; qty: number; done_qty: number; from: string | null; suggested: string | null; to: string | null; source: string | null; exception_note: string | null; assignee: string | null }

const props = defineProps<{
  tasks: { data: Task[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: { view: string; warehouse: string | null; mine: boolean }
  counts: { open: number; exceptions: number }
  warehouses: Array<{ id: string; title: string }>
  can: { move: boolean }
}>()

function reload(patch: Record<string, unknown>) {
  router.get(route('warehouse.floor.putaway.index'), { ...props.filters, ...patch }, { preserveScroll: true })
}
const color = (s: string) => ({ OPEN: 'primary', IN_PROGRESS: 'info', PARTIAL: 'warning', FAILED: 'error', DONE: 'success', CANCELLED: 'secondary' } as Record<string, string>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.putaway.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.putaway.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2 mb-3 flex-wrap align-center">
      <VChip v-for="v in ['open', 'exceptions', 'done']" :key="v" :variant="filters.view === v ? 'flat' : 'tonal'" :color="v === 'exceptions' ? 'error' : 'primary'" @click="reload({ view: v })">
        {{ t(`warehouse_management.putaway.view.${v}`) }}<template v-if="v !== 'done'"> ({{ counts[v as 'open' | 'exceptions'] }})</template>
      </VChip>
      <VSelect :model-value="filters.warehouse" :items="warehouses" item-title="title" item-value="id" clearable density="compact" hide-details style="max-width: 260px"
               :label="t('warehouse_management.common.warehouse')" @update:model-value="v => reload({ warehouse: v })" />
      <VSwitch :model-value="filters.mine" density="compact" hide-details color="primary" :label="t('warehouse_management.putaway.mine')" @update:model-value="v => reload({ mine: v ? 1 : 0 })" />
    </div>
    <VCard border>
      <VCardText v-if="!tasks.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.putaway.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.putaway.task') }}</th><th>{{ t('warehouse_management.movements.item') }}</th><th class="text-end">{{ t('warehouse_management.locations.qty') }}</th>
            <th>{{ t('warehouse_management.putaway.from') }}</th><th>{{ t('warehouse_management.putaway.to') }}</th><th>{{ t('warehouse_management.locations.status') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="tk in tasks.data" :key="tk.id">
              <td class="text-no-wrap">{{ tk.number }}<div class="text-caption text-medium-emphasis">{{ tk.warehouse }} · {{ tk.source }}</div></td>
              <td>{{ tk.item }}<div class="text-caption text-medium-emphasis">{{ tk.item_name }}<template v-if="tk.lot"> · {{ tk.lot }}</template></div></td>
              <td class="text-end">{{ tk.done_qty ? `${tk.done_qty} / ` : '' }}{{ tk.qty }} {{ tk.uom }}</td>
              <td>{{ tk.from ?? t('warehouse_management.stock.no_bin') }}</td>
              <td>{{ tk.to ?? tk.suggested ?? '—' }}<div v-if="!tk.to && !tk.suggested" class="text-caption text-error">{{ t('warehouse_management.putaway.no_fit') }}</div></td>
              <td><VChip size="small" label variant="tonal" :color="color(tk.status)">{{ t(`warehouse_management.task_status.${tk.status}`) }}</VChip>
                <div v-if="tk.exception_note" class="text-caption text-error">{{ tk.exception_note }}</div></td>
              <td class="text-end"><VBtn size="small" :color="['OPEN', 'IN_PROGRESS', 'PARTIAL'].includes(tk.status) ? 'primary' : undefined" variant="tonal" :href="route('warehouse.floor.putaway.show', tk.id)">{{ t('warehouse_management.putaway.open') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardActions v-if="tasks.links.length > 3" class="justify-center flex-wrap">
        <VBtn v-for="(l, i) in tasks.links" :key="i" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
