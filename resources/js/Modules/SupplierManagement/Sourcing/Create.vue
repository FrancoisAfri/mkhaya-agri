<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Supplier {
  id: number
  company_name: string
}

// ── Props ──────────────────────────────────────────────────────────────────────
defineProps<{
  suppliers: Supplier[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const submitting = ref<boolean>(false)

interface RfxForm {
  title: string
  type: string
  description: string
  closing_date: string
  supplier_ids: number[]
}

const form = ref<RfxForm>({
  title: '',
  type: 'rfq',
  description: '',
  closing_date: '',
  supplier_ids: [],
})

// ── Constants ──────────────────────────────────────────────────────────────────
const typeOptions = [
  { title: t('supplier_sourcing_rfx_create.fields.type_rfq'), value: 'rfq' },
  { title: t('supplier_sourcing_rfx_create.fields.type_rfp'), value: 'rfp' },
  { title: t('supplier_sourcing_rfx_create.fields.type_rfi'), value: 'rfi' },
]

// ── Methods ────────────────────────────────────────────────────────────────────
function submit(): void {
  submitting.value = true
  router.post(
    route('suppliers.sourcing.store'),
    form.value,
    { onFinish: () => (submitting.value = false) }
  )
}

function goToIndex(): void {
  router.get(route('suppliers.sourcing.index'))
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-4">{{ t('supplier_sourcing_rfx_create.page_title') }}</h1>
    <VCard border>
      <VCardText>
        <VRow>
          <VCol cols="12" md="8">
            <VTextField
              v-model="form.title"
              :label="t('supplier_sourcing_rfx_create.fields.title')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.type"
              :items="typeOptions"
              :label="t('supplier_sourcing_rfx_create.fields.type')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.closing_date"
              :label="t('supplier_sourcing_rfx_create.fields.closing_date')"
              type="date"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12">
            <VTextarea
              v-model="form.description"
              :label="t('supplier_sourcing_rfx_create.fields.description')"
              rows="4"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12">
            <VSelect
              v-model="form.supplier_ids"
              :items="suppliers"
              item-title="company_name"
              item-value="id"
              :label="t('supplier_sourcing_rfx_create.fields.suppliers')"
              variant="outlined"
              multiple
              chips
              closable-chips
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="goToIndex">
          {{ t('supplier_sourcing_rfx_create.actions.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="submitting"
          :disabled="!form.title"
          @click="submit"
        >
          {{ t('supplier_sourcing_rfx_create.actions.create') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
