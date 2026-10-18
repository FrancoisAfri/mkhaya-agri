<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  warehouses: Array<{ id: string; title: string }>
  suppliers: Array<{ id: number; name: string }>
  units: Array<{ id: number; title: string }>
}>()

const form = useForm({
  warehouse: props.warehouses[0]?.id ?? null, supplier_id: null as number | null, reason: '', delivery_note_ref: '',
  lines: [] as Array<{ item: string; title: string; qty: number; uom_id: number | null; unit_cost: number | null; lot_number: string; expires_on: string }>,
})
const itemOptions = ref<Array<{ id: string; title: string }>>([])
const pick = ref<string | null>(null)
async function searchItems(q: string) {
  const { data } = await axios.get(route('warehouse.products.search'), { params: { q } })
  itemOptions.value = data
}
function addLine() {
  const o = itemOptions.value.find(x => x.id === pick.value)
  if (o) form.lines.push({ item: o.id, title: o.title, qty: 1, uom_id: null, unit_cost: null, lot_number: '', expires_on: '' })
  pick.value = null
}
function submit() {
  form.transform(d => ({ ...d, lines: d.lines.map(l => ({ ...l, expires_on: l.expires_on || null, lot_number: l.lot_number || null })) }))
    .post(route('warehouse.inbound.nonpo.store'))
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.nonpo.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.nonpo.subtitle') }}</p>
    </div>
    <RefusalAlert />
    <VCard border class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="4"><VSelect v-model="form.warehouse" :items="warehouses" item-title="title" item-value="id" :label="t('warehouse_management.common.warehouse')" /></VCol>
          <VCol cols="12" md="4"><VAutocomplete v-model="form.supplier_id" :items="suppliers" item-title="name" item-value="id" clearable :label="t('warehouse_management.products.supplier')" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="form.delivery_note_ref" :label="t('warehouse_management.receive.delivery_note')" /></VCol>
          <VCol cols="12"><VTextarea v-model="form.reason" rows="2" auto-grow :label="t('warehouse_management.nonpo.reason')" :error-messages="form.errors.reason" /></VCol>
        </VRow>
      </VCardText>
    </VCard>
    <VCard border>
      <VCardText>
        <div v-for="(l, i) in form.lines" :key="i" class="d-flex gap-2 flex-wrap align-center mb-2">
          <div style="min-width: 220px" class="flex-grow-1">{{ l.title }}</div>
          <VTextField v-model.number="l.qty" type="number" min="0" density="compact" hide-details style="max-width: 110px" :label="t('warehouse_management.locations.qty')" />
          <VSelect v-model="l.uom_id" :items="units" item-title="title" item-value="id" clearable density="compact" hide-details style="max-width: 160px" :label="t('warehouse_management.products.unit')" />
          <VTextField v-model.number="l.unit_cost" type="number" min="0" density="compact" hide-details style="max-width: 120px" :label="t('warehouse_management.nonpo.unit_cost')" />
          <VTextField v-model="l.lot_number" density="compact" hide-details style="max-width: 140px" :label="t('warehouse_management.receive.lot')" />
          <VTextField v-model="l.expires_on" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.receive.expires_on')" />
          <VBtn icon="tabler-x" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="form.lines.splice(i, 1)" />
        </div>
        <VAutocomplete v-model="pick" :items="itemOptions" item-title="title" item-value="id" density="compact" :label="t('warehouse_management.nonpo.add_item')"
                       @update:search="searchItems" @update:model-value="addLine" />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn color="primary" :loading="form.processing" :disabled="!form.lines.length" @click="submit">{{ t('warehouse_management.nonpo.submit') }}</VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
