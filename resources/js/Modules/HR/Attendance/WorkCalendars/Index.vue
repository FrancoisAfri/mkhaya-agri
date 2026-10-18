<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type WorkCalendar = {
  id: number
  name: string
  year: number
  monday: boolean; tuesday: boolean; wednesday: boolean; thursday: boolean
  friday: boolean; saturday: boolean; sunday: boolean
  shifts: { id: number; name: string }[]
}

type Shift = { id: number; name: string; code: string }

defineProps<{ calendars: WorkCalendar[]; shifts: Shift[] }>()

const dialog      = ref(false)
const submitting  = ref(false)
const editingItem = ref<WorkCalendar | null>(null)
const confirmDialog = ref()

const form = reactive({
  name: '', year: new Date().getFullYear(),
  monday: true, tuesday: true, wednesday: true, thursday: true,
  friday: true, saturday: false, sunday: false,
  shift_ids: [] as number[],
})

function openCreate() {
  editingItem.value = null
  Object.assign(form, { name: '', year: new Date().getFullYear(), monday: true, tuesday: true, wednesday: true, thursday: true, friday: true, saturday: false, sunday: false, shift_ids: [] })
  dialog.value = true
}

function submit() {
  submitting.value = true
  const url = editingItem.value
    ? route('attendance.calendars.update', { attendanceWorkCalendar: editingItem.value.id })
    : route('attendance.calendars.store')
  const method = editingItem.value ? 'put' : 'post'
  router[method](url, form, {
    onFinish: () => { submitting.value = false },
    onSuccess: () => { dialog.value = false },
  })
}

function deleteCalendar(c: WorkCalendar) {
  confirmDialog.value.open(
    { title: 'Delete Calendar', message: `Delete calendar "${c.name}"?`, confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      router.delete(route('attendance.calendars.destroy', { attendanceWorkCalendar: c.id }))
    },
  )
}

const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const
const dayLabel = (d: string) => d.slice(0, 3).toUpperCase()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.settings.index'))" />
        <div>
          <h4 class="text-h4">Work Calendars</h4>
          <p class="text-medium-emphasis mb-0">Define working days and shift assignments per year</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">New Calendar</VBtn>
    </div>

    <VRow>
      <VCol v-for="cal in calendars" :key="cal.id" cols="12" sm="6" lg="4">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-3">
              <div>
                <p class="text-body-2 font-weight-semibold mb-0">{{ cal.name }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ cal.year }}</p>
              </div>
              <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="deleteCalendar(cal)" />
            </div>
            <div class="d-flex gap-1 mb-3">
              <VChip
                v-for="d in days"
                :key="d"
                :color="cal[d] ? 'primary' : 'secondary'"
                size="x-small"
                :variant="cal[d] ? 'tonal' : 'outlined'"
              >
                {{ dayLabel(d) }}
              </VChip>
            </div>
            <div v-if="cal.shifts.length" class="d-flex flex-wrap gap-1">
              <VChip v-for="s in cal.shifts" :key="s.id" color="info" size="x-small" variant="tonal">{{ s.name }}</VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!calendars.length" cols="12">
        <VCard elevation="0" border>
          <VCardText class="text-center py-12">
            <VIcon icon="tabler-calendar" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis mb-4">No work calendars configured.</p>
            <VBtn color="primary" variant="tonal" @click="openCreate">Create Calendar</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">New Work Calendar</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="8"><VTextField v-model="form.name" label="Name *" variant="outlined" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model="form.year" label="Year *" type="number" variant="outlined" density="compact" /></VCol>
            <VCol cols="12">
              <p class="text-body-2 font-weight-medium mb-2">Working Days</p>
              <div class="d-flex flex-wrap gap-2">
                <VCheckbox v-for="d in days" :key="d" v-model="(form as any)[d]" :label="d.slice(0,3)" hide-details density="compact" />
              </div>
            </VCol>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.shift_ids"
                :items="shifts"
                item-title="name"
                item-value="id"
                label="Assigned Shifts"
                variant="outlined"
                density="compact"
                multiple
                chips
                closable-chips
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="submitting" :disabled="!form.name" @click="submit">Create</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
