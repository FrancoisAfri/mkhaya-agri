<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

interface Suspension {
  id: number; employee: string; employee_id: number
  suspension_type: string; start_date: string; end_date: string|null
  actual_return_date: string|null; suspension_days: number|null
  status: string; reason: string; case_number: string|null; case_id: number|null
  issued_by: string; issued_at: string|null; notes: string|null
}
interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number }
interface Employee { id: number; name: string; email: string }
interface OpenCase { id: number; label: string }

const props = defineProps<{
  suspensions: Paginated<Suspension>
  filters: Record<string, string>
  employees: Employee[]
  open_cases: OpenCase[]
}>()

// ── State ─────────────────────────────────────────────────────────────────────
const issueDialog   = ref(false)
const endDialog     = ref(false)
const cancelDialog  = ref(false)
const extendDialog  = ref(false)
const convertDialog = ref(false)
const noteDialog    = ref(false)
const historyDialog = ref(false)
const selectedId    = ref<number|null>(null)
const selected      = computed(() => props.suspensions.data.find(s => s.id === selectedId.value) ?? null)

// Parse the notes field into timeline entries
const historyEntries = computed(() => {
  const s = selected.value
  const entries: { timestamp: string; author: string; text: string; type: string }[] = []

  // First entry: the original suspension
  if (s) {
    entries.push({
      timestamp: s.issued_at ?? s.start_date,
      author:    s.issued_by ?? 'System',
      text:      `Suspension issued — ${s.suspension_type} (${s.suspension_days ?? 'indefinite'} day(s)). Reason: ${s.reason}`,
      type:      'issued',
    })
  }

  // Parse each timestamped note appended by actions
  if (s?.notes) {
    // Entries look like: [21 May 2026 06:33 — Name] text
    const pattern = /\[([^\]]+?)\s*—\s*([^\]]*?)\]\s*(.+?)(?=\[|$)/gs
    let match
    while ((match = pattern.exec(s.notes)) !== null) {
      const text = match[3].trim()
      const lower = text.toLowerCase()
      const type = lower.startsWith('cancelled') ? 'cancelled'
        : lower.startsWith('extended')  ? 'extended'
        : lower.startsWith('type changed') ? 'converted'
        : 'note'
      entries.push({ timestamp: match[1].trim(), author: match[2].trim() || 'System', text, type })
    }
  }

  return entries
})

// ── Forms ─────────────────────────────────────────────────────────────────────
const issueForm = useForm({
  employee_id: null as number|null, suspension_type: 'paid', reason: '',
  start_date: '', end_date: '', case_id: null as number|null, notes: '',
})
const endForm     = useForm({ actual_return_date: '' })
const cancelForm  = useForm({ reason: '' })
const extendForm  = useForm({ new_end_date: '', reason: '' })
const convertForm = useForm({ new_type: '', reason: '' })
const noteForm    = useForm({ note: '' })

// ── Actions ───────────────────────────────────────────────────────────────────
function submitSuspension() {
  issueForm.post(route('disciplinary.progressive-discipline.suspensions.store'), {
    onSuccess: () => { issueDialog.value = false; issueForm.reset() },
  })
}

function openEndDialog(id: number)     { selectedId.value = id; endForm.reset();     endDialog.value = true }
function openCancelDialog(id: number)  { selectedId.value = id; cancelForm.reset();  cancelDialog.value = true }
function openExtendDialog(id: number)  { selectedId.value = id; extendForm.reset();  extendDialog.value = true }
function openConvertDialog(id: number) { selectedId.value = id; convertForm.reset(); convertDialog.value = true }
function openNoteDialog(id: number)    { selectedId.value = id; noteForm.reset();    noteDialog.value = true }
function openHistoryDialog(id: number) { selectedId.value = id; historyDialog.value = true }

function submitEnd() {
  if (!selectedId.value) return
  endForm.post(route('disciplinary.progressive-discipline.suspensions.end', selectedId.value), {
    onSuccess: () => { endDialog.value = false },
  })
}

function submitCancel() {
  if (!selectedId.value) return
  cancelForm.post(route('disciplinary.progressive-discipline.suspensions.cancel', selectedId.value), {
    onSuccess: () => { cancelDialog.value = false },
  })
}

function submitExtend() {
  if (!selectedId.value) return
  extendForm.post(route('disciplinary.progressive-discipline.suspensions.extend', selectedId.value), {
    onSuccess: () => { extendDialog.value = false },
  })
}

function submitConvert() {
  if (!selectedId.value) return
  convertForm.post(route('disciplinary.progressive-discipline.suspensions.convert', selectedId.value), {
    onSuccess: () => { convertDialog.value = false },
  })
}

function submitNote() {
  if (!selectedId.value) return
  noteForm.post(route('disciplinary.progressive-discipline.suspensions.note', selectedId.value), {
    onSuccess: () => { noteDialog.value = false },
  })
}

const statusColor = (s: string) => s === 'active' ? 'warning' : s === 'ended' ? 'success' : s === 'cancelled' ? 'error' : 'grey'

const suspensionTypes = [
  { value: 'paid', title: 'Paid Suspension' },
  { value: 'unpaid', title: 'Unpaid Suspension' },
  { value: 'precautionary', title: 'Precautionary Suspension' },
]
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">Suspensions</h1>
        <p class="text-body-2 text-medium-emphasis">Paid, unpaid and precautionary suspensions</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="issueDialog = true">Issue Suspension</VBtn>
    </div>

    <VCard>
      <VDataTable
        :items="suspensions.data"
        :headers="[
          { title: 'Employee', key: 'employee' },
          { title: 'Type', key: 'suspension_type' },
          { title: 'Start', key: 'start_date' },
          { title: 'End', key: 'end_date' },
          { title: 'Days', key: 'suspension_days' },
          { title: 'Status', key: 'status' },
          { title: 'Case', key: 'case_number' },
          { title: 'Actions', key: '_actions', sortable: false, width: '180' },
        ]"
      >
        <template #item.suspension_type="{ item }">
          <VChip size="small" color="blue-grey" variant="tonal">{{ item.suspension_type }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ item.status }}</VChip>
        </template>
        <template #item.end_date="{ item }">{{ item.end_date ?? '—' }}</template>
        <template #item.suspension_days="{ item }">{{ item.suspension_days ?? '—' }}</template>
        <template #item.case_number="{ item }">{{ item.case_number ?? '—' }}</template>
        <template #item._actions="{ item }">
          <div class="d-flex ga-1 flex-wrap">
            <VBtn size="x-small" color="grey" variant="tonal" @click="openHistoryDialog(item.id)" title="View history">
              <VIcon size="14">tabler-history</VIcon> History
            </VBtn>
            <template v-if="item.status === 'active'">
              <VBtn size="x-small" color="success" variant="tonal" @click="openEndDialog(item.id)" title="End suspension">
                <VIcon size="14">tabler-check</VIcon> End
              </VBtn>
              <VBtn size="x-small" color="primary" variant="tonal" @click="openExtendDialog(item.id)" title="Extend end date">
                <VIcon size="14">tabler-calendar-plus</VIcon> Extend
              </VBtn>
              <VBtn size="x-small" color="info" variant="tonal" @click="openConvertDialog(item.id)" title="Convert type">
                <VIcon size="14">tabler-arrows-left-right</VIcon> Convert
              </VBtn>
              <VBtn size="x-small" color="secondary" variant="tonal" @click="openNoteDialog(item.id)" title="Add note">
                <VIcon size="14">tabler-note-plus</VIcon> Note
              </VBtn>
              <VBtn size="x-small" color="error" variant="tonal" @click="openCancelDialog(item.id)" title="Cancel suspension">
                <VIcon size="14">tabler-x</VIcon> Cancel
              </VBtn>
            </template>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- ── Issue Suspension ──────────────────────────────────────────────────── -->
    <VDialog v-model="issueDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-4">Issue Suspension</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="issueForm.employee_id"
                :items="employees"
                item-title="name"
                item-value="id"
                label="Employee *"
                variant="outlined"
                density="compact"
                :error-messages="issueForm.errors.employee_id"
                clearable
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
                </template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12">
              <VSelect v-model="issueForm.suspension_type" label="Type *" :items="suspensionTypes"
                :error-messages="issueForm.errors.suspension_type" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="issueForm.start_date" label="Start Date *" type="date"
                :error-messages="issueForm.errors.start_date" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="issueForm.end_date" label="End Date" type="date"
                :error-messages="issueForm.errors.end_date" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="issueForm.reason" label="Reason *" rows="3"
                :error-messages="issueForm.errors.reason" variant="outlined" />
            </VCol>
            <VCol cols="6">
              <VAutocomplete
                v-model="issueForm.case_id"
                :items="open_cases"
                item-title="label"
                item-value="id"
                label="Case (optional)"
                variant="outlined"
                density="compact"
                :error-messages="issueForm.errors.case_id"
                clearable
              />
            </VCol>
            <VCol cols="6">
              <VTextarea v-model="issueForm.notes" label="Notes" rows="2" variant="outlined" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="issueDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="issueForm.processing" @click="submitSuspension">Issue</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── End Suspension ───────────────────────────────────────────────────── -->
    <VDialog v-model="endDialog" max-width="400">
      <VCard>
        <VCardTitle class="pa-4">End Suspension</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 mb-4" v-if="selected">
            Ending suspension for <strong>{{ selected.employee }}</strong>. Employee access will be restored.
          </p>
          <VTextField v-model="endForm.actual_return_date" label="Actual Return Date *" type="date"
            variant="outlined" density="compact" :error-messages="endForm.errors.actual_return_date" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="endDialog = false">Cancel</VBtn>
          <VBtn color="success" :loading="endForm.processing" @click="submitEnd">Confirm End</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Extend Suspension ─────────────────────────────────────────────────── -->
    <VDialog v-model="extendDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">Extend Suspension</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 mb-4" v-if="selected">
            <strong>{{ selected.employee }}</strong> — current end: {{ selected.end_date ?? 'None' }}
          </p>
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="extendForm.new_end_date" label="New End Date *" type="date"
                variant="outlined" density="compact" :error-messages="extendForm.errors.new_end_date" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="extendForm.reason" label="Reason for Extension *" rows="3"
                variant="outlined" :error-messages="extendForm.errors.reason" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="extendDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="extendForm.processing" @click="submitExtend">Extend</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Convert Suspension Type ──────────────────────────────────────────── -->
    <VDialog v-model="convertDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">Convert Suspension Type</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 mb-4" v-if="selected">
            <strong>{{ selected.employee }}</strong> — current type:
            <VChip size="x-small" color="blue-grey" variant="tonal">{{ selected.suspension_type }}</VChip>
          </p>
          <VRow dense>
            <VCol cols="12">
              <VSelect v-model="convertForm.new_type" label="New Suspension Type *" :items="suspensionTypes"
                variant="outlined" density="compact" :error-messages="convertForm.errors.new_type" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="convertForm.reason" label="Reason for Conversion *" rows="3"
                variant="outlined" :error-messages="convertForm.errors.reason" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="convertDialog = false">Cancel</VBtn>
          <VBtn color="info" :loading="convertForm.processing" @click="submitConvert">Convert</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Add Note ──────────────────────────────────────────────────────────── -->
    <VDialog v-model="noteDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">Add Note</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 mb-4" v-if="selected">
            Adding a note to <strong>{{ selected.employee }}</strong>'s suspension.
          </p>
          <VTextarea v-model="noteForm.note" label="Note *" rows="4"
            variant="outlined" :error-messages="noteForm.errors.note"
            hint="Note will be timestamped and appended to the suspension record." persistent-hint />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="noteDialog = false">Cancel</VBtn>
          <VBtn color="secondary" :loading="noteForm.processing" @click="submitNote">Add Note</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Suspension History ────────────────────────────────────────────────── -->
    <VDialog v-model="historyDialog" max-width="620">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2">
          <VIcon color="grey">tabler-history</VIcon>
          Suspension History — {{ selected.employee }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <!-- Summary chips -->
          <div class="d-flex flex-wrap ga-2 mb-4">
            <VChip size="small" color="blue-grey" variant="tonal">{{ selected.suspension_type }}</VChip>
            <VChip size="small" :color="statusColor(selected.status)" variant="tonal">{{ selected.status }}</VChip>
            <VChip size="small" variant="outlined" prepend-icon="tabler-calendar-event">{{ selected.start_date }}</VChip>
            <VChip size="small" variant="outlined" prepend-icon="tabler-calendar-due" v-if="selected.end_date">{{ selected.end_date }}</VChip>
            <VChip size="small" variant="outlined" prepend-icon="tabler-briefcase" v-if="selected.case_number">{{ selected.case_number }}</VChip>
          </div>

          <!-- Timeline -->
          <VTimeline side="end" density="compact" truncate-line="both">
            <VTimelineItem
              v-for="(entry, i) in historyEntries"
              :key="i"
              :dot-color="entry.type === 'issued' ? 'warning'
                        : entry.type === 'cancelled' ? 'error'
                        : entry.type === 'extended'  ? 'primary'
                        : entry.type === 'converted' ? 'info'
                        : 'secondary'"
              size="small"
            >
              <template #opposite>
                <span class="text-caption text-medium-emphasis text-no-wrap">{{ entry.timestamp }}</span>
              </template>
              <VCard variant="tonal" class="pa-2">
                <div class="text-caption font-weight-bold mb-1 text-capitalize">
                  <VIcon size="12" class="mr-1">
                    {{ entry.type === 'issued'    ? 'tabler-alert-octagon'
                     : entry.type === 'cancelled' ? 'tabler-circle-x'
                     : entry.type === 'extended'  ? 'tabler-calendar-plus'
                     : entry.type === 'converted' ? 'tabler-arrows-left-right'
                     : 'tabler-note' }}
                  </VIcon>
                  {{ entry.type === 'issued' ? 'Suspension Issued' : entry.type }}
                  <span class="text-medium-emphasis font-weight-regular ml-1">by {{ entry.author }}</span>
                </div>
                <div class="text-body-2">{{ entry.text }}</div>
              </VCard>
            </VTimelineItem>
          </VTimeline>

          <div v-if="historyEntries.length === 0" class="text-center text-medium-emphasis py-4">
            No history recorded.
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="historyDialog = false">Close</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Cancel Suspension ─────────────────────────────────────────────────── -->
    <VDialog v-model="cancelDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4 text-error">Cancel Suspension</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="warning" variant="tonal" class="mb-4" density="compact">
            Cancelling will immediately restore employee system access.
          </VAlert>
          <p class="text-body-2 mb-4" v-if="selected">
            Cancel suspension for <strong>{{ selected.employee }}</strong>?
          </p>
          <VTextarea v-model="cancelForm.reason" label="Cancellation Reason *" rows="3"
            variant="outlined" :error-messages="cancelForm.errors.reason" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="cancelDialog = false">Keep Active</VBtn>
          <VBtn color="error" :loading="cancelForm.processing" @click="submitCancel">Cancel Suspension</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
