<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: number; code: string; name: string; make_or_buy: string | null; lead_time_days: number | null; safety_stock: number | null
  min_order_qty: number | null; order_multiple: number | null; preferred_supplier_id: number | null; warehouse_lead_time: number | null; warehouse_safety_stock: number | null }

const props = defineProps<{
  items: { data: Row[]; current_page: number; last_page: number }
  suppliers: Array<{ id: number; name: string }>
  filters: { search: string | null }
  can: { manage: boolean }
}>()

const search = ref(props.filters.search ?? '')
let timer: ReturnType<typeof setTimeout> | undefined
watch(search, v => { clearTimeout(timer); timer = setTimeout(() => router.get(route('manufacturing.planning.parameters'), { search: v || undefined }, { preserveState: true, replace: true }), 300) })

const editing = ref<Row | null>(null)
const form = ref<Record<string, any>>({})
function edit(r: Row) {
  editing.value = r
  form.value = { make_or_buy: r.make_or_buy, lead_time_days: r.lead_time_days, safety_stock: r.safety_stock, min_order_qty: r.min_order_qty,
    order_multiple: r.order_multiple, preferred_supplier_id: r.preferred_supplier_id }
}
const save = () => router.post(route('manufacturing.planning.parameters.save', editing.value!.id), form.value, { preserveScroll: true, onSuccess: () => { editing.value = null } })
const supplierName = (id: number | null) => props.suppliers.find(s => s.id === id)?.name ?? '—'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('manufacturing.plan.params_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.plan.params_subtitle') }}</p>
    </div>
    <VCard border>
      <VCardText><VTextField v-model="search" density="compact" hide-details clearable style="max-width: 320px" prepend-inner-icon="tabler-search" :label="t('manufacturing.products.search')" /></VCardText>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.orders.material') }}</th><th>{{ t('manufacturing.plan.make_or_buy') }}</th><th class="text-end">{{ t('manufacturing.plan.lead_days') }}</th>
          <th class="text-end">{{ t('manufacturing.plan.safety') }}</th><th class="text-end">{{ t('manufacturing.plan.moq') }}</th><th class="text-end">{{ t('manufacturing.plan.multiple') }}</th>
          <th>{{ t('manufacturing.plan.preferred_supplier') }}</th><th v-if="can.manage" /></tr></thead>
        <tbody>
          <tr v-for="r in items.data" :key="r.id">
            <td>{{ r.code }}<div class="text-caption text-medium-emphasis">{{ r.name }}</div></td>
            <td>{{ r.make_or_buy ? t(`manufacturing.plan.mob.${r.make_or_buy}`) : t('manufacturing.plan.auto') }}</td>
            <td class="text-end">{{ r.lead_time_days ?? (r.warehouse_lead_time !== null ? `${r.warehouse_lead_time}*` : '—') }}</td>
            <td class="text-end">{{ r.safety_stock ?? (r.warehouse_safety_stock !== null ? `${r.warehouse_safety_stock}*` : '—') }}</td>
            <td class="text-end">{{ r.min_order_qty ?? '—' }}</td><td class="text-end">{{ r.order_multiple ?? '—' }}</td>
            <td>{{ supplierName(r.preferred_supplier_id) }}</td>
            <td v-if="can.manage" class="text-end"><VBtn size="x-small" variant="text" @click="edit(r)">{{ t('manufacturing.common.edit') }}</VBtn></td>
          </tr>
        </tbody>
      </VTable>
      <VCardText class="text-caption text-medium-emphasis">{{ t('manufacturing.plan.params_legend') }}</VCardText>
      <VCardText v-if="items.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="items.current_page" :length="items.last_page" total-visible="7" @update:model-value="(p: number) => router.get(route('manufacturing.planning.parameters'), { search: search || undefined, page: p }, { preserveState: true })" />
      </VCardText>
    </VCard>

    <VDialog :model-value="editing !== null" max-width="520" @update:model-value="v => { if (!v) editing = null }">
      <VCard v-if="editing" :title="editing.code" :subtitle="editing.name">
        <VCardText>
          <VRow dense>
            <VCol cols="12"><VSelect v-model="form.make_or_buy" :items="[{ title: t('manufacturing.plan.auto'), value: null }, { title: t('manufacturing.plan.mob.MAKE'), value: 'MAKE' }, { title: t('manufacturing.plan.mob.BUY'), value: 'BUY' }]" :label="t('manufacturing.plan.make_or_buy')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.lead_time_days" type="number" min="0" :label="t('manufacturing.plan.lead_days')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.safety_stock" type="number" min="0" step="any" :label="t('manufacturing.plan.safety')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.min_order_qty" type="number" min="0" step="any" :label="t('manufacturing.plan.moq')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.order_multiple" type="number" min="0" step="any" :label="t('manufacturing.plan.multiple')" /></VCol>
            <VCol cols="12"><VAutocomplete v-model="form.preferred_supplier_id" :items="suppliers" item-value="id" item-title="name" clearable :label="t('manufacturing.plan.preferred_supplier')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="save">{{ t('manufacturing.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
