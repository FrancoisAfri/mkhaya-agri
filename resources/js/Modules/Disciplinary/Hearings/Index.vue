<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Hearing {
  id: number; hearing_number: string; case_number: string|null
  employee_name: string; chairperson: string; status: string; status_color: string
  hearing_type: string; scheduled_at: string; venue: string|null
  notice_issued: boolean; pack_generated: boolean
}
interface Employee { id: number; name: string; email: string }
interface CaseOption { id: number; label: string }

const props = defineProps<{
  hearings: { data: Hearing[]; links: unknown[] }
  employees: Employee[]
  cases: CaseOption[]
}>()

const createDialog = ref(false)
const createForm = useForm({
  case_id:        null as number|null,
  chairperson_id: null as number|null,
  initiator_id:   null as number|null,
  hr_rep_id:      null as number|null,
  hearing_type:   'formal',
  scheduled_at:   '',
  venue:          '',
  venue_type:     'physical',
  meeting_link:   '',
})

function submitCreate() {
  createForm.post(route('disciplinary.hearings.store'), {
    onSuccess: () => { createDialog.value = false; createForm.reset() },
  })
}

const statusIcon: Record<string, string> = {
  scheduled: 'tabler-calendar-clock',
  in_progress: 'tabler-player-play',
  adjourned: 'tabler-player-pause',
  completed: 'tabler-circle-check',
  cancelled: 'tabler-circle-x',
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.hearings') }}</h1>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">
        {{ t('disciplinary.schedule_hearing') }}
      </VBtn>
    </div>

    <VCard>
      <VDataTable
        :items="hearings.data"
        :headers="[
          { title: t('disciplinary.number'), key: 'hearing_number' },
          { title: t('disciplinary.case'), key: 'case_number' },
          { title: t('disciplinary.employee'), key: 'employee_name' },
          { title: t('disciplinary.chairperson'), key: 'chairperson' },
          { title: t('disciplinary.hearing_type'), key: 'hearing_type', align: 'center' },
          { title: t('disciplinary.status'), key: 'status', align: 'center' },
          { title: t('disciplinary.scheduled_at'), key: 'scheduled_at' },
          { title: t('disciplinary.notice'), key: 'notice_issued', align: 'center' },
          { title: '', key: 'actions', align: 'end', sortable: false },
        ]"
        density="comfortable"
        hover
      >
        <template #item.status="{ item }">
          <VChip :color="item.status_color" size="small" :prepend-icon="statusIcon[item.status]">
            {{ item.status.replace('_', ' ') }}
          </VChip>
        </template>
        <template #item.hearing_type="{ item }">
          <VChip size="x-small" color="grey">{{ item.hearing_type }}</VChip>
        </template>
        <template #item.notice_issued="{ item }">
          <VIcon :color="item.notice_issued ? 'success' : 'grey'">
            {{ item.notice_issued ? 'tabler-mail-check' : 'tabler-mail' }}
          </VIcon>
        </template>
        <template #item.actions="{ item }">
          <VBtn :href="route('disciplinary.hearings.show', item.id)" size="x-small" variant="tonal" prepend-icon="tabler-gavel">
            {{ t('disciplinary.view') }}
          </VBtn>
        </template>
      </VDataTable>
    </VCard>

    <!-- Schedule Dialog -->
    <VDialog v-model="createDialog" max-width="560">
      <VCard :title="t('disciplinary.schedule_hearing')">
        <VCardText class="d-flex flex-column ga-3">
          <VAutocomplete v-model="createForm.case_id" :items="cases" item-title="label" item-value="id" :label="t('disciplinary.select_case')" variant="outlined" density="compact" clearable :error-messages="createForm.errors.case_id" />
          <VSelect v-model="createForm.hearing_type" :items="['formal','informal','appeal']" :label="t('disciplinary.hearing_type')" variant="outlined" density="compact" />
          <VTextField v-model="createForm.scheduled_at" :label="t('disciplinary.scheduled_at')" variant="outlined" density="compact" type="datetime-local" />
          <VSelect v-model="createForm.venue_type" :items="['physical','virtual','hybrid']" :label="t('disciplinary.venue_type')" variant="outlined" density="compact" />
          <VTextField v-model="createForm.venue" :label="t('disciplinary.venue')" variant="outlined" density="compact" />
          <VTextField v-if="createForm.venue_type !== 'physical'" v-model="createForm.meeting_link" :label="t('disciplinary.meeting_link')" variant="outlined" density="compact" />
          <VAutocomplete v-model="createForm.chairperson_id" :items="employees" item-title="name" item-value="id" :label="t('disciplinary.chairperson')" variant="outlined" density="compact" clearable />
          <VAutocomplete v-model="createForm.initiator_id" :items="employees" item-title="name" item-value="id" :label="t('disciplinary.initiator')" variant="outlined" density="compact" clearable />
          <VAutocomplete v-model="createForm.hr_rep_id" :items="employees" item-title="name" item-value="id" :label="t('disciplinary.hr_rep')" variant="outlined" density="compact" clearable />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="createDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="createForm.processing" @click="submitCreate">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
