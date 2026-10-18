<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

/**
 * The working calendar on a project, with a way to make one.
 *
 * Every date the scheduler computes comes from this — a task's finish, a milestone's slip, whether
 * Saturday counts. So a project without a calendar is a project whose dates mean nothing, and it
 * was a plain dropdown over whatever Setup happened to contain. On a tenant that had not been
 * through Setup that is an empty list: the field cannot be filled, and the only route to filling it
 * is to abandon the half-typed project, go to Setup, make a calendar, and come back to start again.
 *
 * So it is a picker that can also create, in the shape ClientPicker already established here.
 * Creating posts to Setup's own endpoint rather than a second one of its own — one place that makes
 * calendars, one set of rules about what a valid week looks like.
 */
const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  modelValue: string | null
  calendars: Array<{ id: string; name: string; timezone: string }>
  timezones: Array<{ value: string; title: string }>
  /** Making one is behind the same gate as the rest of Setup; without it, only the list is offered. */
  canCreate?: boolean
  errorMessage?: string
  hint?: string
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: string | null): void }>()

/** Anything created here, kept in front of the prop so a new calendar is selectable at once. */
const added = ref<Array<{ title: string; value: string }>>([])

const items = computed(() => [
  ...added.value,
  ...props.calendars.map(c => ({ title: `${c.name} · ${c.timezone}`, value: c.id })),
])

// ── Making one ───────────────────────────────────────────────────────────────

const dialog = ref(false)

/*
 * Monday to Friday, eight to five, and a stated assumption rather than a hidden one.
 *
 * A blank week would be a calendar on which nothing is a working day, so every task scheduled
 * against it would compute a finish date of never. The form shows the hours it is about to save so
 * that somebody whose site works Saturdays can see they need to say so.
 */
const STANDARD_WEEK = {
  mon: [['08:00', '17:00']],
  tue: [['08:00', '17:00']],
  wed: [['08:00', '17:00']],
  thu: [['08:00', '17:00']],
  fri: [['08:00', '17:00']],
  sat: [] as string[][],
  sun: [] as string[][],
}

const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const

const form = useForm({
  name: '',
  timezone: 'Africa/Johannesburg',
  work_week: JSON.parse(JSON.stringify(STANDARD_WEEK)) as Record<string, string[][]>,
})

/** A day is working or it is not; the hours are the same on the days that are. */
const start = ref('08:00')
const end = ref('17:00')
const workingDays = ref<string[]>(['mon', 'tue', 'wed', 'thu', 'fri'])

function openAdd() {
  form.reset()
  form.clearErrors()
  start.value = '08:00'
  end.value = '17:00'
  workingDays.value = ['mon', 'tue', 'wed', 'thu', 'fri']
  dialog.value = true
}

function save() {
  form.work_week = Object.fromEntries(
    DAYS.map(day => [day, workingDays.value.includes(day) ? [[start.value, end.value]] : []]),
  )

  form.post(route('project-management.setup.calendars.store'), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: page => {
      const created = (page.props.flash as any)?.pmCalendar

      if (created?.id) {
        added.value = [{ title: created.label, value: created.id }, ...added.value]
        emit('update:modelValue', created.id)
      }

      dialog.value = false
      form.reset()
    },
  })
}
</script>

<template>
  <div>
    <VAutocomplete
      :model-value="modelValue"
      :items="items"
      :label="t('project_management.projects.field_calendar')"
      :error-messages="errorMessage"
      :hint="hint"
      persistent-hint
      variant="outlined"
      density="compact"
      clearable
      @update:model-value="value => emit('update:modelValue', value)"
    >
      <template v-if="canCreate" #append-item>
        <VDivider class="my-1" />
        <VListItem prepend-icon="tabler-plus" :title="t('project_management.calendars.add')" @click="openAdd" />
      </template>

      <!-- An empty list is the case this component exists for, so it says what to do about it. -->
      <template #no-data>
        <VListItem>
          <VListItemTitle class="text-body-2 text-medium-emphasis">
            {{ t('project_management.calendars.none_yet') }}
          </VListItemTitle>
        </VListItem>
        <template v-if="canCreate">
          <VDivider class="my-1" />
          <VListItem prepend-icon="tabler-plus" :title="t('project_management.calendars.add')" @click="openAdd" />
        </template>
      </template>
    </VAutocomplete>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle class="text-body-1">{{ t('project_management.calendars.add') }}</VCardTitle>
        <VCardSubtitle class="text-wrap pb-2">{{ t('project_management.calendars.add_sub') }}</VCardSubtitle>
        <VDivider />

        <VCardText>
          <VTextField
            v-model="form.name" variant="outlined" density="compact" class="mb-3"
            :label="t('project_management.calendars.field_name')"
            :placeholder="t('project_management.calendars.name_placeholder')"
            :error-messages="form.errors.name"
          />

          <VAutocomplete
            v-model="form.timezone" :items="timezones" variant="outlined" density="compact" class="mb-3"
            :label="t('project_management.calendars.field_timezone')"
            :error-messages="form.errors.timezone"
          />

          <div class="text-body-2 font-weight-medium mb-1">
            {{ t('project_management.calendars.working_days') }}
          </div>
          <VBtnToggle v-model="workingDays" multiple density="compact" variant="outlined" class="mb-3 flex-wrap">
            <VBtn v-for="day in DAYS" :key="day" :value="day" size="small">
              {{ t(`project_management.calendars.day_${day}`) }}
            </VBtn>
          </VBtnToggle>

          <div class="d-flex ga-2">
            <VTextField
              v-model="start" type="time" variant="outlined" density="compact"
              :label="t('project_management.calendars.field_start')"
            />
            <VTextField
              v-model="end" type="time" variant="outlined" density="compact"
              :label="t('project_management.calendars.field_end')"
            />
          </div>

          <!-- Said plainly, because a calendar with no working days schedules nothing, ever. -->
          <VAlert
            v-if="!workingDays.length" type="warning" variant="tonal" density="compact" class="mt-3"
          >{{ t('project_management.calendars.no_days_warning') }}</VAlert>
        </VCardText>

        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="form.processing"
            :disabled="!form.name || !workingDays.length"
            @click="save"
          >{{ t('project_management.actions.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
