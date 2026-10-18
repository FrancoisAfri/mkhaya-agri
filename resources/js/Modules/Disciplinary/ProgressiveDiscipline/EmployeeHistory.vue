<script setup lang="ts">
import { computed } from 'vue'
import { route } from 'ziggy-js'

interface Warning {
  id: number; warning_type: string; issued_date: string|null; expiry_date: string|null
  is_expired: boolean; reason: string|null; case_number: string|null; case_id: number|null
  issued_by: string|null
}
interface Suspension {
  id: number; start_date: string|null; end_date: string|null; suspension_days: number|null
  reason: string|null; status: string; case_number: string|null; case_id: number|null
}
interface Escalation {
  id: number; from_level: string; to_level: string; reason: string|null
  status: string; case_number: string|null; case_id: number|null; created_at: string|null
}
interface ProgressiveHistory {
  current_level: string; active_warnings: number; verbal_count: number
  written_count: number; final_count: number; suspension_count: number
  prior_cases: number; is_first_offence: boolean
}

const props = defineProps<{
  employee_id: number
  employee_name: string
  warnings: Warning[]
  suspensions: Suspension[]
  escalations: Escalation[]
  history: ProgressiveHistory
}>()

const warningTypeColor = (t: string) => ({ verbal: 'warning', written: 'orange', final_written: 'error' })[t] ?? 'grey'
const cap = (s: string|null|undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'

const levelColor = computed(() => {
  const map: Record<string, string> = {
    none: 'success', verbal: 'warning', written: 'orange',
    final_written: 'error', suspension: 'deep-purple', dismissal: 'red',
  }
  return map[props.history.current_level] ?? 'grey'
})
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">Progressive Discipline</p>
        <h1 class="text-h5 font-weight-bold">{{ employee_name }}</h1>
        <p class="text-body-2 text-medium-emphasis">Discipline history and progressive standing</p>
      </div>
      <VBtn variant="outlined" prepend-icon="tabler-arrow-left" :href="route('disciplinary.progressive-discipline.warnings.index')">
        Back to Warnings
      </VBtn>
    </div>

    <!-- Progressive standing -->
    <VRow dense class="mb-4">
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" :color="levelColor">
          <VCardText class="text-center py-4">
            <div class="text-overline text-medium-emphasis">Current Level</div>
            <div class="text-h5 font-weight-bold mt-1">{{ cap(history.current_level.replace('_', ' ')) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3" md="2">
        <VCard variant="tonal" color="warning">
          <VCardText class="text-center py-4">
            <div class="text-h4 font-weight-bold">{{ history.active_warnings }}</div>
            <div class="text-caption text-medium-emphasis">Active Warnings</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3" md="2">
        <VCard variant="tonal" color="primary">
          <VCardText class="text-center py-4">
            <div class="text-h4 font-weight-bold">{{ history.prior_cases }}</div>
            <div class="text-caption text-medium-emphasis">Prior Cases</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3" md="2">
        <VCard variant="tonal" color="deep-purple">
          <VCardText class="text-center py-4">
            <div class="text-h4 font-weight-bold">{{ history.suspension_count }}</div>
            <div class="text-caption text-medium-emphasis">Suspensions</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3" md="3">
        <VCard variant="tonal" :color="history.is_first_offence ? 'success' : 'error'">
          <VCardText class="text-center py-4">
            <VIcon size="28" class="mb-1">{{ history.is_first_offence ? 'tabler-circle-check' : 'tabler-alert-circle' }}</VIcon>
            <div class="text-caption font-weight-medium">
              {{ history.is_first_offence ? 'First Offence' : 'Repeat Offender' }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Warning breakdown -->
    <VRow dense class="mb-4">
      <VCol cols="4">
        <VCard variant="outlined">
          <VCardText class="text-center">
            <div class="text-h5 font-weight-bold text-warning">{{ history.verbal_count }}</div>
            <div class="text-caption">Verbal</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="4">
        <VCard variant="outlined">
          <VCardText class="text-center">
            <div class="text-h5 font-weight-bold text-orange">{{ history.written_count }}</div>
            <div class="text-caption">Written</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="4">
        <VCard variant="outlined">
          <VCardText class="text-center">
            <div class="text-h5 font-weight-bold text-error">{{ history.final_count }}</div>
            <div class="text-caption">Final Written</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Warnings list -->
    <VCard class="mb-4">
      <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2">
        <VIcon start>tabler-alert-triangle</VIcon>Warnings ({{ warnings.length }})
      </VCardTitle>
      <VCardText class="pa-0">
        <div v-if="!warnings.length" class="text-center py-6 text-medium-emphasis">No warnings on record.</div>
        <VList v-else density="compact" class="px-2 pb-2">
          <VListItem v-for="w in warnings" :key="w.id" class="mb-1">
            <template #prepend>
              <VChip :color="warningTypeColor(w.warning_type)" size="small" class="mr-3">
                {{ cap(w.warning_type.replace('_', ' ')) }}
              </VChip>
            </template>
            <VListItemTitle class="text-body-2">{{ w.reason ?? '—' }}</VListItemTitle>
            <VListItemSubtitle class="text-caption">
              Issued: {{ w.issued_date ?? '—' }} · Expires: {{ w.expiry_date ?? 'N/A' }}
              <span v-if="w.case_number">· {{ w.case_number }}</span>
            </VListItemSubtitle>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip v-if="w.is_expired" color="grey" size="x-small">Expired</VChip>
                <VBtn v-if="w.case_id" size="x-small" variant="text"
                  :href="route('disciplinary.cases.show', { case: w.case_id })">View</VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <!-- Suspensions list -->
    <VCard class="mb-4">
      <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2">
        <VIcon start>tabler-user-off</VIcon>Suspensions ({{ suspensions.length }})
      </VCardTitle>
      <VCardText class="pa-0">
        <div v-if="!suspensions.length" class="text-center py-6 text-medium-emphasis">No suspensions on record.</div>
        <VList v-else density="compact" class="px-2 pb-2">
          <VListItem v-for="s in suspensions" :key="s.id" class="mb-1">
            <template #prepend>
              <VChip :color="s.status === 'active' ? 'error' : 'grey'" size="small" class="mr-3">
                {{ cap(s.status) }}
              </VChip>
            </template>
            <VListItemTitle class="text-body-2">{{ s.reason ?? '—' }}</VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ s.start_date }} → {{ s.end_date ?? 'Ongoing' }}
              <span v-if="s.suspension_days"> · {{ s.suspension_days }} days</span>
              <span v-if="s.case_number"> · {{ s.case_number }}</span>
            </VListItemSubtitle>
            <template #append>
              <VBtn v-if="s.case_id" size="x-small" variant="text"
                :href="route('disciplinary.cases.show', { case: s.case_id })">View</VBtn>
            </template>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <!-- Escalations list -->
    <VCard>
      <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2">
        <VIcon start>tabler-circle-arrow-up</VIcon>Escalations ({{ escalations.length }})
      </VCardTitle>
      <VCardText class="pa-0">
        <div v-if="!escalations.length" class="text-center py-6 text-medium-emphasis">No escalations on record.</div>
        <VList v-else density="compact" class="px-2 pb-2">
          <VListItem v-for="e in escalations" :key="e.id" class="mb-1">
            <template #prepend>
              <VChip :color="e.status === 'approved' ? 'success' : e.status === 'pending' ? 'warning' : 'grey'" size="small" class="mr-3">
                {{ cap(e.status) }}
              </VChip>
            </template>
            <VListItemTitle class="text-body-2">
              {{ cap(e.from_level.replace('_',' ')) }} → {{ cap(e.to_level.replace('_',' ')) }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ e.reason ?? '—' }}<span v-if="e.case_number"> · {{ e.case_number }}</span>
            </VListItemSubtitle>
            <template #append>
              <VBtn v-if="e.case_id" size="x-small" variant="text"
                :href="route('disciplinary.cases.show', { case: e.case_id })">View</VBtn>
            </template>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>
  </div>
</template>
