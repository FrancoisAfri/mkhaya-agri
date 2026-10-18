<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

interface Warning {
  id: number; employee: string; employee_id: number
  warning_type: string; issued_date: string; expiry_date: string
  is_expired: boolean; employee_acknowledged: boolean
  description: string; case_number: string|null; issued_by: string
}
interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number }
interface Employee { id: number; name: string; email: string }
interface OpenCase { id: number; label: string }

const props = defineProps<{
  warnings: Paginated<Warning>
  filters: Record<string, string>
  employees: Employee[]
  open_cases: OpenCase[]
}>()

const issueDialog = ref(false)
const issueForm = useForm({
  employee_id: null as number|null,
  warning_type: 'verbal',
  description: '',
  case_id: null as number|null,
  incident_reference: '',
})

function submitWarning() {
  issueForm.post(route('disciplinary.progressive-discipline.warnings.store'), {
    onSuccess: () => { issueDialog.value = false; issueForm.reset() },
  })
}

const typeColor = (t: string) => t === 'verbal' ? 'orange' : t === 'written' ? 'red-darken-1' : 'red-darken-4'
const typeLabel = (t: string) => ({ verbal: 'Verbal', written: 'Written', final_written: 'Final Written' })[t] ?? t
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">Disciplinary Warnings</h1>
        <p class="text-body-2 text-medium-emphasis">Verbal, Written and Final Written Warnings</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="issueDialog = true">Issue Warning</VBtn>
    </div>

    <VCard>
      <VDataTable
        :items="warnings.data"
        :headers="[
          { title: 'Employee', key: 'employee' },
          { title: 'Type', key: 'warning_type' },
          { title: 'Issued', key: 'issued_date' },
          { title: 'Expires', key: 'expiry_date' },
          { title: 'Status', key: 'is_expired' },
          { title: 'Case', key: 'case_number' },
          { title: 'Issued By', key: 'issued_by' },
        ]"
        class="elevation-0"
      >
        <template #item.warning_type="{ item }">
          <VChip :color="typeColor(item.warning_type)" size="small" variant="tonal">
            {{ typeLabel(item.warning_type) }}
          </VChip>
        </template>
        <template #item.is_expired="{ item }">
          <VChip :color="item.is_expired ? 'grey' : 'success'" size="small" variant="tonal">
            {{ item.is_expired ? 'Expired' : 'Active' }}
          </VChip>
        </template>
        <template #item.case_number="{ item }">
          <span v-if="item.case_number">
            <a :href="route('disciplinary.cases.show', item.case_number)" class="text-primary">{{ item.case_number }}</a>
          </span>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
      </VDataTable>
    </VCard>

    <!-- Issue Warning Dialog -->
    <VDialog v-model="issueDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-4">Issue Warning</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VForm @submit.prevent="submitWarning">
            <VRow dense>
              <VCol cols="12">
                <VAutocomplete
                  v-model="issueForm.employee_id"
                  :items="employees"
                  item-title="name"
                  item-value="id"
                  label="Employee *"
                  variant="outlined"
                  density="compact"
                  :error-messages="issueForm.errors.employee_id"
                  clearable
                >
                  <template #item="{ props: itemProps, item }">
                    <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
                  </template>
                </VAutocomplete>
              </VCol>
              <VCol cols="12">
                <VSelect v-model="issueForm.warning_type" label="Warning Type *" :items="[
                  { value: 'verbal', title: 'Verbal Warning' },
                  { value: 'written', title: 'Written Warning' },
                  { value: 'final_written', title: 'Final Written Warning' },
                ]" :error-messages="issueForm.errors.warning_type" />
              </VCol>
              <VCol cols="12">
                <VTextarea v-model="issueForm.description" label="Description *" rows="3" :error-messages="issueForm.errors.description" />
              </VCol>
              <VCol cols="6">
                <VAutocomplete
                  v-model="issueForm.case_id"
                  :items="open_cases"
                  item-title="label"
                  item-value="id"
                  label="Case (optional)"
                  variant="outlined"
                  density="compact"
                  :error-messages="issueForm.errors.case_id"
                  clearable
                />
              </VCol>
              <VCol cols="6">
                <VTextField v-model="issueForm.incident_reference" label="Incident Reference" :error-messages="issueForm.errors.incident_reference" />
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="issueDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="issueForm.processing" @click="submitWarning">Issue</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
