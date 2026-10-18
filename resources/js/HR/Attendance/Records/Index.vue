<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type StatusOption  = { id: number; name: string; color: string; code: string }
type ShiftOption   = { id: number; name: string; code: string }
type EmployeeOption = { id: number; first_name: string; surname: string }
type Record = {
  id: number; employee_id: number; employee_name: string; status: string; status_color: string
  shift: string; date: string; clock_in: string | null; clock_out: string | null
  hours_worked: number; overtime_hours: number; is_late: boolean; late_minutes: number
  source: string; manager_approved: boolean; notes: string | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  records: Paginated<Record>; statuses: StatusOption[]
  shifts: ShiftOption[]; employees: EmployeeOption[]; selectedDate: string
}>()

const selectedDate = ref(props.selectedDate)
const subNav       = ref('records')
const dialog       = ref(false)
const editMode     = ref(false)
const editId       = ref<number | null>(null)
const deleteDialog = ref(false)
const deleteId     = ref<number | null>(null)

const blank = () => ({
  employee_id: null as number | null, status_id: null as number | null, shift_id: null as number | null,
  date: selectedDate.value, clock_in: '', clock_out: '', source: 'manual', notes: '',
})

const form = useForm(blank())

function changeDate() { router.get('/attendance/records', { date: selectedDate.value }, { preserveState: true }) }
function openCreate() { Object.assign(form, blank()); editMode.value = false; editId.value = null; dialog.value = true }

function openEdit(r: Record) {
  form.employee_id = r.employee_id; form.status_id = null; form.shift_id = null
  form.date = r.date; form.clock_in = r.clock_in ?? ''; form.clock_out = r.clock_out ?? ''
  form.source = r.source; form.notes = r.notes ?? ''
  editMode.value = true; editId.value = r.id; dialog.value = true
}

function save() {
  if (editMode.value && editId.value) form.put(`/attendance/records/${editId.value}`, { onSuccess: () => { dialog.value = false } })
  else form.post('/attendance/records', { onSuccess: () => { dialog.value = false } })
}

function approve(id: number) { router.post(`/attendance/records/${id}/approve`, {}) }
function confirmDelete(id: number) { deleteId.value = id; deleteDialog.value = true }
function doDelete() { useForm({}).delete(`/attendance/records/${deleteId.value}`, { onSuccess: () => { deleteDialog.value = false } }) }
function employeeName(e: EmployeeOption) { return `${e.first_name} ${e.surname}` }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="records" href="/attendance/records"><VIcon start size="16" icon="tabler-list-check" />{{ t('attendance_records_page.tab_records') }}</VTab>
            <VTab value="missed" href="/attendance/clocking/missed"><VIcon start size="16" icon="tabler-alert-circle-outline" />{{ t('attendance_records_page.tab_missed') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_records_page.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_records_page.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_records_page.subtitle') }}</p>
            </div>
            <div class="d-flex gap-3 align-center">
              <VTextField v-model="selectedDate" type="date" density="compact" hide-details style="max-width:180px" @change="changeDate" />
              <VBtn color="primary" prepend-icon="fas fa-plus" @click="openCreate"><i class="fa-solid fa-plus"></i> {{ t('attendance_records_page.add_record') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_records_page.table_employee') }}</th>
                <th>{{ t('attendance_records_page.table_status') }}</th>
                <th>{{ t('attendance_records_page.table_shift') }}</th>
                <th>{{ t('attendance_records_page.table_clock_in') }}</th>
                <th>{{ t('attendance_records_page.table_clock_out') }}</th>
                <th>{{ t('attendance_records_page.table_hours') }}</th>
                <th>{{ t('attendance_records_page.table_ot') }}</th>
                <th>{{ t('attendance_records_page.table_late') }}</th>
                <th>{{ t('attendance_records_page.table_source') }}</th>
                <th>{{ t('attendance_records_page.table_approved') }}</th>
                <th class="text-center">{{ t('attendance_records_page.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!records.data.length">
                <td colspan="11" class="text-center py-8 text-medium-emphasis">{{ t('attendance_records_page.no_records') }}</td>
              </tr>
              <tr v-for="r in records.data" :key="r.id">
                <td class="font-weight-medium">{{ r.employee_name }}</td>
                <td><VChip :color="r.status_color" size="small" label variant="tonal">{{ r.status }}</VChip></td>
                <td>{{ r.shift }}</td>
                <td>{{ r.clock_in ?? '—' }}</td>
                <td>{{ r.clock_out ?? '—' }}</td>
                <td>{{ r.hours_worked }}</td>
                <td><VChip v-if="r.overtime_hours > 0" color="info" size="small" variant="tonal">{{ t('attendance_records_page.overtime_hours_chip', { hours: r.overtime_hours }) }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
                <td><VChip v-if="r.is_late" color="warning" size="small" variant="tonal">{{ t('attendance_records_page.late_minutes_chip', { minutes: r.late_minutes }) }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
                <td><VChip size="x-small" variant="outlined">{{ r.source }}</VChip></td>
                <td><VChip :color="r.manager_approved ? 'success' : 'warning'" size="small" variant="tonal">{{ r.manager_approved ? t('attendance_records_page.approved') : t('attendance_records_page.pending') }}</VChip></td>
                <td class="text-center">
                  <VBtn v-if="!r.manager_approved" icon size="small" variant="text" color="success" @click="approve(r.id)" title="Approve"><i class="fas fa-check"></i></VBtn>
                  <VBtn icon size="small" variant="text" color="primary" @click="openEdit(r)"><i class="fas fa-pencil-alt"></i></VBtn>
                  <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(r.id)"><i class="fas fa-trash-alt"></i></VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-if="records.last_page > 1" class="d-flex justify-center py-4">
            <VPagination :model-value="records.current_page" :length="records.last_page" @update:model-value="page => router.get('/attendance/records', { date: selectedDate, page }, { preserveState: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ editMode ? t('attendance_records_page.dialog_edit_title') : t('attendance_records_page.dialog_new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VRow dense>
            <VCol cols="12" v-if="!editMode"><VSelect v-model="form.employee_id" :items="employees.map(e => ({ title: employeeName(e), value: e.id }))" :label="t('attendance_records_page.field_employee')" density="compact" :error-messages="form.errors.employee_id" /></VCol>
            <VCol cols="12" v-if="!editMode"><VTextField v-model="form.date" :label="t('attendance_records_page.field_date')" type="date" density="compact" /></VCol>
            <VCol cols="12"><VSelect v-model="form.status_id" :items="statuses.map(s => ({ title: s.name, value: s.id }))" :label="t('attendance_records_page.field_status')" density="compact" clearable /></VCol>
            <VCol cols="12"><VSelect v-model="form.shift_id" :items="shifts.map(s => ({ title: s.name, value: s.id }))" :label="t('attendance_records_page.field_shift')" density="compact" clearable /></VCol>
            <VCol cols="6"><VTextField v-model="form.clock_in" :label="t('attendance_records_page.field_clock_in')" type="time" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="form.clock_out" :label="t('attendance_records_page.field_clock_out')" type="time" density="compact" /></VCol>
            <VCol cols="12"><VSelect v-model="form.source" :items="['manual','biometric','web','mobile','import'].map(v => ({ title: v, value: v }))" :label="t('attendance_records_page.field_source')" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.notes" :label="t('attendance_records_page.field_notes')" rows="2" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('attendance_records_page.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('attendance_records_page.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="400">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_records_page.delete_title') }}</VCardTitle>
        <VCardText>{{ t('attendance_records_page.delete_confirm') }}</VCardText>
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="deleteDialog = false">{{ t('attendance_records_page.cancel') }}</VBtn><VBtn color="error" @click="doDelete">{{ t('attendance_records_page.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
