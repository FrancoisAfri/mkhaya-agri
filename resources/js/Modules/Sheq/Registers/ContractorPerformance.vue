<script setup lang="ts">
/**
 * A contractor's safety record, and the arithmetic behind the number (CON-20, CON-21).
 *
 * The page leads with the working rather than the score. A contractor told their score is 61 asks
 * why, and the only answer available before this was to read the source. These figures decide who
 * gets work and they get argued about in meetings — a number that cannot show its working loses
 * to whoever argues hardest, which defeats the point of counting instead of rating by hand.
 */
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

type Factor = {
  key: string
  label: string
  weight: number
  source: string
  available: boolean
  note: string
}

type Comparison = {
  period: string
  previous_period: string
  score: number | null
  previous_score: number | null
  change: number | null
  rating: string | null
  previous_rating: string | null
  factors: {
    key: string; label: string; available: boolean
    current: number; previous: number; change: number; improved: boolean
  }[]
  breakdown: {
    workers_on_site: number; started_at: number; deducted: number; score: number
    floored: boolean; note?: string
    factors: { key: string; label: string; count: number; weight: number; cost: number
      available: boolean; note: string }[]
  } | null
}

const props = defineProps<{
  period: string
  periods: string[]
  contractors: { uuid: string; name: string }[]
  selected: { uuid: string; name: string } | null
  factors: Factor[]
  weights: Record<string, number>
  leaderboard: Record<string, unknown>[]
  history: { period: string; score: number | null; rating: string | null
    workers_on_site: number }[]
  comparison: Comparison | null
  canManage?: boolean
  noCompany?: boolean
}>()

const period = ref(props.period)
const contractor = ref(props.selected?.uuid ?? null)

watch([period, contractor], () => {
  router.get('/sheq/contractor-performance',
    { period: period.value, contractor: contractor.value },
    { preserveState: true, preserveScroll: true, replace: true })
})

const weightForm = useForm({ weights: { ...props.weights } })

const weightsDirty = computed(() =>
  JSON.stringify(weightForm.weights) !== JSON.stringify(props.weights))

function saveWeights() {
  weightForm.post('/sheq/contractor-performance/weights', { preserveScroll: true })
}

const recount = useForm({ period: props.period })

function recompute() {
  recount.period = period.value
  recount.post(`/sheq/contractor-performance/recompute?period=${period.value}`,
    { preserveScroll: true })
}

const ratingColour = (rating: string | null) => ({
  GOOD: 'success', ACCEPTABLE: 'info', WATCH: 'warning', POOR: 'error',
}[rating ?? ''] ?? 'grey')

// A trend drawn from the stored monthly scores. Deliberately a sparkline rather than a chart
// library: the shape is the message, and a page that needs a 300KB bundle to show twelve numbers
// is a page that loads slowly on a site office connection.
const spark = computed(() => {
  const points = props.history.filter(h => h.score !== null)

  if (points.length < 2) return null

  const scores = points.map(p => p.score as number)
  const min = Math.min(...scores, 0)
  const max = Math.max(...scores, 100)
  const span = max - min || 1

  return {
    points: scores.map((s, i) =>
      `${(i / (scores.length - 1)) * 100},${40 - ((s - min) / span) * 40}`).join(' '),
    first: points[0],
    last: points[points.length - 1],
    count: points.length,
  }
})
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">Contractor performance</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        Counted from the registers, with the arithmetic shown — and the trend behind it
      </p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardText class="d-flex flex-wrap align-center ga-3">
          <VSelect
            v-model="contractor"
            :items="contractors.map(c => ({ value: c.uuid, title: c.name }))"
            density="compact" variant="outlined" hide-details style="max-width: 300px"
            label="Contractor"
          />
          <VSelect
            v-model="period" :items="periods" density="compact" variant="outlined" hide-details
            style="max-width: 160px" label="Month"
          />
          <VSpacer />
          <VBtn variant="tonal" size="small" :loading="recount.processing" @click="recompute">
            Recount this month
          </VBtn>
        </VCardText>
      </VCard>

      <VRow>
        <VCol cols="12" md="7">
          <!-- ── CON-20: the arithmetic ─────────────────────────────────────────────────── -->
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              How the score was worked out
            </VCardTitle>
            <VDivider />

            <div v-if="!comparison?.breakdown" class="text-center py-10 text-medium-emphasis">
              <VIcon icon="mdi-calculator-variant-outline" size="32" class="mb-2" />
              <div class="text-body-2">
                No score for {{ period }}. Recount the month to produce one.
              </div>
            </div>

            <template v-else>
              <VCardText class="d-flex align-center ga-4">
                <div>
                  <div class="text-caption text-uppercase text-medium-emphasis">Score</div>
                  <div class="text-h3 font-weight-bold">
                    {{ comparison.breakdown.score }}
                  </div>
                </div>
                <VChip :color="ratingColour(comparison.rating)" variant="tonal">
                  {{ comparison.rating ?? 'Not rated' }}
                </VChip>
                <div class="text-body-2 text-medium-emphasis">
                  Started at {{ comparison.breakdown.started_at }},
                  deducted {{ comparison.breakdown.deducted }},
                  across {{ comparison.breakdown.workers_on_site }} people on site.
                  <!-- Said plainly, because a score of 0 for a small contractor with several
                       incidents reads as a bug otherwise. -->
                  <template v-if="comparison.breakdown.floored">
                    The deductions came to more than 100, so the score is held at zero.
                  </template>
                </div>
              </VCardText>

              <VTable density="compact">
                <thead>
                  <tr>
                    <th class="text-left">Factor</th>
                    <th class="text-end">Count</th>
                    <th class="text-end">Weight</th>
                    <th class="text-end">Cost</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in comparison.breakdown.factors" :key="row.key">
                    <td class="text-body-2">
                      {{ row.label }}
                      <VTooltip :text="row.note">
                        <template #activator="{ props: tip }">
                          <VIcon v-bind="tip" icon="mdi-information-outline" size="13"
                                 class="ml-1 text-medium-emphasis" />
                        </template>
                      </VTooltip>
                      <VChip v-if="!row.available" size="x-small" variant="tonal" color="grey"
                             class="ml-2">
                        not counted
                      </VChip>
                    </td>
                    <td class="text-end text-body-2">{{ row.count }}</td>
                    <td class="text-end text-body-2 text-medium-emphasis">×{{ row.weight }}</td>
                    <td class="text-end text-body-2"
                        :class="row.cost > 0 ? 'text-error font-weight-medium' : ''">
                      {{ row.cost > 0 ? '−' + row.cost : '—' }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>
          </VCard>

          <!-- ── CON-21: the trend ──────────────────────────────────────────────────────── -->
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              Trend
              <span class="text-caption text-medium-emphasis font-weight-regular">
                · one month is an anecdote
              </span>
            </VCardTitle>
            <VDivider />
            <VCardText>
              <div v-if="!spark" class="text-body-2 text-medium-emphasis">
                Two scored months are needed before there is a direction to show.
              </div>
              <template v-else>
                <svg viewBox="0 0 100 44" preserveAspectRatio="none"
                     style="width: 100%; height: 90px" role="img"
                     :aria-label="`Score from ${spark.first.period} to ${spark.last.period}`">
                  <polyline :points="spark.points" fill="none" stroke="currentColor"
                            stroke-width="1.2" vector-effect="non-scaling-stroke" />
                </svg>
                <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                  <span>{{ spark.first.period }} · {{ spark.first.score }}</span>
                  <span>{{ spark.count }} months</span>
                  <span>{{ spark.last.period }} · {{ spark.last.score }}</span>
                </div>
              </template>

              <template v-if="comparison">
                <VDivider class="my-4" />
                <div class="text-subtitle-2 font-weight-bold mb-1">
                  {{ comparison.period }} against {{ comparison.previous_period }}
                </div>
                <!-- The comparison rather than two columns to subtract in your head, because a
                     reader asked to do that gets it wrong in whichever direction suits them. -->
                <div class="text-body-2 text-medium-emphasis mb-3">
                  <template v-if="comparison.change === null">
                    There is no earlier score to compare with.
                  </template>
                  <template v-else-if="comparison.change === 0">
                    The score has not moved.
                  </template>
                  <template v-else>
                    <span :class="comparison.change > 0 ? 'text-success' : 'text-error'">
                      {{ comparison.change > 0 ? '+' : '' }}{{ comparison.change }}
                    </span>
                    on {{ comparison.previous_score }}.
                  </template>
                </div>

                <VTable density="compact">
                  <tbody>
                    <tr v-for="row in comparison.factors.filter(f => f.available)" :key="row.key">
                      <td class="text-body-2">{{ row.label }}</td>
                      <td class="text-end text-body-2 text-medium-emphasis">
                        {{ row.previous }} → {{ row.current }}
                      </td>
                      <td class="text-end" style="width: 70px">
                        <span v-if="row.change === 0" class="text-caption text-medium-emphasis">
                          —
                        </span>
                        <span v-else class="text-body-2"
                              :class="row.improved ? 'text-success' : 'text-error'">
                          {{ row.change > 0 ? '+' : '' }}{{ row.change }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </template>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <!-- ── CON-20: configurable factors ───────────────────────────────────────────── -->
          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              What each factor costs
            </VCardTitle>
            <VDivider />
            <VCardText>
              <p class="text-body-2 text-medium-emphasis">
                The shipped weights are a view, not a standard. Near misses count for little on
                purpose — penalise a contractor for reporting them and they stop reporting, and
                the score improves while the site gets more dangerous.
              </p>

              <div v-for="factor in factors" :key="factor.key" class="mb-3">
                <div class="d-flex align-center ga-2">
                  <div class="text-body-2 flex-grow-1">
                    {{ factor.label }}
                    <VChip v-if="!factor.available" size="x-small" variant="tonal" color="grey">
                      not counted
                    </VChip>
                  </div>
                  <VTextField
                    v-if="factor.available"
                    v-model.number="weightForm.weights[factor.key]"
                    type="number" min="0" max="50" density="compact" variant="outlined"
                    hide-details style="max-width: 86px" :disabled="!canManage"
                  />
                </div>
                <div class="text-caption text-medium-emphasis">{{ factor.note }}</div>
              </div>

              <div v-if="canManage && weightsDirty" class="d-flex justify-end">
                <VBtn color="primary" variant="flat" size="small"
                      :loading="weightForm.processing" @click="saveWeights">
                  Save weights
                </VBtn>
              </div>
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ period }} — worst first
            </VCardTitle>
            <VDivider />
            <div v-if="!leaderboard.length" class="text-center py-8 text-medium-emphasis">
              <div class="text-body-2">Nothing scored for this month yet.</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="(row, i) in leaderboard" :key="i">
                  <td class="text-body-2">{{ row.name }}</td>
                  <td class="text-end">
                    <VChip size="x-small" variant="tonal"
                           :color="ratingColour(row.rating as string)">
                      {{ row.score }}
                    </VChip>
                  </td>
                  <td class="text-end text-caption text-medium-emphasis">
                    {{ row.workers_on_site }} on site
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
