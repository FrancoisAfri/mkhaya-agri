<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { date, dateTime, humanise, money, score } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  review: any
  evaluator: any
  bidders: any[]
  mayEvaluate: boolean
  coiStatuses: string[]
}>()

const declaring = ref(!props.evaluator.coi_declared_at)

const declarationForm = useForm({
  has_conflict: false,
  nature_of_interest: '',
  related_bidder_id: null as number | null,
  declaration_statement: '',
  accept_confidentiality: false,
})

const base = computed(() => `/procurement-governance/evaluations/${props.review.uuid}`)

/** The financial envelope stays shut until the technical stage closes on a two-envelope review. */
const priceHidden = computed(() =>
  props.review.methodology === 'TWO_ENVELOPE' && !props.review.evaluation_closed_at)

function submitDeclaration() {
  declarationForm.post(`${base.value}/declare`, {
    preserveScroll: true,
    onSuccess: () => { declaring.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="d-flex align-center ga-2 mb-1 flex-wrap">
        <span class="text-caption font-weight-bold text-medium-emphasis">{{ review.reference }}</span>
        <StatusChip :status="review.status" />
        <StatusChip :status="evaluator.coi_status" />
      </div>
      <h1 class="text-h5 font-weight-bold">{{ review.title }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t(`procurement_governance.tenders.methodologies.${review.methodology}`) }}
        <template v-if="review.evaluation_deadline">
          · {{ t('procurement_governance.tenders.deadline') }} {{ date(review.evaluation_deadline) }}
        </template>
      </p>
    </div>

    <!-- §1.3: no scoring before the declaration is complete, and the form is the first thing here. -->
    <VCard v-if="declaring || !mayEvaluate" elevation="0" border rounded="lg" class="mb-4">
      <VCardTitle class="text-body-1 font-weight-bold py-3">
        {{ t('procurement_governance.evaluations.declaration') }}
      </VCardTitle>
      <VCardText class="pt-0">
        <VAlert
          v-if="evaluator.coi_status === 'CONFLICT_IDENTIFIED'"
          type="error" variant="tonal" class="mb-3 text-body-2" icon="mdi-account-alert-outline"
        >
          {{ t('procurement_governance.evaluations.conflict_pending') }}
        </VAlert>

        <template v-else>
          <p class="text-body-2 mb-3">{{ t('procurement_governance.evaluations.declaration_intro') }}</p>

          <VRadioGroup v-model="declarationForm.has_conflict" density="compact" class="mb-2">
            <VRadio :value="false" :label="t('procurement_governance.evaluations.no_conflict')" />
            <VRadio :value="true" :label="t('procurement_governance.evaluations.have_conflict')" />
          </VRadioGroup>

          <template v-if="declarationForm.has_conflict">
            <VSelect
              v-model="declarationForm.related_bidder_id"
              :items="bidders.map(b => ({ title: b.supplier_name, value: b.id }))"
              :label="t('procurement_governance.evaluations.related_supplier')"
              variant="outlined" density="comfortable" clearable class="mb-3"
            />
            <VTextarea
              v-model="declarationForm.nature_of_interest"
              :label="t('procurement_governance.evaluations.nature_of_interest')"
              :error-messages="declarationForm.errors.nature_of_interest"
              variant="outlined" rows="3" auto-grow class="mb-3"
            />
          </template>

          <VCheckbox
            v-model="declarationForm.accept_confidentiality"
            :label="t('procurement_governance.evaluations.confidentiality_undertaking')"
            density="compact" hide-details class="mb-3"
          />

          <VBtn
            color="primary" :loading="declarationForm.processing"
            :disabled="declarationForm.processing
              || (declarationForm.has_conflict && !declarationForm.nature_of_interest.trim())"
            @click="submitDeclaration"
          >
            {{ t('procurement_governance.evaluations.submit_declaration') }}
          </VBtn>
        </template>
      </VCardText>
    </VCard>

    <VCard v-if="mayEvaluate" elevation="0" border rounded="lg">
      <VCardTitle class="text-body-1 font-weight-bold py-3">
        {{ t('procurement_governance.evaluations.bids_to_score') }}
      </VCardTitle>
      <VCardSubtitle v-if="priceHidden" class="text-caption pb-2">
        {{ t('procurement_governance.evaluations.price_hidden') }}
      </VCardSubtitle>

      <div v-if="!bidders.length" class="text-center py-10 px-4 text-medium-emphasis">
        <VIcon icon="mdi-domain-off" size="32" class="mb-2" />
        <div class="text-body-2">{{ t('procurement_governance.tenders.no_bids') }}</div>
      </div>

      <VList v-else density="comfortable">
        <VListItem v-for="bidder in bidders" :key="bidder.id">
          <VListItemTitle class="text-body-2 font-weight-medium">{{ bidder.supplier_name }}</VListItemTitle>
          <VListItemSubtitle class="text-caption">
            <template v-if="!priceHidden && bidder.bid_amount">
              {{ money(bidder.bid_amount) }} ·
            </template>
            <template v-if="bidder.evaluation_submitted_at">
              {{ t('procurement_governance.evaluations.submitted_on', {
                date: dateTime(bidder.evaluation_submitted_at),
              }) }}
              · {{ t('procurement_governance.evaluations.your_score', {
                score: score(bidder.total_weighted_score),
              }) }}
            </template>
            <template v-else>
              {{ humanise(bidder.evaluation_status ?? 'NOT_STARTED') }}
            </template>
          </VListItemSubtitle>

          <template #append>
            <div class="d-flex align-center ga-2">
              <StatusChip :status="bidder.evaluation_status ?? 'NOT_STARTED'" />
              <Link :href="`${base}/bidders/${bidder.uuid}`">
                <VBtn size="small" variant="tonal" color="primary">
                  {{ bidder.evaluation_status === 'SUBMITTED'
                    ? t('procurement_governance.common.view')
                    : t('procurement_governance.evaluations.score') }}
                </VBtn>
              </Link>
            </div>
          </template>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
