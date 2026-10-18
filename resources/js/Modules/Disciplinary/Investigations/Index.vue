<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Investigation {
  id: number; investigation_number: string
  case_number: string|null; case_title: string|null; employee_name: string
  investigator: string; status: string; status_color: string
  due_date: string|null; progress: { total: number; done: number; pct: number }; created_at: string
}
interface Employee { id: number; name: string; email: string }
interface CaseOption { id: number; label: string }

const props = defineProps<{
  investigations: { data: Investigation[]; links: unknown[] }
  employees: Employee[]
  cases: CaseOption[]
}>()

const createDialog = ref(false)
const createForm = useForm({
  case_id:        null as number|null,
  investigator_id:null as number|null,
  objective:      '',
  scope:          '',
  start_date:     '',
  due_date:       '',
})

function submitCreate() {
  createForm.post(route('disciplinary.investigations.store'), {
    onSuccess: () => { createDialog.value = false; createForm.reset() },
  })
}

const statusColors: Record<string, string> = {
  pending: 'grey', in_progress: 'primary', completed: 'success', suspended: 'warning',
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.investigations') }}</h1>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">
        {{ t('disciplinary.new_investigation') }}
      </VBtn>
    </div>

    <VCard>
      <VDataTable
        :items="investigations.data"
        :headers="[
          { title: t('disciplinary.number'), key: 'investigation_number' },
          { title: t('disciplinary.case'), key: 'case_number' },
          { title: t('disciplinary.employee'), key: 'employee_name' },
          { title: t('disciplinary.investigator'), key: 'investigator' },
          { title: t('disciplinary.status'), key: 'status', align: 'center' },
          { title: t('disciplinary.progress'), key: 'progress', align: 'center' },
          { title: t('disciplinary.due_date'), key: 'due_date' },
          { title: '', key: 'actions', align: 'end', sortable: false },
        ]"
        density="comfortable"
        hover
      >
        <template #item.status="{ item }">
          <VChip :color="item.status_color" size="small">{{ item.status.replace('_', ' ') }}</VChip>
        </template>
        <template #item.progress="{ item }">
          <div v-if="item.progress.total > 0" class="d-flex align-center ga-1">
            <VProgressCircular :model-value="item.progress.pct" :color="item.status_color" size="28" width="3">
              <span style="font-size:8px">{{ item.progress.pct }}%</span>
            </VProgressCircular>
            <span class="text-caption text-medium-emphasis">{{ item.progress.done }}/{{ item.progress.total }}</span>
          </div>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn :href="route('disciplinary.investigations.show', item.id)" size="x-small" variant="tonal" prepend-icon="tabler-eye">
            {{ t('disciplinary.view') }}
          </VBtn>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create Dialog -->
    <VDialog v-model="createDialog" max-width="560">
      <VCard :title="t('disciplinary.new_investigation')">
        <VCardText class="d-flex flex-column ga-3">
          <VAutocomplete v-model="createForm.case_id" :items="cases" item-title="label" item-value="id" :label="t('disciplinary.select_case')" variant="outlined" density="compact" clearable :error-messages="createForm.errors.case_id" />
          <VAutocomplete v-model="createForm.investigator_id" :items="employees" item-title="name" item-value="id" :label="t('disciplinary.investigator')" variant="outlined" density="compact" clearable />
          <VTextarea v-model="createForm.objective" :label="t('disciplinary.objective')" variant="outlined" density="compact" rows="2" />
          <VTextarea v-model="createForm.scope" :label="t('disciplinary.scope')" variant="outlined" density="compact" rows="2" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="createForm.start_date" :label="t('disciplinary.start_date')" variant="outlined" density="compact" type="date" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="createForm.due_date" :label="t('disciplinary.due_date')" variant="outlined" density="compact" type="date" />
            </VCol>
          </VRow>
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
