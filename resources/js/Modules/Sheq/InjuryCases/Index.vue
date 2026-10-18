<script setup lang="ts">
/**
 * Who is off work, who is on light duty, and who is back.
 *
 * The one question an injury management system has to answer, and the one the module could not
 * answer at all. Above the list sits what it is really for: injuries with nobody following them,
 * because an untracked injury is how a lost-day figure ends up being guessed at year end.
 *
 * Diagnosis and restrictions are special personal information and stay off this list — a
 * supervisor planning work needs the dates, not the medical detail.
 */
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Case = {
  uuid: string; reference: string; status: string; injured_on: string
  expected_return_on: string | null; returned_on: string | null
  days_lost: number; days_restricted: number; coida_claim_number: string | null
  incident_reference: string; incident_uuid: string; description: string; person: string | null
}

type Untracked = {
  uuid: string; reference: string; description: string
  occurred_at: string; treatment_level: string | null
}

defineProps<{
  cases: Case[]
  summary: { away: number; returned: number; days_lost: number; no_claim: number }
  untracked: Untracked[]
  can: { manage: boolean }
  noCompany?: boolean
}>()

const busy = ref(false)

function openCase(incidentUuid: string) {
  busy.value = true
  router.post(`/sheq/injury-cases/from-incident/${incidentUuid}`, {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

function statusColour(status: string): string {
  return status === 'OPEN' ? 'warning' : status === 'RETURNED' ? 'success' : 'grey'
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.injury.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.injury.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <VRow class="mb-2">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg"
               :color="summary.away ? 'warning' : undefined"
               :variant="summary.away ? 'tonal' : 'elevated'">
          <VCardText>
            <div class="text-caption">{{ t('sheq.injury.still_away') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.away }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-caption">{{ t('sheq.injury.days_lost') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.days_lost }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-caption">{{ t('sheq.injury.returned') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.returned }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border rounded="lg">
          <VCardText>
            <div class="text-caption">{{ t('sheq.injury.no_claim') }}</div>
            <div class="text-h4 font-weight-bold">{{ summary.no_claim }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Somebody was hurt and nobody is following them. This is the point of the screen. -->
    <VCard v-if="untracked.length" elevation="0" border rounded="lg" class="mb-4"
           color="error" variant="tonal">
      <VCardItem>
        <VCardTitle class="text-subtitle-1">{{ t('sheq.injury.untracked_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('sheq.injury.untracked_subtitle') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VList density="compact" bg-color="transparent">
          <VListItem v-for="u in untracked" :key="u.uuid">
            <VListItemTitle>
              <Link :href="`/sheq/incidents/${u.uuid}`" class="text-primary">{{ u.reference }}</Link>
              <span class="text-medium-emphasis"> — {{ u.description }}</span>
            </VListItemTitle>
            <VListItemSubtitle>{{ u.treatment_level }}</VListItemSubtitle>
            <template #append>
              <VBtn v-if="can.manage" size="small" variant="tonal" :loading="busy"
                    @click="openCase(u.uuid)">
                {{ t('sheq.injury.open_case') }}
              </VBtn>
            </template>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <VCard elevation="0" border rounded="lg">
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ t('sheq.injury.col_reference') }}</th>
            <th>{{ t('sheq.injury.col_person') }}</th>
            <th>{{ t('sheq.injury.col_injured_on') }}</th>
            <th class="text-center">{{ t('sheq.injury.col_lost') }}</th>
            <th class="text-center">{{ t('sheq.injury.col_restricted') }}</th>
            <th>{{ t('sheq.injury.col_expected') }}</th>
            <th>{{ t('sheq.injury.col_claim') }}</th>
            <th class="text-right">{{ t('sheq.injury.col_status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in cases" :key="c.uuid">
            <td class="text-no-wrap">
              <Link :href="`/sheq/injury-cases/${c.uuid}`" class="text-primary">{{ c.reference }}</Link>
              <div class="text-caption text-medium-emphasis">{{ c.incident_reference }}</div>
            </td>
            <td>{{ c.person || t('sheq.injury.unnamed') }}</td>
            <td class="text-no-wrap">{{ c.injured_on }}</td>
            <td class="text-center font-weight-bold">{{ c.days_lost }}</td>
            <td class="text-center">{{ c.days_restricted }}</td>
            <td class="text-no-wrap">{{ c.expected_return_on || '—' }}</td>
            <td class="text-no-wrap">
              <span v-if="c.coida_claim_number">{{ c.coida_claim_number }}</span>
              <VChip v-else size="x-small" color="warning" variant="tonal">
                {{ t('sheq.injury.no_claim_chip') }}
              </VChip>
            </td>
            <td class="text-right">
              <VChip size="small" :color="statusColour(c.status)" variant="tonal">{{ c.status }}</VChip>
            </td>
          </tr>
          <tr v-if="!cases.length">
            <td colspan="8" class="text-center text-medium-emphasis py-8">
              {{ t('sheq.injury.none') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
