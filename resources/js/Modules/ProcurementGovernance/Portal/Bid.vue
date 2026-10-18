<script setup lang="ts">
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import FormErrors from '../Partials/FormErrors.vue'
import { dateTime } from '../Partials/format'

const { t } = useI18n()

/**
 * The bidder's own screen. Unauthenticated — the invitation token in the URL is the credential.
 *
 * Written for someone who has never seen this system and may never see it again: one page, one
 * form, no navigation, and a receipt they can keep. Every claim it makes about what happens to
 * their bid is one the server actually enforces, which is why the copy says "sealed until the
 * closing date" and not "encrypted and completely private" — the second would be overclaiming.
 */
const props = defineProps<{
  token: string
  tender: {
    reference: string
    tender_number: string | null
    title: string
    description: string | null
    currency: string
    closing_at: string | null
    closed: boolean
  }
  invitation: { supplier_name: string; contact_person: string | null; status: string }
  submission: { submitted_at: string; is_late: boolean; receipt: string; opened: boolean } | null
  currencies: string[]
}>()

const form = useForm({
  bid_amount: null as number | null,
  currency: props.tender.currency ?? 'ZAR',
  bid_reference: '',
  registration_number: '',
  tax_number: '',
  bbbee_level: null as number | null,
  is_local_content_compliant: false,
  notes: '',
  confirm: false,
})

/** A bid can still be submitted after closing — it is simply marked late, and the bidder is told. */
const canSubmit = computed(() => !props.submission)

function submit() {
  form.post(`/bid/${props.token}`, { preserveScroll: true })
}
</script>

<template>
  <VApp>
    <VMain class="bg-surface-variant">
      <VContainer class="py-8" style="max-width: 880px">
        <div class="mb-6">
          <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium">
            {{ tender.reference }}
            <template v-if="tender.tender_number"> · {{ tender.tender_number }}</template>
          </div>
          <h1 class="text-h5 font-weight-bold">{{ tender.title }}</h1>
          <p v-if="tender.description" class="text-body-2 text-medium-emphasis mb-0">
            {{ tender.description }}
          </p>
        </div>

        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardText class="d-flex flex-wrap justify-space-between ga-4">
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.portal.bidding_as') }}</div>
              <div class="text-body-1 font-weight-medium">{{ invitation.supplier_name }}</div>
            </div>
            <div>
              <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.portal.closes') }}</div>
              <div class="text-body-1 font-weight-medium">
                {{ tender.closing_at ? dateTime(tender.closing_at) : t('procurement_governance.portal.no_closing_date') }}
              </div>
            </div>
          </VCardText>
        </VCard>

        <!-- The receipt. Deliberately not a copy of the bid: a page that can display a sealed bid is
             a page anyone holding the token can display it on. -->
        <VCard v-if="submission" elevation="0" border rounded="lg" class="mb-4">
          <VCardText>
            <VAlert
              :type="submission.is_late ? 'warning' : 'success'" variant="tonal" class="mb-4"
              :icon="submission.is_late ? 'mdi-clock-alert-outline' : 'mdi-lock-check-outline'"
            >
              <div class="text-body-1 font-weight-medium mb-1">
                {{ submission.is_late
                  ? t('procurement_governance.portal.received_late')
                  : t('procurement_governance.portal.received') }}
              </div>
              <div class="text-body-2">
                {{ submission.is_late
                  ? t('procurement_governance.portal.received_late_body')
                  : t('procurement_governance.portal.received_body') }}
              </div>
            </VAlert>

            <VRow dense>
              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.portal.submitted_at') }}</div>
                <div class="text-body-2 font-weight-medium">{{ dateTime(submission.submitted_at) }}</div>
              </VCol>
              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis">{{ t('procurement_governance.portal.receipt') }}</div>
                <div class="text-body-2 font-weight-medium font-monospace">{{ submission.receipt }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ t('procurement_governance.portal.receipt_hint') }}
                </div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VCard v-if="canSubmit" elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.portal.your_bid') }}
          </VCardTitle>
          <VCardText>
            <VAlert
              v-if="tender.closed" type="warning" variant="tonal" density="compact" class="mb-4 text-body-2"
            >
              {{ t('procurement_governance.portal.closed_warning') }}
            </VAlert>

            <FormErrors :errors="form.errors" />

            <VRow dense>
              <VCol cols="12" sm="8">
                <VTextField
                  v-model.number="form.bid_amount" type="number" min="0" step="0.01"
                  :label="t('procurement_governance.portal.bid_amount')"
                  :error-messages="form.errors.bid_amount"
                  :suffix="form.currency"
                  variant="outlined" density="comfortable" autofocus
                />
              </VCol>
              <VCol cols="12" sm="4">
                <VSelect
                  v-model="form.currency" :items="currencies"
                  :label="t('procurement_governance.portal.currency')"
                  variant="outlined" density="comfortable"
                />
              </VCol>

              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.bid_reference"
                  :label="t('procurement_governance.portal.bid_reference')"
                  variant="outlined" density="comfortable"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.registration_number"
                  :label="t('procurement_governance.portal.registration_number')"
                  variant="outlined" density="comfortable"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.tax_number"
                  :label="t('procurement_governance.portal.tax_number')"
                  variant="outlined" density="comfortable"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model.number="form.bbbee_level" type="number" min="1" max="8"
                  :label="t('procurement_governance.portal.bbbee_level')"
                  :error-messages="form.errors.bbbee_level"
                  variant="outlined" density="comfortable"
                />
              </VCol>
              <VCol cols="12">
                <VCheckbox
                  v-model="form.is_local_content_compliant"
                  :label="t('procurement_governance.portal.local_content')"
                  density="compact" hide-details
                />
              </VCol>
              <VCol cols="12">
                <VTextarea
                  v-model="form.notes"
                  :label="t('procurement_governance.portal.notes')"
                  variant="outlined" rows="3" auto-grow
                />
              </VCol>

              <VCol cols="12">
                <VDivider class="mb-3" />
                <VCheckbox
                  v-model="form.confirm"
                  :label="t('procurement_governance.portal.confirm')"
                  :error-messages="form.errors.confirm"
                  density="compact"
                />
                <div class="text-caption text-medium-emphasis">
                  {{ t('procurement_governance.portal.confirm_hint') }}
                </div>
              </VCol>
            </VRow>
          </VCardText>
          <VCardActions class="px-4 pb-4">
            <VSpacer />
            <VBtn
              color="primary" size="large" prepend-icon="mdi-lock-outline"
              :loading="form.processing" :disabled="!form.confirm"
              @click="submit"
            >
              {{ t('procurement_governance.portal.seal_and_submit') }}
            </VBtn>
          </VCardActions>
        </VCard>

        <p class="text-caption text-medium-emphasis mt-6 mb-0">
          {{ t('procurement_governance.portal.footer') }}
        </p>
      </VContainer>
    </VMain>
  </VApp>
</template>

<style scoped>
.font-monospace { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: 0.05em; }
</style>
