<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface IndustryType {
  id: number
  industry_type_name: string
}

interface Category {
  id: number
  name: string
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  industryTypes: IndustryType[]
  categories: Category[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const submitting = ref<boolean>(false)

interface SupplierForm {
  company_name: string
  trading_name: string
  company_registration_number: string
  tax_reference_number: string
  vat_registration_number: string
  bbbee_status_level: string
  business_entity_type: string
  physical_address: string
  postal_address: string
  phone_number: string
  email: string
  website_url: string
  bank_name: string
  account_holder_name: string
  account_type: string
  bank_account_number: string
  branch_code: string
  payment_terms: string
  years_in_operation: number | null
  notes: string
  industry_ids: number[]
  category_ids: number[]
}

const form = ref<SupplierForm>({
  company_name: '',
  trading_name: '',
  company_registration_number: '',
  tax_reference_number: '',
  vat_registration_number: '',
  bbbee_status_level: '',
  business_entity_type: '',
  physical_address: '',
  postal_address: '',
  phone_number: '',
  email: '',
  website_url: '',
  bank_name: '',
  account_holder_name: '',
  account_type: '',
  bank_account_number: '',
  branch_code: '',
  payment_terms: '30 days',
  years_in_operation: null,
  notes: '',
  industry_ids: [],
  category_ids: [],
})

// ── Methods ────────────────────────────────────────────────────────────────────
function submit(): void {
  submitting.value = true
  router.post(route('suppliers.store'), form.value, {
    onFinish: () => (submitting.value = false),
  })
}

function goToIndex(): void {
  router.get(route('suppliers.index'))
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-4">{{ t('supplier_create.page_title') }}</h1>
    <VCard border>
      <VCardText>
        <!-- ── Company Information ───────────────────────────────────────────── -->
        <h6 class="text-h6 mb-2">{{ t('supplier_create.sections.company_info') }}</h6>
        <VRow>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.company_name"
              :label="t('supplier_create.fields.company_name')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.trading_name"
              :label="t('supplier_create.fields.trading_name')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.company_registration_number"
              :label="t('supplier_create.fields.company_registration')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.tax_reference_number"
              :label="t('supplier_create.fields.tax_reference')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.vat_registration_number"
              :label="t('supplier_create.fields.vat_registration')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.bbbee_status_level"
              :label="t('supplier_create.fields.bbbee_status')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.business_entity_type"
              :label="t('supplier_create.fields.entity_type')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model.number="form.years_in_operation"
              :label="t('supplier_create.fields.years_in_operation')"
              type="number"
              variant="outlined"
            />
          </VCol>
        </VRow>

        <!-- ── Contact ───────────────────────────────────────────────────────── -->
        <h6 class="text-h6 mb-2 mt-4">{{ t('supplier_create.sections.contact') }}</h6>
        <VRow>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.email"
              :label="t('supplier_create.fields.email')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.phone_number"
              :label="t('supplier_create.fields.phone')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.website_url"
              :label="t('supplier_create.fields.website')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextarea
              v-model="form.physical_address"
              :label="t('supplier_create.fields.physical_address')"
              rows="2"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextarea
              v-model="form.postal_address"
              :label="t('supplier_create.fields.postal_address')"
              rows="2"
              variant="outlined"
            />
          </VCol>
        </VRow>

        <!-- ── Banking ───────────────────────────────────────────────────────── -->
        <h6 class="text-h6 mb-2 mt-4">{{ t('supplier_create.sections.banking') }}</h6>
        <VRow>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.bank_name"
              :label="t('supplier_create.fields.bank_name')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.account_holder_name"
              :label="t('supplier_create.fields.account_holder')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.account_type"
              :label="t('supplier_create.fields.account_type')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.bank_account_number"
              :label="t('supplier_create.fields.account_number')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.branch_code"
              :label="t('supplier_create.fields.branch_code')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.payment_terms"
              :label="t('supplier_create.fields.payment_terms')"
              variant="outlined"
            />
          </VCol>
        </VRow>

        <!-- ── Classification ────────────────────────────────────────────────── -->
        <h6 class="text-h6 mb-2 mt-4">{{ t('supplier_create.sections.classification') }}</h6>
        <VRow>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.industry_ids"
              :items="industryTypes"
              item-title="industry_type_name"
              item-value="id"
              :label="t('supplier_create.fields.industries')"
              variant="outlined"
              multiple
              chips
            />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.category_ids"
              :items="categories"
              item-title="name"
              item-value="id"
              :label="t('supplier_create.fields.categories')"
              variant="outlined"
              multiple
              chips
            />
          </VCol>
          <VCol cols="12">
            <VTextarea
              v-model="form.notes"
              :label="t('supplier_create.fields.notes')"
              rows="2"
              variant="outlined"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="goToIndex">
          {{ t('supplier_create.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="submitting"
          :disabled="!form.company_name"
          @click="submit"
        >
          {{ t('supplier_create.create') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
