<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Option { id: number; label: string }
interface Item { id: number; code: string; name: string; description: string | null; image: string | null; unit: string; type: string; sales_price: number; cost_price: number; tax_rate: number; sales_account_id: number | null; purchase_account_id: number | null; sales_account: string | null; purchase_account: string | null; is_active: boolean }
const props = defineProps<{ items: Item[]; incomeAccounts: Option[]; expenseAccounts: Option[] }>()

const search = ref('')
const dialog = ref(false)
const editing = ref<Item | null>(null)
const imageFile = ref<File | File[] | null>(null)
const form = useForm({ code: '', name: '', description: '', image: null as string | null, unit: 'each', type: 'service', sales_price: 0, cost_price: 0, sales_account_id: null as number | null, purchase_account_id: null as number | null, tax_rate: 15, is_active: true })

function onImage(file: File | File[] | null) {
  const f = Array.isArray(file) ? file[0] : file
  if (!f) { form.image = null; return }
  const reader = new FileReader()
  reader.onload = () => { form.image = reader.result as string }
  reader.readAsDataURL(f)
}

const filtered = computed(() => {
  if (!search.value) return props.items
  const q = search.value.toLowerCase()
  return props.items.filter(i => i.code.toLowerCase().includes(q) || i.name.toLowerCase().includes(q))
})

function openNew() { editing.value = null; form.reset(); imageFile.value = null; form.sales_account_id = props.incomeAccounts[0]?.id ?? null; dialog.value = true }
function openEdit(i: Item) {
  editing.value = i
  imageFile.value = null
  Object.assign(form, { code: i.code, name: i.name, description: i.description ?? '', image: i.image, unit: i.unit, type: i.type, sales_price: i.sales_price, cost_price: i.cost_price, sales_account_id: i.sales_account_id, purchase_account_id: i.purchase_account_id, tax_rate: i.tax_rate, is_active: i.is_active })
  dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editing.value ? form.patch(route('finance.accounting.items.update', editing.value.id), opts) : form.post(route('finance.accounting.items.store'), opts)
}
const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.items') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.items_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_item') }}</VBtn>
    </div>

    <VCard border>
      <VCardText><VTextField v-model="search" :label="t('acc.search_code_name')" density="compact" style="max-width: 280px" prepend-inner-icon="tabler-search" hide-details /></VCardText>
      <VTable>
        <thead><tr><th></th><th>{{ t('acc.code') }}</th><th>{{ t('acc.name') }}</th><th>{{ t('acc.type') }}</th><th>{{ t('acc.unit') }}</th><th class="text-right">{{ t('acc.sales_price') }}</th><th class="text-right">{{ t('acc.cost') }}</th><th class="text-right">{{ t('acc.tax_pct') }}</th><th>{{ t('acc.sales_account') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="i in filtered" :key="i.id">
            <td class="pa-1"><VAvatar v-if="i.image" :image="i.image" size="34" rounded="lg" /><VAvatar v-else size="34" rounded="lg" color="grey-lighten-3"><VIcon icon="tabler-package" size="18" /></VAvatar></td>
            <td class="font-weight-medium">{{ i.code }}</td>
            <td>{{ i.name }}</td>
            <td><VChip size="x-small" variant="tonal" class="text-capitalize">{{ i.type }}</VChip></td>
            <td class="text-caption">{{ i.unit }}</td>
            <td class="text-right">{{ money(i.sales_price) }}</td>
            <td class="text-right text-caption">{{ money(i.cost_price) }}</td>
            <td class="text-right text-caption">{{ i.tax_rate }}%</td>
            <td class="text-caption text-medium-emphasis">{{ i.sales_account || '—' }}</td>
            <td><VChip size="x-small" :color="i.is_active ? 'success' : 'default'" variant="tonal">{{ i.is_active ? t('acc.active') : t('acc.inactive') }}</VChip></td>
            <td class="text-right"><VBtn icon="tabler-pencil" size="small" variant="text" @click="openEdit(i)" /></td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="11" class="text-center text-medium-emphasis py-6">{{ t('acc.no_items') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="960">
      <VCard>
        <VCardTitle class="text-h6 pa-5">{{ editing ? t('acc.edit_item') : t('acc.new_item') }}</VCardTitle>
        <VCardText class="px-5">
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.code" :label="t('acc.code')" variant="outlined" density="comfortable" style="max-width: 160px" :error-messages="form.errors.code" />
            <VTextField v-model="form.name" :label="t('acc.name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
            <VSelect v-model="form.type" :items="[{ title: t('acc.service'), value: 'service' }, { title: t('acc.product'), value: 'product' }]" :label="t('acc.type')" variant="outlined" density="comfortable" style="max-width: 150px" />
          </div>
          <VTextField v-model="form.description" :label="t('acc.description')" variant="outlined" density="comfortable" class="mb-3" />
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.unit" :label="t('acc.unit')" variant="outlined" density="comfortable" style="max-width: 120px" />
            <VTextField v-model.number="form.sales_price" type="number" :label="t('acc.sales_price')" variant="outlined" density="comfortable" :error-messages="form.errors.sales_price" />
            <VTextField v-model.number="form.cost_price" type="number" :label="t('acc.cost_price')" variant="outlined" density="comfortable" />
            <VTextField v-model.number="form.tax_rate" type="number" :label="t('acc.tax_pct')" variant="outlined" density="comfortable" style="max-width: 110px" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VAutocomplete v-model="form.sales_account_id" :items="incomeAccounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.sales_income_account')" variant="outlined" density="comfortable" clearable />
            <VAutocomplete v-model="form.purchase_account_id" :items="expenseAccounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.purchase_expense_account')" variant="outlined" density="comfortable" clearable />
          </div>
          <div class="d-flex gap-4 align-center mb-2">
            <VAvatar v-if="form.image" :image="form.image" size="72" rounded="lg" />
            <VAvatar v-else size="72" rounded="lg" color="grey-lighten-3"><VIcon icon="tabler-photo" /></VAvatar>
            <VFileInput v-model="imageFile" :label="t('acc.item_picture')" accept="image/*" variant="outlined" density="comfortable" prepend-icon="tabler-camera" show-size clearable style="max-width: 340px" @update:model-value="onImage" />
            <VBtn v-if="form.image" size="small" variant="text" color="error" @click="form.image = null; imageFile = null">{{ t('acc.remove') }}</VBtn>
          </div>
          <VSwitch v-model="form.is_active" :label="t('acc.active')" color="success" hide-details />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.code || !form.name" @click="save">{{ editing ? t('acc.save') : t('acc.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
