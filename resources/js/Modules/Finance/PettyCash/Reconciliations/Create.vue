<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface FloatOption { id: number; name: string; current_balance: number; last_reconciled_at: string | null }

const props = defineProps<{ floats: FloatOption[] }>()

const form = useForm({
  float_id: null as number | null,
  period_start: '',
  period_end: '',
  opening_balance: null as number | null,
  total_replenishments: 0,
  total_issued: null as number | null,
  total_receipts: null as number | null,
  cash_on_hand: null as number | null,
  notes: '',
})

const selectedFloat = computed(() => props.floats.find(float => float.id === form.float_id) ?? null)

const expectedBalance = computed(() => {
  if (form.opening_balance == null || form.total_issued == null) return null
  return Number(form.opening_balance) + Number(form.total_replenishments) - Number(form.total_issued)
})

const variance = computed(() => {
  if (expectedBalance.value == null || form.cash_on_hand == null) return null
  return Number(form.cash_on_hand) - expectedBalance.value
})

const submit = () => form.post(route('petty-cash.reconciliations.store'))
</script>

<template>
  <div class="pa-4" style="max-width:1020px;margin:auto">
    <!-- Loading overlay -->
    <VOverlay v-if="form.processing" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="mb-5">
      <VBtn variant="text" prepend-icon="tabler-arrow-left" :href="route('petty-cash.reconciliations.index')" class="mb-2" :disabled="form.processing">
        {{ t('petty_cash_reconciliations_create.back') }}
      </VBtn>
      <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_reconciliations_create.page_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_reconciliations_create.description') }}</p>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-5">
        <VForm @submit.prevent="submit">
          <VRow>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.float_id"
                :items="floats"
                item-title="name"
                item-value="id"
                :label="t('petty_cash_reconciliations_create.form.select_float')"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.float_id"
                :disabled="form.processing"
              />
              <div v-if="selectedFloat" class="text-caption text-medium-emphasis">
                {{ t('petty_cash_reconciliations_create.form.last_reconciled', { date: selectedFloat.last_reconciled_at ?? t('petty_cash_reconciliations_create.form.never') }) }}
              </div>
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.period_start"
                :label="t('petty_cash_reconciliations_create.form.period_start')"
                type="date"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.period_start"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.period_end"
                :label="t('petty_cash_reconciliations_create.form.period_end')"
                type="date"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.period_end"
                :disabled="form.processing"
              />
            </VCol>

            <VCol cols="12">
              <VDivider class="mb-3" />
              <div class="text-body-2 font-weight-semibold text-medium-emphasis mb-3">
                {{ t('petty_cash_reconciliations_create.form.balances_section') }}
              </div>
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.opening_balance"
                :label="t('petty_cash_reconciliations_create.form.opening_balance')"
                type="number"
                min="0"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.opening_balance"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.total_replenishments"
                :label="t('petty_cash_reconciliations_create.form.total_replenishments')"
                type="number"
                min="0"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.total_replenishments"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.total_issued"
                :label="t('petty_cash_reconciliations_create.form.total_issued')"
                type="number"
                min="0"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.total_issued"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.total_receipts"
                :label="t('petty_cash_reconciliations_create.form.total_receipts')"
                type="number"
                min="0"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.total_receipts"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.cash_on_hand"
                :label="t('petty_cash_reconciliations_create.form.cash_on_hand')"
                type="number"
                min="0"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.cash_on_hand"
                :disabled="form.processing"
              />
            </VCol>

            <!-- Live Variance Preview -->
            <VCol cols="12" md="6" v-if="variance !== null">
              <VAlert :type="variance < 0 ? 'error' : variance > 0 ? 'warning' : 'success'" variant="tonal" density="compact">
                <strong>{{ t('petty_cash_reconciliations_create.form.expected_balance', { balance: expectedBalance?.toFixed(2) }) }}</strong><br>
                <strong>{{ t('petty_cash_reconciliations_create.form.variance', { sign: variance > 0 ? '+' : '', variance: variance.toFixed(2) }) }}</strong>
                <span v-if="variance < 0" class="text-error">{{ t('petty_cash_reconciliations_create.form.shortage') }}</span>
                <span v-else-if="variance > 0" class="text-warning">{{ t('petty_cash_reconciliations_create.form.surplus') }}</span>
                <span v-else class="text-success">{{ t('petty_cash_reconciliations_create.form.balanced') }}</span>
              </VAlert>
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('petty_cash_reconciliations_create.form.notes')"
                variant="outlined"
                rows="2"
                density="comfortable"
                :disabled="form.processing"
              />
            </VCol>

            <VCol cols="12" class="d-flex justify-end ga-2">
              <VBtn variant="outlined" :href="route('petty-cash.reconciliations.index')" :disabled="form.processing">
                {{ t('petty_cash_reconciliations_create.form.cancel') }}
              </VBtn>
              <VBtn color="primary" type="submit" :loading="form.processing" :disabled="form.processing">
                {{ t('petty_cash_reconciliations_create.form.submit') }}
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
