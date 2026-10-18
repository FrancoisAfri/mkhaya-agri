<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Assessment = {
  uuid: string; reference: string; title: string; version: number
  review_due: string | null; review_overdue: boolean
}
type Permit = {
  uuid: string; reference: string; permit_type: string; work_description: string | null
  location_text: string | null; valid_from: string | null; valid_to: string | null
  status: string; competency_overridden: boolean; site_name: string | null
  hours_left: number | null; risk_assessment: Assessment | null
}

const props = defineProps<{
  permits: Permit[]
  sites: { id: number; name: string }[]
  siteId: number | null
  noCompany?: boolean
}>()

/*
  What a control room looks at first, in order: work whose permit window has closed and has not
  been closed out, work issued over a competency gap, and work with no approved assessment behind
  it. Each is a reason to walk out and look.
*/
const overdue = computed(() => props.permits.filter(p => p.hours_left !== null && p.hours_left < 0))
const overridden = computed(() => props.permits.filter(p => p.competency_overridden))
const uncovered = computed(() => props.permits.filter(p => p.risk_assessment === null))

const siteItems = computed(() => [
  { title: t('sheq.permit.board.all_sites'), value: null as number | null },
  ...props.sites.map(s => ({ title: s.name, value: s.id as number | null })),
])

function changeSite(siteId: number | null) {
  router.get('/sheq/permits/board', siteId === null ? {} : { site_id: siteId },
    { preserveState: false })
}

/** Negative hours are an overrun, and the words say so rather than a minus sign. */
function timeLeft(hours: number | null): string {
  if (hours === null) return t('sheq.permit.board.no_end')
  if (hours < 0) return t('sheq.permit.board.overrun', { hours: Math.abs(hours) })
  if (hours === 0) return t('sheq.permit.board.ending_now')
  return t('sheq.permit.board.hours_left', { hours })
}

function timeColour(hours: number | null): string {
  if (hours === null) return 'grey'
  if (hours < 0) return 'error'
  if (hours <= 2) return 'warning'
  return 'success'
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.permit.board.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.permit.board.subtitle') }}</p>
      </div>
      <VSelect
        :model-value="siteId" :items="siteItems" density="compact" variant="outlined"
        hide-details style="min-width: 220px" @update:model-value="changeSite"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VRow dense class="mb-2">
        <VCol cols="12" sm="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-h5 font-weight-bold">{{ permits.length }}</div>
            <div class="text-body-2 text-medium-emphasis">{{ t('sheq.permit.board.live') }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" sm="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-h5 font-weight-bold" :class="overdue.length ? 'text-error' : ''">
              {{ overdue.length }}
            </div>
            <div class="text-body-2 text-medium-emphasis">{{ t('sheq.permit.board.past_window') }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" sm="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-h5 font-weight-bold" :class="overridden.length ? 'text-warning' : ''">
              {{ overridden.length }}
            </div>
            <div class="text-body-2 text-medium-emphasis">{{ t('sheq.permit.board.overridden') }}</div>
          </VCard>
        </VCol>
      </VRow>

      <!--
        A permit is written against a risk assessment. With no approved assessment covering the
        site, the authority the permit rests on does not exist — said here, while the work is
        happening, rather than at the next audit.
      -->
      <VAlert v-if="uncovered.length" type="warning" variant="tonal" class="mb-4">
        {{ t('sheq.permit.board.uncovered', { count: uncovered.length }) }}
      </VAlert>

      <VCard v-if="permits.length" elevation="0" border rounded="lg">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.permit.board.permit') }}</th>
              <th scope="col">{{ t('sheq.permit.board.work') }}</th>
              <th scope="col">{{ t('sheq.permit.board.where') }}</th>
              <th scope="col">{{ t('sheq.permit.board.time_left') }}</th>
              <th scope="col">{{ t('sheq.permit.board.assessment') }}</th>
              <th scope="col" class="text-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="permit in permits" :key="permit.uuid">
              <td>
                <div class="font-weight-medium">{{ permit.reference }}</div>
                <div class="text-caption text-medium-emphasis">{{ permit.permit_type }}</div>
                <VChip v-if="permit.status === 'SUSPENDED'" size="x-small" variant="tonal" color="error">
                  {{ t('sheq.permit.board.suspended') }}
                </VChip>
              </td>
              <td style="max-width: 320px">
                <div class="text-body-2">{{ permit.work_description }}</div>
                <VChip v-if="permit.competency_overridden" size="x-small" variant="tonal"
                       color="warning" class="mt-1">
                  {{ t('sheq.permit.board.competency_overridden') }}
                </VChip>
              </td>
              <td class="text-body-2">
                {{ permit.site_name || '—' }}
                <div v-if="permit.location_text" class="text-caption text-medium-emphasis">
                  {{ permit.location_text }}
                </div>
              </td>
              <td>
                <VChip size="small" variant="tonal" :color="timeColour(permit.hours_left)">
                  {{ timeLeft(permit.hours_left) }}
                </VChip>
              </td>
              <td class="text-body-2">
                <template v-if="permit.risk_assessment">
                  {{ permit.risk_assessment.reference }} v{{ permit.risk_assessment.version }}
                  <div v-if="permit.risk_assessment.review_overdue"
                       class="text-caption text-warning">
                    {{ t('sheq.permit.board.ra_review_overdue') }}
                  </div>
                </template>
                <VChip v-else size="x-small" variant="tonal" color="warning">
                  {{ t('sheq.permit.board.no_assessment') }}
                </VChip>
              </td>
              <td class="text-end">
                <VBtn size="small" variant="text" :href="`/sheq/permits/${permit.uuid}`">
                  {{ t('sheq.permit.board.open') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <VCard v-else elevation="0" border rounded="lg" class="pa-8 text-center">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.permit.board.empty') }}</p>
      </VCard>
    </template>
  </div>
</template>
