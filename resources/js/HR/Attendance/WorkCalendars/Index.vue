<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Shift = { id: number; name: string; code: string }
type Calendar = {
  id: number; name: string; year: number
  monday: boolean; tuesday: boolean; wednesday: boolean
  thursday: boolean; friday: boolean; saturday: boolean; sunday: boolean
  include_public_holidays: boolean; description: string | null
  is_default: boolean; status: number; shifts: Shift[]
}

const props = defineProps<{ calendars: Calendar[]; shifts: Shift[] }>()

const subNav       = ref('calendars')
const dialog       = ref(false)
const editMode     = ref(false)
const editId       = ref<number | null>(null)
const deleteDialog = ref(false)
const deleteId     = ref<number | null>(null)

const blank = () => ({
  name: '', year: new Date().getFullYear(),
  monday: true, tuesday: true, wednesday: true, thursday: true, friday: true,
  saturday: false, sunday: false, include_public_holidays: true,
  description: '', is_default: false, status: 1, shift_ids: [] as number[],
})

const form = useForm(blank())

function openCreate() { Object.assign(form, blank()); editMode.value = false; editId.value = null; dialog.value = true }

function openEdit(c: Calendar) {
  form.name = c.name; form.year = c.year; form.monday = c.monday; form.tuesday = c.tuesday
  form.wednesday = c.wednesday; form.thursday = c.thursday; form.friday = c.friday
  form.saturday = c.saturday; form.sunday = c.sunday
  form.include_public_holidays = c.include_public_holidays; form.description = c.description ?? ''
  form.is_default = c.is_default; form.status = c.status; form.shift_ids = c.shifts.map(s => s.id)
  editMode.value = true; editId.value = c.id; dialog.value = true
}

function save() {
  if (editMode.value && editId.value) form.put(`/attendance/calendars/${editId.value}`, { onSuccess: () => { dialog.value = false } })
  else form.post('/attendance/calendars', { onSuccess: () => { dialog.value = false } })
}

function confirmDelete(id: number) { deleteId.value = id; deleteDialog.value = true }
function doDelete() { useForm({}).delete(`/attendance/calendars/${deleteId.value}`, { onSuccess: () => { deleteDialog.value = false } }) }

const dayFields = [
  { key: 'monday', label: t('attendance_calendars_page.day_mon') },
  { key: 'tuesday', label: t('attendance_calendars_page.day_tue') },
  { key: 'wednesday', label: t('attendance_calendars_page.day_wed') },
  { key: 'thursday', label: t('attendance_calendars_page.day_thu') },
  { key: 'friday', label: t('attendance_calendars_page.day_fri') },
  { key: 'saturday', label: t('attendance_calendars_page.day_sat') },
  { key: 'sunday', label: t('attendance_calendars_page.day_sun') },
]

function workingDays(c: Calendar): string {
  return dayFields.filter(d => (c as any)[d.key]).map(d => d.label).join(', ') || t('attendance_calendars_page.none')
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="shifts" href="/attendance/shifts"><VIcon start size="16" icon="tabler-layers" />{{ t('attendance_calendars_page.tab_shifts') }}</VTab>
            <VTab value="calendars" href="/attendance/calendars"><VIcon start size="16" icon="tabler-calendar-month" />{{ t('attendance_calendars_page.tab_calendars') }}</VTab>
            <VTab value="statuses" href="/attendance/statuses"><VIcon start size="16" icon="tabler-tag-multiple" />{{ t('attendance_calendars_page.tab_statuses') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_calendars_page.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_calendars_page.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_calendars_page.subtitle') }}</p>
            </div>
            <VBtn color="primary" prepend-icon="mdi-plus" @click="openCreate">{{ t('attendance_calendars_page.add_calendar') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_calendars_page.table_name') }}</th>
                <th>{{ t('attendance_calendars_page.table_year') }}</th>
                <th>{{ t('attendance_calendars_page.table_working_days') }}</th>
                <th>{{ t('attendance_calendars_page.table_shifts') }}</th>
                <th>{{ t('attendance_calendars_page.table_public_holidays') }}</th>
                <th>{{ t('attendance_calendars_page.table_default') }}</th>
                <th>{{ t('attendance_calendars_page.table_active') }}</th>
                <th class="text-center">{{ t('attendance_calendars_page.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!calendars.length">
                <td colspan="8" class="text-center py-8 text-medium-emphasis">{{ t('attendance_calendars_page.no_calendars') }}</td>
              </tr>
              <tr v-for="c in calendars" :key="c.id">
                <td class="font-weight-medium">{{ c.name }}</td>
                <td>{{ c.year }}</td>
                <td><span class="text-caption">{{ workingDays(c) }}</span></td>
                <td>
                  <div class="d-flex flex-wrap gap-1">
                    <VChip v-for="s in c.shifts" :key="s.id" size="x-small" color="primary" variant="tonal">{{ s.name }}</VChip>
                    <span v-if="!c.shifts.length" class="text-medium-emphasis">—</span>
                  </div>
                </td>
                <td><VIcon :color="c.include_public_holidays ? 'success' : 'medium-emphasis'" size="18">{{ c.include_public_holidays ? 'fas fa-check' : 'fas fa-times' }}</VIcon></td>
                <td><VChip v-if="c.is_default" color="primary" size="small" variant="tonal">{{ t('attendance_calendars_page.default') }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
                <td><VChip :color="c.status === 1 ? 'success' : 'error'" size="small" variant="tonal">{{ c.status === 1 ? t('attendance_calendars_page.active') : t('attendance_calendars_page.inactive') }}</VChip></td>
                <td class="text-center">
                  <VBtn icon size="small" variant="text" color="primary" @click="openEdit(c)"><i class="fas fa-pencil-alt"></i></VBtn>
                  <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(c.id)"><i class="fas fa-trash-alt"></i></VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="620">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ editMode ? t('attendance_calendars_page.dialog_edit_title') : t('attendance_calendars_page.dialog_new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VRow dense>
            <VCol cols="8"><VTextField v-model="form.name" :label="t('attendance_calendars_page.field_name')" :error-messages="form.errors.name" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model.number="form.year" :label="t('attendance_calendars_page.field_year')" type="number" density="compact" /></VCol>
            <VCol cols="12">
              <div class="text-caption text-medium-emphasis mb-2">{{ t('attendance_calendars_page.field_working_days') }}</div>
              <div class="d-flex flex-wrap gap-2">
                <VCheckbox v-for="d in dayFields" :key="d.key" v-model="(form as any)[d.key]" :label="d.label" density="compact" hide-details />
              </div>
            </VCol>
            <VCol cols="12">
              <VSelect v-model="form.shift_ids" :items="shifts.map(s => ({ title: `${s.name} (${s.code})`, value: s.id }))" :label="t('attendance_calendars_page.field_shifts')" multiple chips density="compact" />
            </VCol>
            <VCol cols="6"><VCheckbox v-model="form.include_public_holidays" :label="t('attendance_calendars_page.field_include_holidays')" density="compact" hide-details /></VCol>
            <VCol cols="6"><VCheckbox v-model="form.is_default" :label="t('attendance_calendars_page.field_set_default')" density="compact" hide-details /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :label="t('attendance_calendars_page.field_description')" rows="2" density="compact" /></VCol>
            <VCol cols="12">
              <VSelect v-model="form.status" :items="[{ title: t('attendance_calendars_page.active'), value: 1 }, { title: t('attendance_calendars_page.inactive'), value: 0 }]" :label="t('attendance_calendars_page.field_status')" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('attendance_calendars_page.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('attendance_calendars_page.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="400">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_calendars_page.delete_title') }}</VCardTitle>
        <VCardText>{{ t('attendance_calendars_page.delete_confirm') }}</VCardText>
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('attendance_calendars_page.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('attendance_calendars_page.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
