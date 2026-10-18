<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Calendar = {
  id: string; name: string; timezone: string; is_default: boolean
  work_week: Record<string, Array<[string, string]>>
  exceptions: Array<{ id: string; date: string; is_working: boolean; reason: string | null }>
}

const props = defineProps<{
  calendars: Calendar[]
  standardWeek: Record<string, Array<[string, string]>>
  timezones: string[]
}>()

const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const

const dialog = ref(false)
const editingId = ref<string | null>(null)

/**
 * The working week is edited as "which days, and what hours", not as raw JSON. That covers the
 * shape almost every calendar actually has; split shifts (two windows in a day) are preserved on
 * an existing calendar rather than being flattened — see `load()`.
 */
const workingDays = ref<string[]>(['mon', 'tue', 'wed', 'thu', 'fri'])
const startTime = ref('08:00')
const endTime = ref('17:00')
const splitShiftWarning = ref(false)

const form = useForm<{ name: string; timezone: string; work_week: Record<string, Array<[string, string]>> }>({
  name: '',
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
  work_week: JSON.parse(JSON.stringify(props.standardWeek)),
})

function openNew() {
  editingId.value = null
  form.reset()
  workingDays.value = ['mon', 'tue', 'wed', 'thu', 'fri']
  startTime.value = '08:00'
  endTime.value = '17:00'
  splitShiftWarning.value = false
  dialog.value = true
}

function openEdit(cal: Calendar) {
  editingId.value = cal.id
  form.name = cal.name
  form.timezone = cal.timezone
  form.work_week = JSON.parse(JSON.stringify(cal.work_week ?? {}))

  workingDays.value = DAYS.filter(d => (cal.work_week?.[d] ?? []).length > 0)

  const first = workingDays.value.length ? cal.work_week[workingDays.value[0]][0] : null
  startTime.value = first?.[0] ?? '08:00'
  endTime.value = first?.[1] ?? '17:00'

  // This editor sets one window per day. Say so rather than silently discarding the second.
  splitShiftWarning.value = DAYS.some(d => (cal.work_week?.[d] ?? []).length > 1)

  dialog.value = true
}

/** Fold the day/hour choices back into the stored shape. */
function buildWorkWeek(): Record<string, Array<[string, string]>> {
  const week: Record<string, Array<[string, string]>> = {}
  for (const day of DAYS) {
    week[day] = workingDays.value.includes(day) ? [[startTime.value, endTime.value]] : []
  }
  return week
}

function submit() {
  form.work_week = buildWorkWeek()

  const options = { preserveScroll: true, onSuccess: () => { dialog.value = false } }

  editingId.value
    ? form.patch(route('project-management.setup.calendars.update', editingId.value), options)
    : form.post(route('project-management.setup.calendars.store'), options)
}

function hoursFor(cal: Calendar, day: string): string {
  const windows = cal.work_week?.[day] ?? []
  if (!windows.length) return '—'
  return windows.map(w => `${w[0]}–${w[1]}`).join(', ')
}

const timesInvalid = () => startTime.value >= endTime.value
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.calendars.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.calendars.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">
        {{ t('project_management.calendars.new') }}
      </VBtn>
    </div>

    <VCard v-for="cal in calendars" :key="cal.id" border class="mb-4">
      <VCardItem>
        <VCardTitle class="d-flex align-center gap-2">
          {{ cal.name }}
          <VChip v-if="cal.is_default" size="x-small" color="primary" label>
            {{ t('project_management.calendars.default') }}
          </VChip>
        </VCardTitle>
        <VCardSubtitle>{{ cal.timezone }}</VCardSubtitle>
        <template #append>
          <VBtn size="small" variant="text" prepend-icon="tabler-pencil" @click="openEdit(cal)">
            {{ t('project_management.actions.edit') }}
          </VBtn>
        </template>
      </VCardItem>
      <VDivider />
      <VTable density="compact">
        <thead>
          <tr><th v-for="d in DAYS" :key="d">{{ t(`project_management.calendars.day_${d}`) }}</th></tr>
        </thead>
        <tbody>
          <tr><td v-for="d in DAYS" :key="d" class="text-no-wrap">{{ hoursFor(cal, d) }}</td></tr>
        </tbody>
      </VTable>
      <VCardText v-if="cal.exceptions.length">
        <div class="text-caption text-medium-emphasis mb-2">
          {{ t('project_management.calendars.exceptions') }}
        </div>
        <VChip v-for="ex in cal.exceptions" :key="ex.id" size="small" class="me-2 mb-2"
               :color="ex.is_working ? 'success' : 'warning'" label>
          {{ ex.date }} · {{ ex.reason ?? (ex.is_working
            ? t('project_management.calendars.working')
            : t('project_management.calendars.non_working')) }}
        </VChip>
      </VCardText>
    </VCard>

    <VCard v-if="!calendars.length" border>
      <VCardText class="text-center text-medium-emphasis py-8">
        {{ t('project_management.calendars.empty') }}
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editingId ? t('project_management.calendars.edit') : t('project_management.calendars.new') }}
          </VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VTextField v-model="form.name" :label="t('project_management.calendars.field_name')"
                      :error-messages="form.errors.name" variant="outlined" density="compact" class="mb-3" />
          <VAutocomplete v-model="form.timezone" :items="timezones"
                         :label="t('project_management.calendars.field_timezone')"
                         :error-messages="form.errors.timezone" variant="outlined" density="compact"
                         class="mb-4" />

          <label class="text-caption text-medium-emphasis d-block mb-2">
            {{ t('project_management.calendars.working_days') }}
          </label>
          <VChipGroup v-model="workingDays" multiple column class="mb-3">
            <VChip v-for="d in DAYS" :key="d" :value="d" filter variant="outlined">
              {{ t(`project_management.calendars.day_${d}`) }}
            </VChip>
          </VChipGroup>

          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="startTime" type="time"
                          :label="t('project_management.calendars.field_start')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="endTime" type="time"
                          :label="t('project_management.calendars.field_end')"
                          :error-messages="timesInvalid() ? t('project_management.calendars.times_invalid') : ''"
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>

          <VAlert v-if="splitShiftWarning" type="warning" variant="tonal" density="compact" class="mt-2">
            {{ t('project_management.calendars.split_shift_warning') }}
          </VAlert>

          <p class="text-caption text-medium-emphasis mt-3 mb-0">
            {{ t('project_management.calendars.hint_working_week') }}
          </p>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing"
                :disabled="!form.name || !workingDays.length || timesInvalid()" @click="submit">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
