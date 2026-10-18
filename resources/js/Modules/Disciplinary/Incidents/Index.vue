<script setup lang="ts">
import { ref, computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Incident {
  id: number; uuid: string; incident_number: string; title: string
  reporter_name: string; is_anonymous: boolean; category: string|null
  severity_level: string; status: string; status_color: string
  incident_date: string; location: string|null; investigator: string
  auto_assigned: boolean; created_at: string
}
interface Category { id: number; name: string; severity_level: string }
interface StatusOption { value: string; label: string; color: string }
interface Pagination<T> { data: T[]; total: number; current_page: number; last_page: number }

const props = defineProps<{
  incidents:  Pagination<Incident>
  categories: Category[]
  statuses:   StatusOption[]
}>()

const filterStatus   = ref<string|null>(null)
const filterSeverity = ref<string|null>(null)
const search         = ref('')

const severityColor = (s: string|null) => ({ minor: 'success', serious: 'warning', gross: 'error' })[s ?? ''] ?? 'grey'
const cap = (s: string|null|undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'

const headers = computed(() => [
  { title: t('disciplinary.incident_number'), key: 'incident_number', sortable: true },
  { title: t('disciplinary.title'),           key: 'title', sortable: true },
  { title: t('disciplinary.reported_by'),     key: 'reporter_name', sortable: false },
  { title: t('disciplinary.category'),        key: 'category', sortable: false },
  { title: t('disciplinary.severity'),        key: 'severity_level', sortable: false },
  { title: t('disciplinary.status'),          key: 'status', sortable: false },
  { title: t('disciplinary.incident_date'),   key: 'incident_date', sortable: true },
  { title: t('disciplinary.investigator'),    key: 'investigator', sortable: false },
  { title: '',                                key: 'actions', sortable: false, align: 'end' as const },
])

const filtered = computed(() => {
  let rows = props.incidents.data
  if (filterStatus.value)   rows = rows.filter(r => r.status === filterStatus.value)
  if (filterSeverity.value) rows = rows.filter(r => r.severity_level === filterSeverity.value)
  if (search.value)         rows = rows.filter(r =>
    r.title.toLowerCase().includes(search.value.toLowerCase()) ||
    r.incident_number.toLowerCase().includes(search.value.toLowerCase())
  )
  return rows
})
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.incidents') }}</h1>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" prepend-icon="tabler-layout-dashboard" :href="route('disciplinary.dashboard')">{{ t('disciplinary.dashboard') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" :href="route('disciplinary.incidents.report')">{{ t('disciplinary.report_incident') }}</VBtn>
      </div>
    </div>

    <!-- Filters -->
    <VCard class="mb-4">
      <VCardText class="d-flex ga-3 flex-wrap align-center py-3">
        <VTextField
          v-model="search" prepend-inner-icon="tabler-search" :placeholder="t('disciplinary.search')"
          variant="outlined" density="compact" hide-details clearable style="min-width:220px"
        />
        <VSelect
          v-model="filterStatus" :items="statuses" item-title="label" item-value="value"
          :label="t('disciplinary.filter_by_status')" variant="outlined" density="compact"
          clearable hide-details style="min-width:200px"
        />
        <VSelect
          v-model="filterSeverity"
          :items="[{title:'Minor',value:'minor'},{title:'Serious',value:'serious'},{title:'Gross',value:'gross'}]"
          item-title="title" item-value="value"
          :label="t('disciplinary.severity')" variant="outlined" density="compact"
          clearable hide-details style="min-width:160px"
        />
        <VSpacer />
        <span class="text-body-2 text-medium-emphasis">{{ t('disciplinary.total') }}: {{ incidents.total }}</span>
      </VCardText>
    </VCard>

    <!-- Status chips -->
    <div class="d-flex ga-2 flex-wrap mb-4">
      <VChip
        v-for="s in statuses" :key="s.value" :color="s.color" variant="tonal"
        :class="{ 'v-chip--selected': filterStatus === s.value }"
        @click="filterStatus = filterStatus === s.value ? null : s.value"
      >
        {{ s.label }}
        <span class="ml-1 font-weight-bold">{{ incidents.data.filter(i => i.status === s.value).length }}</span>
      </VChip>
    </div>

    <!-- Table -->
    <VCard>
      <VDataTable :headers="headers" :items="filtered" density="compact" hover>
        <template #item.incident_number="{ item }">
          <a :href="route('disciplinary.incidents.show', { incident: item.id })" class="text-primary font-weight-medium text-decoration-none">
            {{ item.incident_number }}
          </a>
        </template>
        <template #item.reporter_name="{ item }">
          <span class="d-flex align-center ga-1">
            <VIcon v-if="item.is_anonymous" size="14" color="grey">tabler-spy</VIcon>
            {{ item.reporter_name }}
          </span>
        </template>
        <template #item.severity_level="{ item }">
          <VChip :color="severityColor(item.severity_level)" size="x-small">{{ cap(item.severity_level) }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip :color="item.status_color" size="small">{{ cap(item.status.replace('_',' ')) }}</VChip>
        </template>
        <template #item.investigator="{ item }">
          <span class="d-flex align-center ga-1">
            {{ item.investigator === 'Unknown' ? '—' : item.investigator }}
            <VTooltip v-if="item.auto_assigned" text="Auto-assigned">
              <template #activator="{ props: tp }"><VIcon v-bind="tp" size="12" color="primary">tabler-tool</VIcon></template>
            </VTooltip>
          </span>
        </template>
        <template #item.actions="{ item }">
          <VBtn icon="tabler-eye" size="x-small" variant="text" color="primary" :href="route('disciplinary.incidents.show', { incident: item.id })" />
        </template>
        <template #no-data>
          <div class="text-center py-8 text-medium-emphasis">
            <VIcon size="40" class="mb-2">tabler-alert-circle</VIcon>
            <p>{{ t('disciplinary.no_incidents') }}</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
