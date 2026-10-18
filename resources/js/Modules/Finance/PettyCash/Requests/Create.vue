<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface FloatOption { id: number; name: string; current_balance: number; float_limit: number }
interface Category { value: string; label: string }

const props = defineProps<{ floats: FloatOption[]; categories: Category[] }>()

const form = useForm({
  float_id: null as number | null,
  purpose: '',
  category: null as string | null,
  amount_requested: null as number | null,
  notes: '',
})

const selectedFloat = computed(() => props.floats.find(float => float.id === form.float_id) ?? null)

const submit = () => form.post(route('petty-cash.requests.store'))
</script>

<template>
  <div class="pa-4" style="max-width:680px;margin:auto">
    <!-- Loading overlay -->
    <VOverlay v-if="form.processing" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="mb-5">
      <VBtn variant="text" prepend-icon="tabler-arrow-left" :href="route('petty-cash.requests.index')" class="mb-2" :disabled="form.processing">
        {{ t('petty_cash_requests_create.back') }}
      </VBtn>
      <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_requests_create.page_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_requests_create.description') }}</p>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-5">
        <VForm @submit.prevent="submit">
          <VRow>
            <VCol cols="12">
              <VSelect
                v-model="form.float_id"
                :items="floats"
                item-title="name"
                item-value="id"
                :label="t('petty_cash_requests_create.form.select_float')"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.float_id"
                :disabled="form.processing"
              >
                <template #item="{ item, props: iProps }">
                  <VListItem v-bind="iProps">
                    <template #append>
                      <span class="text-caption text-medium-emphasis">
                        {{ t('petty_cash_requests_create.form.balance', { balance: item.raw.current_balance }) }}
                      </span>
                    </template>
                  </VListItem>
                </template>
              </VSelect>
              <VAlert
                v-if="selectedFloat"
                :color="selectedFloat.current_balance < 100 ? 'error' : 'info'"
                variant="tonal"
                density="compact"
                class="mt-1 text-caption"
              >
                {{ t('petty_cash_requests_create.form.available_balance', { balance: selectedFloat.current_balance }) }} / {{ t('petty_cash_requests_create.form.limit', { limit: selectedFloat.float_limit }) }}
              </VAlert>
            </VCol>

            <VCol cols="12">
              <VSelect
                v-model="form.category"
                :items="categories"
                item-title="label"
                item-value="value"
                :label="t('petty_cash_requests_create.form.category')"
                variant="outlined"
                density="comfortable"
                clearable
                :error-messages="form.errors.category"
                :disabled="form.processing"
              />
            </VCol>

            <VCol cols="12">
              <VTextField
                v-model="form.purpose"
                :label="t('petty_cash_requests_create.form.purpose')"
                variant="outlined"
                density="comfortable"
                :hint="t('petty_cash_requests_create.form.purpose_hint')"
                persistent-hint
                :error-messages="form.errors.purpose"
                :disabled="form.processing"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.amount_requested"
                :label="t('petty_cash_requests_create.form.amount_requested')"
                type="number"
                min="0.01"
                max="1000"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.amount_requested"
                :disabled="form.processing"
              />
              <div class="text-caption text-medium-emphasis mt-1">{{ t('petty_cash_requests_create.form.max_request_hint') }}</div>
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('petty_cash_requests_create.form.additional_notes')"
                variant="outlined"
                rows="2"
                density="comfortable"
                :error-messages="form.errors.notes"
                :disabled="form.processing"
              />
            </VCol>

            <VCol cols="12">
              <VAlert type="info" variant="tonal" density="compact" class="text-caption">
                {{ t('petty_cash_requests_create.form.receipt_alert') }}
              </VAlert>
            </VCol>

            <VCol cols="12" class="d-flex justify-end ga-2">
              <VBtn variant="outlined" :href="route('petty-cash.requests.index')" :disabled="form.processing">
                {{ t('petty_cash_requests_create.form.cancel') }}
              </VBtn>
              <VBtn color="primary" type="submit" :loading="form.processing" :disabled="form.processing">
                {{ t('petty_cash_requests_create.form.submit_request') }}
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
