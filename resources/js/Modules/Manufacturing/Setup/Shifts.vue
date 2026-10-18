<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute
const confirmer = ref()

type Shift = {
  id: string; code: string; name: string; factory: string | null; factory_id: string | null
  starts_at: string; ends_at: string; crosses_midnight: boolean; break_minutes: number
  days_of_week: number[]; working_minutes: number; overtime_after_minutes: number | null
  overtime_multiplier: number; observes_public_holidays: boolean; is_active: boolean
}
type Shutdown = {
  id: string; factory: string | null; line: string | null; whole_factory: boolean
  reason: string; kind: string; starts_at: string | null; ends_at: string | null; timezone: string | null
}

const props = defineProps<{
  shifts: Shift[]
  shutdowns: Shutdown[]
  factories: Array<{ id: string; code: string; name: string }>
  production_lines: Array<{ id: number; factory_id: string | null; code: string; name: string }>
  shutdown_kinds: string[]
  calendar: {
    factory_id: string | null; from: string; total_minutes: number
    days: Array<{ date: string; weekday: number; is_holiday: boolean; minutes: number
      shifts: Array<{ code: string; name: string; minutes: number; reason: string | null }> }>
  }
}>()

// ── Working calendar ────────────────────────────────────────────────────────
const calFactory = ref<string | null>(props.calendar.factory_id)
const calFrom = ref<string>(props.calendar.from)

function loadCalendar() {
  router.get(route('manufacturing.setup.shifts'), {
    calendar_factory: calFactory.value || undefined, calendar_from: calFrom.value || undefined,
  }, { preserveState: true, preserveScroll: true, replace: true, only: ['calendar'] })
}

// ISO weekday numbers, 1 = Monday.
const weekdays = [1, 2, 3, 4, 5, 6, 7]

// ── Shift ───────────────────────────────────────────────────────────────────
const dialog = ref(false)
const editingId = ref<string | null>(null)
const form = useForm({
  factory_uuid: null as string | null, code: '', name: '', starts_at: '06:00', ends_at: '14:00',
  crosses_midnight: false, break_minutes: 30, days_of_week: [1, 2, 3, 4, 5] as number[],
  overtime_after_minutes: null as number | null, overtime_multiplier: 1.5,
  observes_public_holidays: true, is_active: true,
})

function openCreate() {
  editingId.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(s: Shift) {
  editingId.value = s.id
  form.factory_uuid = s.factory_id
  form.code = s.code
  form.name = s.name
  form.starts_at = s.starts_at
  form.ends_at = s.ends_at
  form.crosses_midnight = s.crosses_midnight
  form.break_minutes = s.break_minutes
  form.days_of_week = [...s.days_of_week]
  form.overtime_after_minutes = s.overtime_after_minutes
  form.overtime_multiplier = s.overtime_multiplier
  form.observes_public_holidays = s.observes_public_holidays
  form.is_active = s.is_active
  form.clearErrors()
  dialog.value = true
}

function save() {
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editingId.value) {
    form.patch(route('manufacturing.setup.shifts.update', editingId.value), done)
  } else {
    form.post(route('manufacturing.setup.shifts.store'), done)
  }
}

function remove(s: Shift) {
  confirmer.value.open({
    title: t('manufacturing.shifts.remove'),
    message: t('manufacturing.product.remove_named', { name: s.name }),
    confirmText: t('manufacturing.common.remove'), icon: 'tabler-trash',
  }, () => router.delete(route('manufacturing.setup.shifts.destroy', s.id), { preserveScroll: true }))
}

const hours = (minutes: number) => `${(minutes / 60).toFixed(1)} ${t('manufacturing.shifts.hours')}`

// A night shift ends before it starts by the clock. Suggest the flag rather than leave a
// 22:00-06:00 shift silently stored as a negative duration.
const looksOvernight = computed(() => form.ends_at <= form.starts_at && !form.crosses_midnight)

// ── Shutdown ────────────────────────────────────────────────────────────────
const shutdownDialog = ref(false)
const shutdownForm = useForm({
  factory_uuid: null as string | null, production_line_id: null as number | null,
  reason: '', kind: 'PLANNED', starts_at: '', ends_at: '',
})

const linesForShutdown = computed(() =>
  props.production_lines.filter(l => l.factory_id === shutdownForm.factory_uuid))

function saveShutdown() {
  shutdownForm.post(route('manufacturing.setup.shutdowns.store'), {
    preserveScroll: true, onSuccess: () => { shutdownDialog.value = false; shutdownForm.reset() },
  })
}

function removeShutdown(s: Shutdown) {
  router.delete(route('manufacturing.setup.shutdowns.destroy', s.id), { preserveScroll: true })
}

// On the plant's own clock: a shutdown at 06:00 in Johannesburg reads 06:00 wherever it is viewed.
const when = (iso: string | null, tz?: string | null) =>
  (iso ? new Date(iso).toLocaleString(undefined, tz ? { timeZone: tz } : {}) : '—')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.shifts.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.shifts.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-alert-octagon" @click="shutdownDialog = true">
          {{ t('manufacturing.shifts.record_shutdown') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('manufacturing.shifts.add') }}</VBtn>
      </div>
    </div>

    <VCard border class="mb-4">
      <VCardItem><VCardTitle>{{ t('manufacturing.shifts.patterns') }}</VCardTitle></VCardItem>
      <VTable v-if="shifts.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('manufacturing.common.name') }}</th>
            <th>{{ t('manufacturing.workcentres.factory') }}</th>
            <th>{{ t('manufacturing.shifts.time') }}</th>
            <th>{{ t('manufacturing.shifts.days') }}</th>
            <th class="text-end">{{ t('manufacturing.shifts.working') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in shifts" :key="s.id" :class="{ 'opacity-60': !s.is_active }">
            <td>
              <div>{{ s.code }} — {{ s.name }}</div>
              <VChip v-if="s.crosses_midnight" size="x-small" label color="indigo" variant="tonal">
                {{ t('manufacturing.shifts.overnight') }}
              </VChip>
            </td>
            <td>{{ s.factory }}</td>
            <td>{{ s.starts_at }} – {{ s.ends_at }}</td>
            <td>{{ s.days_of_week.map(d => t(`manufacturing.weekday.${d}`)).join(' ') }}</td>
            <td class="text-end">{{ hours(s.working_minutes) }}</td>
            <td class="text-end text-no-wrap">
              <VBtn icon="tabler-pencil" variant="text" size="small" :aria-label="t('manufacturing.common.edit')"
                    @click="openEdit(s)" />
              <VBtn icon="tabler-trash" variant="text" size="small" :aria-label="t('manufacturing.common.remove')"
                    @click="remove(s)" />
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.shifts.none') }}</VCardText>
    </VCard>

    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('manufacturing.shifts.calendar') }}</VCardTitle>
        <VCardSubtitle>{{ t('manufacturing.shifts.calendar_hint') }}</VCardSubtitle>
      </VCardItem>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VSelect v-model="calFactory" :items="factories" item-value="id" density="compact" hide-details
                 style="max-width: 280px" :item-title="(f: any) => `${f.code} — ${f.name}`"
                 :label="t('manufacturing.workcentres.factory')" @update:model-value="loadCalendar" />
        <VTextField v-model="calFrom" type="date" density="compact" hide-details style="max-width: 200px"
                    :label="t('manufacturing.shifts.from')" @change="loadCalendar" />
        <VChip label color="primary" variant="tonal">
          {{ t('manufacturing.shifts.available_total', { hours: (calendar.total_minutes / 60).toFixed(1) }) }}
        </VChip>
      </VCardText>
      <VTable v-if="calendar.days.length" density="compact">
        <thead>
          <tr>
            <th>{{ t('manufacturing.shifts.date') }}</th>
            <th>{{ t('manufacturing.shifts.patterns') }}</th>
            <th class="text-end">{{ t('manufacturing.shifts.available') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in calendar.days" :key="d.date" :class="{ 'text-medium-emphasis': !d.minutes }">
            <td class="text-no-wrap">
              {{ t(`manufacturing.weekday.${d.weekday}`) }} {{ d.date }}
              <VChip v-if="d.is_holiday" size="x-small" label color="info" variant="tonal" class="ms-1">
                {{ t('manufacturing.shifts.holiday') }}
              </VChip>
            </td>
            <td>
              <span v-for="sh in d.shifts" :key="sh.code" class="me-3">
                {{ sh.code }} {{ hours(sh.minutes) }}
                <VChip v-if="sh.reason" size="x-small" label variant="tonal" class="ms-1"
                       :color="sh.reason === 'PARTIAL_SHUTDOWN' ? 'warning' : 'error'">
                  {{ t(`manufacturing.calendar_reason.${sh.reason}`) }}
                </VChip>
              </span>
              <span v-if="!d.shifts.length">—</span>
            </td>
            <td class="text-end font-weight-medium">{{ hours(d.minutes) }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.shifts.calendar_none') }}</VCardText>
    </VCard>

    <VCard border>
      <VCardItem><VCardTitle>{{ t('manufacturing.shifts.shutdowns') }}</VCardTitle></VCardItem>
      <VList v-if="shutdowns.length" density="compact">
        <VListItem v-for="s in shutdowns" :key="s.id">
          <VListItemTitle>{{ s.reason }}</VListItemTitle>
          <VListItemSubtitle>
            {{ s.factory }} ·
            <!-- A null line means the whole factory stops, which must not read as "no line". -->
            {{ s.whole_factory ? t('manufacturing.dashboard.whole_factory') : s.line }}
            · {{ when(s.starts_at, s.timezone) }} → {{ when(s.ends_at, s.timezone) }}
          </VListItemSubtitle>
          <template #append>
            <VChip size="x-small" label variant="tonal" class="me-2">{{ t(`manufacturing.shutdown_kind.${s.kind}`) }}</VChip>
            <VBtn icon="tabler-trash" variant="text" size="small" :aria-label="t('manufacturing.common.remove')"
                  @click="removeShutdown(s)" />
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.shifts.no_shutdowns') }}</VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard :title="editingId ? t('manufacturing.shifts.edit') : t('manufacturing.shifts.add')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect v-model="form.factory_uuid" :disabled="!!editingId" :items="factories" item-value="id"
                       :item-title="(f: any) => `${f.code} — ${f.name}`"
                       :error-messages="form.errors.factory_uuid" :label="t('manufacturing.workcentres.factory')" />
            </VCol>
            <VCol cols="4" md="2">
              <VTextField v-model="form.code" :error-messages="form.errors.code" :label="t('manufacturing.common.code')" />
            </VCol>
            <VCol cols="8" md="4">
              <VTextField v-model="form.name" :error-messages="form.errors.name" :label="t('manufacturing.common.name')" />
            </VCol>
            <VCol cols="4">
              <VTextField v-model="form.starts_at" type="time" :error-messages="form.errors.starts_at"
                          :label="t('manufacturing.shifts.starts')" />
            </VCol>
            <VCol cols="4">
              <VTextField v-model="form.ends_at" type="time" :error-messages="form.errors.ends_at"
                          :label="t('manufacturing.shifts.ends')" />
            </VCol>
            <VCol cols="4">
              <VTextField v-model.number="form.break_minutes" type="number" min="0"
                          :label="t('manufacturing.shifts.break')" :suffix="t('manufacturing.workcentres.min')" />
            </VCol>
          </VRow>

          <VAlert v-if="looksOvernight" type="info" variant="tonal" density="compact" class="my-2">
            {{ t('manufacturing.shifts.overnight_hint') }}
          </VAlert>
          <VSwitch v-model="form.crosses_midnight" color="primary" hide-details
                   :label="t('manufacturing.shifts.crosses_midnight')" />

          <div class="text-subtitle-2 mt-3 mb-1">{{ t('manufacturing.shifts.days') }}</div>
          <VChipGroup v-model="form.days_of_week" multiple column selected-class="text-primary">
            <VChip v-for="d in weekdays" :key="d" :value="d" label filter>{{ t(`manufacturing.weekday.${d}`) }}</VChip>
          </VChipGroup>
          <p v-if="form.errors.days_of_week" class="text-error text-body-2">{{ form.errors.days_of_week }}</p>

          <VRow dense class="mt-2">
            <VCol cols="6">
              <VTextField v-model.number="form.overtime_after_minutes" type="number" min="0"
                          :label="t('manufacturing.shifts.overtime_after')" :suffix="t('manufacturing.workcentres.min')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="form.overtime_multiplier" type="number" min="1" step="0.05"
                          :label="t('manufacturing.shifts.overtime_multiplier')" suffix="×" />
            </VCol>
          </VRow>
          <VSwitch v-model="form.observes_public_holidays" color="primary" hide-details
                   :label="t('manufacturing.shifts.public_holidays')" />
          <VSwitch v-if="editingId" v-model="form.is_active" color="primary" hide-details
                   :label="t('manufacturing.product.active')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.factory_uuid" @click="save">
            {{ t('manufacturing.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="shutdownDialog" max-width="520">
      <VCard :title="t('manufacturing.shifts.record_shutdown')">
        <VCardText>
          <VSelect v-model="shutdownForm.factory_uuid" :items="factories" item-value="id" class="mb-2"
                   :item-title="(f: any) => `${f.code} — ${f.name}`"
                   :error-messages="shutdownForm.errors.factory_uuid" :label="t('manufacturing.workcentres.factory')" />
          <VSelect v-model="shutdownForm.production_line_id" clearable :items="linesForShutdown" item-value="id"
                   :item-title="(l: any) => `${l.code} — ${l.name}`" class="mb-2"
                   :hint="t('manufacturing.shifts.line_hint')" persistent-hint
                   :label="t('manufacturing.workcentres.line')" />
          <VTextField v-model="shutdownForm.reason" :error-messages="shutdownForm.errors.reason" class="mb-2"
                      :label="t('manufacturing.shifts.reason')" />
          <VSelect v-model="shutdownForm.kind" class="mb-2"
                   :items="shutdown_kinds.map(k => ({ title: t(`manufacturing.shutdown_kind.${k}`), value: k }))"
                   :label="t('manufacturing.products.kind')" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="shutdownForm.starts_at" type="datetime-local"
                          :error-messages="shutdownForm.errors.starts_at" :label="t('manufacturing.shifts.starts')" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="shutdownForm.ends_at" type="datetime-local"
                          :error-messages="shutdownForm.errors.ends_at" :label="t('manufacturing.shifts.ends')" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="shutdownDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="shutdownForm.processing" :disabled="!shutdownForm.factory_uuid"
                @click="saveShutdown">{{ t('manufacturing.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
