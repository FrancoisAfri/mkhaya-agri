<script setup lang="ts">
/**
 * One injured person's timeline, and the day counts that come out of it.
 *
 * The counts are shown beside the periods that produced them rather than as standalone figures,
 * because these are the numbers an inspector recalculates and a claims assessor disputes — the
 * point of keeping the timeline is being able to answer "where does fourteen come from".
 */
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Event = {
  event_type: string; from_date: string; to_date: string | null
  practitioner: string | null; notes: string | null
}

type Case = {
  uuid: string; reference: string; status: string; injured_on: string
  expected_return_on: string | null; returned_on: string | null
  days_lost: number; days_restricted: number; restrictions: string | null
  coida_claim_number: string | null; timeline: Event[]
}

const props = defineProps<{
  injuryCase: Case | null
  can: { manage: boolean }
  noCompany?: boolean
}>()

const busy = ref(false)
const adding = ref(false)
const claiming = ref(false)

const entry = ref({
  event_type: 'BOOKED_OFF',
  from_date: '',
  to_date: '',
  practitioner: '',
  notes: '',
})

const claimNumber = ref('')

/** An open-ended period: the person has not been recorded as back, so the count is still running. */
const stillRunning = computed(() =>
  (props.injuryCase?.timeline ?? []).some(
    e => ['BOOKED_OFF', 'RESTRICTED_DUTY'].includes(e.event_type) && !e.to_date,
  ),
)

function record() {
  if (!props.injuryCase) return
  busy.value = true
  router.post(`/sheq/injury-cases/${props.injuryCase.uuid}/record`, {
    event_type: entry.value.event_type,
    from_date: entry.value.from_date,
    to_date: entry.value.to_date || null,
    practitioner: entry.value.practitioner || null,
    notes: entry.value.notes || null,
  }, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; adding.value = false },
  })
}

function saveClaim() {
  if (!props.injuryCase) return
  busy.value = true
  router.post(`/sheq/injury-cases/${props.injuryCase.uuid}/claim`, {
    coida_claim_number: claimNumber.value,
  }, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; claiming.value = false },
  })
}

function close() {
  if (!props.injuryCase) return
  busy.value = true
  router.post(`/sheq/injury-cases/${props.injuryCase.uuid}/close`, {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

function periodColour(type: string): string {
  if (type === 'BOOKED_OFF') return 'error'
  if (type === 'RESTRICTED_DUTY') return 'warning'
  if (type === 'RETURNED_FULL') return 'success'
  return 'info'
}
</script>

<template>
  <div class="pa-4">
    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="injuryCase">
      <div class="mb-5 d-flex align-center flex-wrap gap-3">
        <div>
          <h1 class="text-h5 font-weight-bold mb-0">{{ injuryCase.reference }}</h1>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ t('sheq.injury.injured_on') }} {{ injuryCase.injured_on }}
          </p>
        </div>
        <VChip :color="injuryCase.status === 'OPEN' ? 'warning' : 'success'" variant="tonal">
          {{ injuryCase.status }}
        </VChip>
        <VSpacer />
        <Link href="/sheq/injury-cases" class="text-primary text-body-2">
          {{ t('sheq.injury.back') }}
        </Link>
      </div>

      <VRow class="mb-2">
        <VCol cols="6" sm="3">
          <VCard elevation="0" border rounded="lg">
            <VCardText>
              <div class="text-caption">{{ t('sheq.injury.days_lost') }}</div>
              <div class="text-h4 font-weight-bold">{{ injuryCase.days_lost }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard elevation="0" border rounded="lg">
            <VCardText>
              <div class="text-caption">{{ t('sheq.injury.days_restricted') }}</div>
              <div class="text-h4 font-weight-bold">{{ injuryCase.days_restricted }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" sm="6">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardText>
              <div class="text-caption">{{ t('sheq.injury.coida') }}</div>
              <div v-if="injuryCase.coida_claim_number" class="text-h6 font-weight-bold">
                {{ injuryCase.coida_claim_number }}
              </div>
              <VBtn v-else-if="can.manage" size="small" variant="tonal" class="mt-1"
                    @click="claiming = true">
                {{ t('sheq.injury.record_claim') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VAlert v-if="stillRunning" type="info" variant="tonal" density="compact" class="mb-4">
        {{ t('sheq.injury.still_running') }}
      </VAlert>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardItem>
          <VCardTitle class="text-subtitle-1">{{ t('sheq.injury.timeline') }}</VCardTitle>
          <VCardSubtitle>{{ t('sheq.injury.timeline_help') }}</VCardSubtitle>
          <template #append>
            <VBtn v-if="can.manage && injuryCase.status !== 'CLOSED'" size="small" variant="tonal"
                  @click="adding = true">
              {{ t('sheq.injury.add_entry') }}
            </VBtn>
          </template>
        </VCardItem>
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ t('sheq.injury.col_event') }}</th>
              <th>{{ t('sheq.injury.col_from') }}</th>
              <th>{{ t('sheq.injury.col_to') }}</th>
              <th>{{ t('sheq.injury.col_practitioner') }}</th>
              <th>{{ t('sheq.injury.col_notes') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(e, idx) in injuryCase.timeline" :key="idx">
              <td>
                <VChip size="small" :color="periodColour(e.event_type)" variant="tonal">
                  {{ e.event_type }}
                </VChip>
              </td>
              <td class="text-no-wrap">{{ e.from_date }}</td>
              <td class="text-no-wrap">
                <span v-if="e.to_date">{{ e.to_date }}</span>
                <span v-else class="text-medium-emphasis">{{ t('sheq.injury.ongoing') }}</span>
              </td>
              <td>{{ e.practitioner || '—' }}</td>
              <td>{{ e.notes || '—' }}</td>
            </tr>
            <tr v-if="!injuryCase.timeline.length">
              <td colspan="5" class="text-center text-medium-emphasis py-8">
                {{ t('sheq.injury.no_entries') }}
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <div v-if="can.manage && injuryCase.status !== 'CLOSED'" class="d-flex">
        <VSpacer />
        <VBtn variant="tonal" :loading="busy" :disabled="stillRunning" @click="close">
          {{ t('sheq.injury.close_case') }}
        </VBtn>
      </div>

      <VDialog v-model="adding" max-width="640">
        <VCard rounded="lg">
          <VCardItem>
            <VCardTitle>{{ t('sheq.injury.add_entry') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <VSelect
              v-model="entry.event_type"
              :items="['BOOKED_OFF', 'RESTRICTED_DUTY', 'RETURNED_FULL', 'MEDICAL_VISIT', 'REVIEW']"
              :label="t('sheq.injury.col_event')"
              :hint="t('sheq.injury.event_hint')"
              persistent-hint
              variant="outlined" density="comfortable" class="mb-3"
            />
            <VTextField v-model="entry.from_date" type="date" :label="t('sheq.injury.col_from')"
                        variant="outlined" density="comfortable" class="mb-3" />
            <VTextField v-model="entry.to_date" type="date" :label="t('sheq.injury.col_to')"
                        :hint="t('sheq.injury.to_hint')" persistent-hint
                        variant="outlined" density="comfortable" class="mb-3" />
            <VTextField v-model="entry.practitioner" :label="t('sheq.injury.col_practitioner')"
                        variant="outlined" density="comfortable" class="mb-3" />
            <VTextarea v-model="entry.notes" :label="t('sheq.injury.col_notes')"
                       variant="outlined" density="comfortable" rows="2" hide-details />
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn variant="text" @click="adding = false">{{ t('sheq.injury.cancel') }}</VBtn>
            <VBtn color="primary" variant="flat" :loading="busy" @click="record">
              {{ t('sheq.injury.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <VDialog v-model="claiming" max-width="560">
        <VCard rounded="lg">
          <VCardItem>
            <VCardTitle>{{ t('sheq.injury.record_claim') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <VTextField v-model="claimNumber" :label="t('sheq.injury.claim_number')"
                        variant="outlined" density="comfortable" hide-details />
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn variant="text" @click="claiming = false">{{ t('sheq.injury.cancel') }}</VBtn>
            <VBtn color="primary" variant="flat" :loading="busy" @click="saveClaim">
              {{ t('sheq.injury.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>
