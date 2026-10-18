<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Line = { item: string; code: string; name: string; uom: string; lot_id: number | null; lot: string | null; status: string; qty: number }

const props = defineProps<{
  warehouses: Array<{ id: string; title: string }>
  warehouse: string | null
  from: { id: string; code: string } | null
  contents: Line[]
  bins: Array<{ id: string; title: string }>
  scan_required: boolean
  can: { move: boolean }
}>()

const binScan = ref('')
function openBin() {
  router.get(route('warehouse.floor.move'), { warehouse: props.warehouse, bin: binScan.value.trim() })
}
const chosen = ref<Line | null>(null)
const form = useForm({ warehouse: props.warehouse, from: props.from?.id ?? null, to: null as string | null, item: '', lot_id: null as number | null,
  status: 'AVAILABLE', qty: 0, item_scan: '', to_scan: '', reason: '' })
function choose(l: Line) {
  chosen.value = l
  Object.assign(form, { item: l.item, lot_id: l.lot_id, status: l.status, qty: l.qty })
}
function move() {
  form.post(route('warehouse.floor.move.store'), { preserveScroll: true, onSuccess: () => { chosen.value = null; form.reset('to', 'item_scan', 'to_scan', 'reason') } })
}
</script>

<template>
  <div class="pa-4" style="max-width: 860px">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.binmove.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.binmove.subtitle') }}</p>
    </div>
    <RefusalAlert />
    <VCard border class="mb-4">
      <VCardText class="d-flex gap-2 flex-wrap">
        <VSelect :model-value="warehouse" :items="warehouses" item-title="title" item-value="id" density="compact" hide-details style="min-width: 240px"
                 @update:model-value="v => router.get(route('warehouse.floor.move'), { warehouse: v })" />
        <VTextField v-model="binScan" prepend-inner-icon="tabler-scan" density="compact" hide-details style="min-width: 240px" :label="t('warehouse_management.binmove.scan_from')" @keyup.enter="openBin" />
        <VBtn color="primary" @click="openBin">{{ t('warehouse_management.binmove.open') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard v-if="from" border class="mb-4">
      <VCardTitle>{{ t('warehouse_management.binmove.in_bin', { bin: from.code }) }}</VCardTitle>
      <VCardText v-if="!contents.length" class="text-medium-emphasis">{{ t('warehouse_management.stock.nothing_held') }}</VCardText>
      <VList v-else density="compact">
        <VListItem v-for="(l, i) in contents" :key="i" :active="chosen === l" @click="choose(l)"
                   :title="`${l.code} — ${l.name}`" :subtitle="`${l.qty} ${l.uom}${l.lot ? ' · ' + l.lot : ''} · ${t(`warehouse_management.stock_state.${l.status}`)}`" />
      </VList>
    </VCard>

    <VCard v-if="chosen && can.move" border>
      <VCardTitle>{{ t('warehouse_management.binmove.move_item', { code: chosen.code }) }}</VCardTitle>
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="4"><VTextField v-model.number="form.qty" type="number" min="0" :max="chosen.qty" :label="t('warehouse_management.locations.qty')" /></VCol>
          <VCol cols="12" md="8"><VAutocomplete v-model="form.to" :items="bins.filter(b => b.id !== from?.id)" item-title="title" item-value="id" :label="t('warehouse_management.putaway.destination')" /></VCol>
          <VCol cols="12" md="6"><VTextField v-model="form.item_scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.putaway.scan_item') + (scan_required ? ' *' : '')" /></VCol>
          <VCol cols="12" md="6"><VTextField v-model="form.to_scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.putaway.scan_bin') + (scan_required ? ' *' : '')" /></VCol>
          <VCol cols="12"><VTextField v-model="form.reason" :label="t('warehouse_management.binmove.reason')" /></VCol>
        </VRow>
      </VCardText>
      <VCardActions><VSpacer /><VBtn color="primary" size="large" :disabled="!form.to" :loading="form.processing" @click="move">{{ t('warehouse_management.binmove.confirm') }}</VBtn></VCardActions>
    </VCard>
  </div>
</template>
