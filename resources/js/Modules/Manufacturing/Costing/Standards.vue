<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { money, statusColor } from './money'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; version: number; status: string; product: string; costing_qty: number; unit_cost: number; total_cost: number
  material: number; labour: number; machine: number; overhead: number; subcontract: number; warnings_count: number; effective_from: string | null }

const props = defineProps<{
  standards: { data: Row[]; current_page: number; last_page: number }
  filters: { status?: string; search?: string }
  products: Array<{ id: string; code: string | null; name: string | null }>
  currency: string
  can: { create: boolean }
}>()

const search = ref(props.filters.search ?? '')
const status = ref<string | null>(props.filters.status ?? null)
let timer: ReturnType<typeof setTimeout> | undefined
const apply = (extra: Record<string, unknown> = {}) => router.get(route('manufacturing.costing.standards.index'),
  { search: search.value || undefined, status: status.value || undefined, ...extra }, { preserveState: true, replace: true })
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 300) })
watch(status, () => apply())

const dialog = ref(false)
const form = useForm({ item_uuid: null as string | null, costing_qty: 100 })
const save = () => form.post(route('manufacturing.costing.standards.calculate'), { onSuccess: () => { dialog.value = false } })

const headers = [
  { title: t('manufacturing.bom.product'), key: 'product' },
  { title: t('manufacturing.costing.version'), key: 'version' },
  { title: t('manufacturing.costing.lot_size'), key: 'costing_qty', align: 'end' as const },
  { title: t('manufacturing.costing.unit_cost'), key: 'unit_cost', align: 'end' as const },
  { title: t('manufacturing.costing.material'), key: 'material', align: 'end' as const },
  { title: t('manufacturing.costing.conversion'), key: 'labour', align: 'end' as const, sortable: false },
  { title: t('manufacturing.bom.status'), key: 'status' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.costing.standards_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.costing.standards_subtitle', { currency }) }}</p>
      </div>
      <VBtn v-if="can.create" color="primary" prepend-icon="tabler-calculator" @click="dialog = true">{{ t('manufacturing.costing.calculate') }}</VBtn>
    </div>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap">
        <VTextField v-model="search" density="compact" hide-details clearable style="max-width: 280px" prepend-inner-icon="tabler-search" :label="t('manufacturing.products.search')" />
        <VSelect v-model="status" density="compact" hide-details clearable style="max-width: 220px"
                 :items="['DRAFT', 'APPROVED', 'SUPERSEDED'].map(s => ({ title: t(`manufacturing.status.${s}`), value: s }))" :label="t('manufacturing.bom.status')" />
      </VCardText>
      <VDataTable :headers="headers" :items="standards.data" item-value="id" hover :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.costing.standards.show', item.id))">
        <template #item.version="{ item }">v{{ item.version }}<VChip v-if="item.warnings_count" size="x-small" color="warning" label class="ms-1">{{ item.warnings_count }}</VChip></template>
        <template #item.unit_cost="{ item }"><span class="font-weight-medium">{{ money(item.unit_cost) }}</span></template>
        <template #item.material="{ item }">{{ money(item.material) }}</template>
        <template #item.labour="{ item }">{{ money(item.labour + item.machine + item.overhead + item.subcontract) }}</template>
        <template #item.status="{ item }"><VChip size="small" label variant="tonal" :color="statusColor(item.status)">{{ t(`manufacturing.status.${item.status}`) }}</VChip></template>
        <template #no-data><div class="py-8 text-medium-emphasis">{{ t('manufacturing.costing.no_standards') }}</div></template>
      </VDataTable>
      <VCardText v-if="standards.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="standards.current_page" :length="standards.last_page" total-visible="7" @update:model-value="(p: number) => apply({ page: p })" />
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="520">
      <VCard :title="t('manufacturing.costing.calculate')" :subtitle="t('manufacturing.costing.calculate_hint')">
        <VCardText>
          <VAutocomplete v-model="form.item_uuid" :items="products" item-value="id" :item-title="(p: any) => `${p.code} — ${p.name}`"
                         :error-messages="form.errors.item_uuid" :label="t('manufacturing.bom.product')" class="mb-2" />
          <VTextField v-model.number="form.costing_qty" type="number" min="0" step="any" :error-messages="form.errors.costing_qty"
                      :label="t('manufacturing.costing.lot_size')" :hint="t('manufacturing.costing.lot_size_hint')" persistent-hint />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.item_uuid || !form.costing_qty" @click="save">{{ t('manufacturing.costing.calculate') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
