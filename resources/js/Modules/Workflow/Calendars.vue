<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute
defineProps<{ calendars: any[]; timezones: string[] }>()
const days = [['Mon', 1], ['Tue', 2], ['Wed', 3], ['Thu', 4], ['Fri', 5], ['Sat', 6], ['Sun', 7]] as const
const dialog = ref(false)
const editing = ref<string | null>(null)
const form = useForm({ name: '', timezone: 'Africa/Johannesburg', work_days: [1, 2, 3, 4, 5] as number[], day_start: '08:00', day_end: '17:00', use_public_holidays: true, extra_holidays: [] as string[], is_default: false })
function open(c: any = null) {
  editing.value = c?.uuid ?? null
  form.defaults(c ? { name: c.name, timezone: c.timezone, work_days: c.work_days, day_start: c.day_start, day_end: c.day_end, use_public_holidays: c.use_public_holidays, extra_holidays: c.extra_holidays ?? [], is_default: c.is_default }
    : { name: '', timezone: 'Africa/Johannesburg', work_days: [1, 2, 3, 4, 5], day_start: '08:00', day_end: '17:00', use_public_holidays: true, extra_holidays: [], is_default: false })
  form.reset(); dialog.value = true
}
function save() {
  const opts = { onSuccess: () => { dialog.value = false } }
  editing.value ? form.patch(route('workflow.calendars.update', editing.value), opts) : form.post(route('workflow.calendars.store'), opts)
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div><h4 class="text-h4">Business calendars</h4><div class="text-body-2 text-medium-emphasis">Working hours, weekends and holidays used for business-hour SLAs. South African public holidays (with Monday substitutes) are built in; add company closure days here.</div></div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="open()">New calendar</VBtn>
    </div>
    <VCard border>
      <VTable density="comfortable">
        <thead><tr><th>Calendar</th><th>Hours</th><th>Days</th><th>Holidays</th><th>Used by</th><th /></tr></thead>
        <tbody>
          <tr v-for="c in calendars" :key="c.uuid">
            <td>{{ c.name }}<VChip v-if="c.is_default" size="x-small" class="ms-1" label>default</VChip><div class="text-caption text-medium-emphasis">{{ c.timezone }} · version {{ c.version }}</div></td>
            <td>{{ c.day_start }}–{{ c.day_end }}</td>
            <td>{{ days.filter(d => c.work_days.includes(d[1])).map(d => d[0]).join(', ') }}</td>
            <td class="text-body-2">{{ c.use_public_holidays ? 'Public holidays' : 'None' }}{{ c.extra_holidays?.length ? ` + ${c.extra_holidays.length} closure day(s)` : '' }}</td>
            <td>{{ c.used_by }} workflow(s)</td>
            <td><VBtn size="small" variant="text" @click="open(c)">Edit</VBtn></td>
          </tr>
          <tr v-if="!calendars.length"><td colspan="6" class="text-medium-emphasis">No calendars yet. Without one, business hours are Monday to Friday, 08:00–17:00, Africa/Johannesburg, with public holidays.</td></tr>
        </tbody>
      </VTable>
    </VCard>
    <VDialog v-model="dialog" max-width="560">
      <VCard :title="editing ? 'Edit calendar' : 'New calendar'">
        <VCardText class="d-flex flex-column gap-2">
          <VTextField v-model="form.name" label="Name" :error-messages="form.errors.name" />
          <VSelect v-model="form.timezone" :items="timezones" label="Time zone" />
          <div class="d-flex flex-wrap gap-x-2"><VCheckbox v-for="d in days" :key="d[1]" v-model="form.work_days" :value="d[1]" :label="d[0]" density="compact" hide-details /></div>
          <div class="d-flex gap-2"><VTextField v-model="form.day_start" type="time" label="Day starts" /><VTextField v-model="form.day_end" type="time" label="Day ends" :error-messages="form.errors.day_end" /></div>
          <VSwitch v-model="form.use_public_holidays" label="Skip South African public holidays" color="primary" inset hide-details />
          <VCombobox v-model="form.extra_holidays" label="Company closure days (YYYY-MM-DD)" multiple chips closable-chips :error-messages="form.errors['extra_holidays.0']" />
          <VCheckbox v-model="form.is_default" label="Default calendar" density="compact" hide-details />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
