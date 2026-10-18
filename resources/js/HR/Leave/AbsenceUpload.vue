<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface LeaveType { id: number; name: string }
interface Batch {
  uuid: string; id: number; file_name: string | null; leave_type_name: string | null
  total_employees: number; total_days: number; total_hours: number
  status: string; performed_by_name: string | null; created_at: string
  reversed_by_name: string | null; reversed_at: string | null; items_count: number
}

const props = withDefaults(defineProps<{ leaveTypes: LeaveType[]; batches: Batch[] }>(), {
  leaveTypes: () => [],
  batches: () => [],
})

type Step = 'upload' | 'preview' | 'done'
const step = ref<Step>('upload')

const leaveTypeId  = ref<number | null>(props.leaveTypes[0]?.id ?? null)
const hoursPerDay  = ref<number>(8)
const file         = ref<File | null>(null)
const dragging     = ref(false)

const busy    = ref(false)
const error   = ref<string | null>(null)
const preview = ref<any | null>(null)
const result  = ref<{ applied: number; skipped: string[] } | null>(null)

const expanded = ref<string[]>([])

const csrf = () =>
  (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

/** Rows the confirm step will actually send. */
const applyItems = computed(() => preview.value?.apply_items ?? [])
const totals     = computed(() => preview.value?.totals ?? null)

function setFile(f: File) {
  const ext = f.name.split('.').pop()?.toLowerCase()
  if (!['csv', 'xlsx', 'xls'].includes(ext ?? '')) {
    error.value = t('leave_absence_upload.only_csv_xlsx')
    return
  }
  error.value = null
  file.value = f
}

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  if (input.files?.[0]) setFile(input.files[0])
}

function onDrop(e: DragEvent) {
  dragging.value = false
  const f = e.dataTransfer?.files[0]
  if (f) setFile(f)
}

function reset() {
  step.value = 'upload'
  file.value = null
  preview.value = null
  result.value = null
  error.value = null
  expanded.value = []
}

async function runPreview() {
  if (!file.value || !leaveTypeId.value) return
  busy.value = true
  error.value = null

  const form = new FormData()
  form.append('file', file.value)
  form.append('leave_type_id', String(leaveTypeId.value))
  form.append('hours_per_day', String(hoursPerDay.value))

  try {
    const { data } = await axios.post('/leave-management/absence-upload/preview', form, {
      headers: { 'X-CSRF-TOKEN': csrf(), 'Content-Type': 'multipart/form-data' },
    })
    preview.value = data
    step.value = 'preview'
  } catch (e: any) {
    error.value = e.response?.data?.error ?? t('leave_absence_upload.preview_failed')
  } finally {
    busy.value = false
  }
}

async function confirm() {
  if (!applyItems.value.length) return
  busy.value = true
  error.value = null

  try {
    const { data } = await axios.post('/leave-management/absence-upload/confirm', {
      leave_type_id: leaveTypeId.value,
      hours_per_day: hoursPerDay.value,
      file_name: preview.value?.file_name ?? null,
      items: applyItems.value,
    }, { headers: { 'X-CSRF-TOKEN': csrf() } })

    result.value = { applied: data.applied, skipped: data.skipped ?? [] }
    step.value = 'done'
  } catch (e: any) {
    error.value = e.response?.data?.error ?? t('leave_absence_upload.confirm_failed')
  } finally {
    busy.value = false
  }
}

async function reverseBatch(batch: Batch) {
  const reason = window.prompt(t('leave_absence_upload.reverse_prompt', { n: batch.total_employees }))
  if (reason === null) return

  busy.value = true
  try {
    await axios.post(`/leave-management/absence-upload/batches/${batch.uuid}/reverse`,
      { reason }, { headers: { 'X-CSRF-TOKEN': csrf() } })
    router.reload({ only: ['batches'] })
  } catch (e: any) {
    error.value = e.response?.data?.error ?? t('leave_absence_upload.reverse_failed')
  } finally {
    busy.value = false
  }
}

function outcomeChip(outcome: string) {
  switch (outcome) {
    case 'apply':              return { color: 'success', label: t('leave_absence_upload.outcome_apply') }
    case 'apply_negative':     return { color: 'warning', label: t('leave_absence_upload.outcome_negative') }
    case 'already_on_leave':   return { color: 'info',    label: t('leave_absence_upload.outcome_on_leave') }
    case 'duplicate_in_file':  return { color: 'default', label: t('leave_absence_upload.outcome_duplicate') }
    case 'exempt':             return { color: 'default', label: t('leave_absence_upload.outcome_exempt') }
    default:                   return { color: 'error',   label: t('leave_absence_upload.outcome_not_found') }
  }
}

const batchHeaders = computed(() => [
  { title: t('leave_absence_upload.col_file'), key: 'file_name' },
  { title: t('leave_absence_upload.col_leave_type'), key: 'leave_type_name' },
  { title: t('leave_absence_upload.col_employees'), key: 'total_employees' },
  { title: t('leave_absence_upload.col_days'), key: 'total_days' },
  { title: t('leave_absence_upload.col_by'), key: 'performed_by_name' },
  { title: t('leave_absence_upload.col_status'), key: 'status' },
  { title: '', key: 'actions', sortable: false },
])
</script>

<template>
  <VContainer fluid class="pa-6" style="max-width: 1200px;">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('leave_absence_upload.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('leave_absence_upload.subtitle') }}</p>
      </div>
      <VBtn v-if="step !== 'upload'" variant="text" prepend-icon="ri-arrow-left-line" @click="reset">
        {{ t('leave_absence_upload.start_over') }}
      </VBtn>
    </div>

    <VAlert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="error = null">
      {{ error }}
    </VAlert>

    <!-- ── Step 1: choose settings and upload ─────────────────────────────── -->
    <VCard v-if="step === 'upload'" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" md="6">
            <VSelect
              v-model="leaveTypeId"
              :items="props.leaveTypes"
              item-title="name"
              item-value="id"
              :label="t('leave_absence_upload.leave_type')"
              density="compact"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model.number="hoursPerDay"
              type="number"
              step="0.5"
              min="0.5"
              :label="t('leave_absence_upload.hours_per_day')"
              :hint="t('leave_absence_upload.hours_per_day_hint')"
              persistent-hint
              density="compact"
            />
          </VCol>
        </VRow>

        <div
          class="mt-6 pa-8 text-center rounded"
          :style="`border:2px dashed ${dragging ? 'rgb(var(--v-theme-primary))' : 'rgba(0,0,0,0.2)'}`"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
        >
          <VIcon icon="ri-upload-cloud-2-line" size="40" class="mb-2 text-medium-emphasis" />
          <p class="text-body-1 mb-1">{{ file ? file.name : t('leave_absence_upload.drop_file') }}</p>
          <p class="text-caption text-medium-emphasis mb-3">{{ t('leave_absence_upload.expected_columns') }}</p>
          <VBtn size="small" variant="tonal" @click="($refs.picker as HTMLInputElement).click()">
            {{ t('leave_absence_upload.choose_file') }}
          </VBtn>
          <input ref="picker" type="file" accept=".csv,.xlsx,.xls" hidden @change="onFileChange">
        </div>
      </VCardText>
      <VCardActions class="px-4 pb-4">
        <VSpacer />
        <VBtn color="primary" :loading="busy" :disabled="!file || !leaveTypeId" @click="runPreview">
          {{ t('leave_absence_upload.preview_btn') }}
        </VBtn>
      </VCardActions>
    </VCard>

    <!-- ── Step 2: preview ────────────────────────────────────────────────── -->
    <template v-if="step === 'preview' && preview">
      <VAlert type="info" variant="tonal" class="mb-4">
        <div class="d-flex flex-wrap gap-4">
          <span><strong>{{ totals.employees }}</strong> {{ t('leave_absence_upload.employees_affected') }}</span>
          <span><strong>{{ totals.apply_days }}</strong> {{ t('leave_absence_upload.days_to_deduct') }}</span>
          <span><strong>{{ totals.skipped }}</strong> {{ t('leave_absence_upload.skipped') }}</span>
          <span v-if="totals.not_found"><strong>{{ totals.not_found }}</strong> {{ t('leave_absence_upload.not_found') }}</span>
        </div>
      </VAlert>

      <VAlert v-if="totals.goes_negative" type="warning" variant="tonal" class="mb-4">
        {{ t('leave_absence_upload.negative_warning', { n: totals.goes_negative }) }}
      </VAlert>

      <VAlert v-if="preview.parse_errors?.length" type="warning" variant="tonal" class="mb-4">
        <div class="font-weight-medium mb-1">{{ t('leave_absence_upload.rows_unreadable') }}</div>
        <ul class="ms-4">
          <li v-for="(e, i) in preview.parse_errors" :key="i" class="text-caption">{{ e }}</li>
        </ul>
      </VAlert>

      <VExpansionPanels v-model="expanded" multiple class="mb-6">
        <VExpansionPanel v-for="emp in preview.employees" :key="emp.employee_number" :value="emp.employee_number">
          <VExpansionPanelTitle>
            <div class="d-flex align-center justify-space-between w-100 pe-4">
              <div>
                <span class="font-weight-medium">{{ emp.employee_name }}</span>
                <span class="text-caption text-medium-emphasis ms-2">({{ emp.employee_number }})</span>
              </div>
              <div class="d-flex align-center gap-2">
                <VChip v-if="!emp.found" size="small" color="error" variant="tonal">
                  {{ t('leave_absence_upload.outcome_not_found') }}
                </VChip>
                <template v-else>
                  <VChip size="small" variant="tonal">
                    {{ emp.apply_days }} {{ t('leave_absence_upload.days') }}
                  </VChip>
                  <VChip v-if="emp.goes_negative" size="small" color="warning" variant="tonal">
                    {{ t('leave_absence_upload.goes_negative') }}
                  </VChip>
                </template>
              </div>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('leave_absence_upload.col_date') }}</th>
                  <th>{{ t('leave_absence_upload.col_day') }}</th>
                  <th class="text-end">{{ t('leave_absence_upload.col_before') }}</th>
                  <th class="text-end">{{ t('leave_absence_upload.col_after') }}</th>
                  <th>{{ t('leave_absence_upload.col_outcome') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in emp.dates" :key="d.date">
                  <td>{{ d.date }}</td>
                  <td>{{ d.day }}</td>
                  <td class="text-end">{{ d.days_before ?? '—' }}</td>
                  <td class="text-end" :class="{ 'text-warning': d.outcome === 'apply_negative' }">
                    {{ d.days_after ?? '—' }}
                  </td>
                  <td>
                    <VChip size="x-small" :color="outcomeChip(d.outcome).color" variant="tonal">
                      {{ outcomeChip(d.outcome).label }}
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>

      <div class="d-flex justify-end gap-2">
        <VBtn variant="text" @click="reset">{{ t('leave_absence_upload.cancel') }}</VBtn>
        <VBtn color="primary" :loading="busy" :disabled="!applyItems.length" @click="confirm">
          {{ t('leave_absence_upload.confirm_btn', { n: totals.apply_days }) }}
        </VBtn>
      </div>
    </template>

    <!-- ── Step 3: result ─────────────────────────────────────────────────── -->
    <VCard v-if="step === 'done' && result" class="mb-6">
      <VCardText class="text-center py-8">
        <VIcon icon="ri-checkbox-circle-line" size="48" color="success" class="mb-3" />
        <h2 class="text-h6 mb-2">{{ t('leave_absence_upload.applied_title', { n: result.applied }) }}</h2>
        <p class="text-body-2 text-medium-emphasis">{{ t('leave_absence_upload.applied_hint') }}</p>

        <VAlert v-if="result.skipped.length" type="info" variant="tonal" class="mt-4 text-start">
          <div class="font-weight-medium mb-1">{{ t('leave_absence_upload.skipped_title') }}</div>
          <ul class="ms-4">
            <li v-for="(s, i) in result.skipped" :key="i" class="text-caption">{{ s }}</li>
          </ul>
        </VAlert>

        <VBtn class="mt-4" variant="tonal" @click="reset">{{ t('leave_absence_upload.upload_another') }}</VBtn>
      </VCardText>
    </VCard>

    <!-- ── Past uploads ───────────────────────────────────────────────────── -->
    <VCard>
      <VCardTitle class="text-subtitle-1">{{ t('leave_absence_upload.past_uploads') }}</VCardTitle>
      <VDataTable
        :headers="batchHeaders"
        :items="props.batches"
        density="compact"
        :items-per-page="10"
      >
        <template #item.status="{ item }">
          <VChip size="x-small" :color="item.status === 'reversed' ? 'default' : 'success'" variant="tonal">
            {{ item.status === 'reversed' ? t('leave_absence_upload.reversed') : t('leave_absence_upload.applied') }}
          </VChip>
          <div v-if="item.reversed_by_name" class="text-caption text-medium-emphasis">
            {{ t('leave_absence_upload.reversed_by', { name: item.reversed_by_name }) }}
          </div>
        </template>
        <template #item.actions="{ item }">
          <VBtn
            v-if="item.status !== 'reversed'"
            size="x-small"
            color="error"
            variant="tonal"
            :loading="busy"
            @click="reverseBatch(item)"
          >
            {{ t('leave_absence_upload.reverse') }}
          </VBtn>
        </template>
        <template #no-data>
          <div class="text-center text-medium-emphasis py-6">{{ t('leave_absence_upload.no_uploads') }}</div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
