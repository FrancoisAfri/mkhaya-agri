<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PersonField from '@/Modules/ProjectManagement/Components/PersonField.vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Attendee = { id: string; name: string; organisation: string | null; attendance: string }
type Meeting = {
  id: string; title: string; kind: string; starts_at: string; duration: number
  location: string | null; url: string | null; agenda: string | null; minutes: string | null
  status: string; minutes_outstanding: boolean
  project: { id: string; code: string } | null
  attendees: Attendee[]; actions: number; open_actions: number
}

const props = defineProps<{
  meetings: Meeting[]
  projects: Array<{ id: string; label: string }>
  members: Array<{ id: number; name: string; role?: string | null }>
  kinds: string[]
  filter: { project: string | null }
  can: { manage: boolean }
}>()

const upcoming = computed(() => props.meetings
  .filter(m => m.status === 'scheduled' && new Date(m.starts_at) >= new Date())
  .sort((a, b) => a.starts_at.localeCompare(b.starts_at)))

const past = computed(() => props.meetings.filter(m => !upcoming.value.includes(m)))

// Held with no minutes out is the state where decisions quietly evaporate, so it is counted at
// the top rather than left to be spotted in a list.
const outstanding = computed(() => props.meetings.filter(m => m.minutes_outstanding))

function when(iso: string) {
  return new Date(iso).toLocaleString()
}

function kindColour(kind: string) {
  return ({ safety: 'error', client: 'primary', progress: 'info', kickoff: 'success' } as Record<string, string>)[kind] ?? 'default'
}

const projectFilter = ref<string | null>(props.filter.project)

function applyFilter() {
  router.get(route('project-management.meetings'),
    projectFilter.value ? { project: projectFilter.value } : {},
    { preserveState: true, replace: true })
}

// ── Scheduling ──────────────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Meeting | null>(null)
const form = useForm({
  project_id: props.filter.project, title: '', kind: 'progress', starts_at: '',
  duration_minutes: 60, location: '', meeting_url: '', agenda: '',
})

function open(m: Meeting | null) {
  editing.value = m
  form.clearErrors()
  if (m) {
    Object.assign(form, {
      project_id: m.project?.id ?? null, title: m.title, kind: m.kind,
      starts_at: m.starts_at.slice(0, 16), duration_minutes: m.duration,
      location: m.location ?? '', meeting_url: m.url ?? '', agenda: m.agenda ?? '',
    })
  } else {
    form.reset()
    form.project_id = props.filter.project
  }
  dialog.value = true
}

function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editing.value
    ? form.put(route('project-management.meetings.update', editing.value.id), opts)
    : form.post(route('project-management.meetings.store'), opts)
}

// ── Minutes, attendance and actions ─────────────────────────────────────────
const minutesDialog = ref(false)
const minuting = ref<Meeting | null>(null)
const minutesForm = useForm<{
  minutes: string; circulate: boolean
  attendees: Array<{ name: string; organisation: string; attendance: string; user_id: number | null }>
  actions: Array<{ name: string; assigned_to: number | null; due_date: string }>
}>({ minutes: '', circulate: true, attendees: [], actions: [] })

function openMinutes(m: Meeting) {
  minuting.value = m
  minutesForm.reset()
  minutesForm.clearErrors()
  minutesForm.minutes = m.minutes ?? ''
  // Pre-filled from whoever was already recorded, so a second edit does not start from nothing.
  minutesForm.attendees = m.attendees.length
    ? m.attendees.map(a => ({ name: a.name, organisation: a.organisation ?? '', attendance: a.attendance, user_id: null }))
    : props.members.map(x => ({ name: x.name, organisation: '', attendance: 'present', user_id: x.id }))
  minutesForm.actions = []
  minutesDialog.value = true
}

function addAttendee() {
  minutesForm.attendees.push({ name: '', organisation: '', attendance: 'present', user_id: null })
}
function addAction() {
  minutesForm.actions.push({ name: '', assigned_to: null, due_date: '' })
}

function saveMinutes() {
  if (!minuting.value) return
  minutesForm.post(route('project-management.meetings.minutes', minuting.value.id), {
    preserveScroll: true, onSuccess: () => { minutesDialog.value = false },
  })
}

const confirmer = ref()

function remove(m: Meeting) {
  confirmer.value.open({
    title: t('project_management.meetings.confirm_remove_title'),
    message: t('project_management.meetings.confirm_remove_body', { title: m.title }),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(route('project-management.meetings.destroy', m.id), { preserveScroll: true }))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.meetings.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.meetings.subtitle') }}</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VAutocomplete v-model="projectFilter"
                 :items="[{ title: t('project_management.meetings.all_projects'), value: null }, ...projects.map(p => ({ title: p.label, value: p.id }))]"
                 :label="t('project_management.meetings.filter_project')"
                 variant="outlined" density="compact" hide-details style="min-width:240px"
                 @update:model-value="applyFilter" />
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-calendar-plus" @click="open(null)">
          {{ t('project_management.meetings.schedule') }}
        </VBtn>
      </div>
    </div>

    <VAlert v-if="outstanding.length" type="warning" variant="tonal" class="mb-4"
            :title="t('project_management.meetings.minutes_outstanding_title', { count: outstanding.length })">
      {{ t('project_management.meetings.minutes_outstanding_body') }}
    </VAlert>

    <VCard v-if="upcoming.length" border class="mb-4">
      <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.meetings.upcoming') }}</VCardTitle></VCardItem>
      <VDivider />
      <VList density="comfortable">
        <VListItem v-for="m in upcoming" :key="m.id">
          <template #prepend>
            <VAvatar :color="kindColour(m.kind)" variant="tonal"><VIcon icon="tabler-calendar-event" /></VAvatar>
          </template>
          <VListItemTitle class="d-flex align-center gap-2 flex-wrap">
            {{ m.title }}
            <VChip size="x-small" label :color="kindColour(m.kind)">
              {{ t(`project_management.meetings.kind_${m.kind}`) }}
            </VChip>
            <VChip v-if="m.project" size="x-small" label variant="tonal">{{ m.project.code }}</VChip>
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ when(m.starts_at) }} · {{ t('project_management.meetings.minutes_n', { n: m.duration }) }}
            <template v-if="m.location"> · {{ m.location }}</template>
          </VListItemSubtitle>
          <template #append>
            <VBtn v-if="m.url" :href="m.url" target="_blank" size="small" variant="text" icon="tabler-video"
                  :aria-label="t('project_management.meetings.join')" />
            <VBtn v-if="can.manage" size="small" variant="text" icon="tabler-pencil"
                  :aria-label="t('project_management.meetings.edit_named', { title: m.title })" @click="open(m)" />
            <VBtn v-if="can.manage" size="small" variant="tonal" color="primary" class="ms-1"
                  @click="openMinutes(m)">
              {{ t('project_management.meetings.record_minutes') }}
            </VBtn>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <VCard border>
      <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.meetings.past') }}</VCardTitle></VCardItem>
      <VDivider />
      <VList v-if="past.length" density="comfortable">
        <VListItem v-for="m in past" :key="m.id">
          <VListItemTitle class="d-flex align-center gap-2 flex-wrap">
            {{ m.title }}
            <VChip size="x-small" label :color="kindColour(m.kind)">
              {{ t(`project_management.meetings.kind_${m.kind}`) }}
            </VChip>
            <VChip v-if="m.minutes_outstanding" size="x-small" color="warning" label>
              {{ t('project_management.meetings.no_minutes') }}
            </VChip>
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ when(m.starts_at) }}
            · {{ t('project_management.meetings.present_n', { n: m.attendees.filter(a => a.attendance === 'present').length }) }}
            <template v-if="m.actions">
              · {{ t('project_management.meetings.actions_n', { open: m.open_actions, total: m.actions }) }}
            </template>
          </VListItemSubtitle>
          <template #append>
            <VBtn v-if="can.manage" size="small" variant="text" icon="tabler-notes"
                  :aria-label="t('project_management.meetings.record_minutes')" @click="openMinutes(m)" />
            <VBtn v-if="can.manage" size="small" variant="text" icon="tabler-trash"
                  :aria-label="t('project_management.meetings.remove_named', { title: m.title })" @click="remove(m)" />
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.meetings.none') }}
      </VCardText>
    </VCard>

    <!-- ── Schedule ─────────────────────────────────────────────────────── -->
    <VDialog v-model="dialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editing ? t('project_management.meetings.edit') : t('project_management.meetings.schedule') }}
          </VCardTitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete v-model="form.project_id"
                       :items="[{ title: t('project_management.meetings.no_project'), value: null }, ...projects.map(p => ({ title: p.label, value: p.id }))]"
                       :label="t('project_management.meetings.field_project')"
                       :hint="t('project_management.meetings.project_hint')" persistent-hint
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="8">
              <VTextField v-model="form.title" :label="t('project_management.meetings.field_title')"
                          :error-messages="form.errors.title" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.kind"
                       :items="kinds.map(k => ({ title: t(`project_management.meetings.kind_${k}`), value: k }))"
                       :label="t('project_management.meetings.field_kind')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="8">
              <VTextField v-model="form.starts_at" type="datetime-local"
                          :label="t('project_management.meetings.field_starts')"
                          :error-messages="form.errors.starts_at" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="form.duration_minutes" type="number"
                          :label="t('project_management.meetings.field_duration')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.location" :label="t('project_management.meetings.field_location')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.meeting_url" :label="t('project_management.meetings.field_url')"
                          :error-messages="form.errors.meeting_url" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.agenda" :label="t('project_management.meetings.field_agenda')"
                         :hint="t('project_management.meetings.agenda_hint')" persistent-hint
                         variant="outlined" density="compact" rows="4" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.title || !form.starts_at" @click="save">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Minutes, attendance and actions ──────────────────────────────── -->
    <VDialog v-model="minutesDialog" :fullscreen="smAndDown" max-width="820">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.meetings.record_minutes') }}</VCardTitle>
          <VCardSubtitle>{{ minuting?.title }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.meetings.minutes_note') }}
          </VAlert>

          <VTextarea v-model="minutesForm.minutes" :label="t('project_management.meetings.field_minutes')"
                     :error-messages="minutesForm.errors.minutes"
                     variant="outlined" density="compact" rows="8" class="mb-4" />

          <div class="d-flex align-center justify-space-between mb-2">
            <span class="text-body-2 font-weight-medium">{{ t('project_management.meetings.attendance') }}</span>
            <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addAttendee">
              {{ t('project_management.meetings.add_attendee') }}
            </VBtn>
          </div>
          <VRow v-for="(a, i) in minutesForm.attendees" :key="`att-${i}`" dense class="align-center">
            <VCol cols="12" md="5">
              <!--
                Offered from the team but not restricted to it: the client and the engineer sit in
                these meetings and are not project members, and an attendance list that cannot name
                them is not an attendance list.
              -->
              <PersonField v-model="a.name" :people="members"
                           :label="t('project_management.meetings.field_name')" />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField v-model="a.organisation" :label="t('project_management.meetings.field_organisation')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VAutocomplete v-model="a.attendance"
                       :items="['present', 'apology', 'absent'].map(x => ({ title: t(`project_management.meetings.attendance_${x}`), value: x }))"
                       :label="t('project_management.meetings.field_attendance')"
                       variant="outlined" density="compact" />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <div class="d-flex align-center justify-space-between mb-2">
            <div>
              <span class="text-body-2 font-weight-medium">{{ t('project_management.meetings.actions') }}</span>
              <!-- Says where they go, because it is the point of the design. -->
              <div class="text-caption text-medium-emphasis">{{ t('project_management.meetings.actions_note') }}</div>
            </div>
            <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" :disabled="!minuting?.project"
                  @click="addAction">
              {{ t('project_management.meetings.add_action') }}
            </VBtn>
          </div>
          <VRow v-for="(a, i) in minutesForm.actions" :key="`act-${i}`" dense class="align-center">
            <VCol cols="12" md="6">
              <VTextField v-model="a.name" :label="t('project_management.meetings.field_action')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VAutocomplete v-model="a.assigned_to"
                       :items="members.map(m => ({ title: m.name, value: m.id }))"
                       :label="t('project_management.meetings.field_owner')" clearable
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VTextField v-model="a.due_date" type="date" :label="t('project_management.meetings.field_due')"
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>

          <VCheckbox v-model="minutesForm.circulate" density="compact" class="mt-2"
                     :label="t('project_management.meetings.field_circulate')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="minutesDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="minutesForm.processing"
                :disabled="!minutesForm.minutes" @click="saveMinutes">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
