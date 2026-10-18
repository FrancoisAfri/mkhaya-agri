<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; code: string; name: string; uom: string; status: string; qty_on_hand: number; brand: string | null; barcode: string | null; barcodes: number; lot: boolean; serial: boolean }

const props = defineProps<{
  items: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number }
  filters: { q: string; status: string | null; duplicates: boolean }
  statuses: string[]
  duplicate_count: number
  units: Array<{ id: number; code: string; title: string }>
  can: { manage: boolean }
}>()

const q = ref(props.filters.q)
function reload(patch: Record<string, unknown>) {
  router.get(route('warehouse.products.index'), { ...props.filters, q: q.value, ...patch }, { preserveScroll: true, preserveState: true })
}

const selected = ref<string[]>([])
function printLabels() {
  router.get(route('warehouse.products.labels'), { items: selected.value })
}

const dialog = ref(false)
const form = useForm({ code: '', name: '', uom: 'ea', brand: '', description: '' })
function create() {
  form.post(route('warehouse.products.store'), { onSuccess: () => { dialog.value = false } })
}

const statusColor = (s: string) => ({ ACTIVE: 'success', DISCONTINUED: 'secondary', BLOCKED: 'error', RECALLED: 'error', QUARANTINED: 'warning', OBSOLETE: 'secondary' } as Record<string, string>)[s]
const fmt = (n: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(n)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.products.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.products.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-scan" :href="route('warehouse.products.scan')">{{ t('warehouse_management.cards.scan.title') }}</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-printer" :disabled="!selected.length" @click="printLabels">{{ t('warehouse_management.products.print_labels', { n: selected.length }) }}</VBtn>
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('warehouse_management.products.add') }}</VBtn>
      </div>
    </div>

    <RefusalAlert />

    <VAlert v-if="duplicate_count && !filters.duplicates" type="warning" variant="tonal" class="mb-3">
      {{ t('warehouse_management.products.duplicates_found', { n: duplicate_count }) }}
      <template #append><VBtn size="small" variant="text" @click="reload({ duplicates: 1 })">{{ t('warehouse_management.products.review') }}</VBtn></template>
    </VAlert>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VTextField v-model="q" prepend-inner-icon="tabler-search" density="compact" hide-details clearable style="max-width: 360px"
                    :placeholder="t('warehouse_management.products.search')" @keyup.enter="reload({ page: 1 })" @click:clear="q = ''; reload({ page: 1 })" />
        <VSelect :model-value="filters.status" :items="statuses.map(s => ({ value: s, title: t(`warehouse_management.item_status.${s}`) }))" clearable
                 density="compact" hide-details style="max-width: 200px" :label="t('warehouse_management.products.status')"
                 @update:model-value="v => reload({ status: v, page: 1 })" />
        <VChip v-if="filters.duplicates" closable color="warning" @click:close="reload({ duplicates: 0 })">{{ t('warehouse_management.cards.barcode_duplicates.title') }}</VChip>
      </VCardText>
      <VCardText v-if="!items.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.products.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th style="width: 40px" />
              <th>{{ t('warehouse_management.common.code') }}</th>
              <th>{{ t('warehouse_management.common.name') }}</th>
              <th>{{ t('warehouse_management.products.barcode') }}</th>
              <th>{{ t('warehouse_management.products.control') }}</th>
              <th>{{ t('warehouse_management.products.status') }}</th>
              <th class="text-end">{{ t('warehouse_management.products.on_hand') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in items.data" :key="i.id">
              <td><VCheckbox v-model="selected" :value="i.id" density="compact" hide-details :aria-label="t('warehouse_management.products.select', { code: i.code })" /></td>
              <td><a :href="route('warehouse.products.show', i.id)" class="font-weight-medium">{{ i.code }}</a></td>
              <td>{{ i.name }}<div v-if="i.brand" class="text-caption text-medium-emphasis">{{ i.brand }}</div></td>
              <td class="text-no-wrap" style="font-family: ui-monospace, monospace">
                {{ i.barcode ?? '—' }}<span v-if="i.barcodes > 1" class="text-caption text-medium-emphasis"> +{{ i.barcodes - 1 }}</span>
              </td>
              <td>
                <VChip v-if="i.lot" size="x-small" label variant="tonal" class="me-1">{{ t('warehouse_management.products.lot') }}</VChip>
                <VChip v-if="i.serial" size="x-small" label variant="tonal">{{ t('warehouse_management.products.serial') }}</VChip>
                <span v-if="!i.lot && !i.serial" class="text-caption text-medium-emphasis">{{ t('warehouse_management.products.quantity_only') }}</span>
              </td>
              <td><VChip size="small" label variant="tonal" :color="statusColor(i.status)">{{ t(`warehouse_management.item_status.${i.status}`) }}</VChip></td>
              <td class="text-end" style="font-variant-numeric: tabular-nums">{{ fmt(i.qty_on_hand) }} {{ i.uom }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardActions v-if="items.links.length > 3" class="justify-center flex-wrap">
        <VBtn v-for="(l, i) in items.links" :key="i" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url"
              @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
      </VCardActions>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.products.add') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.products.add_hint') }}</p>
          <VRow dense>
            <VCol cols="12" md="5"><VTextField v-model="form.code" :label="t('warehouse_management.products.sku')" :error-messages="form.errors.code" /></VCol>
            <VCol cols="12" md="7"><VTextField v-model="form.name" :label="t('warehouse_management.common.name')" :error-messages="form.errors.name" /></VCol>
            <VCol cols="12" md="5">
              <VSelect v-model="form.uom" :items="units" item-title="title" item-value="code" :label="t('warehouse_management.products.base_unit')" :error-messages="form.errors.uom" />
            </VCol>
            <VCol cols="12" md="7"><VTextField v-model="form.brand" :label="t('warehouse_management.products.brand')" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" rows="2" auto-grow :label="t('warehouse_management.products.description')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="create">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
