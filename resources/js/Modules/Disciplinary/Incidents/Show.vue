<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface WitnessDetail {
  id: number; name: string; email: string|null; phone: string|null
  is_anonymous: boolean; has_statement: boolean; statement: string|null; statement_date: string|null
}
interface EvidenceDetail {
  id: number; file_name: string; file_type: string; file_size: string
  description: string|null; url: string; uploaded_by: string; created_at: string
}
interface AccusedDetail {
  id: number; name: string; job_title: string|null; description: string|null
}
interface IncidentDetail {
  id: number; uuid: string; incident_number: string; title: string; description: string
  reporter_name: string; is_anonymous: boolean; reporter_email: string|null
  category: string|null; severity_level: string; status: string; status_color: string
  incident_date: string; location: string|null; location_details: string|null
  investigator: string; investigator_id: number|null; investigator_assigned_at: string|null; auto_assigned: boolean
  case_id: number|null; case_number: string|null
  resolution_notes: string|null; resolved_at: string|null
  witnesses: WitnessDetail[]; evidence: EvidenceDetail[]; accused: AccusedDetail[]
  created_at: string
}
interface ActivityEntry {
  id: number; event: string|null; description: string; properties: Record<string,unknown>|null; user: string; created_at: string
}
interface StatusOption { value: string; label: string; color: string }
interface Employee    { id: number; name: string; email: string }
interface Position    { id: number; name: string }

const props = defineProps<{
  incident:     IncidentDetail
  activityLogs: ActivityEntry[]
  employees:    Employee[]
  statuses:     StatusOption[]
  positions:    Position[]
  viewerRole:   string  // 'admin_hr' | 'investigator' | 'reporter' | 'manager' | 'none'
}>()

const tab = ref('details')

const severityColor = (s: string|null) => ({ minor: 'success', serious: 'warning', gross: 'error' })[s ?? ''] ?? 'grey'
const cap = (s: string | null | undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'
const isResolved = computed(() => ['closed', 'dismissed'].includes(props.incident.status))

// admin_hr only: update status, assign investigator
const canAdmin = computed(() => props.viewerRole === 'admin_hr')
// admin_hr + investigator: upload evidence, witness actions
const canInvestigate = computed(() => ['admin_hr', 'investigator'].includes(props.viewerRole))

// ── Status update ──────────────────────────────────────────────────────────────
const statusDialog = ref(false)
const statusForm = useForm({ status: props.incident.status, resolution_notes: '' })
function submitStatus() {
  statusForm.post(route('disciplinary.incidents.update-status', { incident: props.incident.id }), {
    onSuccess: () => { statusDialog.value = false },
  })
}

// ── Assign Investigator ────────────────────────────────────────────────────────
const investigatorDialog = ref(false)
const investigatorForm = useForm({ investigator_id: props.incident.investigator_id as number|null })
function submitInvestigator() {
  investigatorForm.post(route('disciplinary.incidents.assign-investigator', { incident: props.incident.id }), {
    onSuccess: () => { investigatorDialog.value = false },
  })
}

// ── Evidence upload ────────────────────────────────────────────────────────────
const evidenceDrawer   = ref(false)
const evidenceFiles    = ref<File[]>([])
const evidenceDescriptions = ref<string[]>([])
const evidenceUploading = ref(false)

function addEvidenceSlot() {
  evidenceFiles.value.push(null as unknown as File)
  evidenceDescriptions.value.push('')
}
function removeEvidenceSlot(i: number) {
  evidenceFiles.value.splice(i, 1)
  evidenceDescriptions.value.splice(i, 1)
}

function submitEvidence() {
  const fd = new FormData()
  evidenceFiles.value.forEach((f: any, i: number) => {
    // Vuetify 3 v-file-input sets model to [File] array — extract the actual File
    const file: File | null = Array.isArray(f) ? (f[0] ?? null) : f
    if (file instanceof File) {
      fd.append(`files[${i}]`, file)
      fd.append(`descriptions[${i}]`, evidenceDescriptions.value[i] ?? '')
    }
  })
  evidenceUploading.value = true
  router.post(route('disciplinary.incidents.upload-evidence', { incident: props.incident.id }), fd as any, {
    preserveScroll: true,
    onSuccess: () => {
      evidenceDrawer.value = false
      evidenceFiles.value = []
      evidenceDescriptions.value = []
      evidenceUploading.value = false
    },
    onError: () => { evidenceUploading.value = false },
  })
}

// ── Witness statement ──────────────────────────────────────────────────────────
const statementDialog  = ref(false)
const selectedWitness  = ref<WitnessDetail|null>(null)
const statementForm    = useForm({ witness_id: 0, statement: '', statement_date: '' })

function openStatement(w: WitnessDetail) {
  selectedWitness.value = w
  statementForm.witness_id   = w.id
  statementForm.statement    = w.statement ?? ''
  statementForm.statement_date = w.statement_date ?? ''
  statementDialog.value = true
}
function submitStatement() {
  statementForm.post(route('disciplinary.incidents.witness-statement', { incident: props.incident.id }), {
    onSuccess: () => { statementDialog.value = false },
  })
}

// ── Add witness inline ─────────────────────────────────────────────────────────
const addWitnessDialog = ref(false)
const witnessForm = useForm({ employee_id: null as number|null, name: '', email: '', phone: '', is_anonymous: false })
function submitWitness() {
  witnessForm.post(route('disciplinary.incidents.add-witness', { incident: props.incident.id }), {
    onSuccess: () => { addWitnessDialog.value = false; witnessForm.reset() },
  })
}

// ── Activity icons ─────────────────────────────────────────────────────────────
const actionIcon = (log: ActivityEntry) => {
  const action = (log.properties?.action as string) ?? log.event ?? ''
  return ({
    incident_reported:         'tabler-alert-circle',
    status_changed:            'tabler-arrows-left-right',
    investigator_assigned:     'tabler-user-search',
    investigator_auto_assigned:'tabler-tool',
    evidence_uploaded:         'tabler-paperclip',
    witness_statement_captured:'tabler-message-dots',
    created:                   'tabler-circle-plus',
    updated:                   'tabler-pencil',
  })[action] ?? 'tabler-info-circle'
}

const actionDotColor = (log: ActivityEntry) => {
  const action = (log.properties?.action as string) ?? log.event ?? ''
  if (action === 'incident_reported' || action === 'created') return 'success'
  if (action === 'evidence_uploaded') return 'primary'
  return 'grey'
}

function fileIcon(type: string) {
  if (type.startsWith('image/')) return 'tabler-photo'
  if (type === 'application/pdf') return 'tabler-file-type-pdf'
  if (type.includes('word'))       return 'tabler-file-word'
  if (type.includes('excel') || type.includes('spreadsheet')) return 'tabler-file-spreadsheet'
  return 'tabler-file'
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ incident.incident_number }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-0">{{ incident.title }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" size="small" prepend-icon="tabler-arrow-left" :href="route('disciplinary.incidents.index')">{{ t('disciplinary.incidents') }}</VBtn>
        <VBtn v-if="canInvestigate && !isResolved" size="small" variant="tonal" prepend-icon="tabler-paperclip" @click="evidenceDrawer = true; addEvidenceSlot()">{{ t('disciplinary.upload_evidence') }}</VBtn>
      </div>
    </div>

    <!-- Status strip -->
    <VRow dense class="mb-4">
      <VCol cols="12" sm="auto">
        <VChip :color="incident.status_color" size="large" prepend-icon="tabler-point">
          {{ cap(incident.status.replace('_', ' ')) }}
        </VChip>
      </VCol>
      <VCol cols="12" sm="auto">
        <VChip :color="severityColor(incident.severity_level)" size="large">{{ cap(incident.severity_level) }}</VChip>
      </VCol>
      <VCol v-if="incident.is_anonymous" cols="12" sm="auto">
        <VChip color="grey" size="large" prepend-icon="tabler-spy">{{ t('disciplinary.anonymous') }}</VChip>
      </VCol>
      <VCol v-if="incident.case_number" cols="12" sm="auto">
        <VChip color="primary" size="large" prepend-icon="tabler-briefcase"
          :href="route('disciplinary.cases.show', { case: incident.case_id })">
          {{ incident.case_number }}
        </VChip>
      </VCol>
      <VSpacer />
      <VCol v-if="canAdmin && !isResolved" cols="12" sm="auto" class="d-flex ga-2">
        <VBtn size="small" variant="tonal" prepend-icon="tabler-arrows-left-right" @click="statusDialog = true">{{ t('disciplinary.update_status') }}</VBtn>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-user-search" @click="investigatorDialog = true">{{ t('disciplinary.assign_investigator') }}</VBtn>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="details">{{ t('disciplinary.details') }}</VTab>
        <VTab value="accused">{{ t('disciplinary.accused_persons') }} <VChip class="ml-2" size="x-small">{{ incident.accused.length }}</VChip></VTab>
        <VTab value="witnesses">{{ t('disciplinary.witnesses') }} <VChip class="ml-2" size="x-small">{{ incident.witnesses.length }}</VChip></VTab>
        <VTab value="evidence">{{ t('disciplinary.evidence') }} <VChip class="ml-2" size="x-small">{{ incident.evidence.length }}</VChip></VTab>
        <VTab value="activity">{{ t('disciplinary.activity_log') }} <VChip class="ml-2" size="x-small">{{ activityLogs.length }}</VChip></VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- ── Details ──────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="details">
          <VCardText>
            <VRow>
              <VCol cols="12" md="8">
                <VRow dense>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.reported_by') }}</div>
                    <div class="font-weight-medium d-flex align-center ga-1">
                      <VIcon v-if="incident.is_anonymous" size="14" color="grey">tabler-spy</VIcon>
                      {{ incident.reporter_name }}
                    </div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.category') }}</div>
                    <div>{{ incident.category ?? '—' }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.incident_date') }}</div>
                    <div>{{ incident.incident_date }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.location') }}</div>
                    <div>{{ incident.location ?? '—' }}</div>
                  </VCol>
                  <VCol v-if="incident.location_details" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.location_details') }}</div>
                    <div class="text-body-2">{{ incident.location_details }}</div>
                  </VCol>
                  <VCol cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.description') }}</div>
                    <div class="text-body-2">{{ incident.description }}</div>
                  </VCol>
                  <VCol v-if="incident.resolution_notes" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.resolution_notes') }}</div>
                    <div class="text-body-2">{{ incident.resolution_notes }}</div>
                  </VCol>
                </VRow>
              </VCol>

              <!-- Sidebar -->
              <VCol cols="12" md="4">
                <VCard variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.investigator') }}</div>
                    <div class="font-weight-medium d-flex align-center ga-1">
                      {{ incident.investigator !== 'Unknown' ? incident.investigator : '—' }}
                      <VTooltip v-if="incident.auto_assigned" :text="t('disciplinary.auto_assigned')">
                        <template #activator="{ props: tp }"><VIcon v-bind="tp" size="14" color="primary">tabler-tool</VIcon></template>
                      </VTooltip>
                    </div>
                    <div v-if="incident.investigator_assigned_at" class="text-caption text-medium-emphasis">{{ incident.investigator_assigned_at }}</div>
                  </VCardText>
                </VCard>
                <VCard v-if="incident.resolved_at" variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.resolved_at') }}</div>
                    <div>{{ incident.resolved_at }}</div>
                  </VCardText>
                </VCard>
                <VCard v-if="incident.reporter_email" variant="tonal" color="grey">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.reporter_email') }}</div>
                    <div>{{ incident.reporter_email }}</div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Accused ───────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="accused">
          <VCardText>
            <div v-if="!incident.accused.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="40" class="mb-2">tabler-user-off</VIcon>
              <p>{{ t('disciplinary.no_accused_added') }}</p>
            </div>
            <VRow dense>
              <VCol v-for="a in incident.accused" :key="a.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-center ga-2 mb-1">
                    <VIcon color="error">tabler-user-exclamation</VIcon>
                    <span class="font-weight-medium">{{ a.name }}</span>
                  </div>
                  <div v-if="a.job_title" class="text-caption text-medium-emphasis">{{ a.job_title }}</div>
                  <div v-if="a.description" class="text-body-2 mt-1">{{ a.description }}</div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Witnesses ─────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="witnesses">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn v-if="canInvestigate && !isResolved" size="small" variant="tonal" color="primary" prepend-icon="tabler-plus" @click="addWitnessDialog = true">{{ t('disciplinary.add_witness') }}</VBtn>
            </div>
            <div v-if="!incident.witnesses.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="40" class="mb-2">tabler-users</VIcon>
              <p>{{ t('disciplinary.no_witnesses_added') }}</p>
            </div>
            <VRow dense>
              <VCol v-for="w in incident.witnesses" :key="w.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex justify-space-between align-start">
                    <div>
                      <div class="d-flex align-center ga-1 mb-1">
                        <VIcon v-if="w.is_anonymous" size="14" color="grey">tabler-spy</VIcon>
                        <span class="font-weight-medium">{{ w.name }}</span>
                      </div>
                      <div v-if="w.email" class="text-caption text-medium-emphasis">{{ w.email }}</div>
                      <div v-if="w.phone" class="text-caption text-medium-emphasis">{{ w.phone }}</div>
                    </div>
                    <VChip v-if="w.has_statement" color="success" size="x-small">{{ t('disciplinary.statement_captured') }}</VChip>
                    <VChip v-else color="warning" size="x-small">{{ t('disciplinary.no_statement') }}</VChip>
                  </div>
                  <div v-if="w.statement" class="text-body-2 mt-2 pa-2 bg-grey-lighten-4 rounded">{{ w.statement }}</div>
                  <div class="d-flex justify-end mt-2">
                    <VBtn v-if="canInvestigate && !isResolved" size="x-small" variant="tonal" prepend-icon="tabler-message-dots" @click="openStatement(w)">
                      {{ w.has_statement ? t('disciplinary.edit_statement') : t('disciplinary.capture_statement') }}
                    </VBtn>
                  </div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Evidence ──────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="evidence">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn v-if="canInvestigate && !isResolved" size="small" variant="tonal" color="primary" prepend-icon="tabler-upload" @click="evidenceDrawer = true; if (!evidenceFiles.length) addEvidenceSlot()">{{ t('disciplinary.upload_evidence') }}</VBtn>
            </div>
            <div v-if="!incident.evidence.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="40" class="mb-2">tabler-paperclip</VIcon>
              <p>{{ t('disciplinary.no_evidence') }}</p>
            </div>
            <VRow dense>
              <VCol v-for="e in incident.evidence" :key="e.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-center ga-2">
                    <VIcon :color="e.file_type.startsWith('image/') ? 'teal' : 'primary'" size="32">{{ fileIcon(e.file_type) }}</VIcon>
                    <div class="flex-1-1">
                      <div class="font-weight-medium text-body-2">{{ e.file_name }}</div>
                      <div class="text-caption text-medium-emphasis">{{ e.file_size }} · {{ e.uploaded_by }} · {{ e.created_at }}</div>
                      <div v-if="e.description" class="text-caption">{{ e.description }}</div>
                    </div>
                    <VBtn :href="e.url" target="_blank" icon="tabler-download" size="x-small" variant="text" color="primary" />
                  </div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Activity Log ───────────────────────────────────────────────────── -->
        <VTabsWindowItem value="activity">
          <VTimeline side="end" density="compact" class="pa-4">
            <VTimelineItem
              v-for="log in activityLogs" :key="log.id"
              :dot-color="actionDotColor(log)"
              size="small"
            >
              <template #icon>
                <VIcon size="16">{{ actionIcon(log) }}</VIcon>
              </template>
              <VCard variant="outlined" class="mb-2">
                <VCardText class="py-2 px-3">
                  <div class="d-flex justify-space-between align-start">
                    <span class="text-body-2 font-weight-medium">{{ log.description }}</span>
                    <span class="text-caption text-medium-emphasis ml-2 text-no-wrap">{{ log.created_at }}</span>
                  </div>
                  <div class="text-caption text-medium-emphasis">{{ log.user }}</div>
                </VCardText>
              </VCard>
            </VTimelineItem>
            <VTimelineItem v-if="!activityLogs.length" dot-color="grey" size="small">
              <span class="text-body-2 text-medium-emphasis">{{ t('disciplinary.no_activity') }}</span>
            </VTimelineItem>
          </VTimeline>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>

    <!-- Status Dialog -->
    <VDialog v-if="canAdmin" v-model="statusDialog" max-width="440">
      <VCard :title="t('disciplinary.update_status')">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect
            v-model="statusForm.status" :items="statuses" item-title="label" item-value="value"
            :label="t('disciplinary.status')" variant="outlined" density="compact"
          />
          <VTextarea
            v-if="['closed','dismissed'].includes(statusForm.status)"
            v-model="statusForm.resolution_notes" :label="t('disciplinary.resolution_notes')"
            variant="outlined" density="compact" rows="3"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="statusDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="statusForm.processing" @click="submitStatus">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Investigator Dialog -->
    <VDialog v-if="canAdmin" v-model="investigatorDialog" max-width="400">
      <VCard :title="t('disciplinary.assign_investigator')">
        <VCardText>
          <VAutocomplete
            v-model="investigatorForm.investigator_id" :items="employees" item-title="name" item-value="id"
            :label="t('disciplinary.investigator')" variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="investigatorDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="investigatorForm.processing" @click="submitInvestigator">{{ t('disciplinary.assign') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Witness Statement Dialog -->
    <VDialog v-if="canInvestigate" v-model="statementDialog" max-width="520">
      <VCard :title="t('disciplinary.capture_statement')">
        <VCardText class="d-flex flex-column ga-3">
          <div class="text-body-2 font-weight-medium">{{ selectedWitness?.name }}</div>
          <VTextarea v-model="statementForm.statement" :label="t('disciplinary.statement')" variant="outlined" density="compact" rows="5" :error-messages="statementForm.errors.statement" />
          <VTextField v-model="statementForm.statement_date" :label="t('disciplinary.statement_date')" variant="outlined" density="compact" type="date" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="statementDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="statementForm.processing" @click="submitStatement">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Add Witness Dialog -->
    <VDialog v-if="canInvestigate" v-model="addWitnessDialog" max-width="480">
      <VCard :title="t('disciplinary.add_witness')">
        <VCardText class="d-flex flex-column ga-3">
          <VAutocomplete v-model="witnessForm.employee_id" :items="employees" item-title="name" item-value="id" :label="t('disciplinary.employee_optional')" variant="outlined" density="compact" clearable />
          <VTextField v-model="witnessForm.name" :label="t('disciplinary.name')" variant="outlined" density="compact" :error-messages="witnessForm.errors.name" />
          <VTextField v-model="witnessForm.email" :label="t('disciplinary.email')" variant="outlined" density="compact" type="email" />
          <VTextField v-model="witnessForm.phone" :label="t('disciplinary.phone')" variant="outlined" density="compact" />
          <VSwitch v-model="witnessForm.is_anonymous" :label="t('disciplinary.witness_is_anonymous')" color="primary" inset density="compact" hide-details />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="addWitnessDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="witnessForm.processing" @click="submitWitness">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Evidence Upload Drawer -->
    <VNavigationDrawer v-if="canInvestigate" v-model="evidenceDrawer" location="right" width="480" temporary>
      <VToolbar density="compact" color="primary" :title="t('disciplinary.upload_evidence')" />
      <div class="pa-4 d-flex flex-column ga-3">
        <div v-for="(_, i) in evidenceFiles" :key="i">
          <VCard variant="outlined" class="pa-3">
            <div class="d-flex justify-space-between align-center mb-2">
              <span class="text-caption font-weight-medium text-medium-emphasis">{{ t('disciplinary.file') }} {{ i + 1 }}</span>
              <VBtn icon="tabler-x" size="x-small" variant="text" @click="removeEvidenceSlot(i)" />
            </div>
            <VFileInput
              :model-value="evidenceFiles[i] ? [evidenceFiles[i]] : []"
              @update:model-value="(v: File[]) => evidenceFiles[i] = v?.length ? v[0] : null as any"
              :label="t('disciplinary.select_file')" variant="outlined" density="compact"
              prepend-icon="" prepend-inner-icon="tabler-paperclip" hide-details class="mb-2"
              accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
            />
            <VTextField v-model="evidenceDescriptions[i]" :label="t('disciplinary.description_optional')" variant="outlined" density="compact" />
          </VCard>
        </div>
        <VBtn variant="tonal" prepend-icon="tabler-plus" @click="addEvidenceSlot">{{ t('disciplinary.add_file') }}</VBtn>
        <div class="d-flex ga-2 justify-end mt-2">
          <VBtn variant="outlined" @click="evidenceDrawer = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="evidenceUploading" :disabled="!evidenceFiles.some(f => f)" prepend-icon="tabler-upload" @click="submitEvidence">{{ t('disciplinary.upload') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

  </div>
</template>
