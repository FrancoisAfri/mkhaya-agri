<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

interface Escalation {
  id: number; employee: string; employee_id: number
  from_level: string|null; to_level: string
  reason: string; ai_recommended: boolean; ai_reasoning: string|null
  prior_offences_count: number; approved: boolean
  approved_by: string|null; approved_at: string|null
  triggered_by: string; created_at: string
}
interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number }
interface Employee { id: number; name: string; email: string }
interface OpenCase { id: number; label: string }

const props = defineProps<{
  escalations: Paginated<Escalation>
  employees: Employee[]
  open_cases: OpenCase[]
}>()

const recommendDialog = ref(false)
const recommendForm   = useForm({ employee_id: null as number|null, case_id: null as number|null })

function submitRecommend() {
  recommendForm.post(route('disciplinary.progressive-discipline.escalations.recommend'), {
    onSuccess: () => { recommendDialog.value = false; recommendForm.reset() },
  })
}
function approve(id: number) {
  useForm({}).post(route('disciplinary.progressive-discipline.escalations.approve', id))
}

const levelColor = (l: string) => {
  const map: Record<string, string> = {
    none: 'grey', verbal: 'orange', written: 'red-darken-1',
    final_written: 'red-darken-3', suspension: 'purple', dismissal: 'black',
  }
  return map[l] ?? 'grey'
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">Discipline Escalations</h1>
        <p class="text-body-2 text-medium-emphasis">Progressive discipline level escalation recommendations</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-arrow-up" @click="recommendDialog = true">Recommend Escalation</VBtn>
    </div>

    <VCard>
      <VDataTable
        :items="escalations.data"
        :headers="[
          { title: 'Employee', key: 'employee' },
          { title: 'From', key: 'from_level' },
          { title: 'To', key: 'to_level' },
          { title: 'Prior Offences', key: 'prior_offences_count' },
          { title: 'AI', key: 'ai_recommended' },
          { title: 'Approved', key: 'approved' },
          { title: 'Created', key: 'created_at' },
          { title: 'Actions', key: '_actions', sortable: false },
        ]"
      >
        <template #item.from_level="{ item }">
          <VChip v-if="item.from_level" :color="levelColor(item.from_level)" size="small" variant="tonal">
            {{ item.from_level?.replace('_', ' ') ?? 'None' }}
          </VChip>
          <span v-else class="text-medium-emphasis">None</span>
        </template>
        <template #item.to_level="{ item }">
          <VChip :color="levelColor(item.to_level)" size="small" variant="tonal">
            {{ item.to_level.replace('_', ' ') }}
          </VChip>
        </template>
        <template #item.ai_recommended="{ item }">
          <VIcon v-if="item.ai_recommended" color="blue" size="small">tabler-robot</VIcon>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.approved="{ item }">
          <VChip :color="item.approved ? 'success' : 'warning'" size="small" variant="tonal">
            {{ item.approved ? 'Approved' : 'Pending' }}
          </VChip>
        </template>
        <template #item._actions="{ item }">
          <VBtn v-if="!item.approved" size="small" color="success" variant="tonal" @click="approve(item.id)">
            Approve
          </VBtn>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="recommendDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">Recommend Escalation</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAutocomplete
            v-model="recommendForm.employee_id"
            :items="employees"
            item-title="name"
            item-value="id"
            label="Employee *"
            variant="outlined"
            density="compact"
            :error-messages="recommendForm.errors.employee_id"
            clearable
            class="mb-3"
          >
            <template #item="{ props: itemProps, item }">
              <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
            </template>
          </VAutocomplete>
          <VAutocomplete
            v-model="recommendForm.case_id"
            :items="open_cases"
            item-title="label"
            item-value="id"
            label="Case (optional)"
            variant="outlined"
            density="compact"
            :error-messages="recommendForm.errors.case_id"
            clearable
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="recommendDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="recommendForm.processing" @click="submitRecommend">Recommend</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
