<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  financialYears: { id: number; name: string }[]
  categories: { id: number; name: string; type: string }[]
}>()

const submitting = ref(false)
const form = ref({
  name: '',
  financial_year_id: null as number | null,
  budget_category_id: null as number | null,
  expenditure_type: '' as string,
  scope_type: '' as string,
  scope_label: '',
  currency: 'ZAR',
  allocated_amount: null as number | null,
  description: '',
})

const expenditureTypes = ['capex', 'opex']
const scopeTypes = ['department', 'project', 'category', 'general']
const currencies = ['ZAR', 'USD', 'EUR', 'GBP']

function submit() {
  submitting.value = true
  router.post(route('budgets.store'), form.value, {
    forceFormData: false,
    onFinish: () => (submitting.value = false),
  })
}
</script>

<template>
  <div class="pa-4">
    <!-- Loading overlay -->
    <VOverlay v-if="submitting" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" :disabled="submitting" @click="router.get(route('budgets.index'))" />
      <h4 class="text-h4 mb-0">{{ t('budgets_create.page_title') }}</h4>
    </div>

    <VCard border>
      <VCardText>
        <VRow>
          <VCol cols="12">
            <VTextField
              v-model="form.name"
              :label="t('budgets_create.form.name')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
        </VRow>
        <VRow>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.financial_year_id"
              :items="financialYears"
              item-title="name"
              item-value="id"
              :label="t('budgets_create.form.financial_year')"
              variant="outlined"
              clearable
              :disabled="submitting"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.budget_category_id"
              :items="categories"
              item-title="name"
              item-value="id"
              :label="t('budgets_create.form.category')"
              variant="outlined"
              clearable
              :disabled="submitting"
            />
          </VCol>
        </VRow>
        <VRow>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.expenditure_type"
              :items="expenditureTypes"
              :label="t('budgets_create.form.expenditure_type')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.scope_type"
              :items="scopeTypes"
              :label="t('budgets_create.form.scope_type')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
        </VRow>
        <VRow>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.scope_label"
              :label="t('budgets_create.form.scope_label')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField
              v-model.number="form.allocated_amount"
              type="number"
              :label="t('budgets_create.form.allocated_amount')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect
              v-model="form.currency"
              :items="currencies"
              :label="t('budgets_create.form.currency')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
        </VRow>
        <VRow>
          <VCol cols="12">
            <VTextarea
              v-model="form.description"
              :label="t('budgets_create.form.description')"
              variant="outlined"
              rows="3"
              auto-grow
              :disabled="submitting"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" :disabled="submitting" @click="router.get(route('budgets.index'))">
          {{ t('budgets_create.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="submitting"
          :disabled="!form.name || form.allocated_amount == null || submitting"
          @click="submit"
        >
          {{ t('budgets_create.create_budget') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
