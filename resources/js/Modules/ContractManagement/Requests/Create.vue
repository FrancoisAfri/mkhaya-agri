<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  types: { id: number; name: string }[]
  units: string[]
}>()

const submitting = ref(false)
const form = ref({
  request_title: '',
  contract_type_id: null as number | null,
  contract_length: '',
  contract_length_unit: 'months',
  criticality: null as number | null,
  contract_value: null as number | null,
  summary: '',
  motivation: '',
})

function submit() {
  submitting.value = true
  router.post(route('contracts.requests.store'), form.value, {
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
      <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('contracts.requests.index'))" />
      <h4 class="text-h4 mb-0">{{ t('contracts_requests_create.page_title') }}</h4>
    </div>

    <VCard border>
      <VCardText>
        <VTextField
          v-model="form.request_title"
          :label="t('contracts_requests_create.form.request_title')"
          variant="outlined"
          class="mb-3"
          :disabled="submitting"
        />
        <VRow>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.contract_type_id"
              :items="types"
              item-title="name"
              item-value="id"
              :label="t('contracts_requests_create.form.contract_type')"
              variant="outlined"
              clearable
              :disabled="submitting"
            />
          </VCol>
          <VCol cols="6" md="3">
            <VTextField
              v-model="form.contract_length"
              :label="t('contracts_requests_create.form.length')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
          <VCol cols="6" md="3">
            <VSelect
              v-model="form.contract_length_unit"
              :items="units"
              :label="t('contracts_requests_create.form.unit')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
        </VRow>
        <VRow>
          <VCol cols="6" md="6">
            <VTextField
              v-model.number="form.criticality"
              type="number"
              :label="t('contracts_requests_create.form.criticality')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
          <VCol cols="6" md="6">
            <VTextField
              v-model.number="form.contract_value"
              type="number"
              :label="t('contracts_requests_create.form.estimated_value')"
              variant="outlined"
              :disabled="submitting"
            />
          </VCol>
        </VRow>
        <VTextarea
          v-model="form.summary"
          :label="t('contracts_requests_create.form.summary')"
          variant="outlined"
          rows="3"
          class="mb-3"
          :disabled="submitting"
        />
        <VTextarea
          v-model="form.motivation"
          :label="t('contracts_requests_create.form.motivation')"
          variant="outlined"
          rows="3"
          :disabled="submitting"
        />
      </VCardText>
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" :disabled="submitting" @click="router.get(route('contracts.requests.index'))">
          {{ t('contracts_requests_create.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="submitting"
          :disabled="!form.request_title || submitting"
          @click="submit"
        >
          {{ t('contracts_requests_create.submit_request') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
