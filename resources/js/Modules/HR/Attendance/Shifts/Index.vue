<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Shift = {
  id: number
  name: string
  code: string
  start_time: string
  end_time: string
  break_duration_minutes: number
  is_overnight: boolean
  is_flexible: boolean
  flexible_window_minutes: number
  grace_period_minutes: number | null
  net_hours: number
  active: boolean
}

defineProps<{ shifts: Shift[] }>()

const dialog      = ref(false)
const submitting  = ref(false)
const editingItem = ref<Shift | null>(null)
const confirmDialog = ref()

const form = reactive({
  name: '', code: '', start_time: '08:00', end_time: '17:00',
  break_duration_minutes: 60, is_overnight: false, is_flexible: false,
  flexible_window_minutes: 0, grace_period_minutes: 5, active: true,
})

function openCreate() {
  editingItem.value = null
  Object.assign(form, { name: '', code: '', start_time: '08:00', end_time: '17:00', break_duration_minutes: 60, is_overnight: false, is_flexible: false, flexible_window_minutes: 0, grace_period_minutes: 5, active: true })
  dialog.value = true
}

function openEdit(s: Shift) {
  editingItem.value = s
  Object.assign(form, { name: s.name, code: s.code, start_time: s.start_time, end_time: s.end_time, break_duration_minutes: s.break_duration_minutes, is_overnight: s.is_overnight, is_flexible: s.is_flexible, flexible_window_minutes: s.flexible_window_minutes, grace_period_minutes: s.grace_period_minutes, active: s.active })
  dialog.value = true
}

function submit() {
  submitting.value = true
  if (editingItem.value) {
    router.put(route('attendance.shifts.update', { attendanceShift: editingItem.value.id }), form, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { dialog.value = false },
    })
  } else {
    router.post(route('attendance.shifts.store'), form, {
      onFinish: () => { submitting.value = false },
      onSuccess: () => { dialog.value = false },
    })
  }
}

function deleteShift(s: Shift) {
  confirmDialog.value.open(
    { title: 'Delete Shift', message: `Delete shift "${s.name}"?`, confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      router.delete(route('attendance.shifts.destroy', { attendanceShift: s.id }))
    },
  )
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Shifts</h4>
          <p class="text-medium-emphasis mb-0">Configure work shift definitions</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">New Shift</VBtn>
    </div>

    <VRow>
      <VCol v-for="s in shifts" :key="s.id" cols="12" sm="6" lg="4">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-3">
              <div class="d-flex align-center gap-2">
                <VAvatar color="primary" variant="tonal" size="40" rounded>
                  <VIcon icon="tabler-clock" size="20" />
                </VAvatar>
                <div>
                  <p class="text-body-2 font-weight-semibold mb-0">{{ s.name }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ s.code }}</p>
                </div>
              </div>
              <VChip :color="s.active ? 'success' : 'secondary'" size="x-small" variant="tonal">{{ s.active ? 'Active' : 'Inactive' }}</VChip>
            </div>
            <VDivider class="mb-3" />
            <div class="d-flex justify-space-between text-body-2 mb-1">
              <span class="text-medium-emphasis">Hours</span>
              <span>{{ s.start_time }} – {{ s.end_time }}</span>
            </div>
            <div class="d-flex justify-space-between text-body-2 mb-1">
              <span class="text-medium-emphasis">Net Hours</span>
              <span class="font-weight-medium">{{ s.net_hours }}h</span>
            </div>
            <div class="d-flex justify-space-between text-body-2 mb-1">
              <span class="text-medium-emphasis">Break</span>
              <span>{{ s.break_duration_minutes }}min</span>
            </div>
            <div v-if="s.grace_period_minutes" class="d-flex justify-space-between text-body-2 mb-1">
              <span class="text-medium-emphasis">Grace</span>
              <span>{{ s.grace_period_minutes }}min</span>
            </div>
            <div class="d-flex gap-2 mt-2">
              <VChip v-if="s.is_overnight" color="info" size="x-small" variant="tonal">Overnight</VChip>
              <VChip v-if="s.is_flexible" color="warning" size="x-small" variant="tonal">Flexible</VChip>
            </div>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-2">
            <VSpacer />
            <VBtn icon="tabler-edit" size="small" variant="text" color="primary" @click="openEdit(s)" />
            <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="deleteShift(s)" />
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!shifts.length" cols="12">
        <VCard elevation="0" border>
          <VCardText class="text-center py-12">
            <VIcon icon="tabler-clock" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis mb-4">No shifts defined.</p>
            <VBtn color="primary" variant="tonal" @click="openCreate">Create First Shift</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="560" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editingItem ? 'Edit Shift' : 'New Shift' }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="8"><VTextField v-model="form.name" label="Name *" variant="outlined" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model="form.code" label="Code *" variant="outlined" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="form.start_time" label="Start Time" type="time" variant="outlined" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="form.end_time" label="End Time" type="time" variant="outlined" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="form.break_duration_minutes" label="Break (min)" type="number" variant="outlined" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="form.grace_period_minutes" label="Grace (min)" type="number" variant="outlined" density="compact" /></VCol>
            <VCol cols="4"><VCheckbox v-model="form.is_overnight" label="Overnight" hide-details density="compact" /></VCol>
            <VCol cols="4"><VCheckbox v-model="form.is_flexible" label="Flexible" hide-details density="compact" /></VCol>
            <VCol cols="4"><VCheckbox v-model="form.active" label="Active" hide-details density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="submitting" :disabled="!form.name || !form.code" @click="submit">{{ editingItem ? 'Save' : 'Create' }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
