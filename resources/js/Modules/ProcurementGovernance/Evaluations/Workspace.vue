<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { dateTime, humanise, money, score } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  evaluation: any
  review: any
  bidder: any
  criteria: any[]
  editable: boolean
  history: any[]
  context: any
}>()

const confirmSubmit = ref(false)

/**
 * Local scores, keyed by criterion. Kept in a reactive map rather than the Inertia form so the
 * running total recomputes as the evaluator types, and only the map is sent on save.
 */
const scores = reactive<Record<number, { score: number | null; passed: boolean | null; comment: string; flagged: boolean }>>(
  Object.fromEntries(props.criteria.map(c => [c.id, {
    score: c.my_score !== null && c.my_score !== undefined ? Number(c.my_score) : null,
    passed: c.my_passed ?? null,
    comment: c.my_comment ?? '',
    flagged: !!c.my_flagged,
  }])),
)

const meta = reactive({
  overall_comment: props.evaluation.overall_comment ?? '',
  has_concerns: !!props.evaluation.has_concerns,
  concern_note: props.evaluation.concern_note ?? '',
})

const form = useForm({ scores: {} as any, ...meta })

const leaves = computed(() => props.criteria.filter(c => c.is_leaf))
const parentOf = (id: number | null) => props.criteria.find(c => c.id === id)

/**
 * The running weighted total, computed exactly as the server does: each criterion's ratio times its
 * effective weight. Showing a different number here than the one that gets stored would undermine
 * confidence in every figure on the page.
 */
const runningTotal = computed(() => leaves.value.reduce((total, criterion) => {
  const entry = scores[criterion.id]
  if (!entry || entry.score === null) return total

  const max = Number(criterion.max_score) || 100
  return total + (Number(entry.score) / max) * Number(criterion.effective_weight ?? 0)
}, 0))

const unscored = computed(() => leaves.value.filter(c => {
  const entry = scores[c.id]
  return c.is_pass_fail ? entry?.passed === null : entry?.score === null
}))

const base = computed(() =>
  `/procurement-governance/evaluations/${props.review.uuid}/bidders/${props.bidder.uuid}`)

function save() {
  form.scores = scores
  form.overall_comment = meta.overall_comment
  form.has_concerns = meta.has_concerns
  form.concern_note = meta.concern_note
  form.post(`${base.value}/draft`, { preserveScroll: true })
}

function submit() {
  form.scores = scores
  form.overall_comment = meta.overall_comment
  form.has_concerns = meta.has_concerns
  form.concern_note = meta.concern_note

  // Saved first, so a submit never discards edits made since the last save.
  form.post(`${base.value}/draft`, {
    preserveScroll: true,
    onSuccess: () => form.post(`${base.value}/submit`, { preserveScroll: true }),
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-4">
      <div class="min-width-0">
        <Link
          :href="`/procurement-governance/evaluations/${review.uuid}`"
          class="text-caption text-primary text-decoration-none d-inline-flex align-center mb-1"
        >
          <VIcon icon="mdi-arrow-left" size="14" class="me-1" />{{ review.reference }}
        </Link>
        <h1 class="text-h5 font-weight-bold">{{ bidder.supplier_name }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ review.title }}
          <template v-if="bidder.bid_amount"> · {{ money(bidder.bid_amount, bidder.currency) }}</template>
        </p>
      </div>
      <div class="text-end">
        <div class="text-caption text-medium-emphasis text-uppercase">
          {{ t('procurement_governance.evaluations.running_total') }}
        </div>
        <div class="text-h4 font-weight-bold">{{ score(runningTotal) }}</div>
        <StatusChip :status="evaluation.status" class="mt-1" />
      </div>
    </div>

    <!-- §1.5: submission locks the evaluation. Saying so before they submit, not after. -->
    <VAlert
      v-if="!editable" type="info" variant="tonal" class="mb-4" icon="mdi-lock-outline"
    >
      <div class="text-body-2 font-weight-medium mb-1">{{ t('procurement_governance.evaluations.locked_title') }}</div>
      <div class="text-body-2">
        {{ t('procurement_governance.evaluations.locked_body', {
          date: dateTime(evaluation.submitted_at), revision: evaluation.revision,
        }) }}
      </div>
    </VAlert>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard
          v-for="criterion in criteria.filter(c => !c.parent_id)" :key="criterion.id"
          elevation="0" border rounded="lg" class="mb-4"
        >
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap ga-2 py-3">
            <div class="min-width-0">
              <div class="text-body-1 font-weight-bold">{{ criterion.name }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ humanise(criterion.criterion_group) }} ·
                {{ t('procurement_governance.tenders.weight') }} {{ score(criterion.weight, '%') }}
                <template v-if="criterion.minimum_threshold">
                  · {{ t('procurement_governance.tenders.minimum') }} {{ score(criterion.minimum_threshold, '%') }}
                </template>
              </div>
            </div>
            <VChip v-if="criterion.is_mandatory" size="small" color="warning" variant="tonal" label>
              <VIcon icon="mdi-asterisk" start size="12" />{{ t('procurement_governance.tenders.mandatory') }}
            </VChip>
          </VCardTitle>

          <VCardText class="pt-0">
            <p v-if="criterion.description" class="text-body-2 text-medium-emphasis mb-3">
              {{ criterion.description }}
            </p>

            <!-- Both the parent (when it is itself scorable) and each child get a scoring row. -->
            <template v-for="target in [criterion, ...criteria.filter(c => c.parent_id === criterion.id)]" :key="target.id">
              <div v-if="target.is_leaf" class="procgov-score-row py-3">
                <div v-if="target.id !== criterion.id" class="text-body-2 font-weight-medium mb-2">
                  {{ target.name }}
                  <span class="text-caption text-medium-emphasis">
                    ({{ score(target.weight, '%') }} {{ t('procurement_governance.tenders.of_parent') }})
                  </span>
                </div>

                <VRow dense align="center">
                  <VCol cols="12" md="5">
                    <VRadioGroup
                      v-if="target.is_pass_fail"
                      v-model="scores[target.id].passed" :disabled="!editable"
                      inline density="compact" hide-details
                    >
                      <VRadio :value="true" :label="t('procurement_governance.common.passed')" />
                      <VRadio :value="false" :label="t('procurement_governance.common.failed')" />
                    </VRadioGroup>

                    <VTextField
                      v-else
                      v-model.number="scores[target.id].score" type="number"
                      :min="0" :max="Number(target.max_score)" step="0.01"
                      :disabled="!editable"
                      :label="t('procurement_governance.evaluations.score_out_of', { max: score(target.max_score) })"
                      variant="outlined" density="compact" hide-details
                    />
                  </VCol>
                  <VCol cols="12" md="7">
                    <VTextField
                      v-model="scores[target.id].comment" :disabled="!editable"
                      :label="t('procurement_governance.evaluations.motivation')"
                      variant="outlined" density="compact" hide-details
                    />
                  </VCol>
                </VRow>

                <VCheckbox
                  v-model="scores[target.id].flagged" :disabled="!editable"
                  :label="t('procurement_governance.evaluations.flag_concern')"
                  density="compact" hide-details class="mt-1"
                />
              </div>
            </template>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.evaluations.overall') }}
          </VCardTitle>
          <VCardText class="pt-0">
            <VTextarea
              v-model="meta.overall_comment" :disabled="!editable"
              :label="t('procurement_governance.evaluations.overall_comment')"
              variant="outlined" rows="3" auto-grow class="mb-2"
            />
            <VCheckbox
              v-model="meta.has_concerns" :disabled="!editable"
              :label="t('procurement_governance.evaluations.raise_concern')"
              density="compact" hide-details class="mb-2"
            />
            <VTextarea
              v-if="meta.has_concerns"
              v-model="meta.concern_note" :disabled="!editable"
              :label="t('procurement_governance.evaluations.concern_note')"
              :hint="t('procurement_governance.evaluations.concern_hint')" persistent-hint
              variant="outlined" rows="2" auto-grow
            />
          </VCardText>
        </VCard>

        <template v-if="editable">
          <VAlert
            v-if="unscored.length" type="info" variant="tonal"
            density="compact" class="mb-3 text-body-2"
          >
            {{ t('procurement_governance.evaluations.unscored', { n: unscored.length }) }}
          </VAlert>

          <VBtn
            block variant="tonal" class="mb-2" :loading="form.processing"
            prepend-icon="mdi-content-save-outline" @click="save"
          >
            {{ t('procurement_governance.evaluations.save_draft') }}
          </VBtn>
          <VBtn
            block color="primary" :disabled="!!unscored.length || form.processing"
            prepend-icon="mdi-send-outline" @click="confirmSubmit = true"
          >
            {{ t('procurement_governance.evaluations.submit') }}
          </VBtn>
          <p class="text-caption text-medium-emphasis mt-2 mb-0">
            {{ t('procurement_governance.evaluations.submit_hint') }}
          </p>
        </template>

        <!-- §1.6: the original scores survive a correction, and they are readable here. -->
        <VCard v-if="history.length" elevation="0" border rounded="lg" class="mt-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.evaluations.previous_revisions') }}
          </VCardTitle>
          <VList density="compact">
            <VListItem v-for="(entry, i) in history" :key="i">
              <VListItemTitle class="text-body-2">
                {{ entry.criterion_name ?? '—' }} — {{ score(entry.score) }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ t('procurement_governance.evaluations.revision_n', { n: entry.revision }) }}
                · {{ dateTime(entry.archived_at) }}
                <template v-if="entry.reason"> · {{ entry.reason }}</template>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="confirmSubmit" max-width="480">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">
          {{ t('procurement_governance.evaluations.confirm_submit_title') }}
        </VCardTitle>
        <VCardText class="text-body-2">
          {{ t('procurement_governance.evaluations.confirm_submit_body', { score: score(runningTotal) }) }}
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="confirmSubmit = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="confirmSubmit = false; submit()">
            {{ t('procurement_governance.evaluations.submit') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.min-width-0 { min-inline-size: 0; }
.procgov-score-row + .procgov-score-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
</style>
