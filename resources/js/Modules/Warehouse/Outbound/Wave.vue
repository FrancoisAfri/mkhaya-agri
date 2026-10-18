<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Task = { id: string; sequence: number; bin: string | null; zone: string | null; item: string; name: string; uom: string; lot: string | null; expires_on: string | null; order: string; order_id: string; slot: number | null; qty: number; picked: number; short: number; status: string; scan_errors: number }

const props = defineProps<{
  wave: { id: string; number: string; strategy: string; status: string; zone: string | null; warehouse: string; scan_required: boolean }
  tasks: Task[]
  can: { pick: boolean }
}>()

// The picker sees one task at a time, in walking order; the rest of the list is underneath.
const current = computed(() => props.tasks.find(t => t.status === 'OPEN') ?? null)
const remaining = computed(() => current.value ? Math.round((current.value.qty - current.value.picked - current.value.short) * 10000) / 10000 : 0)
const form = useForm({ qty: 0, short: false, bin_scan: '', item_scan: '', lot_scan: '', note: '' })
const showShort = ref(false)
function confirm(short = false) {
  if (!current.value) return
  form.short = short
  if (!short) form.qty = form.qty || remaining.value
  form.post(route('warehouse.outbound.picks.confirm', current.value.id), { preserveScroll: true, onSuccess: () => { form.reset(); showShort.value = false } })
}
</script>

<template>
  <div class="pa-4" style="max-width: 820px">
    <div class="text-caption"><a :href="route('warehouse.outbound.waves.index')">{{ t('warehouse_management.picking.title') }}</a></div>
    <h4 class="text-h4 mb-1">{{ wave.number }} · {{ t(`warehouse_management.strategy.${wave.strategy}`) }}<template v-if="wave.zone"> · {{ wave.zone }}</template></h4>
    <p class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.picking.progress', { done: tasks.filter(x => x.status !== 'OPEN').length, total: tasks.length, warehouse: wave.warehouse }) }}</p>
    <RefusalAlert />

    <VCard v-if="current && can.pick" border color="primary" variant="tonal" class="mb-4">
      <VCardText>
        <div class="d-flex justify-space-between flex-wrap gap-2">
          <div><div class="text-caption">{{ t('warehouse_management.picking.go_to') }}</div><div class="text-h3 font-weight-bold">{{ current.bin ?? t('warehouse_management.stock.no_bin') }}</div></div>
          <div class="text-end"><div class="text-caption">{{ t('warehouse_management.picking.take') }}</div><div class="text-h3 font-weight-bold">{{ remaining }} <span class="text-h6">{{ current.uom }}</span></div></div>
        </div>
        <div class="text-h6 mt-2">{{ current.item }} — {{ current.name }}</div>
        <div v-if="current.lot" class="text-body-1">{{ t('warehouse_management.movements.lot', { lot: current.lot }) }}<template v-if="current.expires_on"> · {{ t('warehouse_management.stock.expires', { date: current.expires_on }) }}</template></div>
        <div class="text-body-2">{{ current.order }}<template v-if="current.slot"> · {{ t('warehouse_management.picking.slot', { n: current.slot }) }}</template></div>
      </VCardText>
      <VCardText class="bg-surface">
        <VRow dense>
          <VCol cols="12" md="4"><VTextField v-model="form.bin_scan" prepend-inner-icon="tabler-scan" autofocus :label="t('warehouse_management.picking.scan_bin') + (wave.scan_required ? ' *' : '')" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="form.item_scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.putaway.scan_item') + (wave.scan_required ? ' *' : '')" /></VCol>
          <VCol v-if="current.lot" cols="12" md="4"><VTextField v-model="form.lot_scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.picking.scan_lot')" /></VCol>
          <VCol cols="6" md="4"><VTextField v-model.number="form.qty" type="number" min="0" :max="remaining" :placeholder="String(remaining)" :label="t('warehouse_management.picking.picked_qty')" /></VCol>
          <VCol v-if="showShort" cols="12"><VTextField v-model="form.note" :label="t('warehouse_management.picking.short_note')" /></VCol>
        </VRow>
      </VCardText>
      <VCardActions class="bg-surface">
        <VBtn color="primary" size="large" :loading="form.processing" @click="confirm(false)">{{ t('warehouse_management.picking.confirm') }}</VBtn>
        <VSpacer />
        <VBtn v-if="!showShort" color="error" variant="text" @click="showShort = true">{{ t('warehouse_management.picking.short') }}</VBtn>
        <VBtn v-else color="error" variant="tonal" :loading="form.processing" @click="confirm(true)">{{ t('warehouse_management.picking.confirm_short') }}</VBtn>
      </VCardActions>
    </VCard>
    <VAlert v-else-if="!current" type="success" variant="tonal" class="mb-4">{{ t('warehouse_management.picking.all_done') }}</VAlert>

    <VCard border>
      <VTable density="compact">
        <thead><tr><th>#</th><th>{{ t('warehouse_management.stock.bin') }}</th><th>{{ t('warehouse_management.movements.item') }}</th><th>{{ t('warehouse_management.outbound.order') }}</th><th class="text-end">{{ t('warehouse_management.locations.qty') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="tk in tasks" :key="tk.id" :class="{ 'font-weight-bold': tk === current, 'opacity-60': tk.status !== 'OPEN' }">
            <td>{{ tk.sequence }}</td><td>{{ tk.bin }}<span v-if="tk.zone" class="text-caption"> · {{ tk.zone }}</span></td><td>{{ tk.item }}<span v-if="tk.lot" class="text-caption"> · {{ tk.lot }}</span></td>
            <td>{{ tk.order }}<span v-if="tk.slot" class="text-caption"> · {{ t('warehouse_management.picking.slot', { n: tk.slot }) }}</span></td>
            <td class="text-end">{{ tk.picked }}/{{ tk.qty }}<span v-if="tk.short" class="text-error"> −{{ tk.short }}</span></td>
            <td><VChip size="x-small" variant="tonal" :color="tk.status === 'SHORT' ? 'error' : tk.status === 'PICKED' ? 'success' : undefined">{{ t(`warehouse_management.pick_status.${tk.status}`) }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
