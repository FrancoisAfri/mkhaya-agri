<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Shift = {
  id: number; name: string; code: string; start_time: string; end_time: string
  break_duration_minutes: number; is_overnight: boolean; is_flexible: boolean
  flexible_window_minutes: number; grace_period_minutes: number | null
  description: string | null; status: number; net_hours: number
}

defineProps<{ shifts: Shift[] }>()

const subNav       = ref('shifts')
const dialog       = ref(false)
const editMode     = ref(false)
const editId       = ref<number | null>(null)
const deleteDialog = ref(false)
const deleteId     = ref<number | null>(null)

const blank = () => ({
  name: '', code: '', start_time: '08:00', end_time: '17:00',
  break_duration_minutes: 60, is_overnight: false, is_flexible: false,
  flexible_window_minutes: 0, grace_period_minutes: null as number | null,
  description: '', status: 1,
})

const form = useForm(blank())

function openCreate() { Object.assign(form, blank()); editMode.value = false; editId.value = null; dialog.value = true }

function openEdit(s: Shift) {
  form.name = s.name; form.code = s.code; form.start_time = s.start_time; form.end_time = s.end_time
  form.break_duration_minutes = s.break_duration_minutes; form.is_overnight = s.is_overnight
  form.is_flexible = s.is_flexible; form.flexible_window_minutes = s.flexible_window_minutes
  form.grace_period_minutes = s.grace_period_minutes; form.description = s.description ?? ''; form.status = s.status
  editMode.value = true; editId.value = s.id; dialog.value = true
}

function save() {
  if (editMode.value && editId.value) form.put(`/attendance/shifts/${editId.value}`, { onSuccess: () => { dialog.value = false } })
  else form.post('/attendance/shifts', { onSuccess: () => { dialog.value = false } })
}

function confirmDelete(id: number) { deleteId.value = id; deleteDialog.value = true }

function doDelete() { useForm({}).delete(`/attendance/shifts/${deleteId.value}`, { onSuccess: () => { deleteDialog.value = false } }) }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="shifts" href="/attendance/shifts"><VIcon start size="16" icon="tabler-layers" />{{ t('attendance_shift.tab_shifts') }}</VTab>
            <VTab value="calendars" href="/attendance/calendars"><VIcon start size="16" icon="tabler-calendar-month" />{{ t('attendance_shift.tab_calendars') }}</VTab>
            <VTab value="statuses" href="/attendance/statuses"><VIcon start size="16" icon="tabler-tag-multiple" />{{ t('attendance_shift.tab_statuses') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_shift.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_shift.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_shift.subtitle') }}</p>
            </div>
            <VBtn color="primary" prepend-icon="fas fa-plus" @click="openCreate"><i class="fa-solid fa-plus"></i> {{ t('attendance_shift.add_shift') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_shift.table_name') }}</th>
                <th>{{ t('attendance_shift.table_code') }}</th>
                <th>{{ t('attendance_shift.table_start') }}</th>
                <th>{{ t('attendance_shift.table_end') }}</th>
                <th>{{ t('attendance_shift.table_break') }}</th>
                <th>{{ t('attendance_shift.table_net_hours') }}</th>
                <th>{{ t('attendance_shift.table_overnight') }}</th>
                <th>{{ t('attendance_shift.table_flexible') }}</th>
                <th>{{ t('attendance_shift.table_active') }}</th>
                <th class="text-center">{{ t('attendance_shift.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!shifts.length">
                <td colspan="10" class="text-center py-8 text-medium-emphasis">{{ t('attendance_shift.no_shifts') }}</td>
              </tr>
              <tr v-for="s in shifts" :key="s.id">
                <td class="font-weight-medium">{{ s.name }}</td>
                <td><code>{{ s.code }}</code></td>
                <td>{{ s.start_time }}</td>
                <td>{{ s.end_time }}</td>
                <td>{{ t('attendance_shift.break_min', { minutes: s.break_duration_minutes }) }}</td>
                <td><VChip color="primary" size="small" variant="tonal">{{ t('attendance_shift.net_hours_h', { hours: s.net_hours }) }}</VChip></td>
                <td><VChip v-if="s.is_overnight" color="info" size="small" variant="tonal">{{ t('attendance_shift.yes') }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
                <td><VChip v-if="s.is_flexible" color="secondary" size="small" variant="tonal">{{ t('attendance_shift.flex') }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
                <td><VChip :color="s.status === 1 ? 'success' : 'error'" size="small" variant="tonal">{{ s.status === 1 ? t('attendance_shift.active') : t('attendance_shift.inactive') }}</VChip></td>
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

    <VDialog v-model="dialog" max-width="600">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ editMode ? t('attendance_shift.dialog_edit_title') : t('attendance_shift.dialog_new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VRow dense>
            <VCol cols="8"><VTextField v-model="form.name" :label="t('attendance_shift.field_name')" :error-messages="form.errors.name" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model="form.code" :label="t('attendance_shift.field_code')" :error-messages="form.errors.code" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="form.start_time" :label="t('attendance_shift.field_start_time')" type="time" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="form.end_time" :label="t('attendance_shift.field_end_time')" type="time" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.break_duration_minutes" :label="t('attendance_shift.field_break')" type="number" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.grace_period_minutes" :label="t('attendance_shift.field_grace_period')" type="number" density="compact" :placeholder="t('attendance_shift.field_grace_placeholder')" /></VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_overnight" :label="t('attendance_shift.field_overnight')" density="compact" hide-details /></VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_flexible" :label="t('attendance_shift.field_flexible')" density="compact" hide-details /></VCol>
            <VCol cols="12" v-if="form.is_flexible"><VTextField v-model.number="form.flexible_window_minutes" :label="t('attendance_shift.field_flexible_window')" type="number" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :label="t('attendance_shift.field_description')" rows="2" density="compact" /></VCol>
            <VCol cols="12">
              <VSelect v-model="form.status" :items="[{ title: t('attendance_shift.active'), value: 1 }, { title: t('attendance_shift.inactive'), value: 0 }]" :label="t('attendance_shift.field_status')" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('attendance_shift.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('attendance_shift.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="400">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_shift.delete_title') }}</VCardTitle>
        <VCardText>{{ t('attendance_shift.delete_confirm') }}</VCardText>
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('attendance_shift.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('attendance_shift.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
