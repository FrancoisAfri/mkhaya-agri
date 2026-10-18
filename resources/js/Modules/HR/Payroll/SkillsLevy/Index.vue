<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const s = (key: string, a?: Record<string, unknown>) => t('payroll.skills_levy.' + key, a ?? {})

const props = defineProps<{
  report: {
    year: number; period: { from: string; to: string }
    leviable_payroll: number; levy_paid: number; exempt: boolean
    mandatory_grant: number; grant_claimed: number; grant_outstanding: number
    submitted: boolean; deadline: string; findings: string[]
  }
  year: number
  years: number[]
  history: { year: number; seta: string | null; submitted_at: string | null; grant_expected: number; grant_received: number; status: string; reference: string | null }[]
}>()

const rand = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const chosen = ref(props.year)
function load() {
  router.get(route('payroll.skills-levy.index'), { year: chosen.value }, { preserveState: true })
}

const form = useForm({
  year: props.year, seta: '', sdl_number: '', reference: '',
  submitted: props.report.submitted, grant_received: props.report.grant_claimed,
  grant_received_on: '', status: 'submitted', notes: '',
})
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ s('page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ s('period', { from: report.period.from, to: report.period.to }) }}
        </p>
      </div>
      <VSelect
        v-model="chosen" :items="years" :label="s('levy_year')" density="compact"
        variant="outlined" hide-details style="min-width:9rem" @update:model-value="load"
      />
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ s('what_this_is') }}</VAlert>

    <VRow class="mb-2">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ s('leviable') }}</div>
          <div class="text-h6">{{ rand(report.leviable_payroll) }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ s('levy_paid') }}</div>
          <div class="text-h6">{{ rand(report.levy_paid) }}</div>
          <div v-if="report.exempt" class="text-caption text-success">{{ s('exempt') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ s('claimable') }}</div>
          <div class="text-h6">{{ rand(report.mandatory_grant) }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ s('outstanding') }}</div>
          <div class="text-h6" :class="report.grant_outstanding ? 'text-warning' : ''">{{ rand(report.grant_outstanding) }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <!-- Each of these is money nothing else in the system would notice. -->
    <VAlert v-if="report.findings.length" type="warning" variant="tonal" class="mb-4" :title="s('worth_knowing')">
      <div v-for="(f, i) in report.findings" :key="i" class="text-body-2">{{ f }}</div>
    </VAlert>
    <VAlert v-else type="success" variant="tonal" density="compact" class="mb-4">{{ s('all_good') }}</VAlert>

    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ s('record_title') }}</VCardTitle>
      <VCardSubtitle class="pb-2">{{ s('record_help', { deadline: report.deadline }) }}</VCardSubtitle>
      <VCardText class="d-flex flex-column ga-3">
        <div class="field-row">
          <VTextField v-model="form.seta" :label="s('seta')" placeholder="MERSETA" />
          <VTextField v-model="form.sdl_number" :label="s('sdl_number')" />
          <VTextField v-model="form.reference" :label="s('reference')" />
        </div>
        <div class="field-row">
          <VTextField v-model.number="form.grant_received" type="number" :label="s('grant_received')" />
          <VTextField v-model="form.grant_received_on" type="date" :label="s('received_on')" />
          <VSelect v-model="form.status" :items="['draft', 'submitted', 'approved', 'rejected', 'paid']" :label="s('status')" />
        </div>
        <VSwitch v-model="form.submitted" :label="s('submitted')" density="compact" hide-details inset />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn color="primary" :loading="form.processing"
          @click="form.post(route('payroll.skills-levy.record'), { preserveScroll: true })">{{ s('save') }}</VBtn>
      </VCardActions>
    </VCard>

    <VCard v-if="history.length" elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ s('history') }}</VCardTitle>
      <div style="overflow-x:auto">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ s('levy_year') }}</th><th>{{ s('seta') }}</th><th>{{ s('submitted') }}</th>
              <th class="text-end">{{ s('claimable') }}</th><th class="text-end">{{ s('grant_received') }}</th>
              <th>{{ s('status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in history" :key="row.year">
              <td>{{ row.year }}</td>
              <td>{{ row.seta || '—' }}</td>
              <td>{{ row.submitted_at?.slice(0, 10) || '—' }}</td>
              <td class="text-end">{{ rand(row.grant_expected) }}</td>
              <td class="text-end">{{ rand(row.grant_received) }}</td>
              <td><VChip size="x-small" variant="tonal">{{ row.status }}</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>
</template>

<style scoped>
.field-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }
.field-row > * { flex: 1 1 11rem; min-width: 0; }
</style>
