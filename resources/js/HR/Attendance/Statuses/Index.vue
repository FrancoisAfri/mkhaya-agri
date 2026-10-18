<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Status = {
  id: number; name: string; code: string; color: string
  is_paid: boolean; is_present: boolean; is_absent: boolean
  is_late: boolean; is_leave: boolean; is_public_holiday: boolean; status: number
}

defineProps<{ statuses: Status[] }>()

const subNav       = ref('statuses')
const dialog       = ref(false)
const editMode     = ref(false)
const editId       = ref<number | null>(null)
const deleteDialog = ref(false)
const deleteId     = ref<number | null>(null)

const blank = () => ({
  name: '', code: '', color: '#4CAF50',
  is_paid: true, is_present: false, is_absent: false,
  is_late: false, is_leave: false, is_public_holiday: false, status: 1,
})

const form = useForm(blank())

function openCreate() { Object.assign(form, blank()); editMode.value = false; editId.value = null; dialog.value = true }

function openEdit(s: Status) {
  form.name = s.name; form.code = s.code; form.color = s.color
  form.is_paid = s.is_paid; form.is_present = s.is_present; form.is_absent = s.is_absent
  form.is_late = s.is_late; form.is_leave = s.is_leave; form.is_public_holiday = s.is_public_holiday
  form.status = s.status; editMode.value = true; editId.value = s.id; dialog.value = true
}

function save() {
  if (editMode.value && editId.value) form.put(`/attendance/statuses/${editId.value}`, { onSuccess: () => { dialog.value = false } })
  else form.post('/attendance/statuses', { onSuccess: () => { dialog.value = false } })
}

function confirmDelete(id: number) { deleteId.value = id; deleteDialog.value = true }
function doDelete() { useForm({}).delete(`/attendance/statuses/${deleteId.value}`, { onSuccess: () => { deleteDialog.value = false } }) }

const boolFlags = [
  { key: 'is_paid',           label: t('attendance_statuses_page.flag_paid') },
  { key: 'is_present',        label: t('attendance_statuses_page.flag_counts_present') },
  { key: 'is_absent',         label: t('attendance_statuses_page.flag_counts_absent') },
  { key: 'is_late',           label: t('attendance_statuses_page.flag_counts_late') },
  { key: 'is_leave',          label: t('attendance_statuses_page.flag_leave_type') },
  { key: 'is_public_holiday', label: t('attendance_statuses_page.flag_public_holiday') },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="shifts" href="/attendance/shifts"><VIcon start size="16" icon="tabler-layers" />{{ t('attendance_statuses_page.tab_shifts') }}</VTab>
            <VTab value="calendars" href="/attendance/calendars"><VIcon start size="16" icon="tabler-calendar-month" />{{ t('attendance_statuses_page.tab_calendars') }}</VTab>
            <VTab value="statuses" href="/attendance/statuses"><VIcon start size="16" icon="tabler-tag-multiple" />{{ t('attendance_statuses_page.tab_statuses') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_statuses_page.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_statuses_page.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_statuses_page.subtitle') }}</p>
            </div>
            <VBtn color="primary" prepend-icon="fas fa-plus" @click="openCreate"> <i class="fa-solid fa-plus"></i>{{ t('attendance_statuses_page.add_status') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_statuses_page.table_color') }}</th>
                <th>{{ t('attendance_statuses_page.table_name') }}</th>
                <th>{{ t('attendance_statuses_page.table_code') }}</th>
                <th>{{ t('attendance_statuses_page.table_paid') }}</th>
                <th>{{ t('attendance_statuses_page.table_present') }}</th>
                <th>{{ t('attendance_statuses_page.table_absent') }}</th>
                <th>{{ t('attendance_statuses_page.table_late') }}</th>
                <th>{{ t('attendance_statuses_page.table_leave') }}</th>
                <th>{{ t('attendance_statuses_page.table_active') }}</th>
                <th class="text-center">{{ t('attendance_statuses_page.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!statuses.length">
                <td colspan="10" class="text-center py-8 text-medium-emphasis">{{ t('attendance_statuses_page.no_statuses') }}</td>
              </tr>
              <tr v-for="s in statuses" :key="s.id">
                <td><VAvatar :color="s.color" size="28"></VAvatar></td>
                <td class="font-weight-medium">{{ s.name }}</td>
                <td><code>{{ s.code }}</code></td>
                <td><VIcon :color="s.is_paid ? 'success' : 'error'" size="18">{{ s.is_paid ? 'fas fa-check' : 'fas fa-times' }}</VIcon></td>
                <td><VIcon :color="s.is_present ? 'success' : 'medium-emphasis'" size="18">{{ s.is_present ? 'fas fa-check' : 'fas fa-minus' }}</VIcon></td>
                <td><VIcon :color="s.is_absent ? 'error' : 'medium-emphasis'" size="18">{{ s.is_absent ? 'fas fa-check' : 'fas fa-minus' }}</VIcon></td>
                <td><VIcon :color="s.is_late ? 'warning' : 'medium-emphasis'" size="18">{{ s.is_late ? 'fas fa-check' : 'fas fa-minus' }}</VIcon></td>
                <td><VIcon :color="s.is_leave ? 'info' : 'medium-emphasis'" size="18">{{ s.is_leave ? 'fas fa-check' : 'fas fa-minus' }}</VIcon></td>
                <td><VChip :color="s.status === 1 ? 'success' : 'error'" size="small" variant="tonal">{{ s.status === 1 ? t('attendance_statuses_page.active') : t('attendance_statuses_page.inactive') }}</VChip></td>
                <td class="text-center">
                  <VBtn icon size="small" variant="text" color="primary" @click="openEdit(s)"><i class="fas fa-pencil-alt"></i></VBtn>
                  <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(s.id)"><i class="fas fa-trash-alt"></i></VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="520">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ editMode ? t('attendance_statuses_page.dialog_edit_title') : t('attendance_statuses_page.dialog_new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VRow dense>
            <VCol cols="8"><VTextField v-model="form.name" :label="t('attendance_statuses_page.field_name')" :error-messages="form.errors.name" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model="form.code" :label="t('attendance_statuses_page.field_code')" :error-messages="form.errors.code" density="compact" /></VCol>
            <VCol cols="12">
              <label class="text-caption text-medium-emphasis mb-1 d-block">{{ t('attendance_statuses_page.field_color') }}</label>
              <input type="color" v-model="form.color" style="width:100%;height:40px;border-radius:8px;border:1px solid #ccc;cursor:pointer" />
            </VCol>
            <VCol cols="12">
              <div class="d-flex flex-wrap gap-3 mt-2">
                <VCheckbox v-for="flag in boolFlags" :key="flag.key" v-model="(form as any)[flag.key]" :label="flag.label" density="compact" hide-details />
              </div>
            </VCol>
            <VCol cols="12">
              <VSelect v-model="form.status" :items="[{ title: t('attendance_statuses_page.active'), value: 1 }, { title: t('attendance_statuses_page.inactive'), value: 0 }]" :label="t('attendance_statuses_page.field_status')" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('attendance_statuses_page.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('attendance_statuses_page.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="400">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_statuses_page.delete_title') }}</VCardTitle>
        <VCardText>{{ t('attendance_statuses_page.delete_confirm') }}</VCardText>
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('attendance_statuses_page.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('attendance_statuses_page.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
