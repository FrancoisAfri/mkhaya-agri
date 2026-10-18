<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type KpiScore = { score_pct: number | null; weighted_score: number | null }
type KpiGoal  = { id: number; title: string }

type Kpi = {
  id: number
  title: string
  description: string | null
  target_value: number | null
  unit: string | null
  weighting: number
  status: string
  latest_value: number | null
  score_pct: number | null
  weighted_score: number | null
  self_update_allowed: boolean
  manager_update_allowed: boolean
  employee: { id: number; email: string }
  goal: KpiGoal | null
  score: KpiScore | null
}

type ProgressLog = {
  id: number
  value: number
  notes: string | null
  log_date: string
  updated_by: string
}

type Evidence = {
  id: number
  file_name: string
  mime: string
  size: number | null
  description: string | null
  uploaded_by: string
  created_at: string
}

const props = defineProps<{
  kpi: Kpi
  logs: ProgressLog[]
  evidence: Evidence[]
}>()

const sortedLogs = computed(() =>
  [...props.logs].sort((a, b) => b.log_date.localeCompare(a.log_date))
)

const scorePct = computed(() => props.kpi.score?.score_pct ?? props.kpi.score_pct)
const weightedScore = computed(() => props.kpi.score?.weighted_score ?? props.kpi.weighted_score)

const scoreColor = computed(() => {
  const s = scorePct.value
  if (s === null) return 'default'
  if (s >= 80) return 'success'
  if (s >= 60) return 'warning'
  return 'error'
})

const achievementPct = computed(() => {
  if (props.kpi.latest_value === null || !props.kpi.target_value) return null
  return Math.min(100, Math.round((props.kpi.latest_value / props.kpi.target_value) * 100))
})

const statusColor = (s: string) => ({
  pending: 'default', in_progress: 'primary', completed: 'success', cancelled: 'error',
})[s] ?? 'default'

// ── Log Progress ──────────────────────────────────────────────────────────────
const progressOpen  = ref(false)
const progressValue = ref<number>(props.kpi.latest_value ?? 0)
const progressNotes = ref('')
const progressDate  = ref(new Date().toISOString().substring(0, 10))

function openProgress() {
  progressValue.value = props.kpi.latest_value ?? 0
  progressNotes.value = ''
  progressDate.value  = new Date().toISOString().substring(0, 10)
  progressOpen.value  = true
}

function saveProgress() {
  router.post(
    route('performance.kpis.progress.log', props.kpi.id),
    { value: progressValue.value, notes: progressNotes.value, log_date: progressDate.value },
    { onSuccess: () => { progressOpen.value = false } }
  )
}

// ── Score ─────────────────────────────────────────────────────────────────────
const scoreConfirmOpen = ref(false)

function submitScore() {
  router.post(
    route('performance.kpis.score', props.kpi.id),
    {},
    { preserveScroll: true, onSuccess: () => { scoreConfirmOpen.value = false } }
  )
}

// ── Evidence ──────────────────────────────────────────────────────────────────
const evidenceUploadOpen = ref(false)
const evidenceFile       = ref<File | null>(null)
const evidenceDesc       = ref('')

function openEvidenceUpload() {
  evidenceFile.value = null
  evidenceDesc.value = ''
  evidenceUploadOpen.value = true
}

function submitEvidence() {
  if (!evidenceFile.value) return
  const form = new FormData()
  form.append('file', evidenceFile.value)
  if (evidenceDesc.value) form.append('description', evidenceDesc.value)
  router.post(route('performance.kpis.evidence.upload', props.kpi.id), form, {
    forceFormData: true,
    onSuccess: () => { evidenceUploadOpen.value = false },
  })
}

function deleteEvidence(id: number) {
  router.delete(route('performance.kpis.evidence.destroy', id), { preserveScroll: true })
}

function mimeIcon(mime: string): string {
  if (mime.startsWith('image/')) return 'tabler-file-type-jpg'
  if (mime === 'application/pdf') return 'tabler-file-type-pdf'
  if (mime.includes('spreadsheet') || mime.includes('excel')) return 'tabler-file-spreadsheet'
  if (mime.includes('word')) return 'tabler-file-word'
  return 'tabler-file'
}

function formatBytes(bytes: number | null): string {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-1">
      <VBtn variant="text" prepend-icon="tabler-arrow-left" href="/performance/kpis" class="mr-2">{{ t('pa.common.back') }}</VBtn>
      <VSpacer />
      <VBtn variant="outlined" prepend-icon="ri-line-chart-line" class="mr-2" @click="openProgress()">
        {{ t('pa.kpis.log_progress') }}
      </VBtn>
      <VBtn variant="outlined" prepend-icon="ri-attachment-2" class="mr-2" @click="openEvidenceUpload()">
        Upload Evidence
      </VBtn>
      <VBtn color="primary" prepend-icon="ri-medal-line" @click="scoreConfirmOpen = true">
        Score KPI
      </VBtn>
    </div>

    <div class="mb-4">
      <div class="d-flex align-center gap-1 text-body-2 text-medium-emphasis mb-1">
        <span>{{ t('pa.kpis.title') }}</span>
        <VIcon icon="tabler-chevron-right" size="14" />
        <span>{{ t('pa.kpis.progress') }}: {{ kpi.title }}</span>
      </div>
      <h1 class="text-h5 font-weight-bold">{{ kpi.title }}</h1>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <!-- KPI Details -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-semibold pa-4 pb-2">{{ t('pa.kpis.details') }}</VCardTitle>
          <VCardText class="pt-0">
            <VList density="compact" lines="one">
              <VListItem v-if="kpi.description">
                <template #prepend><VIcon icon="tabler-text-size" size="16" class="mr-2" /></template>
                <VListItemTitle class="text-body-2">{{ kpi.description }}</VListItemTitle>
              </VListItem>

              <VListItem>
                <template #prepend><VIcon icon="tabler-user" size="16" class="mr-2" /></template>
                <VListItemTitle class="text-body-2">{{ kpi.employee.email }}</VListItemTitle>
                <VListItemSubtitle>{{ t('pa.common.employee') }}</VListItemSubtitle>
              </VListItem>

              <VListItem v-if="kpi.goal">
                <template #prepend><VIcon icon="tabler-flag" size="16" class="mr-2" /></template>
                <VListItemTitle class="text-body-2">{{ kpi.goal.title }}</VListItemTitle>
                <VListItemSubtitle>{{ t('pa.kpis.linked_goal_optional') }}</VListItemSubtitle>
              </VListItem>

              <VListItem v-if="kpi.target_value !== null">
                <template #prepend><VIcon icon="tabler-target" size="16" class="mr-2" /></template>
                <VListItemTitle class="text-body-2">
                  {{ kpi.target_value }} {{ kpi.unit ?? '' }}
                </VListItemTitle>
                <VListItemSubtitle>{{ t('pa.goals.target_value') }}</VListItemSubtitle>
              </VListItem>

              <VListItem>
                <template #prepend><VIcon icon="tabler-weight" size="16" class="mr-2" /></template>
                <VListItemTitle class="text-body-2">{{ kpi.weighting }}%</VListItemTitle>
                <VListItemSubtitle>{{ t('pa.kpis.col_weight') }}</VListItemSubtitle>
              </VListItem>

              <VListItem>
                <template #prepend><VIcon icon="tabler-circle-filled" size="16" class="mr-2" /></template>
                <VListItemTitle>
                  <VChip size="small" :color="statusColor(kpi.status)" label>
                    {{ t('pa.kpis.statuses.' + kpi.status) }}
                  </VChip>
                </VListItemTitle>
                <VListItemSubtitle>{{ t('pa.common.status') }}</VListItemSubtitle>
              </VListItem>

              <VListItem>
                <template #prepend><VIcon icon="tabler-user-check" size="16" class="mr-2" /></template>
                <VListItemTitle class="text-body-2 d-flex gap-3">
                  <span>
                    <VIcon :icon="kpi.self_update_allowed ? 'tabler-circle-check' : 'tabler-circle-x'"
                      :color="kpi.self_update_allowed ? 'success' : 'error'" size="14" />
                    {{ t('pa.kpis.self_update') }}
                  </span>
                  <span>
                    <VIcon :icon="kpi.manager_update_allowed ? 'tabler-circle-check' : 'tabler-circle-x'"
                      :color="kpi.manager_update_allowed ? 'success' : 'error'" size="14" />
                    {{ t('pa.kpis.manager_update') }}
                  </span>
                </VListItemTitle>
                <VListItemSubtitle>{{ t('pa.kpis.update_permissions') }}</VListItemSubtitle>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>

        <!-- Progress Timeline -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-semibold pa-4 pb-2 d-flex align-center">
            {{ t('pa.kpis.progress_timeline') }}
            <VSpacer />
            <VBtn size="small" variant="tonal" prepend-icon="ri-line-chart-line" @click="openProgress()">
              Log Progress
            </VBtn>
          </VCardTitle>
          <VCardText class="pt-0">
            <div v-if="!sortedLogs.length" class="text-center py-8 text-medium-emphasis text-body-2">
              {{ t('pa.kpis.no_progress') }}
            </div>
            <VTimeline v-else density="compact" align="start" side="end">
              <VTimelineItem
                v-for="log in sortedLogs"
                :key="log.id"
                dot-color="primary"
                size="small"
              >
                <template #opposite>
                  <span class="text-caption text-medium-emphasis">{{ log.log_date }}</span>
                </template>
                <div>
                  <span class="font-weight-bold text-body-1">{{ log.value }}</span>
                  <span v-if="kpi.unit" class="text-caption text-medium-emphasis ml-1">{{ kpi.unit }}</span>
                  <div class="text-caption text-medium-emphasis">{{ t('pa.kpis.by') }} {{ log.updated_by }}</div>
                  <div v-if="log.notes" class="text-body-2 mt-1">{{ log.notes }}</div>
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>

        <!-- Evidence -->
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1 font-weight-semibold pa-4 pb-2 d-flex align-center">
            Evidence Files
            <VChip size="x-small" class="ml-2">{{ evidence.length }}</VChip>
            <VSpacer />
            <VBtn size="small" variant="tonal" prepend-icon="ri-attachment-2" @click="openEvidenceUpload()">
              Upload
            </VBtn>
          </VCardTitle>
          <VCardText class="pt-0">
            <div v-if="!evidence.length" class="text-center py-6 text-medium-emphasis text-body-2">
              No evidence files uploaded yet.
            </div>
            <VList v-else density="compact" lines="two">
              <VListItem
                v-for="ev in evidence"
                :key="ev.id"
                :prepend-icon="mimeIcon(ev.mime)"
              >
                <VListItemTitle class="text-body-2 font-weight-medium">{{ ev.file_name }}</VListItemTitle>
                <VListItemSubtitle>
                  <span v-if="ev.description" class="mr-2">{{ ev.description }}</span>
                  <span class="text-medium-emphasis">{{ ev.uploaded_by }} · {{ ev.created_at }}{{ ev.size ? ' · ' + formatBytes(ev.size) : '' }}</span>
                </VListItemSubtitle>
                <template #append>
                  <div class="d-flex gap-1">
                    <VBtn
                      size="small" variant="text" icon="ri-download-line"
                      :href="route('performance.kpis.evidence.download', ev.id)"
                      title="Download"
                    />
                    <VBtn
                      size="small" variant="text" icon="ri-delete-bin-line" color="error"
                      title="Remove"
                      @click="deleteEvidence(ev.id)"
                    />
                  </div>
                </template>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <!-- Score -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 font-weight-semibold pa-4 pb-2 d-flex align-center">
            {{ t('pa.kpis.col_score') }}
            <VSpacer />
            <VBtn size="small" variant="tonal" prepend-icon="ri-medal-line" @click="scoreConfirmOpen = true">
              Score
            </VBtn>
          </VCardTitle>
          <VCardText class="pt-0">
            <div v-if="scorePct !== null" class="d-flex flex-column align-center py-2">
              <span :style="{ fontSize: '3rem', fontWeight: 700, lineHeight: 1 }" :class="`text-${scoreColor}`">
                {{ scorePct }}%
              </span>
              <VProgressCircular
                :model-value="scorePct"
                :color="scoreColor"
                size="100"
                width="8"
                class="my-4"
              />
              <div class="text-body-2 text-medium-emphasis mb-1">{{ t('pa.kpis.weighted_score') }}</div>
              <span class="text-h6 font-weight-bold">{{ weightedScore ?? '—' }}</span>
            </div>
            <div v-else class="text-center py-6 text-medium-emphasis text-body-2">
              {{ t('pa.kpis.not_scored') }}
            </div>
          </VCardText>
        </VCard>

        <!-- Achievement -->
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1 font-weight-semibold pa-4 pb-2">{{ t('pa.kpis.achievement') }}</VCardTitle>
          <VCardText class="pt-0">
            <div v-if="achievementPct !== null">
              <div class="d-flex justify-space-between text-body-2 mb-2">
                <span>
                  {{ kpi.latest_value }} / {{ kpi.target_value }}
                  <span v-if="kpi.unit" class="text-medium-emphasis">{{ kpi.unit }}</span>
                </span>
                <span class="font-weight-medium">{{ achievementPct }}%</span>
              </div>
              <VProgressLinear
                :model-value="achievementPct"
                :color="achievementPct >= 80 ? 'success' : achievementPct >= 60 ? 'warning' : 'error'"
                height="8"
                rounded
              />
            </div>
            <div v-else class="text-center py-4 text-medium-emphasis text-body-2">
              {{ t('pa.kpis.no_progress_data') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Log Progress dialog -->
    <VDialog v-model="progressOpen" max-width="440">
      <VCard>
        <VCardTitle>{{ t('pa.kpis.log_progress') }} — {{ kpi.title }}</VCardTitle>
        <VCardText>
          <VTextField
            v-model.number="progressValue"
            :label="`Current value${kpi.unit ? ' (' + kpi.unit + ')' : ''}`"
            type="number" class="mb-3"
          />
          <VTextField v-model="progressDate" label="Date" type="date" class="mb-3" />
          <VTextField v-model="progressNotes" label="Notes (optional)" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="progressOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="saveProgress">Save Progress</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Score confirm dialog -->
    <VDialog v-model="scoreConfirmOpen" max-width="440">
      <VCard>
        <VCardTitle class="d-flex align-center pa-4 pb-2">
          <VIcon icon="ri-medal-line" class="mr-2" color="primary" />
          Score KPI
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 mb-3">
            This will calculate the score for <strong>{{ kpi.title }}</strong> based on the latest logged progress value compared to the target.
          </p>
          <VAlert v-if="kpi.latest_value !== null && kpi.target_value" type="info" variant="tonal" density="compact">
            Latest value: <strong>{{ kpi.latest_value }} {{ kpi.unit ?? '' }}</strong>
            / Target: <strong>{{ kpi.target_value }} {{ kpi.unit ?? '' }}</strong>
            → <strong>{{ Math.min(100, Math.round((kpi.latest_value / kpi.target_value) * 100)) }}%</strong>
          </VAlert>
          <VAlert v-else type="warning" variant="tonal" density="compact">
            No progress logged yet. Score cannot be calculated until a value is logged.
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="scoreConfirmOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" :disabled="kpi.latest_value === null || !kpi.target_value" @click="submitScore">
            Submit Score
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Upload Evidence dialog -->
    <VDialog v-model="evidenceUploadOpen" max-width="480">
      <VCard>
        <VCardTitle class="d-flex align-center pa-4 pb-2">
          <VIcon icon="ri-attachment-2" class="mr-2" color="primary" />
          Upload Evidence
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            Attach supporting documents, screenshots, or reports as evidence for this KPI.
          </p>
          <VFileInput
            v-model="evidenceFile"
            label="Select file"
            accept=".pdf,.png,.jpg,.jpeg,.xlsx,.xls,.docx,.doc,.csv"
            show-size
            class="mb-3"
          />
          <VTextField v-model="evidenceDesc" label="Description (optional)" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="evidenceUploadOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!evidenceFile" @click="submitEvidence">Upload</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
