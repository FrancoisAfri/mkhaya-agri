<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Appeal {
  id: number; appeal_number: string; employee: string; employee_id: number
  case_number: string|null; grounds: string; deadline_date: string|null
  submitted_at: string; status: string; chairperson: string|null
  outcome: { verdict: string }|null
}
interface Stats { submitted: number; under_review: number; hearing_scheduled: number; decided: number; total: number }
interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number }
interface Employee { id: number; name: string; email: string }
interface OpenCase    { id: number; label: string }
interface OpenHearing { id: number; label: string }

const props = defineProps<{
  appeals: Paginated<Appeal>
  stats: Stats
  filters: Record<string, string>
  employees: Employee[]
  open_cases: OpenCase[]
  open_hearings: OpenHearing[]
}>()

const submitDialog = ref(false)
const submitForm   = useForm({
  case_id: null as number|null, employee_id: null as number|null, grounds: '', supporting_argument: '',
  deadline_date: '', hearing_id: null as number|null,
})

function doSubmit() {
  submitForm.post(route('disciplinary.appeals.submit'), {
    onSuccess: () => { submitDialog.value = false; submitForm.reset() },
  })
}

const statusColor = (s: string) => ({
  submitted: 'blue', under_review: 'orange', hearing_scheduled: 'purple',
  decided: 'success', withdrawn: 'grey', lapsed: 'grey',
})[s] ?? 'grey'

const verdictColor = (v: string) => ({
  upheld: 'success', dismissed: 'error', partially_upheld: 'warning',
})[v] ?? 'grey'

const statsLabels: Record<string, string> = {
  submitted: t('disciplinary_appeals.stats.submitted'),
  under_review: t('disciplinary_appeals.stats.under_review'),
  hearing_scheduled: t('disciplinary_appeals.stats.hearing_scheduled'),
  decided: t('disciplinary_appeals.stats.decided'),
  total: t('disciplinary_appeals.stats.total'),
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_appeals.index.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_appeals.index.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-scale" @click="submitDialog = true">{{ t('disciplinary_appeals.index.submit_appeal') }}</VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="12" sm="4" md="2" v-for="(val, key) in stats" :key="key">
        <VCard variant="tonal" :color="key === 'decided' ? 'success' : 'primary'">
          <VCardText class="text-center py-3">
            <div class="text-h5 font-weight-bold">{{ val }}</div>
            <div class="text-caption text-uppercase">{{ statsLabels[key] ?? key }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard>
      <VDataTable
        :items="appeals.data"
        :headers="[
          { title: t('disciplinary_appeals.index.table.appeal_no'), key: 'appeal_number' },
          { title: t('disciplinary_appeals.index.table.employee'), key: 'employee' },
          { title: t('disciplinary_appeals.index.table.case'), key: 'case_number' },
          { title: t('disciplinary_appeals.index.table.submitted'), key: 'submitted_at' },
          { title: t('disciplinary_appeals.index.table.deadline'), key: 'deadline_date' },
          { title: t('disciplinary_appeals.index.table.status'), key: 'status' },
          { title: t('disciplinary_appeals.index.table.outcome'), key: 'outcome' },
          { title: t('disciplinary_appeals.index.table.chairperson'), key: 'chairperson' },
          { title: t('disciplinary_appeals.index.table.actions'), key: '_actions', sortable: false },
        ]"
      >
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ item.status.replace('_', ' ') }}</VChip>
        </template>
        <template #item.outcome="{ item }">
          <VChip v-if="item.outcome" :color="verdictColor(item.outcome.verdict)" size="small" variant="tonal">
            {{ item.outcome.verdict.replace('_', ' ') }}
          </VChip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.case_number="{ item }">{{ item.case_number ?? '—' }}</template>
        <template #item.chairperson="{ item }">{{ item.chairperson ?? t('disciplinary_appeals.index.table.not_assigned') }}</template>
        <template #item.deadline_date="{ item }">{{ item.deadline_date ?? '—' }}</template>
        <template #item._actions="{ item }">
          <VBtn size="small" variant="text" color="primary" :href="route('disciplinary.appeals.show', item.id)">{{ t('disciplinary_appeals.index.table.view') }}</VBtn>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="submitDialog" max-width="600">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_appeals.index.dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="6">
              <VAutocomplete
                v-model="submitForm.case_id"
                :items="open_cases"
                item-title="label"
                item-value="id"
                :label="t('disciplinary_appeals.index.dialog.case_label')"
                variant="outlined"
                density="compact"
                :error-messages="submitForm.errors.case_id"
                clearable
              />
            </VCol>
            <VCol cols="6">
              <VAutocomplete
                v-model="submitForm.employee_id"
                :items="employees"
                item-title="name"
                item-value="id"
                :label="t('disciplinary_appeals.index.dialog.employee_label')"
                variant="outlined"
                density="compact"
                :error-messages="submitForm.errors.employee_id"
                clearable
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
                </template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="submitForm.grounds" :label="t('disciplinary_appeals.index.dialog.grounds_label')" rows="3" :error-messages="submitForm.errors.grounds" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="submitForm.supporting_argument" :label="t('disciplinary_appeals.index.dialog.supporting_label')" rows="3" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="submitForm.deadline_date" :label="t('disciplinary_appeals.index.dialog.deadline_label')" type="date" />
            </VCol>
            <VCol cols="6">
              <VAutocomplete
                v-model="submitForm.hearing_id"
                :items="open_hearings"
                item-title="label"
                item-value="id"
                :label="t('disciplinary_appeals.index.dialog.hearing_label')"
                variant="outlined"
                density="compact"
                :error-messages="submitForm.errors.hearing_id"
                clearable
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="submitDialog = false">{{ t('disciplinary_appeals.index.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="submitForm.processing" @click="doSubmit">{{ t('disciplinary_appeals.index.dialog.submit') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
