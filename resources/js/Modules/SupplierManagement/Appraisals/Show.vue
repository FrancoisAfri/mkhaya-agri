<script setup lang="ts">
/**
 * One appraisal, question by question.
 *
 * The thing the old screen could not do at all. A total score with no way to see what produced it
 * settles an argument by assertion — and a renewal gets decided on it. Each answer is shown with the
 * weight it carried and whether it cleared the question's own minimum, because a respectable average
 * can hide a failed safety question underneath it.
 */
import { computed } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Response {
  id: number; question: string | null; description: string | null
  weight: number | null; minimum: number | null
  rating: number | null; score: number | null
  outcome: string | null; comment: string | null
}

const props = defineProps<{
  appraisal: {
    uuid: string; supplier: string | null; supplier_uuid: string | null
    template: string | null; template_uuid: string | null; industry: string | null
    date: string | null; status: string | null; score: number | null
  }
  responses: Response[]
}>()

function band(score: number | null) {
  if (score === null) return 'default'
  if (score >= 80) return 'success'
  if (score >= 60) return 'info'
  if (score >= 40) return 'warning'
  return 'error'
}

/*
 * The questions that were not merely scored low but failed their own minimum. These are the reason
 * the page exists: a template can total 72 with a mandatory safety question at one out of five, and
 * the total is the only thing anybody would otherwise see.
 */
const failed = computed(() => props.responses.filter(r => r.outcome === 'fail'))

/** How much of the available weight was actually covered, which says whether the total is complete. */
const weightCovered = computed(() => props.responses.reduce((sum, r) => sum + (r.weight ?? 0), 0))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ appraisal.supplier ?? t('supplier_management.appraisals.unknown_supplier') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ appraisal.template ?? '—' }}<template v-if="appraisal.industry"> · {{ appraisal.industry }}</template>
          <template v-if="appraisal.date"> · {{ appraisal.date }}</template>
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn v-if="appraisal.supplier_uuid" variant="text" :href="route('suppliers.show', appraisal.supplier_uuid)">
          {{ t('supplier_management.appraisals.open_supplier') }}
        </VBtn>
        <VBtn variant="tonal" :href="route('suppliers.appraisals.index')">
          {{ t('supplier_management.appraisals.back_to_list') }}
        </VBtn>
      </div>
    </div>

    <VRow class="mb-1">
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg"><VCardText class="text-center py-6">
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.total_score') }}</div>
          <div class="text-h3 font-weight-bold" :class="`text-${band(appraisal.score)}`">
            {{ appraisal.score === null ? '—' : appraisal.score }}
          </div>
          <!-- Out of the weight that was actually asked about, not out of 100: a template whose
               weights total 70 can never award more than 70, and the total alone hides that. -->
          <div class="text-caption text-medium-emphasis mt-1">
            {{ t('supplier_management.appraisals.out_of', { weight: weightCovered }) }}
          </div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" md="8">
        <VAlert v-if="failed.length" type="error" variant="tonal" density="comfortable" class="h-100">
          <div class="text-body-2 font-weight-medium mb-1">
            {{ t('supplier_management.appraisals.failed_minimums', { count: failed.length }) }}
          </div>
          <ul class="text-caption ms-4">
            <li v-for="f in failed" :key="f.id">{{ f.question }}</li>
          </ul>
        </VAlert>
        <VAlert v-else type="success" variant="tonal" density="comfortable" class="h-100">
          <span class="text-body-2">{{ t('supplier_management.appraisals.no_failed_minimums') }}</span>
        </VAlert>
      </VCol>
    </VRow>

    <VCard elevation="0" border rounded="lg" class="mt-3">
      <VCardTitle class="text-body-1 font-weight-semibold">{{ t('supplier_management.appraisals.answers') }}</VCardTitle>
      <VDivider />
      <VCardText v-if="!responses.length" class="text-center py-10 text-medium-emphasis">
        {{ t('supplier_management.appraisals.no_answers') }}
      </VCardText>
      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th>{{ t('supplier_management.appraisals.question') }}</th>
            <th class="text-right" style="width:110px">{{ t('supplier_management.appraisals.rating') }}</th>
            <th class="text-right" style="width:90px">{{ t('supplier_management.appraisals.weight') }}</th>
            <th class="text-right" style="width:90px">{{ t('supplier_management.appraisals.score') }}</th>
            <th style="width:110px">{{ t('supplier_management.appraisals.outcome') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in responses" :key="r.id">
            <td>
              <div class="text-body-2">{{ r.question ?? '—' }}</div>
              <div v-if="r.description" class="text-caption text-medium-emphasis">{{ r.description }}</div>
              <div v-if="r.comment" class="text-caption mt-1" style="white-space:pre-wrap">“{{ r.comment }}”</div>
            </td>
            <td class="text-right">
              <!-- Appraisals recorded before the rating was kept show "not recorded" rather than a
                   zero, which would read as the worst possible answer. -->
              <template v-if="r.rating === null">
                <span class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.rating_not_kept') }}</span>
              </template>
              <template v-else>
                <span style="font-variant-numeric: tabular-nums">{{ r.rating }} / 5</span>
                <div v-if="r.minimum" class="text-caption text-medium-emphasis">
                  {{ t('supplier_management.appraisals.minimum_of', { n: r.minimum }) }}
                </div>
              </template>
            </td>
            <td class="text-right text-caption text-medium-emphasis" style="font-variant-numeric: tabular-nums">
              {{ r.weight === null ? '—' : r.weight + '%' }}
            </td>
            <td class="text-right" style="font-variant-numeric: tabular-nums">{{ r.score ?? '—' }}</td>
            <td>
              <VChip v-if="r.outcome" size="x-small" variant="tonal" :color="r.outcome === 'fail' ? 'error' : 'success'">
                {{ t('supplier_management.appraisals.outcomes.' + r.outcome) }}
              </VChip>
              <span v-else class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.no_minimum') }}</span>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
