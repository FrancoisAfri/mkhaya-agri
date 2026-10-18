<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Cell = { status: string; expires_at?: string | null }
type Row = {
  hr_person_id: number; required: number; compliant: number
  percent: number | null; cells: Record<string, Cell>
}
type Competency = { competency_id: number; code: string; title: string; blocks_work_when_expired?: boolean }

const props = defineProps<{
  matrix: {
    competencies: Competency[]
    rows: Row[]
    by_competency: Record<string, { required: number; compliant: number; percent: number | null }>
    overall_percent: number | null
  } | null
  people: Record<string, string>
  capped: boolean
  total: number
  noCompany?: boolean
}>()

const competencies = computed(() => props.matrix?.competencies ?? [])
const rows = computed(() => props.matrix?.rows ?? [])

/*
  The point of a matrix is the gaps, so they come first.

  A column where nobody is compliant is a different problem from one person being short: it means
  the training was never arranged, or the requirement was attached to people nobody has ticketed.
*/
const weakestColumns = computed(() =>
  Object.entries(props.matrix?.by_competency ?? {})
    .filter(([, totals]) => totals.percent !== null && totals.percent < 100)
    .sort((a, b) => (a[1].percent ?? 0) - (b[1].percent ?? 0))
    .slice(0, 3))

/** Worst people first: somebody short of three tickets is who a trainer calls today. */
const orderedRows = computed(() =>
  [...rows.value].sort((a, b) => (a.percent ?? 101) - (b.percent ?? 101)))

const cellColour = (status: string | undefined) => ({
  COMPLIANT: 'success', EXPIRING: 'warning', EXEMPT: 'grey', IN_PROGRESS: 'info',
  EXPIRED: 'error', NOT_STARTED: 'error', FAILED: 'error',
}[String(status)] ?? 'grey')

const cellLabel = (status: string | undefined) => ({
  COMPLIANT: '✓', EXPIRING: '!', EXEMPT: '–', IN_PROGRESS: '…',
  EXPIRED: '✕', NOT_STARTED: '✕', FAILED: '✕',
}[String(status)] ?? '·')
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.matrix.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.matrix.subtitle') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="mdi-arrow-left" href="/sheq/competency">
        {{ t('sheq.matrix.back') }}
      </VBtn>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="!competencies.length" type="info" variant="tonal">
        <!-- Nothing required of anybody is not the same as everybody being compliant. -->
        {{ t('sheq.matrix.nothing_required') }}
      </VAlert>

      <template v-else>
        <VRow dense class="mb-2">
          <VCol cols="12" sm="4">
            <VCard elevation="0" border rounded="lg" class="pa-4">
              <div class="text-h5 font-weight-bold">
                {{ matrix?.overall_percent ?? 0 }}%
              </div>
              <div class="text-body-2 text-medium-emphasis">{{ t('sheq.matrix.overall') }}</div>
            </VCard>
          </VCol>
          <VCol cols="12" sm="8">
            <VCard elevation="0" border rounded="lg" class="pa-4 h-100">
              <div class="text-caption text-uppercase text-medium-emphasis mb-1">
                {{ t('sheq.matrix.weakest') }}
              </div>
              <div v-if="!weakestColumns.length" class="text-body-2">
                {{ t('sheq.matrix.no_gaps') }}
              </div>
              <div v-for="[code, totals] in weakestColumns" :key="code" class="text-body-2">
                {{ code }} — {{ t('sheq.matrix.held_by', {
                  compliant: totals.compliant, required: totals.required }) }}
              </div>
            </VCard>
          </VCol>
        </VRow>

        <VAlert v-if="capped" type="info" variant="tonal" density="compact" class="mb-3">
          {{ t('sheq.matrix.capped', { shown: rows.length, total }) }}
        </VAlert>

        <VCard elevation="0" border rounded="lg">
          <!-- Wide by nature: it scrolls in its own container so the page never scrolls sideways. -->
          <div style="overflow-x: auto">
            <VTable density="compact">
              <thead>
                <tr>
                  <th scope="col" style="min-width: 180px">{{ t('sheq.matrix.person') }}</th>
                  <th v-for="c in competencies" :key="c.competency_id" scope="col"
                      class="text-center" style="min-width: 72px">
                    <abbr :title="c.title" style="text-decoration: none">{{ c.code }}</abbr>
                  </th>
                  <th scope="col" class="text-end" style="min-width: 90px">
                    {{ t('sheq.matrix.held') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in orderedRows" :key="row.hr_person_id">
                  <td class="text-body-2">{{ people[row.hr_person_id] ?? '—' }}</td>
                  <td v-for="c in competencies" :key="c.competency_id" class="text-center">
                    <!-- Colour and a glyph, never colour alone. -->
                    <VChip size="x-small" variant="tonal"
                           :color="cellColour(row.cells[c.code]?.status)"
                           :title="row.cells[c.code]?.status ?? t('sheq.matrix.not_required')">
                      {{ row.cells[c.code] ? cellLabel(row.cells[c.code].status) : '·' }}
                    </VChip>
                  </td>
                  <td class="text-end text-body-2">
                    <span :class="(row.percent ?? 100) < 100 ? 'text-warning font-weight-medium' : ''">
                      {{ row.compliant }}/{{ row.required }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>

        <p class="text-caption text-medium-emphasis mt-3">{{ t('sheq.matrix.footnote') }}</p>
      </template>
    </template>
  </div>
</template>
