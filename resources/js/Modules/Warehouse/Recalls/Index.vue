<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; number: string; title: string; severity: string; status: string; scope: string; item: string | null; lot: string | null; lots: number; opened_at: string; closed_at: string | null }
defineProps<{ recalls: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> }; filters: { status: string | null }; severities: string[]; can: { recall: boolean } }>()

const dialog = ref(false)
const form = useForm({ scope: 'LOT', item: null as string | null, lot_id: null as number | null, serial_from: '', serial_to: '', title: '', reason: '', severity: 'CLASS_II' })
const items = ref<Array<{ id: string; title: string }>>([])
const lots = ref<Array<{ id: number; title: string }>>([])
async function searchItems(q: string) { items.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data }
async function loadLots() { lots.value = form.item ? (await axios.get(route('warehouse.recalls.lots'), { params: { item: form.item } })).data : [] }
const when = (s: string | null) => s ? new Date(s).toLocaleDateString() : '—'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.recalls.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.recalls.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VSelect :model-value="filters.status" :items="['OPEN', 'CLOSED'].map(s => ({ value: s, title: t(`warehouse_management.recall_status.${s}`) }))" clearable density="compact" hide-details style="min-width: 160px"
                 :label="t('warehouse_management.locations.status')" @update:model-value="v => router.get(route('warehouse.recalls.index'), { status: v })" />
        <VBtn v-if="can.recall" color="error" prepend-icon="tabler-alert-triangle" @click="dialog = true">{{ t('warehouse_management.recalls.new') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VCard border>
      <VCardText v-if="!recalls.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.recalls.none') }}</VCardText>
      <VTable v-else density="comfortable">
        <thead><tr><th>{{ t('warehouse_management.recalls.number') }}</th><th>{{ t('warehouse_management.recalls.what') }}</th><th>{{ t('warehouse_management.recalls.class') }}</th>
          <th class="text-end">{{ t('warehouse_management.recalls.lots') }}</th><th>{{ t('warehouse_management.recalls.opened') }}</th><th>{{ t('warehouse_management.locations.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in recalls.data" :key="r.id" style="cursor: pointer" @click="router.get(route('warehouse.recalls.show', r.id))">
            <td class="font-weight-medium">{{ r.number }}</td>
            <td>{{ r.title }}<div class="text-caption">{{ t(`warehouse_management.recall_scope.${r.scope}`) }} · {{ r.item }}<span v-if="r.lot"> · {{ r.lot }}</span></div></td>
            <td>{{ t(`warehouse_management.recall_class.${r.severity}`) }}</td><td class="text-end">{{ r.lots }}</td><td>{{ when(r.opened_at) }}</td>
            <td><VChip size="small" :color="r.status === 'OPEN' ? 'error' : 'success'" variant="tonal">{{ t(`warehouse_management.recall_status.${r.status}`) }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard :title="t('warehouse_management.recalls.new')">
        <VCardText class="d-flex flex-column gap-3">
          <VBtnToggle v-model="form.scope" mandatory density="compact" variant="outlined">
            <VBtn value="LOT">{{ t('warehouse_management.recall_scope.LOT') }}</VBtn>
            <VBtn value="SERIALS">{{ t('warehouse_management.recall_scope.SERIALS') }}</VBtn>
            <VBtn value="ITEM">{{ t('warehouse_management.recall_scope.ITEM') }}</VBtn>
          </VBtnToggle>
          <p class="text-caption mb-0">{{ t(`warehouse_management.recall_scope_hint.${form.scope}`) }}</p>
          <VAutocomplete v-model="form.item" :items="items" item-value="id" :label="t('warehouse_management.counts.item')" :error-messages="form.errors.item" @update:search="searchItems" @update:model-value="loadLots" />
          <VAutocomplete v-if="form.scope === 'LOT'" v-model="form.lot_id" :items="lots" item-value="id" :label="t('warehouse_management.recalls.lot')" :error-messages="form.errors.lot_id" />
          <div v-if="form.scope === 'SERIALS'" class="d-flex gap-2">
            <VTextField v-model="form.serial_from" :label="t('warehouse_management.recalls.serial_from')" :error-messages="form.errors.serial_from" />
            <VTextField v-model="form.serial_to" :label="t('warehouse_management.recalls.serial_to')" />
          </div>
          <VTextField v-model="form.title" :label="t('warehouse_management.recalls.title_field')" :error-messages="form.errors.title" />
          <VTextarea v-model="form.reason" rows="2" :label="t('warehouse_management.common.reason')" :error-messages="form.errors.reason" />
          <VSelect v-model="form.severity" :items="severities.map(s => ({ value: s, title: t(`warehouse_management.recall_class.${s}`) }))" :label="t('warehouse_management.recalls.class')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="error" :loading="form.processing" @click="form.post(route('warehouse.recalls.store'))">{{ t('warehouse_management.recalls.open') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
