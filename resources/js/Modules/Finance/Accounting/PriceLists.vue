<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Item { id: number; code: string; name: string; default: number }
interface Product { description: string; grade: string | null; unit: string | null; price: number | null }
interface PriceList { id: number; name: string; is_active: boolean; prices: Record<number, number>; count: number; party_id: number | null; currency: string | null; valid_from: string | null; valid_to: string | null; products: Product[] }
const props = defineProps<{ priceLists: PriceList[]; items: Item[]; customers: { value: number; title: string }[] }>()
const customerName = (id: number | null) => props.customers.find(c => c.value === id)?.title ?? null

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// New list
const newForm = useForm({ name: '' })
function createList() { newForm.post(route('finance.accounting.price-lists.store'), { preserveScroll: true, onSuccess: () => newForm.reset() }) }

// Edit prices
const dialog = ref(false)
const editing = ref<PriceList | null>(null)
const rows = ref<Record<number, number | null>>({})
const editForm = useForm<any>({ name: '', is_active: true, prices: [], products: [], party_id: null, currency: null, valid_from: null, valid_to: null })
function openEdit(pl: PriceList) {
  editing.value = pl
  editForm.name = pl.name; editForm.is_active = pl.is_active
  Object.assign(editForm, { party_id: pl.party_id, currency: pl.currency, valid_from: pl.valid_from, valid_to: pl.valid_to, products: pl.products.map(p => ({ ...p })) })
  rows.value = {}
  props.items.forEach(i => { rows.value[i.id] = pl.prices[i.id] ?? null })
  dialog.value = true
}
function save() {
  editForm.prices = props.items
    .filter(i => rows.value[i.id] !== null && rows.value[i.id] !== undefined && String(rows.value[i.id]) !== '')
    .map(i => ({ item_id: i.id, price: Number(rows.value[i.id]) }))
  editForm.products = editForm.products.filter((p: Product) => p.description && p.price !== null && String(p.price) !== '')
  editForm.patch(route('finance.accounting.price-lists.update', editing.value!.id), { preserveScroll: true, onSuccess: () => { dialog.value = false } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.price_lists') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.price_lists_sub') }}</div>
      </div>
      <div class="d-flex gap-2 align-center">
        <VTextField v-model="newForm.name" :label="t('acc.new_price_list_name')" density="compact" hide-details style="max-width: 240px" :error-messages="newForm.errors.name" @keyup.enter="createList" />
        <VBtn color="primary" prepend-icon="tabler-plus" :loading="newForm.processing" :disabled="!newForm.name" @click="createList">{{ t('acc.create') }}</VBtn>
      </div>
    </div>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ t('acc.name') }}</th><th class="text-right">{{ t('acc.items_priced') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="pl in priceLists" :key="pl.id">
            <td class="font-weight-medium">{{ pl.name }}<div class="text-caption text-medium-emphasis">{{ [customerName(pl.party_id) ?? t('acc.general_list'), pl.valid_from ? `${pl.valid_from}${pl.valid_to ? ' – ' + pl.valid_to : ''}` : null, pl.currency].filter(Boolean).join(' · ') }}</div></td>
            <td class="text-right">{{ pl.count }}</td>
            <td><VChip size="x-small" :color="pl.is_active ? 'success' : 'default'" variant="tonal">{{ pl.is_active ? t('acc.active') : t('acc.inactive') }}</VChip></td>
            <td class="text-right"><VBtn size="small" variant="tonal" prepend-icon="tabler-pencil" @click="openEdit(pl)">{{ t('acc.prices') }}</VBtn></td>
          </tr>
          <tr v-if="!priceLists.length"><td colspan="4" class="text-center text-medium-emphasis py-6">{{ t('acc.no_price_lists') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="720" scrollable>
      <VCard>
        <VCardTitle>{{ editing?.name }} — {{ t('acc.prices') }}</VCardTitle>
        <VCardText style="max-height: 60vh">
          <div class="d-flex gap-3 mb-3 align-center">
            <VTextField v-model="editForm.name" :label="t('acc.name')" variant="outlined" density="comfortable" style="max-width: 280px" />
            <VSwitch v-model="editForm.is_active" :label="t('acc.active')" color="success" hide-details />
          </div>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VAutocomplete v-model="editForm.party_id" :items="customers" :label="t('acc.for_customer')" :placeholder="t('acc.general_list')" persistent-placeholder clearable variant="outlined" density="comfortable" style="min-width: 220px" />
            <VTextField v-model="editForm.valid_from" type="date" :label="t('acc.valid_from')" variant="outlined" density="comfortable" style="max-width: 170px" />
            <VTextField v-model="editForm.valid_to" type="date" :label="t('acc.valid_to')" variant="outlined" density="comfortable" style="max-width: 170px" />
            <VTextField v-model="editForm.currency" :label="t('acc.currency')" maxlength="3" variant="outlined" density="comfortable" style="max-width: 110px" />
          </div>
          <p class="text-caption text-medium-emphasis mb-2">{{ t('acc.blank_default_hint') }}</p>
          <VTable density="compact">
            <thead><tr><th>{{ t('acc.item') }}</th><th class="text-right">{{ t('acc.default_label') }}</th><th class="text-right" style="width: 160px">{{ t('acc.list_price') }}</th></tr></thead>
            <tbody>
              <tr v-for="i in items" :key="i.id">
                <td>{{ i.code }} · {{ i.name }}</td>
                <td class="text-right text-medium-emphasis">{{ money(i.default) }}</td>
                <td class="pa-1"><VTextField v-model.number="rows[i.id]" type="number" density="compact" variant="outlined" hide-details reverse :placeholder="String(i.default)" clearable /></td>
              </tr>
              <tr v-if="!items.length"><td colspan="3" class="text-center text-medium-emphasis py-4">{{ t('acc.add_items_first') }}</td></tr>
            </tbody>
          </VTable>
          <div class="text-subtitle-2 mt-4 mb-1">{{ t('acc.products_by_name') }}</div>
          <VRow v-for="(p, k) in editForm.products" :key="k" dense class="align-center">
            <VCol cols="5"><VTextField v-model="p.description" :label="t('acc.product')" density="compact" variant="outlined" hide-details /></VCol>
            <VCol cols="2"><VTextField v-model="p.grade" :label="t('acc.grade')" density="compact" variant="outlined" hide-details /></VCol>
            <VCol cols="2"><VTextField v-model="p.unit" :label="t('acc.unit')" density="compact" variant="outlined" hide-details /></VCol>
            <VCol cols="2"><VTextField v-model.number="p.price" type="number" :label="t('acc.list_price')" density="compact" variant="outlined" hide-details reverse /></VCol>
            <VCol cols="1"><VBtn icon="tabler-x" size="x-small" variant="text" @click="editForm.products.splice(k, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="editForm.products.push({ description: '', grade: null, unit: null, price: null })">{{ t('acc.add_product') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="editForm.processing" @click="save">{{ t('acc.save_prices') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
