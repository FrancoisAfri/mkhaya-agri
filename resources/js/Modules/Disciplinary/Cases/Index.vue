<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface CaseRow {
  id: number; uuid: string; case_number: string; title: string
  employee_name: string; category: string|null; severity: string|null
  status: string|null; status_color: string|null; incident_date: string
  hearing_date: string|null; is_closed: boolean; created_at: string
}
interface Status   { id: number; name: string; color: string; is_closed: boolean }
interface Category { id: number; name: string; severity_level: string }
interface Employee { id: number; name: string; email: string }
interface Pagination<T> {
  data: T[]; current_page: number; last_page: number; per_page: number; total: number
}

const props = defineProps<{
  cases:      Pagination<CaseRow>
  statuses:   Status[]
  categories: Category[]
  employees:  Employee[]
}>()

const createDrawer = ref(false)
const filterStatus = ref<number|null>(null)

const createForm = useForm({
  employee_id:    null as number|null,
  category_id:    null as number|null,
  title:          '',
  description:    '',
  incident_date:  '',
  policy_reference: '',
  internal_notes: '',
})

function submitCreate() {
  createForm.post(route('disciplinary.cases.store'), {
    onSuccess: () => { createDrawer.value = false; createForm.reset() },
  })
}

const headers = computed(() => [
  { title: t('disciplinary.case_number'), key: 'case_number', sortable: true },
  { title: t('disciplinary.employee'),    key: 'employee_name', sortable: true },
  { title: t('disciplinary.title'),       key: 'title', sortable: true },
  { title: t('disciplinary.category'),    key: 'category', sortable: false },
  { title: t('disciplinary.status'),      key: 'status', sortable: false },
  { title: t('disciplinary.incident_date'), key: 'incident_date', sortable: true },
  { title: t('disciplinary.hearing_date'),  key: 'hearing_date', sortable: true },
  { title: '',                            key: 'actions', sortable: false, align: 'end' as const },
])

const filteredCases = computed(() =>
  filterStatus.value
    ? props.cases.data.filter(c => props.statuses.find(s => s.id === filterStatus.value)?.name === c.status)
    : props.cases.data
)

const severityColor = (s: string|null) => ({ minor: 'success', serious: 'warning', gross: 'error' })[s ?? ''] ?? 'grey'
const cap = (s: string | null | undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.cases') }}</h1>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" prepend-icon="tabler-layout-dashboard" :href="route('disciplinary.dashboard')">{{ t('disciplinary.dashboard') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="createDrawer = true">{{ t('disciplinary.new_case') }}</VBtn>
      </div>
    </div>

    <!-- Filters -->
    <VCard class="mb-4">
      <VCardText class="d-flex ga-3 flex-wrap align-center py-3">
        <VSelect
          v-model="filterStatus" :items="statuses" item-title="name" item-value="id"
          :label="t('disciplinary.filter_by_status')" variant="outlined" density="compact"
          clearable hide-details style="min-width:200px"
        />
        <VChip v-if="filterStatus" color="primary" closable @click:close="filterStatus = null">
          {{ statuses.find(s => s.id === filterStatus)?.name }}
        </VChip>
        <VSpacer />
        <span class="text-body-2 text-medium-emphasis">
          {{ t('disciplinary.total') }}: {{ cases.total }}
        </span>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VDataTable :headers="headers" :items="filteredCases" density="compact" hover>
        <template #item.case_number="{ item }">
          <a :href="route('disciplinary.cases.show', { case: item.id })" class="text-primary font-weight-medium text-decoration-none">
            {{ item.case_number }}
          </a>
        </template>
        <template #item.category="{ item }">
          <span v-if="item.category">
            {{ item.category }}
            <VChip :color="severityColor(item.severity)" size="x-small" class="ml-1">{{ cap(item.severity) }}</VChip>
          </span>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="item.status_color ?? 'grey'" size="small">{{ item.status ?? '—' }}</VChip>
        </template>
        <template #item.hearing_date="{ item }">
          {{ item.hearing_date ?? '—' }}
        </template>
        <template #item.actions="{ item }">
          <VBtn icon="tabler-eye" size="x-small" variant="text" color="primary" :href="route('disciplinary.cases.show', { case: item.id })" />
        </template>
        <template #no-data>
          <div class="text-center py-8 text-medium-emphasis">
            <VIcon size="40" class="mb-2">tabler-briefcase</VIcon>
            <p>{{ t('disciplinary.no_cases') }}</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create Case Drawer -->
    <VNavigationDrawer v-model="createDrawer" location="right" width="480" temporary>
      <VToolbar density="compact" color="primary" :title="t('disciplinary.new_case')" />
      <div class="pa-4 d-flex flex-column ga-3">
        <VAutocomplete
          v-model="createForm.employee_id" :items="employees" item-title="name" item-value="id"
          :label="t('disciplinary.employee')" variant="outlined" density="compact"
          :error-messages="createForm.errors.employee_id"
        />
        <VSelect
          v-model="createForm.category_id" :items="categories" item-title="name" item-value="id"
          :label="t('disciplinary.category')" variant="outlined" density="compact" clearable
        />
        <VTextField
          v-model="createForm.title" :label="t('disciplinary.title')" variant="outlined" density="compact"
          :error-messages="createForm.errors.title"
        />
        <VTextarea
          v-model="createForm.description" :label="t('disciplinary.description')" variant="outlined" density="compact"
          rows="4" :error-messages="createForm.errors.description"
        />
        <VTextField
          v-model="createForm.incident_date" :label="t('disciplinary.incident_date')" variant="outlined" density="compact"
          type="date" :error-messages="createForm.errors.incident_date"
        />
        <VTextField
          v-model="createForm.policy_reference" :label="t('disciplinary.policy_reference')" variant="outlined" density="compact"
        />
        <VTextarea v-model="createForm.internal_notes" :label="t('disciplinary.internal_notes')" variant="outlined" density="compact" rows="2" />
        <div class="d-flex ga-2 justify-end mt-2">
          <VBtn variant="outlined" @click="createDrawer = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="createForm.processing" @click="submitCreate">{{ t('disciplinary.create_case') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>
  </div>
</template>
