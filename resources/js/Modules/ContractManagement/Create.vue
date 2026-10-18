<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import TiptapEditor from '@/@core/components/TiptapEditor.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  types: { id: number; name: string }[]
  categories: { id: number; name: string }[]
  suppliers: { id: number; name: string }[]
  templates: { id: number; name: string; content: string | null }[]
  requests: { id: number; label: string; request_title: string; contract_value: number | null }[]
}>()

const submitting = ref(false)
const file = ref<File | null>(null)
const templateId = ref<number | null>(null)
const form = ref({
  title: '', description: '',
  contract_request_id: null as number | null,
  contract_type_id: null as number | null,
  contract_category_id: null as number | null,
  supplier_id: null as number | null,
  contract_value: null as number | null,
  currency: 'ZAR',
  start_date: '', end_date: '',
  periodicity: '' as string,
  governing_law: '', jurisdiction: '',
})

const periodicities = computed(()=> [
  { title: t('contracts_create.periodicities.monthly'), value: 'Monthly' },
  { title: t('contracts_create.periodicities.quarterly'), value: 'Quarterly' },
  { title: t('contracts_create.periodicities.semiannually'), value: 'Semiannually' },
  { title: t('contracts_create.periodicities.yearly'), value: 'Yearly' },
])

const currencies = ['ZAR', 'USD', 'EUR', 'GBP', 'BWP', 'NAD', 'KES', 'NGN']
const governingLaws = ['South African law', 'English law', 'New York law', 'Botswana law', 'Namibian law', 'Kenyan law']
const jurisdictions = ['South Africa', 'High Court of South Africa', 'England & Wales', 'Botswana', 'Namibia', 'Kenya']

// Prefill the contract body from a chosen template.
function applyTemplate(id: number | null) {
  const template = props.templates.find(x => x.id === id)
  if (template) form.value.description = template.content ?? ''
}

// Every contract originates from an approved request — prefill from it.
function applyRequest(id: number | null) {
  const r = props.requests.find(x => x.id === id)
  if (!r) return
  if (!form.value.title) form.value.title = r.request_title
  if (r.contract_value != null) form.value.contract_value = r.contract_value
}

function submit(publish: boolean) {
  submitting.value = true
  const data: Record<string, any> = { ...form.value, publish }
  if (file.value) data.file = file.value
  router.post(route('contracts.store'), data, {
    forceFormData: true,
    onFinish: () => (submitting.value = false),
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('contracts.index'))" />
        <h4 class="text-h4 mb-0">{{ t('contracts_create.page_title') }}</h4>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-upload" @click="router.get(route('contracts.bulk-upload'))">
        {{ t('contracts_create.bulk_upload') }}
      </VBtn>
    </div>

    <VCard border>
      <VCardText>
        <VAlert v-if="!requests.length" type="warning" variant="tonal" class="mb-3" density="compact">
          {{ t('contracts_create.no_requests_warning') }}
          <strong>{{ t('contracts_create.requests_link') }}</strong>.
        </VAlert>
        <VRow class="mb-1">
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.contract_request_id"
              :items="requests"
              item-title="label"
              item-value="id"
              :label="t('contracts_create.form.contract_request')"
              variant="outlined"
              :disabled="!requests.length"
              @update:model-value="applyRequest"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect
              v-model="templateId"
              :items="templates"
              item-title="name"
              item-value="id"
              :label="t('contracts_create.form.start_from_template')"
              variant="outlined"
              clearable
              @update:model-value="applyTemplate"
            />
          </VCol>
        </VRow>
        <VRow class="mb-1">
          <VCol cols="12">
            <VTextField v-model="form.title" :label="t('contracts_create.form.title')" variant="outlined" />
          </VCol>
        </VRow>

        <div class="text-caption text-medium-emphasis mb-1">{{ t('contracts_create.form.contract_body') }}</div>
        <TiptapEditor
          v-model="form.description"
          :placeholder="t('contracts_create.form.body_placeholder')"
          class="mb-3"
        />

        <VRow>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.contract_type_id"
              :items="types"
              item-title="name"
              item-value="id"
              :label="t('contracts_create.form.type')"
              variant="outlined"
              clearable
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.contract_category_id"
              :items="categories"
              item-title="name"
              item-value="id"
              :label="t('contracts_create.form.category')"
              variant="outlined"
              clearable
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.supplier_id"
              :items="suppliers"
              item-title="name"
              item-value="id"
              :label="t('contracts_create.form.supplier')"
              variant="outlined"
              clearable
            />
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12" md="3">
            <VTextField
              v-model.number="form.contract_value"
              type="number"
              :label="t('contracts_create.form.contract_value')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="1">
            <VSelect
              v-model="form.currency"
              :items="currencies"
              :label="t('contracts_create.form.currency')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.start_date"
              type="date"
              :label="t('contracts_create.form.start_date')"
              variant="outlined"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.end_date"
              type="date"
              :label="t('contracts_create.form.end_date')"
              variant="outlined"
            />
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.periodicity"
              :items="periodicities"
              :label="t('contracts_create.form.periodicity')"
              variant="outlined"
              clearable
            />
          </VCol>
          <VCol cols="12" md="4">
            <VCombobox
              v-model="form.governing_law"
              :items="governingLaws"
              :label="t('contracts_create.form.governing_law')"
              variant="outlined"
              clearable
            />
          </VCol>
          <VCol cols="12" md="4">
            <VCombobox
              v-model="form.jurisdiction"
              :items="jurisdictions"
              :label="t('contracts_create.form.jurisdiction')"
              variant="outlined"
              clearable
            />
          </VCol>
        </VRow>
        <VRow>
          <VCol cols="12">
            <VFileInput
              :label="t('contracts_create.form.contract_document')"
              variant="outlined"
              prepend-icon="tabler-paperclip"
              @update:model-value="(f: any) => file = Array.isArray(f) ? f[0] : f"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="router.get(route('contracts.index'))">
          {{ t('contracts_create.cancel') }}
        </VBtn>
        <VBtn
          variant="tonal"
          :loading="submitting"
          :disabled="!form.title || !form.contract_request_id"
          @click="submit(false)"
        >
          {{ t('contracts_create.save_draft') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="submitting"
          :disabled="!form.title || !form.contract_request_id"
          @click="submit(true)"
        >
          {{ t('contracts_create.save_publish') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
