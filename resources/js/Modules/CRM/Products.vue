<script lang="ts" setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Product {
  id: number; name: string; type: string; code: string | null
  description: string | null; price: number | string | null; currency: string; is_active: boolean
}
const props = defineProps<{ products: Product[] }>()

const dialog = ref(false)
const editing = ref<Product | null>(null)
const form = useForm<{ name: string; type: string; code: string; description: string; price: number | null; currency: string; is_active: boolean }>({
  name: '', type: 'product', code: '', description: '', price: null, currency: 'ZAR', is_active: true,
})

function openNew() {
  editing.value = null
  form.reset()
  form.type = 'product'; form.currency = 'ZAR'; form.is_active = true
  dialog.value = true
}
function openEdit(p: Product) {
  editing.value = p
  form.name = p.name; form.type = p.type; form.code = p.code ?? ''
  form.description = p.description ?? ''; form.price = p.price != null ? Number(p.price) : null
  form.currency = p.currency || 'ZAR'; form.is_active = p.is_active
  dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } }
  if (editing.value) form.patch(route('crm.products.update', editing.value.id), opts)
  else form.post(route('crm.products.store'), opts)
}

const confirm = ref()
function remove(p: Product) {
  confirm.value.open(
    { title: t('crm_products.remove_item'), message: t('crm_products.remove_confirm_message', { name: p.name }), confirmText: t('crm_products.remove'), confirmColor: 'error' },
    () => router.delete(route('crm.products.destroy', p.id), { preserveScroll: true }),
  )
}
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('crm_products.crm') }}</div>
        <h2 class="text-h5 mb-1">{{ t('crm_products.products_services') }}</h2>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('crm_products.catalogue_description') }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('crm_products.new_product_service') }}</VBtn>
    </div>

    <VCard border>
      <VTable>
        <thead>
          <tr><th>{{ t('crm_products.name') }}</th><th>{{ t('crm_products.type') }}</th><th>{{ t('crm_products.code') }}</th><th>{{ t('crm_products.price') }}</th><th>{{ t('crm_products.status') }}</th><th class="text-right"></th></tr>
        </thead>
        <tbody>
          <tr v-if="!products.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('crm_products.no_products_yet') }}</td></tr>
          <tr v-for="p in products" :key="p.id">
            <td class="font-weight-medium">
              {{ p.name }}
              <div v-if="p.description" class="text-caption text-medium-emphasis text-truncate" style="max-width: 360px">{{ p.description }}</div>
            </td>
            <td><VChip size="x-small" variant="tonal" class="text-capitalize" :color="p.type === 'service' ? 'info' : 'primary'">{{ p.type }}</VChip></td>
            <td class="text-caption">{{ p.code || '—' }}</td>
            <td class="text-caption">{{ p.price != null ? `${p.currency} ${Number(p.price).toFixed(2)}` : '—' }}</td>
            <td><VChip size="x-small" :color="p.is_active ? 'success' : 'default'" variant="tonal">{{ p.is_active ? t('crm_products.active') : t('crm_products.inactive') }}</VChip></td>
            <td class="text-right">
              <VBtn icon="tabler-pencil" size="small" variant="text" @click="openEdit(p)" />
              <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="remove(p)" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>{{ editing ? t('crm_products.edit') : t('crm_products.new') }} {{ t('crm_products.product_service') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" :label="t('crm_products.name')" variant="outlined" density="comfortable" class="mb-3" :error-messages="form.errors.name" />
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="form.type" :items="[{title: t('crm_products.product'), value:'product'},{title: t('crm_products.service'), value:'service'}]" :label="t('crm_products.type')" variant="outlined" density="comfortable" />
            <VTextField v-model="form.code" :label="t('crm_products.code_sku_optional')" variant="outlined" density="comfortable" :hint="t('crm_products.used_to_match_on_import')" persistent-hint />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="form.price" type="number" min="0" step="0.01" :label="t('crm_products.price_optional')" variant="outlined" density="comfortable" :error-messages="form.errors.price" />
            <VTextField v-model="form.currency" :label="t('crm_products.currency')" variant="outlined" density="comfortable" style="max-width: 120px" />
          </div>
          <VTextarea v-model="form.description" :label="t('crm_products.description_optional')" variant="outlined" density="comfortable" rows="2" class="mb-2" />
          <VSwitch v-model="form.is_active" :label="t('crm_products.active')" color="success" hide-details />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('crm_products.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">{{ editing ? t('crm_products.save') : t('crm_products.add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
