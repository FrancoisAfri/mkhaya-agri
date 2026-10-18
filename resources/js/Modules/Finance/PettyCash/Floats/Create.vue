<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Custodian { id: number; name: string }

defineProps<{ custodians: Custodian[] }>()

const form = useForm({
  name: '',
  description: '',
  custodian_id: null as number | null,
  float_limit: null as number | null,
  replenishment_threshold: null as number | null,
  currency: 'ZAR',
  notes: '',
})

const submit = () => form.post(route('petty-cash.floats.store'))
</script>

<template>
  <div class="pa-4" style="max-width:680px;margin:auto">
    <!-- Loading overlay -->
    <VOverlay v-if="form.processing" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="mb-5">
      <VBtn variant="text" prepend-icon="tabler-arrow-left" :href="route('petty-cash.floats.index')" class="mb-2" :disabled="form.processing">
        {{ t('petty_cash_floats_create.back') }}
      </VBtn>
      <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_floats_create.page_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_floats_create.description') }}</p>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-5">
        <VForm @submit.prevent="submit">
          <VRow>
            <VCol cols="12">
              <VTextField
                v-model="form.name"
                :label="t('petty_cash_floats_create.form.float_name')"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.name"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.description"
                :label="t('petty_cash_floats_create.form.description')"
                variant="outlined"
                rows="2"
                density="comfortable"
                :error-messages="form.errors.description"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="form.custodian_id"
                :items="custodians"
                item-title="name"
                item-value="id"
                :label="t('petty_cash_floats_create.form.custodian')"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.custodian_id"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.float_limit"
                :label="t('petty_cash_floats_create.form.float_limit')"
                type="number"
                min="1"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.float_limit"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.replenishment_threshold"
                :label="t('petty_cash_floats_create.form.replenishment_threshold')"
                type="number"
                min="0"
                variant="outlined"
                density="comfortable"
                :hint="t('petty_cash_floats_create.form.threshold_hint')"
                persistent-hint
                :error-messages="form.errors.replenishment_threshold"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.currency"
                :label="t('petty_cash_floats_create.form.currency')"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.currency"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('petty_cash_floats_create.form.notes')"
                variant="outlined"
                rows="2"
                density="comfortable"
                :error-messages="form.errors.notes"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" class="d-flex justify-end ga-2">
              <VBtn variant="outlined" :href="route('petty-cash.floats.index')" :disabled="form.processing">
                {{ t('petty_cash_floats_create.form.cancel') }}
              </VBtn>
              <VBtn color="primary" type="submit" :loading="form.processing" :disabled="form.processing">
                {{ t('petty_cash_floats_create.form.create_float') }}
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
