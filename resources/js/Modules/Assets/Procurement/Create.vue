<script setup lang="ts">
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  categories: { id: number; name: string }[]
}>()

const form = useForm({
  item_description:    '',
  asset_category_id:   null as number | null,
  quantity:            1,
  estimated_unit_cost: null as number | null,
  justification:       '',
  priority:            'medium',
})

const estimatedTotal = computed(() => {
  if (!form.estimated_unit_cost || !form.quantity) return null
  return form.quantity * form.estimated_unit_cost
})

const priorityOptions = computed(() => [
  { title: t('assets_procurement_create.priorities.low'), value: 'low' },
  { title: t('assets_procurement_create.priorities.medium'), value: 'medium' },
  { title: t('assets_procurement_create.priorities.high'), value: 'high' },
  { title: t('assets_procurement_create.priorities.urgent'), value: 'urgent' },
])

function formatCurrency(val: number | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(val)
}

function submit() {
  form.post(route('assets.procurement.store'))
}
</script>

<template>
  <div class="pa-6" style="max-width:720px">
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_procurement_create.page_title') }}</h4>
      <p class="text-medium-emphasis mb-0">{{ t('assets_procurement_create.description') }}</p>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-6">
        <VForm @submit.prevent="submit">
          <VRow>
            <VCol cols="12">
              <VTextField v-model="form.item_description" :label="t('assets_procurement_create.form.item_description')" counter="500" :error-messages="form.errors.item_description" />
            </VCol>
            <VCol cols="12" sm="6">
              <VAutocomplete v-model="form.asset_category_id" :items="categories" item-title="name" item-value="id" :label="t('assets_procurement_create.form.asset_category')" clearable />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.priority" :items="priorityOptions" item-title="title" item-value="value" :label="t('assets_procurement_create.form.priority')" :error-messages="form.errors.priority" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model.number="form.quantity" :label="t('assets_procurement_create.form.quantity')" type="number" min="1" :error-messages="form.errors.quantity" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model.number="form.estimated_unit_cost" :label="t('assets_procurement_create.form.estimated_unit_cost')" type="number" min="0.01" step="0.01" :error-messages="form.errors.estimated_unit_cost" />
            </VCol>
            <VCol cols="12" v-if="estimatedTotal">
              <VAlert type="info" variant="tonal" density="compact">
                {{ t('assets_procurement_create.form.estimated_total') }}: <strong>{{ formatCurrency(estimatedTotal) }}</strong>
              </VAlert>
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.justification" :label="t('assets_procurement_create.form.justification')" rows="4" counter="5000" :error-messages="form.errors.justification" />
            </VCol>
          </VRow>
          <div class="d-flex gap-3 mt-4">
            <VBtn type="submit" color="primary" :loading="form.processing">{{ t('assets_procurement_create.form.submit') }}</VBtn>
            <VBtn variant="text" :href="route('assets.procurement.index')">{{ t('assets_procurement_create.form.cancel') }}</VBtn>
          </div>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
