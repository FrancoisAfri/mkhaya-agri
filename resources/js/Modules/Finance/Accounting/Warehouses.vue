<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Warehouse { id: number; code: string; name: string; address: string | null; is_default: boolean; is_active: boolean; lines: number; units: number }
const props = defineProps<{ warehouses: Warehouse[] }>()

const qty = (n: number) => (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 4 })

const dialog = ref(false)
const editingId = ref<number | null>(null)
const form = useForm<any>({ code: '', name: '', address: '', is_default: false, is_active: true })

function openNew() { editingId.value = null; form.reset(); dialog.value = true }
function openEdit(w: Warehouse) {
  editingId.value = w.id
  Object.assign(form, { code: w.code, name: w.name, address: w.address ?? '', is_default: w.is_default, is_active: w.is_active })
  dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editingId.value
    ? form.patch(route('finance.accounting.warehouses.update', editingId.value), opts)
    : form.post(route('finance.accounting.warehouses.store'), opts)
}
function makeDefault(w: Warehouse) { router.post(route('finance.accounting.warehouses.default', w.id), {}, { preserveScroll: true }) }
function remove(w: Warehouse) {
  if (confirm(t('acc.wh_delete_confirm', { name: w.name }))) router.delete(route('finance.accounting.warehouses.destroy', w.id), { preserveScroll: true })
}
const pageErrors = () => (router as any)?.page?.props?.errors ?? {}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.inventory') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.warehouses') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.warehouses_sub') }}</div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-package" @click="router.visit(route('finance.accounting.inventory'))">{{ t('acc.inventory') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_warehouse') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol v-for="w in warehouses" :key="w.id" cols="12" sm="6" md="4">
        <VCard border rounded="lg" class="h-100">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-1">
              <div class="d-flex align-center gap-2">
                <VIcon icon="tabler-building-warehouse" size="20" :color="w.is_active ? 'primary' : 'disabled'" />
                <span class="text-body-1 font-weight-semibold">{{ w.name }}</span>
              </div>
              <VChip v-if="w.is_default" size="x-small" color="success" variant="tonal">{{ t('acc.default_label') }}</VChip>
            </div>
            <div class="text-caption text-medium-emphasis mb-2">{{ w.code }}<span v-if="w.address"> · {{ w.address }}</span></div>
            <div class="d-flex gap-4">
              <div><div class="text-caption text-medium-emphasis">{{ t('acc.items_held') }}</div><div class="text-h6">{{ w.lines }}</div></div>
              <div><div class="text-caption text-medium-emphasis">{{ t('acc.units_held') }}</div><div class="text-h6">{{ qty(w.units) }}</div></div>
              <VSpacer />
              <VChip v-if="!w.is_active" size="x-small" color="warning" variant="tonal" class="align-self-start">{{ t('acc.inactive') }}</VChip>
            </div>
          </VCardText>
          <VCardActions>
            <VBtn size="small" variant="text" prepend-icon="tabler-pencil" @click="openEdit(w)">{{ t('acc.edit') }}</VBtn>
            <VBtn v-if="!w.is_default" size="small" variant="text" @click="makeDefault(w)">{{ t('acc.set_default') }}</VBtn>
            <VSpacer />
            <VBtn v-if="!w.is_default" size="small" variant="text" color="error" icon="tabler-trash" @click="remove(w)" />
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!warehouses.length" cols="12"><VCard border rounded="lg"><VCardText class="text-center text-medium-emphasis py-8">{{ t('acc.no_warehouses') }}</VCardText></VCard></VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="480">
      <VCard>
        <VCardTitle>{{ editingId ? t('acc.edit_warehouse') : t('acc.new_warehouse') }}</VCardTitle>
        <VCardText>
          <VAlert v-if="pageErrors().warehouse" type="error" variant="tonal" density="compact" class="mb-3">{{ pageErrors().warehouse }}</VAlert>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.code" :label="t('acc.warehouse_code')" variant="outlined" density="comfortable" style="max-width: 160px" :error-messages="form.errors.code" />
            <VTextField v-model="form.name" :label="t('acc.warehouse_name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
          </div>
          <VTextField v-model="form.address" :label="t('acc.warehouse_address')" variant="outlined" density="comfortable" class="mb-1" :error-messages="form.errors.address" />
          <div class="d-flex flex-wrap gap-4">
            <VSwitch v-if="!editingId" v-model="form.is_default" :label="t('acc.make_default')" color="success" density="compact" hide-details />
            <VSwitch v-if="editingId" v-model="form.is_active" :label="t('acc.active')" color="primary" density="compact" hide-details />
          </div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.code || !form.name" @click="save">{{ editingId ? t('acc.save') : t('acc.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
