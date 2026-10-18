<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ChecklistItem { item: string; done: boolean; done_at?: string }
interface Note {
  id: number; note_type: string; title: string|null; content: string
  is_confidential: boolean; icon: string; author: string; created_at: string
}
interface Report {
  id: number; report_type: string; title: string; executive_summary: string|null
  findings: string; conclusion: string|null; recommendations: string|null
  is_ai_generated: boolean; has_file: boolean; download_url: string|null
  generated_by: string; created_at: string
}
interface InvestigationDetail {
  id: number; investigation_number: string
  case_id: number; case_number: string; case_title: string; employee_name: string; category: string|null
  investigator_id: number|null; investigator_name: string
  status: string; status_color: string
  objective: string|null; scope: string|null; checklist: ChecklistItem[]
  start_date: string|null; due_date: string|null; completed_date: string|null
  findings: string|null; recommended_action: string|null
  progress: { total: number; done: number; pct: number }; created_at: string
}
interface Employee { id: number; name: string; email: string }

const props = defineProps<{
  investigation: InvestigationDetail
  notes:    Note[]
  reports:  Report[]
  employees:Employee[]
  canEdit:  boolean
  isInvestigator: boolean
}>()

const tab = ref('overview')

// ── Update form ────────────────────────────────────────────────────────────────
const editDrawer = ref(false)
const editForm = useForm({
  status:             props.investigation.status,
  objective:          props.investigation.objective ?? '',
  scope:              props.investigation.scope ?? '',
  start_date:         props.investigation.start_date ?? '',
  due_date:           props.investigation.due_date ?? '',
  completed_date:     props.investigation.completed_date ?? '',
  findings:           props.investigation.findings ?? '',
  recommended_action: props.investigation.recommended_action ?? '',
  checklist:          props.investigation.checklist as ChecklistItem[],
})
function submitEdit() {
  editForm.put(route('disciplinary.investigations.update', props.investigation.id), {
    onSuccess: () => { editDrawer.value = false },
  })
}

// ── Checklist helpers ──────────────────────────────────────────────────────────
function addChecklistItem() {
  editForm.checklist.push({ item: '', done: false })
}
function removeChecklistItem(i: number) {
  editForm.checklist.splice(i, 1)
}

// ── Add note ───────────────────────────────────────────────────────────────────
const noteDialog = ref(false)
const noteForm = useForm({ note_type: 'general', title: '', content: '', is_confidential: false })
function submitNote() {
  noteForm.post(route('disciplinary.investigations.add-note', props.investigation.id), {
    onSuccess: () => { noteDialog.value = false; noteForm.reset() },
  })
}

// ── Generate report ────────────────────────────────────────────────────────────
const generateForm = useForm({})
function generateReport() {
  generateForm.post(route('disciplinary.investigations.generate-report', props.investigation.id))
}

const noteTypeOptions = [
  { value: 'general',           title: 'General' },
  { value: 'witness_interview', title: 'Witness Interview' },
  { value: 'evidence_review',   title: 'Evidence Review' },
  { value: 'finding',           title: 'Finding' },
  { value: 'recommendation',    title: 'Recommendation' },
]

const canAddNote = computed(() => props.canEdit || props.isInvestigator)
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ investigation.investigation_number }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-0">{{ investigation.case_title }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" size="small" prepend-icon="tabler-arrow-left" :href="route('disciplinary.investigations.index')">
          {{ t('disciplinary.investigations') }}
        </VBtn>
        <VBtn variant="outlined" size="small" prepend-icon="tabler-briefcase" :href="route('disciplinary.cases.show', investigation.case_id)">
          {{ investigation.case_number }}
        </VBtn>
        <VBtn v-if="canEdit" size="small" prepend-icon="tabler-pencil" @click="editDrawer = true">{{ t('disciplinary.edit') }}</VBtn>
      </div>
    </div>

    <!-- Status strip -->
    <VRow dense class="mb-4">
      <VCol cols="12" sm="auto">
        <VChip :color="investigation.status_color" size="large" prepend-icon="tabler-point">
          {{ investigation.status.replace('_', ' ') }}
        </VChip>
      </VCol>
      <VCol cols="12" sm="auto">
        <VChip color="grey" size="large" prepend-icon="tabler-user-search">
          {{ investigation.investigator_name }}
        </VChip>
      </VCol>
      <VCol v-if="investigation.progress.total > 0" cols="12" sm="auto">
        <VChip color="primary" size="large">
          {{ investigation.progress.done }}/{{ investigation.progress.total }} checklist ({{ investigation.progress.pct }}%)
        </VChip>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="overview">{{ t('disciplinary.overview') }}</VTab>
        <VTab value="checklist">{{ t('disciplinary.checklist') }} <VChip class="ml-2" size="x-small">{{ investigation.checklist.length }}</VChip></VTab>
        <VTab value="notes">{{ t('disciplinary.notes') }} <VChip class="ml-2" size="x-small">{{ notes.length }}</VChip></VTab>
        <VTab value="reports">{{ t('disciplinary.reports') }} <VChip class="ml-2" size="x-small">{{ reports.length }}</VChip></VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- Overview -->
        <VTabsWindowItem value="overview">
          <VCardText>
            <VRow>
              <VCol cols="12" md="8">
                <VRow dense>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.employee') }}</div>
                    <div class="font-weight-medium">{{ investigation.employee_name }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.category') }}</div>
                    <div>{{ investigation.category ?? '—' }}</div>
                  </VCol>
                  <VCol v-if="investigation.objective" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.objective') }}</div>
                    <div class="text-body-2">{{ investigation.objective }}</div>
                  </VCol>
                  <VCol v-if="investigation.scope" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.scope') }}</div>
                    <div class="text-body-2">{{ investigation.scope }}</div>
                  </VCol>
                  <VCol v-if="investigation.findings" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.findings') }}</div>
                    <div class="text-body-2 pa-2 rounded bg-grey-lighten-5">{{ investigation.findings }}</div>
                  </VCol>
                  <VCol v-if="investigation.recommended_action" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.recommended_action') }}</div>
                    <div class="text-body-2">{{ investigation.recommended_action }}</div>
                  </VCol>
                </VRow>
              </VCol>
              <VCol cols="12" md="4">
                <VCard variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.start_date') }}</div>
                    <div>{{ investigation.start_date ?? '—' }}</div>
                  </VCardText>
                </VCard>
                <VCard variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.due_date') }}</div>
                    <div>{{ investigation.due_date ?? '—' }}</div>
                  </VCardText>
                </VCard>
                <VCard v-if="investigation.completed_date" variant="tonal" color="success">
                  <VCardText class="pb-2">
                    <div class="text-caption">{{ t('disciplinary.completed_date') }}</div>
                    <div>{{ investigation.completed_date }}</div>
                  </VCardText>
                </VCard>
                <div v-if="canAddNote" class="mt-3">
                  <VBtn block variant="tonal" color="primary" prepend-icon="tabler-file-text" :loading="generateForm.processing" @click="generateReport">
                    {{ t('disciplinary.generate_report') }}
                  </VBtn>
                </div>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- Checklist -->
        <VTabsWindowItem value="checklist">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn v-if="canAddNote" size="small" variant="tonal" prepend-icon="tabler-plus" @click="editForm.checklist.push({ item: '', done: false }); editDrawer = true">
                {{ t('disciplinary.add_item') }}
              </VBtn>
            </div>
            <div v-if="!investigation.checklist.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-square</VIcon>
              <p>{{ t('disciplinary.no_checklist') }}</p>
            </div>
            <VList>
              <VListItem v-for="(item, i) in investigation.checklist" :key="i" class="px-0">
                <template #prepend>
                  <VIcon :color="item.done ? 'success' : 'grey'">
                    {{ item.done ? 'tabler-circle-check' : 'tabler-circle' }}
                  </VIcon>
                </template>
                <VListItemTitle :class="item.done ? 'text-decoration-line-through text-medium-emphasis' : ''">
                  {{ item.item }}
                </VListItemTitle>
              </VListItem>
            </VList>
          </VCardText>
        </VTabsWindowItem>

        <!-- Notes -->
        <VTabsWindowItem value="notes">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn v-if="canAddNote" size="small" variant="tonal" color="primary" prepend-icon="tabler-plus" @click="noteDialog = true">
                {{ t('disciplinary.add_note') }}
              </VBtn>
            </div>
            <div v-if="!notes.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-note</VIcon>
              <p>{{ t('disciplinary.no_notes_yet') }}</p>
            </div>
            <VTimeline side="end" density="compact">
              <VTimelineItem v-for="n in notes" :key="n.id" dot-color="primary" size="small">
                <template #icon><VIcon size="14">{{ n.icon }}</VIcon></template>
                <VCard variant="outlined" class="mb-2">
                  <VCardText class="py-2 px-3">
                    <div class="d-flex justify-space-between align-start mb-1">
                      <div class="d-flex align-center ga-2">
                        <VChip size="x-small" color="primary">{{ n.note_type.replace('_', ' ') }}</VChip>
                        <span v-if="n.is_confidential" class="text-caption text-warning">
                          <VIcon size="12">tabler-lock</VIcon> Confidential
                        </span>
                      </div>
                      <span class="text-caption text-medium-emphasis">{{ n.created_at }}</span>
                    </div>
                    <div v-if="n.title" class="font-weight-medium text-body-2 mb-1">{{ n.title }}</div>
                    <div class="text-body-2">{{ n.content }}</div>
                    <div class="text-caption text-medium-emphasis mt-1">{{ n.author }}</div>
                  </VCardText>
                </VCard>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VTabsWindowItem>

        <!-- Reports -->
        <VTabsWindowItem value="reports">
          <VCardText>
            <div v-if="!reports.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-file-text</VIcon>
              <p>{{ t('disciplinary.no_reports') }}</p>
            </div>
            <VExpansionPanels>
              <VExpansionPanel v-for="r in reports" :key="r.id">
                <VExpansionPanelTitle>
                  <div class="d-flex align-center ga-2">
                    <VChip size="x-small" :color="r.is_ai_generated ? 'purple' : 'primary'">
                      {{ r.is_ai_generated ? 'AI' : r.report_type }}
                    </VChip>
                    <span>{{ r.title }}</span>
                    <span class="text-caption text-medium-emphasis ml-2">{{ r.created_at }}</span>
                  </div>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <div v-if="r.executive_summary" class="mb-3">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.executive_summary') }}</div>
                    <div class="text-body-2">{{ r.executive_summary }}</div>
                  </div>
                  <div class="mb-3">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.findings') }}</div>
                    <div class="text-body-2 pa-2 rounded bg-grey-lighten-5">{{ r.findings }}</div>
                  </div>
                  <div v-if="r.conclusion" class="mb-3">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.conclusion') }}</div>
                    <div class="text-body-2">{{ r.conclusion }}</div>
                  </div>
                  <div v-if="r.recommendations">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.recommendations') }}</div>
                    <div class="text-body-2">{{ r.recommendations }}</div>
                  </div>
                  <div v-if="r.has_file" class="mt-3">
                    <VBtn
                      size="small"
                      variant="tonal"
                      color="primary"
                      prepend-icon="tabler-file-download"
                      :href="r.download_url"
                    >{{ t('disciplinary.download_report') }}</VBtn>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>

    <!-- Add Note Dialog -->
    <VDialog v-if="canAddNote" v-model="noteDialog" max-width="520">
      <VCard :title="t('disciplinary.add_note')">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect v-model="noteForm.note_type" :items="noteTypeOptions" item-title="title" item-value="value" :label="t('disciplinary.note_type')" variant="outlined" density="compact" />
          <VTextField v-model="noteForm.title" :label="t('disciplinary.title_optional')" variant="outlined" density="compact" />
          <VTextarea v-model="noteForm.content" :label="t('disciplinary.content')" variant="outlined" density="compact" rows="5" :error-messages="noteForm.errors.content" />
          <VSwitch v-model="noteForm.is_confidential" :label="t('disciplinary.confidential')" color="warning" inset density="compact" hide-details />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="noteDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="noteForm.processing" @click="submitNote">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit Drawer -->
    <VNavigationDrawer v-if="canEdit" v-model="editDrawer" location="right" width="520" temporary>
      <VToolbar density="compact" color="primary" :title="t('disciplinary.edit_investigation')" />
      <div class="pa-4 d-flex flex-column ga-3">
        <VSelect v-model="editForm.status" :items="['pending','in_progress','completed','suspended']" :label="t('disciplinary.status')" variant="outlined" density="compact" />
        <VTextarea v-model="editForm.objective" :label="t('disciplinary.objective')" variant="outlined" density="compact" rows="2" />
        <VTextarea v-model="editForm.scope" :label="t('disciplinary.scope')" variant="outlined" density="compact" rows="2" />
        <VRow dense>
          <VCol cols="6"><VTextField v-model="editForm.start_date" :label="t('disciplinary.start_date')" variant="outlined" density="compact" type="date" /></VCol>
          <VCol cols="6"><VTextField v-model="editForm.due_date" :label="t('disciplinary.due_date')" variant="outlined" density="compact" type="date" /></VCol>
          <VCol cols="6"><VTextField v-model="editForm.completed_date" :label="t('disciplinary.completed_date')" variant="outlined" density="compact" type="date" /></VCol>
        </VRow>
        <VTextarea v-model="editForm.findings" :label="t('disciplinary.findings')" variant="outlined" density="compact" rows="3" />
        <VTextarea v-model="editForm.recommended_action" :label="t('disciplinary.recommended_action')" variant="outlined" density="compact" rows="2" />

        <!-- Checklist -->
        <div>
          <div class="d-flex align-center justify-space-between mb-2">
            <span class="text-subtitle-2">{{ t('disciplinary.checklist') }}</span>
            <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="addChecklistItem">{{ t('disciplinary.add_item') }}</VBtn>
          </div>
          <div v-for="(item, i) in editForm.checklist" :key="i" class="d-flex align-center ga-2 mb-2">
            <VCheckbox v-model="editForm.checklist[i].done" hide-details density="compact" />
            <VTextField v-model="editForm.checklist[i].item" variant="outlined" density="compact" hide-details class="flex-1-1" />
            <VBtn icon="tabler-x" size="x-small" variant="text" @click="removeChecklistItem(i)" />
          </div>
        </div>

        <div class="d-flex ga-2 justify-end mt-2">
          <VBtn variant="outlined" @click="editDrawer = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="editForm.processing" @click="submitEdit">{{ t('disciplinary.save') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>
  </div>
</template>
