<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Status = {
  id: number
  name: string
  code: string
  color: string
  is_paid: boolean
  is_present: boolean
  is_absent: boolean
  is_late: boolean
  is_leave: boolean
  is_public_holiday: boolean
  status: number
}

defineProps<{ statuses: Status[] }>()

const dialog      = ref(false)
const submitting  = ref(false)
const editingItem = ref<Status | null>(null)
const confirmDialog = ref()

const form = reactive({
  name: '', code: '', color: '#4CAF50',
  is_paid: true, is_present: true, is_absent: false,
  is_late: false, is_leave: false, is_public_holiday: false, status: 1,
})

function openCreate() {
  editingItem.value = null
  Object.assign(form, { name: '', code: '', color: '#4CAF50', is_paid: true, is_present: true, is_absent: false, is_late: false, is_leave: false, is_public_holiday: false, status: 1 })
  dialog.value = true
}

function openEdit(s: Status) {
  editingItem.value = s
  Object.assign(form, { ...s })
  dialog.value = true
}

function submit() {
  submitting.value = true
  const url = editingItem.value
    ? route('attendance.statuses.update', { attendanceStatus: editingItem.value.id })
    : route('attendance.statuses.store')
  const method = editingItem.value ? 'put' : 'post'
  router[method](url, form, {
    onFinish: () => { submitting.value = false },
    onSuccess: () => { dialog.value = false },
  })
}

function deleteStatus(s: Status) {
  confirmDialog.value.open(
    { title: 'Delete Status', message: `Delete status "${s.name}"?`, confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      router.delete(route('attendance.statuses.destroy', { attendanceStatus: s.id }))
    },
  )
}

const headers = [
  { title: 'Name',     key: 'name',   sortable: false },
  { title: 'Code',     key: 'code',   sortable: false },
  { title: 'Color',    key: 'color',  sortable: false },
  { title: 'Present',  key: 'is_present', sortable: false, align: 'center' as const },
  { title: 'Paid',     key: 'is_paid', sortable: false, align: 'center' as const },
  { title: 'Leave',    key: 'is_leave', sortable: false, align: 'center' as const },
  { title: 'Active',   key: 'status', sortable: false, align: 'center' as const },
  { title: '',         key: 'actions',sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.settings.index'))" />
        <div>
          <h4 class="text-h4">Attendance Statuses</h4>
          <p class="text-medium-emphasis mb-0">Define clocking and attendance status codes</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">New Status</VBtn>
    </div>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="statuses" hover hide-default-footer :items-per-page="50">
        <template #item.color="{ item }">
          <div class="d-flex align-center gap-2">
            <div :style="`width:18px;height:18px;border-radius:4px;background:${item.color}`" />
            <span class="text-caption">{{ item.color }}</span>
          </div>
        </template>
        <template #item.is_present="{ item }">
          <VIcon :icon="item.is_present ? 'tabler-circle-check' : 'tabler-circle-x'" :color="item.is_present ? 'success' : 'secondary'" size="18" />
        </template>
        <template #item.is_paid="{ item }">
          <VIcon :icon="item.is_paid ? 'tabler-circle-check' : 'tabler-circle-x'" :color="item.is_paid ? 'success' : 'secondary'" size="18" />
        </template>
        <template #item.is_leave="{ item }">
          <VIcon :icon="item.is_leave ? 'tabler-circle-check' : 'tabler-circle-x'" :color="item.is_leave ? 'info' : 'secondary'" size="18" />
        </template>
        <template #item.status="{ item }">
          <VChip :color="item.status ? 'success' : 'secondary'" size="x-small" variant="tonal">{{ item.status ? 'Active' : 'Inactive' }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn icon="tabler-edit" size="small" variant="text" color="primary" @click="openEdit(item)" />
            <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="deleteStatus(item)" />
          </div>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-tags" size="48" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No statuses defined.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="dialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editingItem ? 'Edit Status' : 'New Status' }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="8"><VTextField v-model="form.name" label="Name *" variant="outlined" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model="form.code" label="Code *" variant="outlined" density="compact" /></VCol>
            <VCol cols="12">
              <VTextField v-model="form.color" label="Color" variant="outlined" density="compact" type="color" />
            </VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_present" label="Is Present" hide-details density="compact" /></VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_paid" label="Is Paid" hide-details density="compact" /></VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_absent" label="Is Absent" hide-details density="compact" /></VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_late" label="Is Late" hide-details density="compact" /></VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_leave" label="Is Leave" hide-details density="compact" /></VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_public_holiday" label="Public Holiday" hide-details density="compact" /></VCol>
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
