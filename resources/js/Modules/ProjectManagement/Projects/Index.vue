<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import CalendarPicker from './CalendarPicker.vue'
import ClientPicker from './ClientPicker.vue'

const { t } = useI18n()
const route = ziggyRoute

type Project = {
  id: string; code: string; name: string; status: string; stage: string; priority: string
  client: string | null; currency: string; budget_amount: string | null
  planned_start: string | null; planned_end: string | null; is_billable: boolean
}

const props = defineProps<{
  projects: { data: Project[]; meta: { current_page: number; last_page: number; total: number } }
  filters: { status?: string; search?: string }
  statuses: string[]
  stages: string[]
  priorities: string[]
  calendars: Array<{ id: string; name: string; timezone: string }>
  timezones: Array<{ value: string; title: string }>
  capabilities: Record<string, boolean>
  can: { create: boolean; manageSettings?: boolean }
  /** The code this project will get unless the user overwrites it. */
  nextCode: string
  currencies: Array<{ code: string; name: string; priority: boolean }>
}>()

/** "ZAR — South African Rand": the code is what gets stored, the name is what makes it findable. */
const currencyItems = computed(() => props.currencies.map(c => ({
  title: `${c.code} — ${c.name}`,
  value: c.code,
})))

const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')

let timer: ReturnType<typeof setTimeout>
watch([search, status], () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.get(
      route('project-management.projects.index'),
      { filter: { search: search.value || undefined, status: status.value || undefined } },
      { preserveState: true, replace: true },
    )
  }, 300)
})

const dialog = ref(false)

// Money is a string on the wire so a JS float can never round it (PM-ADR-006).
const form = useForm({
  name: '',
  code: '',
  description: '',
  currency: 'ZAR',
  budget_amount: '',
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
  status: 'draft',
  stage: 'initiation',
  priority: 'medium',
  planned_start: '',
  planned_end: '',
  location_name: '',
  is_billable: true,
  calendar_uuid: '',
  client_id: null as string | null,
})

function openNew() {
  form.reset()
  // Show the code up front rather than asking the user to leave it blank and hope.
  form.code = props.nextCode
  dialog.value = true
}

function submit() {
  form.transform(data => ({
    ...data,
    budget_amount: data.budget_amount === '' ? null : data.budget_amount,
    planned_start: data.planned_start || null,
    planned_end: data.planned_end || null,
    calendar_uuid: data.calendar_uuid || null,
    client_id: data.client_id || null,
  })).post(route('project-management.projects.store'), {
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

const statusColor: Record<string, string> = {
  draft: 'grey', active: 'success', on_hold: 'warning',
  completed: 'info', cancelled: 'error', archived: 'grey',
}

function money(amount: string | null | undefined, currency: string | undefined) {
  // Redacted for external roles: the key is absent, not null (PM-1021).
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZAR' }).format(Number(amount))
  } catch {
    return `${currency} ${amount}`
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.projects.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.projects.subtitle') }}</p>
      </div>
      <VBtn v-if="can.create" color="primary" prepend-icon="tabler-plus" @click="openNew">
        {{ t('project_management.projects.new') }}
      </VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="search"
          :label="t('project_management.projects.filter_search')"
          density="compact" variant="outlined" hide-details clearable
          prepend-inner-icon="tabler-search" style="max-width:280px"
        />
        <VSelect
          v-model="status"
          :items="statuses.map(s => ({ title: t(`project_management.status.${s}`), value: s }))"
          :label="t('project_management.projects.filter_status')"
          density="compact" variant="outlined" hide-details clearable style="max-width:220px"
        />
      </VCardText>
    </VCard>

    <VCard border>
      <VTable v-if="projects.data.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.projects.col_code') }}</th>
            <th>{{ t('project_management.projects.col_name') }}</th>
            <th>{{ t('project_management.projects.col_client') }}</th>
            <th>{{ t('project_management.projects.col_status') }}</th>
            <th>{{ t('project_management.projects.col_dates') }}</th>
            <th class="text-right">{{ t('project_management.projects.col_budget') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="p in projects.data" :key="p.id" style="cursor:pointer"
            @click="router.get(route('project-management.projects.show', p.id))"
          >
            <td class="font-weight-medium">{{ p.code }}</td>
            <td>{{ p.name }}</td>
            <td>{{ p.client ?? '—' }}</td>
            <td>
              <VChip size="small" :color="statusColor[p.status] ?? 'grey'" label>
                {{ t(`project_management.status.${p.status}`) }}
              </VChip>
            </td>
            <td class="text-no-wrap">{{ p.planned_start ?? '—' }} → {{ p.planned_end ?? '—' }}</td>
            <td class="text-right">{{ money(p.budget_amount, p.currency) }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.projects.empty') }}
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="720">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.projects.new') }}</VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol cols="12" md="8">
              <VTextField
                v-model="form.name" :label="t('project_management.projects.field_name')"
                :error-messages="form.errors.name" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.code" :label="t('project_management.projects.field_code')"
                :hint="t('project_management.projects.hint_code')" persistent-hint
                :error-messages="form.errors.code" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.description" :label="t('project_management.projects.field_description')"
                rows="2" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <!-- Who the job is for. Searches CRM when it is installed and PM's own list when it
                   is not, and can add one without leaving the form. -->
              <ClientPicker v-model="form.client_id" :error-message="form.errors.client_id" />
            </VCol>
            <VCol cols="12" md="4">
              <VAutocomplete
                v-model="form.currency" :items="currencyItems"
                :label="t('project_management.projects.field_currency')"
                :error-messages="form.errors.currency" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="form.budget_amount" :label="t('project_management.projects.field_budget')"
                :error-messages="form.errors.budget_amount" variant="outlined" density="compact"
                inputmode="decimal"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VAutocomplete
                v-model="form.timezone" :items="timezones"
                :label="t('project_management.projects.field_timezone')"
                :error-messages="form.errors.timezone" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect
                v-model="form.status"
                :items="statuses.map(s => ({ title: t(`project_management.status.${s}`), value: s }))"
                :label="t('project_management.projects.col_status')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect
                v-model="form.stage"
                :items="stages.map(s => ({ title: t(`project_management.stage.${s}`), value: s }))"
                :label="t('project_management.projects.field_stage')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect
                v-model="form.priority"
                :items="priorities.map(p => ({ title: t(`project_management.priority.${p}`), value: p }))"
                :label="t('project_management.projects.field_priority')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <!--
                Pick a calendar, or make one without leaving the form.

                Every date the scheduler computes comes from this, so a project without one has
                dates that mean nothing — and on a tenant that has not been through Setup the list
                was empty with no way forward from here.
              -->
              <CalendarPicker
                v-model="form.calendar_uuid"
                :calendars="calendars" :timezones="timezones"
                :can-create="can.manageSettings"
                :hint="t('project_management.projects.hint_calendar')"
                :error-message="form.errors.calendar_uuid"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.location_name" :label="t('project_management.projects.field_location')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.planned_start" type="date"
                :label="t('project_management.projects.field_planned_start')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.planned_end" type="date"
                :label="t('project_management.projects.field_planned_end')"
                :error-messages="form.errors.planned_end" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VSwitch
                v-model="form.is_billable" color="primary" density="compact" hide-details
                :label="t('project_management.projects.field_billable')"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="submit">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
