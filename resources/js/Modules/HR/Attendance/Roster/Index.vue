<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type RosterSchedule = {
  id: number
  name: string
  department: string | null
  start_date: string
  end_date: string
  status: string
  assignment_count: number
}

const props = defineProps<{
  schedules: RosterSchedule[]
  departments: string[]
  filters: { status?: string }
  summary: { draft: number; published: number; archived: number }
}>()

const statusFilter = ref(props.filters.status ?? '')
const createDialog = ref(false)
const confirmDialog = ref()

const createForm = useForm({
  name:          '',
  department:    '',
  start_date:    '',
  end_date:      '',
})

function applyFilter() {
  router.get(route('attendance.roster.index'), { status: statusFilter.value || undefined }, { preserveState: true, replace: true })
}

function submitCreate() {
  createForm.post(route('attendance.roster.store'), {
    onSuccess: () => { createDialog.value = false },
  })
}

function publish(s: RosterSchedule) {
  router.post(route('attendance.roster.publish', { rosterSchedule: s.id }))
}

function archive(s: RosterSchedule) {
  confirmDialog.value.open(
    { title: 'Archive Roster', message: `Archive roster "${s.name}"?`, confirmText: 'Archive', confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => {
      router.post(route('attendance.roster.archive', { rosterSchedule: s.id }))
    },
  )
}

const statusColor = (s: string) => s === 'published' ? 'success' : s === 'draft' ? 'warning' : 'secondary'
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Roster Schedules</h4>
          <p class="text-medium-emphasis mb-0">Manage and publish employee work rosters</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">New Roster</VBtn>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="4"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.draft }}</h6><p class="text-caption text-medium-emphasis mb-0">Draft</p></VCardText></VCard></VCol>
      <VCol cols="4"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.published }}</h6><p class="text-caption text-medium-emphasis mb-0">Published</p></VCardText></VCard></VCol>
      <VCol cols="4"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.archived }}</h6><p class="text-caption text-medium-emphasis mb-0">Archived</p></VCardText></VCard></VCol>
    </VRow>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VSelect
          v-model="statusFilter"
          :items="[{ title: 'All', value: '' }, { title: 'Draft', value: 'draft' }, { title: 'Published', value: 'published' }, { title: 'Archived', value: 'archived' }]"
          item-title="title" item-value="value"
          label="Status"
          variant="outlined" density="compact" hide-details
          style="max-width: 240px;"
          @update:model-value="applyFilter"
        />
      </VCardText>
    </VCard>

    <!-- Schedules -->
    <VCard elevation="0" border>
      <VList v-if="schedules.length">
        <VListItem
          v-for="s in schedules"
          :key="s.id"
          :subtitle="`${s.start_date} → ${s.end_date} · ${s.assignment_count} assignments`"
          class="cursor-pointer"
          @click="router.visit(route('attendance.roster.show', { rosterSchedule: s.id }))"
        >
          <template #title>
            <span class="text-body-2 font-weight-semibold">{{ s.name }}</span>
            <span v-if="s.department" class="text-caption text-medium-emphasis ms-2">{{ s.department }}</span>
          </template>
          <template #prepend>
            <VAvatar :color="statusColor(s.status)" variant="tonal" size="38" rounded class="me-3">
              <VIcon icon="tabler-calendar-week" size="18" />
            </VAvatar>
          </template>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip :color="statusColor(s.status)" size="x-small" variant="tonal" class="text-capitalize">{{ s.status }}</VChip>
              <VBtn v-if="s.status === 'draft'" size="x-small" color="success" variant="tonal" @click.stop="publish(s)">Publish</VBtn>
              <VBtn v-if="s.status === 'published'" size="x-small" color="secondary" variant="text" @click.stop="archive(s)">Archive</VBtn>
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center py-12">
        <VIcon icon="tabler-calendar-week" size="56" color="secondary" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis mb-4">No rosters created yet.</p>
        <VBtn color="primary" variant="tonal" @click="createDialog = true">Create First Roster</VBtn>
      </VCardText>
    </VCard>

    <!-- Create Dialog -->
    <VDialog v-model="createDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">New Roster Schedule</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="createForm.name" label="Name *" variant="outlined" density="comfortable" :error-messages="createForm.errors.name" />
            </VCol>
            <VCol cols="12">
              <VAutocomplete v-model="createForm.department" :items="departments" label="Department" variant="outlined" density="comfortable" clearable />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="createForm.start_date" label="Start Date *" type="date" variant="outlined" density="comfortable" :error-messages="createForm.errors.start_date" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="createForm.end_date" label="End Date *" type="date" variant="outlined" density="comfortable" :min="createForm.start_date" :error-messages="createForm.errors.end_date" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="createForm.processing" :disabled="!createForm.name || !createForm.start_date || !createForm.end_date" @click="submitCreate">Create</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
