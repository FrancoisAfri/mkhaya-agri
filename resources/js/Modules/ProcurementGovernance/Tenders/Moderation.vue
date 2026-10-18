<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { humanise, money, score } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  review: any
  moderation: any | null
  items: any[]
  comparison: any | null
  bidders: any[]
  varianceThreshold: number | null
  actions: string[]
  can: { moderate: boolean }
}>()

const acting = ref<any | null>(null)
const actionForm = useForm({ action: 'CLARIFY', comment: '' })

const base = computed(() => `/procurement-governance/tenders/${props.review.uuid}`)

const openItems = computed(() => props.items.filter(i => i.status !== 'RESOLVED'))

/**
 * The comparison matrix: one row per criterion, one column per evaluator, per bidder. This is the
 * first screen on which anybody sees other evaluators' scores — individual workspaces deliberately
 * never do (see EvaluationService).
 */
const matrix = computed(() => {
  if (!props.comparison?.rows?.length) return []

  const byBidder = new Map<number, any>()

  for (const row of props.comparison.rows) {
    if (!byBidder.has(row.bidder_id)) {
      byBidder.set(row.bidder_id, { bidder_id: row.bidder_id, name: row.supplier_name, criteria: new Map() })
    }

    const bidder = byBidder.get(row.bidder_id)

    if (!bidder.criteria.has(row.criterion_id)) {
      bidder.criteria.set(row.criterion_id, {
        name: row.criterion_name, max: row.criterion_max, scores: [] as any[],
      })
    }

    bidder.criteria.get(row.criterion_id).scores.push({
      evaluator: row.evaluator_name, score: row.score, flagged: row.flagged, comment: row.comment,
    })
  }

  return [...byBidder.values()].map(b => ({
    ...b,
    criteria: [...b.criteria.entries()].map(([id, c]) => ({ id, ...c })),
  }))
})

function act(item: any, action: string) {
  acting.value = item
  actionForm.action = action
  actionForm.comment = ''
}

function submitAction() {
  actionForm.post(`${base.value}/moderation/${acting.value.id}`, {
    preserveScroll: true,
    onSuccess: () => { acting.value = null; actionForm.reset() },
  })
}

function approveScores() {
  router.post(`${base.value}/approve-scores`, {}, { preserveScroll: true })
}

/** Spread across a criterion, as a share of its maximum — comparable across differing maxima. */
function spread(criterion: any): number | null {
  const values = criterion.scores.map((s: any) => s.score).filter((v: any) => v !== null)
  if (values.length < 2 || !criterion.max) return null

  return ((Math.max(...values) - Math.min(...values)) / criterion.max) * 100
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-4">
      <div>
        <div class="text-caption font-weight-bold text-medium-emphasis mb-1">{{ review.reference }}</div>
        <h1 class="text-h5 font-weight-bold">{{ t('procurement_governance.moderation.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('procurement_governance.moderation.subtitle') }}</p>
      </div>
      <VBtn
        v-if="can.moderate && moderation && !moderation.scores_approved_at"
        color="primary" prepend-icon="mdi-check-decagram-outline"
        :disabled="!!openItems.length"
        @click="approveScores"
      >
        {{ t('procurement_governance.moderation.approve_scores') }}
      </VBtn>
    </div>

    <VAlert
      v-if="moderation?.scores_approved_at" type="success" variant="tonal"
      class="mb-4" icon="mdi-check-decagram-outline"
    >
      {{ t('procurement_governance.moderation.scores_approved') }}
    </VAlert>
    <VAlert
      v-else-if="openItems.length" type="warning" variant="tonal"
      class="mb-4" icon="mdi-alert-outline"
    >
      {{ t('procurement_governance.moderation.open_items', { n: openItems.length }) }}
    </VAlert>

    <VRow>
      <VCol cols="12" lg="5">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.moderation.flagged_items') }}
          </VCardTitle>
          <VCardSubtitle v-if="varianceThreshold" class="text-caption pb-2">
            {{ t('procurement_governance.moderation.variance_hint', { pct: varianceThreshold }) }}
          </VCardSubtitle>

          <div v-if="!items.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-check-all" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.moderation.nothing_flagged') }}</div>
          </div>

          <VList v-else density="comfortable">
            <VListItem v-for="item in items" :key="item.id" class="align-start">
              <VListItemTitle class="text-body-2 font-weight-medium">
                {{ item.supplier_name ?? '—' }}
                <template v-if="item.criterion_name"> · {{ item.criterion_name }}</template>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ humanise(item.item_type) }}
                <template v-if="item.variance">
                  · {{ t('procurement_governance.moderation.spread', { pct: score(item.variance) }) }}
                  ({{ score(item.lowest_score) }}–{{ score(item.highest_score) }})
                </template>
              </VListItemSubtitle>
              <div v-if="item.moderator_comment" class="text-caption mt-1">{{ item.moderator_comment }}</div>
              <div v-if="item.evaluator_response" class="text-caption mt-1 font-italic">
                {{ item.evaluator_response }}
              </div>

              <div class="d-flex flex-wrap ga-1 mt-2">
                <StatusChip :status="item.status" size="x-small" />
                <template v-if="can.moderate && item.status !== 'RESOLVED'">
                  <VBtn
                    v-for="action in actions" :key="action"
                    size="x-small" variant="text"
                    :color="action === 'RE_EVALUATE' ? 'warning' : undefined"
                    @click="act(item, action)"
                  >
                    {{ t(`procurement_governance.moderation.actions.${action}`) }}
                  </VBtn>
                </template>
              </div>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="7">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.moderation.comparison') }}
          </VCardTitle>

          <div v-if="!matrix.length" class="text-center py-10 px-4 text-medium-emphasis">
            <VIcon icon="mdi-table-off" size="32" class="mb-2" />
            <div class="text-body-2">{{ t('procurement_governance.moderation.no_submissions') }}</div>
          </div>

          <VExpansionPanels v-else variant="accordion" class="procgov-panels">
            <VExpansionPanel v-for="bidder in matrix" :key="bidder.bidder_id">
              <VExpansionPanelTitle>
                <span class="text-body-2 font-weight-medium">{{ bidder.name }}</span>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <div class="procgov-scroll">
                  <VTable density="compact">
                    <thead>
                      <tr>
                        <th scope="col">{{ t('procurement_governance.tenders.criterion') }}</th>
                        <th scope="col" class="text-end">{{ t('procurement_governance.moderation.scores') }}</th>
                        <th scope="col" class="text-end">{{ t('procurement_governance.moderation.spread_col') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr
                        v-for="criterion in bidder.criteria" :key="criterion.id"
                        :class="{ 'procgov-flagged': (spread(criterion) ?? 0) >= (varianceThreshold ?? 100) }"
                      >
                        <td class="text-body-2">{{ criterion.name }}</td>
                        <td class="text-end">
                          <div class="d-flex justify-end flex-wrap ga-1">
                            <VTooltip v-for="(s, i) in criterion.scores" :key="i" location="top">
                              <template #activator="{ props: tip }">
                                <VChip
                                  v-bind="tip" size="x-small" label variant="tonal"
                                  :color="s.flagged ? 'warning' : undefined"
                                >
                                  {{ s.score ?? '—' }}
                                </VChip>
                              </template>
                              <div class="text-caption">
                                {{ s.evaluator }}<template v-if="s.comment"> — {{ s.comment }}</template>
                              </div>
                            </VTooltip>
                          </div>
                        </td>
                        <td class="text-end text-body-2">
                          {{ spread(criterion) === null ? '—' : score(spread(criterion), '%') }}
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </div>
              </VExpansionPanelText>
            </VExpansionPanel>
          </VExpansionPanels>
        </VCard>

        <VCard elevation="0" border rounded="lg" class="mt-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.moderation.current_ranking') }}
          </VCardTitle>
          <div class="procgov-scroll">
            <VTable density="compact">
              <thead>
                <tr>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.rank') }}</th>
                  <th scope="col">{{ t('procurement_governance.tenders.supplier') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.bid_amount') }}</th>
                  <th scope="col" class="text-end">{{ t('procurement_governance.tenders.weighted') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="bidder in bidders" :key="bidder.id">
                  <td class="text-end font-weight-bold">{{ bidder.overall_rank ?? '—' }}</td>
                  <td class="text-body-2">{{ bidder.supplier_name }}</td>
                  <td class="text-end text-body-2">{{ money(bidder.bid_amount, bidder.currency) }}</td>
                  <td class="text-end text-body-2">{{ score(bidder.weighted_score) }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <VDialog :model-value="!!acting" max-width="520" @update:model-value="acting = null">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t(`procurement_governance.moderation.actions.${actionForm.action}`) }}
        </VCardTitle>
        <VCardText>
          <!-- A re-evaluation reopens the evaluator's submitted work, so it must say why. -->
          <VAlert
            v-if="actionForm.action === 'RE_EVALUATE'" type="warning" variant="tonal"
            density="compact" class="mb-3 text-body-2"
          >
            {{ t('procurement_governance.moderation.reevaluate_warning') }}
          </VAlert>
          <VTextarea
            v-model="actionForm.comment"
            :label="t('procurement_governance.common.comment')"
            :error-messages="actionForm.errors.comment"
            variant="outlined" rows="3" auto-grow
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="acting = null">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="actionForm.processing"
            :disabled="['RE_EVALUATE', 'ADJUST'].includes(actionForm.action) && !actionForm.comment.trim()"
            @click="submitAction"
          >
            {{ t('procurement_governance.common.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.procgov-flagged td:first-child { box-shadow: inset 3px 0 0 0 rgb(var(--v-theme-warning)); }
.procgov-panels :deep(.v-expansion-panel-title) { min-block-size: 44px; }

/* WCAG 2.2 target size. A 20px row action is comfortable with a mouse and unusable with a thumb,
   so coarse pointers get a real 44px target while desktop keeps the density a register needs. */
@media (pointer: coarse) {
  :deep(.v-btn--size-x-small) { min-block-size: 44px; min-inline-size: 44px; }
}
</style>
