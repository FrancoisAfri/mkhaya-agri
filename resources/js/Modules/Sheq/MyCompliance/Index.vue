<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Competency = {
  uuid: string; code: string; title: string; competency_type: string | null
  achieved_at: string | null; expires_at: string | null; certificate_number: string | null
  issuing_body: string | null; regulatory_reference: string | null
  appointment_scope: string | null; awaiting_my_signature: boolean
  blocks_work: boolean; status: string; days_left: number | null
}
type Gap = { code: string; title: string; status: string; blocks_work_when_expired?: boolean }
type Medical = {
  examination: string; examined_on: string | null; expires_on: string | null
  fitness_outcome: string | null; restrictions: string | null
  expired: boolean; blocks_work: boolean
}

const props = defineProps<{
  person: { name: string; employee_number: string | null } | null
  competencies: Competency[]
  gaps: Gap[]
  medicals: Medical[]
  induction: { current: boolean; required: boolean } | null
  unacknowledged: number
  documentsIOwn: Array<Record<string, any>>
  summary: { expired: number; expiring: number; blocking: number }
  noCompany?: boolean
  noRecord?: boolean
}>()

/*
  Whether this page has anything at all to say about the person.

  An empty page of tidy sections is indistinguishable from compliance, and that is the wrong answer
  to give somebody who will be standing at a permit gate tomorrow. So "nothing is on file" is said
  in words.
*/
const hasAnything = computed(() =>
  props.competencies.length > 0 || props.gaps.length > 0 || props.medicals.length > 0
  || props.unacknowledged > 0 || props.documentsIOwn.length > 0
  || !!props.induction?.required)

const statusColour = (status: string) => ({
  EXPIRED: 'error', EXPIRING: 'warning', COMPLIANT: 'success', EXEMPT: 'grey',
  IN_PROGRESS: 'info', NOT_STARTED: 'error', FAILED: 'error',
}[status] ?? 'grey')

/** Negative days are a lapse, and the words say so rather than a minus sign. */
function when(record: Competency): string {
  if (record.days_left === null) return t('sheq.mine.no_expiry')
  if (record.days_left < 0) return t('sheq.mine.lapsed_days', { days: Math.abs(record.days_left) })
  if (record.days_left === 0) return t('sheq.mine.lapses_today')
  return t('sheq.mine.days_left', { days: record.days_left })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.mine.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t('sheq.mine.subtitle') }}
        <span v-if="person?.employee_number"> · {{ person.employee_number }}</span>
      </p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <!--
      No personnel record. Creating a user account does not create one, so this is a real
      situation for a real employee — and "nothing on file" is the honest answer. Telling them
      they are compliant would be a guess, and the one they would act on.
    -->
    <VAlert v-if="noRecord" type="info" variant="tonal" class="mb-4" icon="mdi-account-question-outline">
      {{ t('sheq.mine.no_record') }}
    </VAlert>

    <template v-else-if="!noCompany">
      <!-- The one figure that decides whether this person can work tomorrow. -->
      <VAlert v-if="summary.blocking" type="error" variant="tonal" class="mb-4">
        {{ t('sheq.mine.blocking', { count: summary.blocking }) }}
      </VAlert>
      <VAlert v-else-if="summary.expired" type="warning" variant="tonal" class="mb-4">
        {{ t('sheq.mine.expired_note', { count: summary.expired }) }}
      </VAlert>
      <VAlert v-else-if="summary.expiring" type="info" variant="tonal" class="mb-4">
        {{ t('sheq.mine.expiring_note', { count: summary.expiring }) }}
      </VAlert>
      <VAlert v-else-if="hasAnything" type="success" variant="tonal" class="mb-4">
        {{ t('sheq.mine.all_current') }}
      </VAlert>
      <VAlert v-else type="info" variant="tonal" class="mb-4" icon="mdi-information-outline">
        {{ t('sheq.mine.nothing_on_file') }}
      </VAlert>

      <!-- ── My tickets ──────────────────────────────────────────────────────────────────── -->
      <VCard v-if="competencies.length" elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ t('sheq.mine.my_competencies') }}
        </VCardTitle>
        <VDivider />
        <VTable density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.mine.competency') }}</th>
              <th scope="col">{{ t('sheq.mine.status') }}</th>
              <th scope="col">{{ t('sheq.mine.expires') }}</th>
              <th scope="col">{{ t('sheq.mine.certificate') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in competencies" :key="c.uuid">
              <td>
                <div class="font-weight-medium">{{ c.title }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ c.code }}
                  <span v-if="c.regulatory_reference"> · {{ c.regulatory_reference }}</span>
                </div>
                <!-- An appointment takes effect when it is signed, and the reader is the signatory. -->
                <VChip v-if="c.awaiting_my_signature" size="x-small" variant="tonal"
                       color="warning" class="mt-1">
                  {{ t('sheq.mine.awaiting_signature') }}
                </VChip>
              </td>
              <td>
                <VChip size="x-small" variant="tonal" :color="statusColour(c.status)">
                  {{ t('sheq.mine.state.' + c.status) }}
                </VChip>
                <div v-if="c.blocks_work" class="text-caption text-medium-emphasis mt-1">
                  {{ t('sheq.mine.blocks_work') }}
                </div>
              </td>
              <td class="text-body-2">
                {{ c.expires_at ?? '—' }}
                <div class="text-caption text-medium-emphasis">{{ when(c) }}</div>
              </td>
              <td class="text-body-2">
                {{ c.certificate_number ?? '—' }}
                <div v-if="c.issuing_body" class="text-caption text-medium-emphasis">
                  {{ c.issuing_body }}
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <!-- ── Required of me and never held ────────────────────────────────────────────────── -->
      <VCard v-if="gaps.length" elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ t('sheq.mine.gaps') }}
        </VCardTitle>
        <VCardText class="pt-0">
          <!-- A lapse is a renewal; this is training that has to be arranged. Different ask. -->
          <p class="text-body-2 text-medium-emphasis">{{ t('sheq.mine.gaps_hint') }}</p>
          <ul class="ps-4 mb-0">
            <li v-for="gap in gaps" :key="gap.code" class="text-body-2">
              {{ gap.title }} <span class="text-medium-emphasis">({{ gap.code }})</span>
            </li>
          </ul>
        </VCardText>
      </VCard>

      <VRow dense>
        <!-- ── Medicals ──────────────────────────────────────────────────────────────────── -->
        <VCol v-if="medicals.length" cols="12" md="6">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.mine.my_medicals') }}
            </VCardTitle>
            <VDivider />
            <VTable density="compact">
              <tbody>
                <tr v-for="m in medicals" :key="m.examination">
                  <td class="text-body-2">
                    {{ m.examination }}
                    <div v-if="m.restrictions" class="text-caption text-medium-emphasis">
                      {{ m.restrictions }}
                    </div>
                  </td>
                  <td style="width: 150px">
                    <VChip size="x-small" variant="tonal"
                           :color="m.blocks_work || m.expired ? 'error' : 'success'">
                      {{ m.expired ? t('sheq.mine.state.EXPIRED') : (m.fitness_outcome ?? '—') }}
                    </VChip>
                    <div class="text-caption text-medium-emphasis">{{ m.expires_on ?? '—' }}</div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>

        <!-- ── Induction, and the reading I owe ──────────────────────────────────────────── -->
        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.mine.other') }}
            </VCardTitle>
            <VDivider />
            <VList density="compact">
              <VListItem v-if="induction?.required">
                <template #prepend>
                  <VIcon :icon="induction.current ? 'mdi-check-circle-outline' : 'mdi-alert-circle-outline'"
                         :color="induction.current ? 'success' : 'error'" />
                </template>
                <VListItemTitle class="text-body-2">
                  {{ induction.current ? t('sheq.mine.induction_current')
                                       : t('sheq.mine.induction_not_current') }}
                </VListItemTitle>
              </VListItem>

              <!--
                A count and a link, not the list. The library *is* the list — it applies the
                audience rule, shows the version in force and carries the acknowledge button.
              -->
              <VListItem :to="undefined">
                <template #prepend>
                  <VIcon :icon="unacknowledged ? 'mdi-book-alert-outline' : 'mdi-book-check-outline'"
                         :color="unacknowledged ? 'warning' : 'success'" />
                </template>
                <VListItemTitle class="text-body-2">
                  <Link v-if="unacknowledged" href="/sheq/library" class="text-primary">
                    {{ t('sheq.mine.unacknowledged', { count: unacknowledged }) }}
                  </Link>
                  <span v-else>{{ t('sheq.mine.all_acknowledged') }}</span>
                </VListItemTitle>
              </VListItem>

              <VListItem v-for="doc in documentsIOwn" :key="doc.uuid">
                <template #prepend>
                  <VIcon icon="mdi-file-clock-outline" color="warning" />
                </template>
                <VListItemTitle class="text-body-2">
                  {{ t('sheq.mine.i_own_review', { document: doc.doc_number }) }}
                </VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  {{ doc.title }} · {{ doc.review_due }}
                </VListItemSubtitle>
              </VListItem>
            </VList>
          </VCard>
        </VCol>
      </VRow>

      <p class="text-caption text-medium-emphasis mt-3">{{ t('sheq.mine.footnote') }}</p>
    </template>
  </div>
</template>
