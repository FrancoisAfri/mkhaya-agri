<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { count, date, dateTime, humanise, money, score } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  review: any
  bidders: any[]
  criteria: any[]
  weightProblems: string[]
  evaluators: any[]
  declarations: any[]
  outstandingCoi: any[]
  moderation: any | null
  moderationItems: any[]
  recommendations: any[]
  criteriaChanges: any[]
  nextStatuses: string[]
  groups: string[]
  scoringMethods: string[]
  suppliers: { id: number; name: string; bbbee_level: number | null }[]
  supplierModule: boolean
  committees: { id: number; name: string }[]
  unconvertibleCurrencies?: string[]
  invitations?: any[]
  officers?: { id: number; name: string }[]
  seal?: { opened_at: string | null; submissions_opened: number; blocked_because: string | null } | null
  can: { edit: boolean; moderate: boolean; recommend: boolean; approve: boolean }
}>()

const tab = ref('overview')
const showCriterion = ref(false)
const showBidder = ref(false)
const disqualifying = ref<any | null>(null)
const recommending = ref(false)

const frozen = computed(() => !!props.review.criteria_frozen_at)
const topLevel = computed(() => props.criteria.filter(c => !c.parent_id))
const childrenOf = (id: number) => props.criteria.filter(c => c.parent_id === id)

const criterionForm = useForm({
  parent_id: null as number | null,
  name: '',
  description: '',
  criterion_group: 'TECHNICAL',
  weight: 0,
  max_score: 100,
  scoring_method: 'POINTS',
  is_mandatory: false,
  minimum_threshold: null as number | null,
  sort_order: 0,
})

const bidderForm = useForm({
  supplier_id: null as number | null,
  supplier_name: '',
  registration_number: '',
  bid_reference: '',
  bid_amount: null as number | null,
  currency: props.review.currency ?? 'ZAR',
  submitted_at: '',
  bbbee_level: null as number | null,
  preference_points: null as number | null,
})

// §1.1 electronic intake.
const inviting = ref(false)
const opening = ref(false)
const withdrawing = ref<any | null>(null)

/**
 * The portal link, held only for as long as the page is open.
 *
 * Returned once by the server when the invitation is created and never rendered again on a later
 * load. The token is the bidder's entire credential, and one that stays on screen ends up in a
 * screenshot in a group chat.
 */
const portalLink = ref<string | null>(null)

const sealedCount = computed(
  () => (props.invitations ?? []).filter(i => i.submitted_at && !i.opened_at).length,
)

const inviteForm = useForm({
  supplier_id: null as number | null,
  supplier_name: '',
  contact_person: '',
  contact_email: '',
})

const openForm = useForm({ witnessed_by: null as number | null })

function sendInvite() {
  inviteForm.post(`${base.value}/invitations`, {
    preserveScroll: true,
    onSuccess: page => {
      inviting.value = false
      inviteForm.reset()
      portalLink.value = (page.props.flash as any)?.bid_portal_link ?? null
    },
  })
}

function copyLink() {
  if (portalLink.value) navigator.clipboard?.writeText(portalLink.value)
}

function confirmWithdraw() {
  router.delete(`${base.value}/invitations/${withdrawing.value.id}`, {
    preserveScroll: true,
    onFinish: () => { withdrawing.value = null },
  })
}

function confirmOpen() {
  openForm.post(`${base.value}/open-bids`, {
    preserveScroll: true,
    onSuccess: () => { opening.value = false; openForm.reset() },
  })
}

const disqualifyForm = useForm({ stage: 'ADMINISTRATIVE', reason: '' })
const committeeForm = useForm({ committee_id: props.review.committee_id ?? null })
const recommendForm = useForm({
  recommended_bidder_id: null as number | null,
  recommendation: '',
  committee_comments: '',
  award_conditions: '',
})

const base = computed(() => `/procurement-governance/tenders/${props.review.uuid}`)

function addCriterion() {
  criterionForm.post(`${base.value}/criteria`, {
    preserveScroll: true,
    onSuccess: () => { showCriterion.value = false; criterionForm.reset() },
  })
}

function addBidder() {
  bidderForm.post(`${base.value}/bidders`, {
    preserveScroll: true,
    onSuccess: () => { showBidder.value = false; bidderForm.reset() },
  })
}

function confirmDisqualify() {
  disqualifyForm.post(`${base.value}/bidders/${disqualifying.value.id}/disqualify`, {
    preserveScroll: true,
    onSuccess: () => { disqualifying.value = null; disqualifyForm.reset() },
  })
}

function openEvaluation() {
  router.post(`${base.value}/open-evaluation`, {}, { preserveScroll: true })
}

function recompute() {
  router.post(`${base.value}/recompute`, {}, { preserveScroll: true })
}

function runComplianceCheck() {
  router.post(`${base.value}/compliance-check`, {}, { preserveScroll: true })
}

function moveTo(status: string) {
  router.post(`${base.value}/transition`, { status }, { preserveScroll: true })
}

function generateRecommendation() {
  recommendForm.post(`${base.value}/recommendation`, {
    preserveScroll: true,
    onSuccess: () => { recommending.value = false; recommendForm.reset() },
  })
}

function assignCommittee() {
  committeeForm.post(`${base.value}/committee`, { preserveScroll: true })
}

/** Pre-filling the supplier name from the register saves retyping and keeps the two consistent. */
function onSupplierPicked(id: number | null) {
  const supplier = props.suppliers.find(s => s.id === id)
  if (supplier) {
    bidderForm.supplier_name = supplier.name
    bidderForm.bbbee_level = supplier.bbbee_level
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-4">
      <div class="min-width-0">
        <div class="d-flex align-center ga-2 mb-1 flex-wrap">
          <span class="text-caption font-weight-bold text-medium-emphasis">{{ review.reference }}</span>
          <StatusChip :status="review.status" />
          <VChip v-if="frozen" size="small" variant="tonal" color="blue-grey" label>
            <VIcon icon="mdi-lock-outline" start size="14" />
            {{ t('procurement_governance.tenders.criteria_frozen') }}
          </VChip>
        </div>
        <h1 class="text-h5 font-weight-bold">{{ review.title }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t(`procurement_governance.tenders.methodologies.${review.methodology}`) }}
          <template v-if="review.tender_number"> · {{ review.tender_number }}</template>
          <template v-if="review.evaluation_deadline">
            · {{ t('procurement_governance.tenders.deadline') }} {{ date(review.evaluation_deadline) }}
          </template>
        </p>
      </div>

      <div class="d-flex flex-wrap ga-2">
        <VBtn size="small" variant="text" prepend-icon="mdi-shield-check-outline" @click="runComplianceCheck">
          {{ t('procurement_governance.common.compliance_check') }}
        </VBtn>
        <VBtn
          v-if="!frozen && can.edit" color="primary" size="small"
          prepend-icon="mdi-play-circle-outline" @click="openEvaluation"
        >
          {{ t('procurement_governance.tenders.open_evaluation') }}
        </VBtn>
        <Link v-if="frozen && can.moderate" :href="`${base}/moderation`">
          <VBtn color="primary" size="small" variant="tonal" prepend-icon="mdi-scale-balance">
            {{ t('procurement_governance.tenders.moderation') }}
          </VBtn>
        </Link>
        <VBtn
          v-for="status in nextStatuses.filter(s => s !== 'CANCELLED')" :key="status"
          size="small" variant="outlined" @click="moveTo(status)"
        >
          {{ humanise(status) }}
        </VBtn>
      </div>
    </div>

    <!-- §1.3: an unresolved conflict blocks evaluation, so it is stated at the top, not buried. -->
    <VAlert
      v-if="outstandingCoi.length" type="warning" variant="tonal"
      class="mb-4" icon="mdi-account-alert-outline"
    >
      <div class="text-body-2 font-weight-medium mb-1">
        {{ t('procurement_governance.tenders.coi_outstanding_title', { n: outstandingCoi.length }) }}
      </div>
      <div class="text-body-2">{{ t('procurement_governance.tenders.coi_outstanding_body') }}</div>
    </VAlert>

    <VAlert v-if="weightProblems.length" type="error" variant="tonal" class="mb-4" icon="mdi-scale-unbalanced">
      <div class="text-body-2 font-weight-medium mb-1">{{ t('procurement_governance.tenders.weights_problem') }}</div>
      <ul class="text-body-2 ps-4 mb-0">
        <li v-for="(problem, i) in weightProblems" :key="i">{{ problem }}</li>
      </ul>
    </VAlert>

    <!-- §1.6. Raised here, on the page, rather than at the moment someone presses "recompute
         scores" and the ranking refuses. A missing exchange rate is a five-minute fix in Finance on
         the day bids open, and a crisis on the afternoon the committee sits. -->
    <VAlert
      v-if="unconvertibleCurrencies?.length" type="error" variant="tonal" class="mb-4"
      icon="mdi-currency-usd-off"
    >
      <div class="text-body-2 font-weight-medium mb-1">
        {{ t('procurement_governance.tenders.unconvertible_title') }}
      </div>
      <div class="text-body-2">
        {{ t('procurement_governance.tenders.unconvertible_body', {
          currencies: unconvertibleCurrencies.join(', '),
          target: review.currency,
        }) }}
      </div>
    </VAlert>

    <VTabs v-model="tab" class="mb-4" density="comfortable">
      <VTab value="intake">
        {{ t('procurement_governance.intake.tab') }}
        <VBadge v-if="sealedCount" color="primary" :content="sealedCount" inline />
      </VTab>
      <VTab value="overview">{{ t('procurement_governance.tenders.tabs.bidders') }}</VTab>
      <VTab value="criteria">{{ t('procurement_governance.tenders.tabs.criteria') }}</VTab>
      <VTab value="committee">{{ t('procurement_governance.tenders.tabs.committee') }}</VTab>
      <VTab value="recommendation">{{ t('procurement_governance.tenders.tabs.recommendation') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- ── §1.1 electronic bid intake ──────────────────────────────────────────────────────── -->
      <VWindowItem value="intake">
        <VAlert type="info" variant="tonal" class="mb-4 text-body-2" icon="mdi-lock-outline">
          {{ t('procurement_governance.intake.explainer') }}
        </VAlert>

        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.intake.invited_bidders') }}
            <VBtn
              v-if="can.edit && !seal?.opened_at" color="primary" variant="tonal" size="small"
              prepend-icon="mdi-account-plus-outline" @click="inviting = true"
            >
              {{ t('procurement_governance.intake.invite') }}
            </VBtn>
          </VCardTitle>

          <!-- The link is returned once, on creation, and never rendered again. A credential that
               stays on screen ends up in a screenshot in a group chat. -->
          <VCardText v-if="portalLink" class="pt-0">
            <VAlert type="success" variant="tonal" class="text-body-2">
              <div class="font-weight-medium mb-1">{{ t('procurement_governance.intake.link_ready') }}</div>
              <div class="d-flex align-center ga-2 flex-wrap">
                <code class="text-caption text-break">{{ portalLink }}</code>
                <VBtn size="small" variant="text" prepend-icon="mdi-content-copy" @click="copyLink">
                  {{ t('procurement_governance.intake.copy_link') }}
                </VBtn>
              </div>
              <div class="text-caption mt-1">{{ t('procurement_governance.intake.link_once') }}</div>
            </VAlert>
          </VCardText>

          <div v-if="!invitations?.length" class="text-center py-10 text-medium-emphasis text-body-2">
            {{ t('procurement_governance.intake.none_invited') }}
          </div>

          <div v-else class="procgov-scroll">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th scope="col">{{ t('procurement_governance.tenders.supplier') }}</th>
                  <th scope="col" class="d-none d-md-table-cell">{{ t('procurement_governance.intake.contact') }}</th>
                  <th scope="col">{{ t('procurement_governance.common.status') }}</th>
                  <th scope="col" class="d-none d-lg-table-cell">{{ t('procurement_governance.intake.submitted') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.common.actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="invitation in invitations" :key="invitation.id">
                  <td class="text-body-2 font-weight-medium">{{ invitation.supplier_name }}</td>
                  <td class="text-body-2 text-medium-emphasis d-none d-md-table-cell">
                    {{ invitation.contact_person || invitation.contact_email || '—' }}
                  </td>
                  <td>
                    <StatusChip
                      :status="invitation.submitted_at ? (invitation.opened_at ? 'OPENED' : 'SEALED') : 'INVITED'"
                      :label="invitation.submitted_at
                        ? (invitation.opened_at
                          ? t('procurement_governance.intake.opened')
                          : t('procurement_governance.intake.sealed'))
                        : t('procurement_governance.intake.awaiting')"
                    />
                    <div v-if="invitation.is_late" class="text-caption text-error">
                      {{ t('procurement_governance.tenders.late_bid') }}
                    </div>
                  </td>
                  <td class="text-body-2 text-medium-emphasis d-none d-lg-table-cell">
                    {{ invitation.submitted_at ? dateTime(invitation.submitted_at) : '—' }}
                  </td>
                  <td class="text-end">
                    <VBtn
                      v-if="can.edit && invitation.submitted_at && !invitation.opened_at"
                      size="x-small" variant="text" color="error"
                      @click="withdrawing = invitation"
                    >
                      {{ t('procurement_governance.intake.withdraw') }}
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>

        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.intake.opening') }}
          </VCardTitle>
          <VCardText class="pt-0">
            <VAlert
              v-if="seal?.opened_at" type="success" variant="tonal" class="text-body-2" icon="mdi-lock-open-check-outline"
            >
              {{ t('procurement_governance.intake.already_opened', {
                n: seal.submissions_opened,
                at: dateTime(seal.opened_at),
              }) }}
            </VAlert>

            <template v-else>
              <p class="text-body-2 text-medium-emphasis">
                {{ t('procurement_governance.intake.opening_explainer') }}
              </p>

              <!-- Why it cannot happen yet, in words. A greyed-out button with no explanation is
                   how a committee ends up ringing IT while sitting in a room together. -->
              <VAlert
                v-if="seal?.blocked_because" type="warning" variant="tonal" density="compact"
                class="text-body-2 mb-3"
              >
                {{ seal.blocked_because }}
              </VAlert>

              <VBtn
                v-if="can.moderate" color="primary" prepend-icon="mdi-lock-open-outline"
                :disabled="!!seal?.blocked_because" @click="opening = true"
              >
                {{ t('procurement_governance.intake.open_bids') }}
              </VBtn>
            </template>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- ── Bidders and ranking (§1.6) ──────────────────────────────────────────────────────── -->
      <VWindowItem value="overview">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.tenders.participating_suppliers') }}
            <div class="d-flex ga-2">
              <VBtn size="small" variant="text" prepend-icon="mdi-calculator" @click="recompute">
                {{ t('procurement_governance.tenders.recompute') }}
              </VBtn>
              <VBtn
                v-if="can.edit && !frozen" size="small" color="primary" variant="tonal"
                prepend-icon="mdi-plus" @click="showBidder = true"
              >
                {{ t('procurement_governance.tenders.add_bid') }}
              </VBtn>
            </div>
          </VCardTitle>

          <div v-if="!bidders.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-domain-off" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.tenders.no_bids') }}</div>
          </div>

          <div v-else class="procgov-scroll">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th scope="col" class="text-end" style="width: 70px">{{ t('procurement_governance.tenders.rank') }}</th>
                  <th scope="col">{{ t('procurement_governance.tenders.supplier') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.bid_amount') }}</th>
                  <th scope="col" class="text-end d-none d-md-table-cell">{{ t('procurement_governance.tenders.technical') }}</th>
                  <th scope="col" class="text-end d-none d-md-table-cell">{{ t('procurement_governance.tenders.financial') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.weighted') }}</th>
                  <th scope="col" class="d-none d-lg-table-cell">{{ t('procurement_governance.tenders.threshold') }}</th>
                  <th scope="col">{{ t('procurement_governance.common.status') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.common.actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="bidder in bidders" :key="bidder.id" :class="{ 'procgov-flagged': bidder.is_disqualified }">
                  <td class="text-end font-weight-bold">{{ bidder.overall_rank ?? '—' }}</td>
                  <td>
                    <div class="text-body-2 font-weight-medium">{{ bidder.supplier_name }}</div>
                    <div v-if="bidder.is_late" class="text-caption text-error">
                      {{ t('procurement_governance.tenders.late_bid') }}
                    </div>
                    <div v-if="bidder.disqualification_reason" class="text-caption text-medium-emphasis">
                      {{ bidder.disqualification_reason }}
                    </div>
                  </td>
                  <td class="text-end text-body-2">
                    <div>{{ money(bidder.bid_amount, bidder.currency) }}</div>
                    <!-- A bid in another currency is ranked on its converted value, so the converted
                         value is what the table has to show. Printing only the amount as bid leaves
                         a reader unable to see why the ordering is what it is. -->
                    <div
                      v-if="bidder.bid_amount_base !== null && bidder.currency !== review.currency"
                      class="text-caption text-medium-emphasis"
                    >
                      = {{ money(bidder.bid_amount_base, review.currency) }}
                      <VTooltip activator="parent" location="top">
                        {{ t('procurement_governance.tenders.converted_at', {
                          rate: Number(bidder.fx_rate).toFixed(4),
                          from: bidder.currency,
                          to: review.currency,
                          date: bidder.fx_rate_date,
                        }) }}
                      </VTooltip>
                    </div>
                  </td>
                  <td class="text-end text-body-2 d-none d-md-table-cell">{{ score(bidder.technical_score) }}</td>
                  <td class="text-end text-body-2 d-none d-md-table-cell">{{ score(bidder.financial_score) }}</td>
                  <td class="text-end text-body-2 font-weight-bold">{{ score(bidder.weighted_score) }}</td>
                  <td class="d-none d-lg-table-cell">
                    <StatusChip
                      v-if="bidder.threshold_passed !== null"
                      :status="bidder.threshold_passed ? 'PASS' : 'FAIL'"
                      :label="bidder.threshold_passed
                        ? t('procurement_governance.common.passed')
                        : t('procurement_governance.common.failed')"
                    />
                    <span v-else class="text-medium-emphasis">—</span>
                  </td>
                  <td><StatusChip :status="bidder.status" /></td>
                  <td class="text-end">
                    <VBtn
                      v-if="can.edit && !bidder.is_disqualified"
                      size="x-small" variant="text" color="error"
                      @click="disqualifying = bidder"
                    >
                      {{ t('procurement_governance.tenders.disqualify') }}
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VWindowItem>

      <!-- ── Criteria (§1.4) ─────────────────────────────────────────────────────────────────── -->
      <VWindowItem value="criteria">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.tenders.evaluation_criteria') }}
            <VBtn
              v-if="can.edit && !frozen" size="small" color="primary" variant="tonal"
              prepend-icon="mdi-plus" @click="showCriterion = true"
            >
              {{ t('procurement_governance.tenders.add_criterion') }}
            </VBtn>
          </VCardTitle>

          <VAlert
            v-if="frozen" type="info" variant="tonal" density="compact"
            class="mx-4 mb-3 text-body-2" icon="mdi-lock-outline"
          >
            {{ t('procurement_governance.tenders.frozen_explainer', { at: dateTime(review.criteria_frozen_at) }) }}
          </VAlert>

          <div v-if="!criteria.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-format-list-checks" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.tenders.no_criteria') }}</div>
          </div>

          <div v-else class="procgov-scroll">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th scope="col">{{ t('procurement_governance.tenders.criterion') }}</th>
                  <th scope="col">{{ t('procurement_governance.tenders.group') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.weight') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.max_score') }}</th>
                  <th scope="col" class="d-none d-md-table-cell">{{ t('procurement_governance.tenders.scoring_method') }}</th>
                  <th scope="col" class="text-end d-none d-md-table-cell">{{ t('procurement_governance.tenders.minimum') }}</th>
                  <th scope="col">{{ t('procurement_governance.tenders.mandatory') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="parent in topLevel" :key="parent.id">
                  <tr>
                    <td class="text-body-2 font-weight-medium">{{ parent.name }}</td>
                    <td class="text-body-2">{{ humanise(parent.criterion_group) }}</td>
                    <td class="text-end text-body-2">{{ score(parent.weight, '%') }}</td>
                    <td class="text-end text-body-2">{{ score(parent.max_score) }}</td>
                    <td class="text-body-2 d-none d-md-table-cell">{{ humanise(parent.scoring_method) }}</td>
                    <td class="text-end text-body-2 d-none d-md-table-cell">
                      {{ parent.minimum_threshold === null ? '—' : score(parent.minimum_threshold, '%') }}
                    </td>
                    <td>
                      <VIcon
                        :icon="parent.is_mandatory ? 'mdi-check-circle-outline' : 'mdi-minus'"
                        :color="parent.is_mandatory ? 'warning' : 'grey'" size="18"
                        :aria-label="parent.is_mandatory
                          ? t('procurement_governance.tenders.mandatory')
                          : t('procurement_governance.common.no')"
                      />
                    </td>
                  </tr>
                  <tr v-for="child in childrenOf(parent.id)" :key="child.id" class="procgov-child-row">
                    <td class="text-body-2 ps-8">↳ {{ child.name }}</td>
                    <td class="text-body-2">{{ humanise(child.criterion_group) }}</td>
                    <td class="text-end text-body-2">{{ score(child.weight, '%') }}</td>
                    <td class="text-end text-body-2">{{ score(child.max_score) }}</td>
                    <td class="text-body-2 d-none d-md-table-cell">{{ humanise(child.scoring_method) }}</td>
                    <td class="text-end text-body-2 d-none d-md-table-cell">
                      {{ child.minimum_threshold === null ? '—' : score(child.minimum_threshold, '%') }}
                    </td>
                    <td>
                      <VIcon
                        :icon="child.is_mandatory ? 'mdi-check-circle-outline' : 'mdi-minus'"
                        :color="child.is_mandatory ? 'warning' : 'grey'" size="18"
                      />
                    </td>
                  </tr>
                </template>
              </tbody>
            </VTable>
          </div>
        </VCard>

        <VCard v-if="criteriaChanges.length" elevation="0" border rounded="lg" class="mt-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.tenders.criteria_changes') }}
          </VCardTitle>
          <VList density="compact">
            <VListItem v-for="change in criteriaChanges" :key="change.id">
              <VListItemTitle class="text-body-2">
                {{ humanise(change.change_type) }} — {{ change.reason }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ dateTime(change.requested_at) }} · {{ humanise(change.status) }}
                <template v-if="change.requires_rescoring">
                  · {{ t('procurement_governance.tenders.requires_rescoring') }}
                </template>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>

      <!-- ── Committee and declarations (§1.2, §1.3) ─────────────────────────────────────────── -->
      <VWindowItem value="committee">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.tenders.evaluators') }}
            <div v-if="can.edit && !frozen" class="d-flex ga-2 align-center">
              <VSelect
                v-model="committeeForm.committee_id"
                :items="committees.map(c => ({ title: c.name, value: c.id }))"
                :label="t('procurement_governance.tenders.committee')"
                density="compact" variant="outlined" hide-details style="min-inline-size: 240px"
              />
              <VBtn size="small" color="primary" variant="tonal" @click="assignCommittee">
                {{ t('procurement_governance.tenders.assign') }}
              </VBtn>
            </div>
          </VCardTitle>

          <div v-if="!evaluators.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-account-group-outline" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.tenders.no_evaluators') }}</div>
          </div>

          <div v-else class="procgov-scroll">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th scope="col">{{ t('procurement_governance.tenders.member') }}</th>
                  <th scope="col">{{ t('procurement_governance.tenders.role') }}</th>
                  <th scope="col">{{ t('procurement_governance.tenders.coi') }}</th>
                  <th scope="col" class="d-none d-md-table-cell">{{ t('procurement_governance.tenders.confidentiality') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.progress') }}</th>
                  <th scope="col">{{ t('procurement_governance.common.status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="evaluator in evaluators" :key="evaluator.id">
                  <td class="text-body-2">
                    {{ evaluator.display_name }}
                    <VChip v-if="evaluator.is_external" size="x-small" variant="tonal" class="ms-1" label>
                      {{ t('procurement_governance.tenders.external') }}
                    </VChip>
                  </td>
                  <td class="text-body-2">{{ humanise(evaluator.member_role) }}</td>
                  <td><StatusChip :status="evaluator.coi_status" /></td>
                  <td class="d-none d-md-table-cell text-body-2">
                    {{ evaluator.confidentiality_accepted_at
                      ? t('procurement_governance.tenders.signed')
                      : t('procurement_governance.tenders.not_signed') }}
                  </td>
                  <td class="text-end text-body-2">{{ evaluator.submitted }} / {{ evaluator.expected }}</td>
                  <td><StatusChip :status="evaluator.status" /></td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>

        <VCard v-if="declarations.length" elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.tenders.declarations') }}
          </VCardTitle>
          <VList density="compact">
            <VListItem v-for="declaration in declarations" :key="declaration.uuid">
              <template #prepend>
                <VIcon
                  :icon="declaration.has_conflict ? 'mdi-alert-circle-outline' : 'mdi-check-circle-outline'"
                  :color="declaration.has_conflict ? 'error' : 'success'" size="20"
                />
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium">
                {{ declaration.declared_by }} — {{ humanise(declaration.status) }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ dateTime(declaration.declared_at) }}
                <template v-if="declaration.related_supplier_name"> · {{ declaration.related_supplier_name }}</template>
                <template v-if="declaration.resolution"> · {{ humanise(declaration.resolution) }}</template>
              </VListItemSubtitle>
              <div v-if="declaration.nature_of_interest" class="text-caption mt-1">
                {{ declaration.nature_of_interest }}
              </div>
              <template v-if="declaration.has_conflict && !declaration.resolution" #append>
                <VBtn
                  size="x-small" variant="tonal" color="primary"
                  :href="`/procurement-governance/declarations/${declaration.uuid}/resolve`"
                  @click.prevent="router.post(
                    `/procurement-governance/declarations/${declaration.uuid}/resolve`,
                    { resolution: 'CLEARED', note: t('procurement_governance.tenders.cleared_note') },
                    { preserveScroll: true },
                  )"
                >
                  {{ t('procurement_governance.tenders.clear_conflict') }}
                </VBtn>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>

      <!-- ── Recommendation (§1.8) ───────────────────────────────────────────────────────────── -->
      <VWindowItem value="recommendation">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.tenders.recommendation_reports') }}
            <VBtn
              v-if="can.recommend" size="small" color="primary" variant="tonal"
              prepend-icon="mdi-file-document-outline" @click="recommending = true"
            >
              {{ t('procurement_governance.tenders.generate_report') }}
            </VBtn>
          </VCardTitle>

          <div v-if="!recommendations.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-file-document-outline" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.tenders.no_recommendation') }}</div>
          </div>

          <VList v-else density="comfortable">
            <VListItem v-for="rec in recommendations" :key="rec.id">
              <VListItemTitle class="text-body-2 font-weight-medium">
                {{ rec.reference }} v{{ rec.version }} — {{ rec.recommended_supplier_name }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ money(rec.recommended_amount, review.currency) }} · {{ dateTime(rec.generated_at) }}
              </VListItemSubtitle>
              <template #append>
                <div class="d-flex align-center ga-2">
                  <StatusChip :status="rec.status" />
                  <VBtn
                    size="x-small" variant="text" prepend-icon="mdi-file-pdf-box"
                    :href="`${base}/recommendation/${rec.id}/pdf`"
                  >
                    {{ t('procurement_governance.common.pdf') }}
                  </VBtn>
                  <VBtn
                    v-if="rec.status === 'DRAFT' && can.recommend"
                    size="x-small" variant="tonal" color="primary"
                    @click="router.post(`${base}/recommendation/${rec.id}/submit`, {}, { preserveScroll: true })"
                  >
                    {{ t('procurement_governance.common.submit') }}
                  </VBtn>
                </div>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- ── Dialogs ─────────────────────────────────────────────────────────────────────────── -->
    <VDialog v-model="showCriterion" max-width="640">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.tenders.add_criterion') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="criterionForm.parent_id"
                :items="[{ title: t('procurement_governance.tenders.top_level'), value: null },
                         ...topLevel.map(c => ({ title: c.name, value: c.id }))]"
                :label="t('procurement_governance.tenders.parent')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="criterionForm.name" :label="t('procurement_governance.tenders.criterion')"
                :error-messages="criterionForm.errors.name" variant="outlined" density="comfortable" required
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="criterionForm.criterion_group"
                :items="groups.map(g => ({ title: humanise(g), value: g }))"
                :label="t('procurement_governance.tenders.group')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="criterionForm.scoring_method"
                :items="scoringMethods.map(m => ({ title: humanise(m), value: m }))"
                :label="t('procurement_governance.tenders.scoring_method')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField
                v-model.number="criterionForm.weight" type="number" min="0" max="100" step="0.01"
                :label="t('procurement_governance.tenders.weight')"
                :hint="t('procurement_governance.tenders.weight_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField
                v-model.number="criterionForm.max_score" type="number" min="1"
                :label="t('procurement_governance.tenders.max_score')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model.number="criterionForm.minimum_threshold" type="number" min="0" max="100"
                :label="t('procurement_governance.tenders.minimum')"
                variant="outlined" density="comfortable" clearable
              />
            </VCol>
            <VCol cols="12">
              <VCheckbox
                v-model="criterionForm.is_mandatory"
                :label="t('procurement_governance.tenders.is_mandatory')"
                density="compact" hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="showCriterion = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="criterionForm.processing" @click="addCriterion">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="inviting" max-width="560">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.intake.invite') }}
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('procurement_governance.intake.invite_hint') }}</p>
          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="inviteForm.supplier_name"
                :label="t('procurement_governance.tenders.supplier')"
                :error-messages="inviteForm.errors.supplier_name"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="inviteForm.contact_person"
                :label="t('procurement_governance.intake.contact_person')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="inviteForm.contact_email" type="email"
                :label="t('procurement_governance.intake.contact_email')"
                :error-messages="inviteForm.errors.contact_email"
                variant="outlined" density="comfortable"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="inviting = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="inviteForm.processing" @click="sendInvite">
            {{ t('procurement_governance.intake.create_invitation') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="opening" max-width="600">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.intake.open_bids') }}
        </VCardTitle>
        <VCardText>
          <!-- Irreversible, and it happens once for every bid at the same moment. Saying so before
               the button is pressed is the whole reason this is a dialog and not an inline action. -->
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4 text-body-2">
            {{ t('procurement_governance.intake.open_warning') }}
          </VAlert>

          <VSelect
            v-model="openForm.witnessed_by"
            :items="(officers ?? []).map(o => ({ title: o.name, value: o.id }))"
            :label="t('procurement_governance.intake.witness')"
            :hint="t('procurement_governance.intake.witness_hint')" persistent-hint
            :error-messages="openForm.errors.witnessed_by"
            variant="outlined" density="comfortable"
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="opening = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn
            color="primary" variant="tonal" :loading="openForm.processing"
            :disabled="!openForm.witnessed_by" @click="confirmOpen"
          >
            {{ t('procurement_governance.intake.confirm_open') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!withdrawing" max-width="520" @update:model-value="withdrawing = null">
      <VCard v-if="withdrawing">
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.intake.withdraw_named', { name: withdrawing.supplier_name }) }}
        </VCardTitle>
        <VCardText class="text-body-2">
          {{ t('procurement_governance.intake.withdraw_body') }}
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="withdrawing = null">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="error" variant="tonal" @click="confirmWithdraw">
            {{ t('procurement_governance.intake.withdraw') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="showBidder" max-width="640">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.tenders.add_bid') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol v-if="supplierModule" cols="12">
              <VSelect
                v-model="bidderForm.supplier_id"
                :items="suppliers.map(s => ({ title: s.name, value: s.id }))"
                :label="t('procurement_governance.tenders.from_register')"
                variant="outlined" density="comfortable" clearable
                @update:model-value="onSupplierPicked"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="bidderForm.supplier_name" :label="t('procurement_governance.tenders.supplier')"
                :error-messages="bidderForm.errors.supplier_name" variant="outlined" density="comfortable" required
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="bidderForm.bid_amount" type="number" min="0" step="0.01"
                :label="t('procurement_governance.tenders.bid_amount')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="bidderForm.submitted_at" type="datetime-local"
                :label="t('procurement_governance.tenders.submitted_at')"
                :hint="t('procurement_governance.tenders.submitted_at_hint')" persistent-hint
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="bidderForm.bid_reference" :label="t('procurement_governance.tenders.bid_reference')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField
                v-model.number="bidderForm.bbbee_level" type="number" min="1" max="8"
                :label="t('procurement_governance.tenders.bbbee_level')"
                variant="outlined" density="comfortable"
              />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField
                v-model.number="bidderForm.preference_points" type="number" min="0" max="100"
                :label="t('procurement_governance.tenders.preference_points')"
                variant="outlined" density="comfortable"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="showBidder = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="bidderForm.processing" @click="addBidder">
            {{ t('procurement_governance.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!disqualifying" max-width="520" @update:model-value="disqualifying = null">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.tenders.disqualify_supplier', { name: disqualifying?.supplier_name }) }}
        </VCardTitle>
        <VCardText>
          <VSelect
            v-model="disqualifyForm.stage"
            :items="['ADMINISTRATIVE', 'TECHNICAL', 'FINANCIAL'].map(s => ({ title: humanise(s), value: s }))"
            :label="t('procurement_governance.tenders.disqualification_stage')"
            variant="outlined" density="comfortable" class="mb-3"
          />
          <VTextarea
            v-model="disqualifyForm.reason"
            :label="t('procurement_governance.tenders.disqualification_reason')"
            :hint="t('procurement_governance.tenders.disqualification_reason_hint')" persistent-hint
            :error-messages="disqualifyForm.errors.reason"
            variant="outlined" rows="3" auto-grow
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="disqualifying = null">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn
            color="error" :loading="disqualifyForm.processing"
            :disabled="disqualifyForm.reason.trim().length < 10"
            @click="confirmDisqualify"
          >
            {{ t('procurement_governance.tenders.disqualify') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="recommending" max-width="680" scrollable>
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.tenders.generate_report') }}
        </VCardTitle>
        <VCardText>
          <VSelect
            v-model="recommendForm.recommended_bidder_id"
            :items="bidders.filter(b => !b.is_disqualified).map(b => ({
              title: `${b.supplier_name} — ${score(b.weighted_score)}`, value: b.id,
            }))"
            :label="t('procurement_governance.tenders.recommended_supplier')"
            :hint="t('procurement_governance.tenders.recommended_supplier_hint')" persistent-hint
            variant="outlined" density="comfortable" clearable class="mb-3"
          />
          <VTextarea
            v-model="recommendForm.recommendation" :label="t('procurement_governance.tenders.recommendation_text')"
            variant="outlined" rows="3" auto-grow class="mb-3"
          />
          <VTextarea
            v-model="recommendForm.committee_comments" :label="t('procurement_governance.tenders.committee_comments')"
            variant="outlined" rows="2" auto-grow class="mb-3"
          />
          <VTextarea
            v-model="recommendForm.award_conditions" :label="t('procurement_governance.tenders.award_conditions')"
            variant="outlined" rows="2" auto-grow
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="recommending = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="recommendForm.processing" @click="generateRecommendation">
            {{ t('procurement_governance.tenders.generate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.min-width-0 { min-inline-size: 0; }
.procgov-flagged td:first-child { box-shadow: inset 3px 0 0 0 rgb(var(--v-theme-error)); }
.procgov-child-row { background: rgba(var(--v-theme-on-surface), 0.02); }

/* WCAG 2.2 target size. A 20px row action is comfortable with a mouse and unusable with a thumb,
   so coarse pointers get a real 44px target while desktop keeps the density a register needs. */
@media (pointer: coarse) {
  :deep(.v-btn--size-x-small) { min-block-size: 44px; min-inline-size: 44px; }
}
</style>
