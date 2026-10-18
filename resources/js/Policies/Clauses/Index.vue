<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import AppSelect from '@/@core/components/app-form-elements/AppSelect.vue'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'
import { formatDateTime } from '@/utils/policyFormatters'

const route = ziggyRoute

type Clause = {
  id: number
  clause_reference: string | null
  type: string
  content: string
  ai_interpretation: string | null
  extracted_entities: Record<string, any> | null
  ai_extracted: boolean
  approved: boolean
  approved_at: string | null
  sort_order: number
}

type DocumentItem = {
  id: number
  filename: string
  extraction_status: string
  extraction_provider?: string | null
  extraction_model?: string | null
  raw_ai_output?: Record<string, any> | null
  extraction_error?: string | null
  extracted_at?: string | null
  created_at?: string | null
}

const props = defineProps<{
  policy: any
  clauses: Clause[]
  documents?: DocumentItem[]
  types: string[]
}>()

// ── table state ──────────────────────────────────────────────
const tableSearch = ref('')

const tableHeaders = [
  { title: '#', key: 'sort_order', width: 60 },
  { title: 'Reference', key: 'clause_reference', width: 150 },
  { title: 'Type', key: 'type', width: 130 },
  { title: 'Content', key: 'content' },
  { title: 'Status', key: 'status', sortable: false, width: 140 },
  { title: 'Actions', key: 'actions', sortable: false, width: 120, align: 'end' as const },
]

// ── dialog state ─────────────────────────────────────────────
const editDialog = ref(false)
const viewDialog = ref(false)
const deleteDialog = ref(false)
const rawAiDialog = ref(false)

type ClauseDraft = {
  clause_reference: string
  type: string
  content: string
  ai_interpretation: string
  sort_order: number | null
}

const activeClause = ref<Clause | null>(null)
const editDraft = ref<ClauseDraft>({
  clause_reference: '',
  type: 'general',
  content: '',
  ai_interpretation: '',
  sort_order: null,
})

// ── drafts + selection ────────────────────────────────────────
const clauseDrafts = reactive<Record<number, ClauseDraft>>({})
const selectedClauseIds = ref<number[]>([])

const sortedClauses = computed(() => [...(props.clauses ?? [])].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id))
const pendingClauses = computed(() => sortedClauses.value.filter(c => c.ai_extracted && !c.approved))
const approvedClauses = computed(() => sortedClauses.value.filter(c => c.approved))
const latestDocument = computed(() => [...(props.documents ?? [])].sort((a, b) => (new Date(b.created_at ?? 0).getTime()) - (new Date(a.created_at ?? 0).getTime()))[0] ?? null)
const latestRawOutput = computed(() => latestDocument.value?.raw_ai_output ?? null)

const ambiguousTerms = ['reasonable', 'timely', 'as needed', 'as appropriate', 'promptly', 'significant', 'adequate']

const typeOptions = computed(() => props.types.map(type => ({
  title: type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()),
  value: type,
})))

const highAmbiguityCount = computed(() => sortedClauses.value.filter(c => clauseAmbiguityScore(c) >= 40).length)
const selectedClauseForPanel = computed(() => {
  const id = selectedClauseIds.value[0]
  return sortedClauses.value.find(c => c.id === id) ?? null
})

watch(
  () => sortedClauses.value,
  clauses => {
    clauses.forEach(clause => {
      clauseDrafts[clause.id] = {
        clause_reference: clause.clause_reference ?? '',
        type: clause.type ?? 'general',
        content: clause.content ?? '',
        ai_interpretation: clause.ai_interpretation ?? '',
        sort_order: clause.sort_order ?? null,
      }
    })
    selectedClauseIds.value = pendingClauses.value.map(c => c.id)
  },
  { immediate: true }
)

// ── helpers ───────────────────────────────────────────────────
function clauseTypeColor(type: string) {
  if (type === 'obligation') return 'success'
  if (type === 'prohibition') return 'error'
  if (type === 'threshold') return 'warning'
  if (type === 'approval') return 'primary'
  return 'secondary'
}

function formatJson(value: Record<string, any> | null | undefined) {
  if (!value) return 'No AI audit payload available.'
  return JSON.stringify(value, null, 2)
}

function summaryLabel(value: string | null | undefined) {
  return value?.trim() ? value : '—'
}

function clauseAmbiguityScore(clause: Clause): number {
  const text = (clauseDrafts[clause.id]?.content ?? clause.content ?? '').toLowerCase()
  if (!text.trim()) return 0
  let score = 0
  ambiguousTerms.forEach(term => { if (text.includes(term)) score += 15 })
  if (text.length < 80) score += 10
  if (!/\d/.test(text) && clause.type === 'threshold') score += 25
  if (!text.includes('must') && !text.includes('shall') && !text.includes('required')) score += 10
  return Math.min(score, 100)
}

function clauseWarnings(clause: Clause): string[] {
  const warnings: string[] = []
  const score = clauseAmbiguityScore(clause)
  const text = (clauseDrafts[clause.id]?.content ?? clause.content ?? '').toLowerCase()
  if (score >= 40) warnings.push('Clause appears ambiguous and may be interpreted inconsistently.')
  if (clause.type === 'threshold' && !/\d/.test(text)) warnings.push('Threshold clause has no numeric boundary.')
  if (text.length < 50) warnings.push('Clause text is very short; consider adding explicit conditions or scope.')
  if (!clauseDrafts[clause.id]?.ai_interpretation?.trim()) warnings.push('AI interpretation is empty; downstream explanation quality may degrade.')
  return warnings
}

function clauseViolationMessage(clause: Clause): string {
  const ref = summaryLabel(clauseDrafts[clause.id]?.clause_reference ?? clause.clause_reference)
  return `Potential violation detected for clause ${ref}. Please review compliance evidence and confirm whether an exception is required.`
}

function clauseExplanation(clause: Clause): string {
  const score = clauseAmbiguityScore(clause)
  if (score >= 60) return 'High ambiguity risk: wording can trigger inconsistent enforcement outcomes across departments.'
  if (score >= 30) return 'Moderate ambiguity risk: rule intent is partially clear but may still cause manual interpretation drift.'
  return 'Low ambiguity risk: wording is reasonably specific for consistent enforcement.'
}

function truncate(text: string, max = 100) {
  return text.length > max ? text.slice(0, max) + '…' : text
}

// ── actions ───────────────────────────────────────────────────
function goBack() {
  router.visit(route('policies.show', props.policy.uuid))
}

function approveExtraction() {
  if (!selectedClauseIds.value.length) return
  router.post(route('policies.clauses.approve', props.policy.uuid), {
    clause_ids: selectedClauseIds.value,
  }, {
    preserveScroll: true,
    onSuccess: () => router.reload({ only: ['policy', 'clauses', 'documents'] }),
  })
}

function openEdit(clause: Clause) {
  activeClause.value = clause
  editDraft.value = { ...clauseDrafts[clause.id] }
  editDialog.value = true
}

function closeEdit() {
  editDialog.value = false
  activeClause.value = null
}

function saveEditDialog() {
  if (!activeClause.value) return
  const clauseId = activeClause.value.id
  clauseDrafts[clauseId] = { ...editDraft.value }
  router.patch(route('policies.clauses.update', [props.policy.uuid, clauseId]), editDraft.value, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => router.reload({ only: ['policy', 'clauses', 'documents'] }),
  })
  closeEdit()
}

function openView(clause: Clause) {
  activeClause.value = clause
  viewDialog.value = true
}

function openDelete(clause: Clause) {
  activeClause.value = clause
  deleteDialog.value = true
}

function closeDelete() {
  deleteDialog.value = false
  activeClause.value = null
}

function confirmDelete() {
  if (!activeClause.value) return
  router.delete(route('policies.clauses.destroy', [props.policy.uuid, activeClause.value.id]), {
    preserveScroll: true,
    onSuccess: () => router.reload({ only: ['policy', 'clauses', 'documents'] }),
  })
  closeDelete()
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <!-- ── Header ───────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-6">
      <div>
        <h3 class="text-h5 font-weight-semibold mb-1">AI Clauses: {{ policy.name }}</h3>
        <p class="text-medium-emphasis mb-0">Review extracted clauses, edit them, then approve the AI extraction.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VBtn variant="tonal" color="secondary" @click="goBack">
          <i class="fa-solid fa-arrow-left me-2" /> Back to Policy
        </VBtn>
        <VBtn variant="tonal" color="info" @click="rawAiDialog = true">
          <i class="fa-solid fa-robot me-2" /> Raw AI Output
        </VBtn>
        <VBtn color="primary" :disabled="!selectedClauseIds.length" @click="approveExtraction">
          <i class="fa-solid fa-circle-check me-2" />
          Approve Selected ({{ selectedClauseIds.length }})
        </VBtn>
      </div>
    </div>

    <!-- ── Stat cards ────────────────────────────────────────── -->
    <VRow class="mb-6">
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border rounded="lg" color="primary" variant="tonal">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Total</div>
            <div class="text-h5 font-weight-bold">{{ sortedClauses.length }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border rounded="lg" color="warning" variant="tonal">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Pending</div>
            <div class="text-h5 font-weight-bold">{{ pendingClauses.length }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border rounded="lg" color="success" variant="tonal">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Approved</div>
            <div class="text-h5 font-weight-bold">{{ approvedClauses.length }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border rounded="lg" color="error" variant="tonal">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">High Ambiguity</div>
            <div class="text-h5 font-weight-bold">{{ highAmbiguityCount }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border rounded="lg" color="secondary" variant="tonal">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Documents</div>
            <div class="text-h5 font-weight-bold">{{ props.documents?.length ?? 0 }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border rounded="lg" :color="latestDocument?.extraction_status === 'completed' ? 'success' : 'warning'" variant="tonal">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Extraction</div>
            <div class="text-body-2 font-weight-bold text-capitalize">{{ latestDocument?.extraction_status ?? 'None' }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Enforcement explanation + Latest AI Extraction (top section) ── -->
    <VRow v-if="selectedClauseForPanel || latestDocument" class="mb-6">
      <VCol v-if="selectedClauseForPanel" cols="12" md="6">
        <VCard elevation="0" border rounded="lg">
          <VCardItem>
            <VCardTitle class="text-subtitle-1 font-weight-semibold">Enforcement Explanation</VCardTitle>
            <VCardSubtitle>Focused clause: {{ summaryLabel(selectedClauseForPanel.clause_reference) }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <div class="text-caption text-medium-emphasis mb-1">Ambiguity Score</div>
            <VProgressLinear
              :model-value="clauseAmbiguityScore(selectedClauseForPanel)"
              :color="clauseAmbiguityScore(selectedClauseForPanel) >= 40 ? 'warning' : 'success'"
              rounded
              height="8"
              class="mb-3"
            />
            <div class="text-body-2 mb-3">{{ clauseExplanation(selectedClauseForPanel) }}</div>
            <VAlert type="info" variant="tonal" density="comfortable" class="mb-0">
              {{ clauseViolationMessage(selectedClauseForPanel) }}
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="latestDocument" cols="12" :md="selectedClauseForPanel ? 6 : 12">
        <VCard elevation="0" border rounded="lg">
          <VCardItem>
            <VCardTitle class="text-subtitle-1 font-weight-semibold">Latest AI Extraction</VCardTitle>
          </VCardItem>
          <VCardText>
            <div class="mb-2"><span class="text-medium-emphasis">Document:</span> {{ latestDocument.filename }}</div>
            <div class="mb-2"><span class="text-medium-emphasis">Status:</span>
              <VChip size="x-small" :color="latestDocument.extraction_status === 'completed' ? 'success' : 'warning'" variant="tonal" label class="ms-1">
                {{ latestDocument.extraction_status }}
              </VChip>
            </div>
            <div class="mb-2"><span class="text-medium-emphasis">Provider:</span> {{ latestDocument.extraction_provider || '—' }}</div>
            <div class="mb-2"><span class="text-medium-emphasis">Model:</span> {{ latestDocument.extraction_model || '—' }}</div>
            <div><span class="text-medium-emphasis">Extracted At:</span> {{ formatDateTime(latestDocument.extracted_at) }}</div>
            <VAlert v-if="latestDocument.extraction_error" type="error" variant="tonal" class="mt-3 mb-0">
              {{ latestDocument.extraction_error }}
            </VAlert>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- ── Main table (full width) ─────────────────────────────── -->
      <VCol cols="12">
        <VCard elevation="0" border rounded="lg">
          <!-- table toolbar -->
          <VCardText class="d-flex align-center flex-wrap gap-3 pb-0">
            <AppTextField
              v-model="tableSearch"
              placeholder="Search clauses…"
              prepend-inner-icon="bx-search"
              density="compact"
              style="max-inline-size: 280px;"
            />
          </VCardText>

          <VAlert v-if="!sortedClauses.length" type="info" variant="tonal" class="ma-4">
            No clauses have been extracted yet.
          </VAlert>

          <VDataTable
            v-else
            v-model="selectedClauseIds"
            :headers="tableHeaders"
            :items="sortedClauses"
            :search="tableSearch"
            item-value="id"
            show-select
            hover
            class="text-no-wrap"
          >
            <!-- reference -->
            <template #item.clause_reference="{ item }">
              <span class="font-weight-medium">{{ summaryLabel(item.clause_reference) }}</span>
            </template>

            <!-- type chip -->
            <template #item.type="{ item }">
              <VChip :color="clauseTypeColor(item.type)" size="small" variant="tonal" label>
                {{ item.type }}
              </VChip>
            </template>

            <!-- content truncated -->
            <template #item.content="{ item }">
              <span class="text-wrap" style="max-inline-size: 420px; display: block; white-space: normal;">
                {{ truncate(item.content, 120) }}
              </span>
            </template>

            <!-- status -->
            <template #item.status="{ item }">
              <VChip v-if="item.approved" size="small" color="success" variant="tonal" label>
                <i class="fa-solid fa-circle-check me-1" /> Approved
              </VChip>
              <VChip v-else-if="item.ai_extracted" size="small" color="warning" variant="tonal" label>
                <i class="fa-solid fa-robot me-1" /> AI Extracted
              </VChip>
              <VChip v-else size="small" color="secondary" variant="tonal" label>Manual</VChip>
            </template>

            <!-- actions -->
            <template #item.actions="{ item }">
              <div class="d-flex gap-1 justify-end">
                <VTooltip text="View Details" location="top">
                  <template #activator="{ props: tip }">
                    <IconBtn v-bind="tip" @click="openView(item)">
                      <i class="fa-solid fa-eye" />
                    </IconBtn>
                  </template>
                </VTooltip>
                <VTooltip text="Edit Clause" location="top">
                  <template #activator="{ props: tip }">
                    <IconBtn v-bind="tip" @click="openEdit(item)">
                      <i class="fa-solid fa-pen-to-square" />
                    </IconBtn>
                  </template>
                </VTooltip>
                <VTooltip text="Delete" location="top">
                  <template #activator="{ props: tip }">
                    <IconBtn v-bind="tip" color="error" @click="openDelete(item)">
                      <i class="fa-solid fa-trash" />
                    </IconBtn>
                  </template>
                </VTooltip>
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>

  <!-- ── Edit Dialog ──────────────────────────────────────────── -->
  <VDialog v-model="editDialog" max-width="720" persistent>
    <VCard :title="`Edit Clause — ${summaryLabel(activeClause?.clause_reference)}`">
      <VCardText>
        <VRow class="mt-1">
          <VCol cols="12" sm="5">
            <AppTextField
              v-model="editDraft.clause_reference"
              label="Clause Reference"
              placeholder="e.g. Section 3.2"
            />
          </VCol>
          <VCol cols="12" sm="4">
            <AppSelect
              v-model="editDraft.type"
              :items="typeOptions"
              item-title="title"
              item-value="value"
              label="Type"
            />
          </VCol>
          <VCol cols="12" sm="3">
            <AppTextField
              v-model="editDraft.sort_order"
              type="number"
              label="Sort Order"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="editDraft.content"
              rows="5"
              label="Clause Content"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="editDraft.ai_interpretation"
              rows="3"
              label="AI Interpretation"
              hint="Plain-language explanation generated by the AI"
              persistent-hint
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardText class="d-flex justify-end gap-3 pt-0">
        <VBtn variant="outlined" color="secondary" @click="closeEdit">Cancel</VBtn>
        <VBtn color="primary" variant="elevated" @click="saveEditDialog">
          <i class="fa-solid fa-floppy-disk me-2" /> Save Clause
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>

  <!-- ── View Details Dialog ─────────────────────────────────── -->
  <VDialog v-model="viewDialog" max-width="760" scrollable>
    <VCard v-if="activeClause">
      <VCardItem>
        <VCardTitle>
          {{ summaryLabel(activeClause.clause_reference) }}
          <VChip :color="clauseTypeColor(activeClause.type)" size="small" variant="tonal" label class="ms-2">
            {{ activeClause.type }}
          </VChip>
        </VCardTitle>
        <VCardSubtitle>Clause #{{ activeClause.id }} • Sort order {{ activeClause.sort_order }}</VCardSubtitle>
        <template #append>
          <VBtn icon variant="text" @click="viewDialog = false">
            <i class="fa-solid fa-xmark" />
          </VBtn>
        </template>
      </VCardItem>

      <VDivider />

      <VCardText>
        <div class="text-caption font-weight-semibold text-medium-emphasis mb-1">CONTENT</div>
        <p class="text-body-2 mb-4" style="white-space: pre-wrap;">{{ activeClause.content }}</p>

        <div v-if="activeClause.ai_interpretation" class="mb-4">
          <div class="text-caption font-weight-semibold text-medium-emphasis mb-1">AI INTERPRETATION</div>
          <p class="text-body-2 mb-0">{{ activeClause.ai_interpretation }}</p>
        </div>

        <!-- ambiguity -->
        <div class="mb-4">
          <div class="text-caption font-weight-semibold text-medium-emphasis mb-1">AMBIGUITY SCORE</div>
          <VProgressLinear
            :model-value="clauseAmbiguityScore(activeClause)"
            :color="clauseAmbiguityScore(activeClause) >= 40 ? 'warning' : 'success'"
            rounded
            height="8"
            class="mb-2"
          />
          <div class="text-caption text-medium-emphasis">{{ clauseExplanation(activeClause) }}</div>
        </div>

        <!-- warnings -->
        <VAlert v-if="clauseWarnings(activeClause).length" type="warning" variant="tonal" class="mb-4">
          <div class="font-weight-medium mb-1">Warnings</div>
          <ul class="mb-0 ps-4">
            <li v-for="(w, i) in clauseWarnings(activeClause)" :key="i">{{ w }}</li>
          </ul>
        </VAlert>

        <!-- violation preview -->
        <VAlert type="info" variant="tonal" class="mb-4">
          <div class="font-weight-medium mb-1">Violation Message Preview</div>
          <div class="text-body-2">{{ clauseViolationMessage(activeClause) }}</div>
        </VAlert>

        <!-- extracted entities -->
        <div v-if="activeClause.extracted_entities">
          <div class="text-caption font-weight-semibold text-medium-emphasis mb-1">EXTRACTED ENTITIES</div>
          <VSheet border rounded class="pa-3">
            <pre class="text-caption mb-0" style="white-space: pre-wrap;">{{ formatJson(activeClause.extracted_entities) }}</pre>
          </VSheet>
        </div>
      </VCardText>

      <VDivider />
      <VCardText class="d-flex justify-end gap-3 py-3">
        <VBtn variant="tonal" color="primary" @click="() => { viewDialog = false; openEdit(activeClause!) }">
          <i class="fa-solid fa-pen-to-square me-2" /> Edit
        </VBtn>
        <VBtn variant="outlined" color="secondary" @click="viewDialog = false">Close</VBtn>
      </VCardText>
    </VCard>
  </VDialog>

  <!-- ── Raw AI Output Dialog ────────────────────────────────── -->
  <VDialog v-model="rawAiDialog" max-width="860" scrollable>
    <VCard title="Raw AI Output for Audit">
      <template #append>
        <VBtn icon variant="text" @click="rawAiDialog = false">
          <i class="fa-solid fa-xmark" />
        </VBtn>
      </template>

      <VDivider />

      <VCardText v-if="latestDocument" class="pb-0">
        <VRow dense class="mb-2">
          <VCol cols="12" sm="6">
            <div class="text-caption text-medium-emphasis">Document</div>
            <div class="text-body-2 font-weight-medium">{{ latestDocument.filename }}</div>
          </VCol>
          <VCol cols="6" sm="3">
            <div class="text-caption text-medium-emphasis">Provider</div>
            <div class="text-body-2">{{ latestDocument.extraction_provider || '—' }}</div>
          </VCol>
          <VCol cols="6" sm="3">
            <div class="text-caption text-medium-emphasis">Model</div>
            <div class="text-body-2">{{ latestDocument.extraction_model || '—' }}</div>
          </VCol>
          <VCol cols="6" sm="3">
            <div class="text-caption text-medium-emphasis">Status</div>
            <VChip size="x-small" :color="latestDocument.extraction_status === 'completed' ? 'success' : 'warning'" variant="tonal" label>
              {{ latestDocument.extraction_status }}
            </VChip>
          </VCol>
          <VCol cols="6" sm="9">
            <div class="text-caption text-medium-emphasis">Extracted At</div>
            <div class="text-body-2">{{ formatDateTime(latestDocument.extracted_at) }}</div>
          </VCol>
        </VRow>
      </VCardText>

      <VCardText>
        <VAlert v-if="!latestRawOutput" type="info" variant="tonal">
          No raw AI output stored yet.
        </VAlert>
        <VSheet v-else border rounded class="pa-4">
          <pre class="text-caption mb-0" style="white-space: pre-wrap; max-block-size: 60vh; overflow-y: auto;">{{ formatJson(latestRawOutput) }}</pre>
        </VSheet>
      </VCardText>

      <VDivider />
      <VCardText class="d-flex justify-end py-3">
        <VBtn variant="outlined" color="secondary" @click="rawAiDialog = false">Close</VBtn>
      </VCardText>
    </VCard>
  </VDialog>

  <!-- ── Delete Confirmation Dialog ─────────────────────────── -->
  <VDialog v-model="deleteDialog" max-width="500" persistent>
    <VCard>
      <VCardText class="d-flex flex-column align-center text-center pa-6">
        <VAvatar color="error" variant="tonal" size="56" class="mb-4">
          <i class="fa-solid fa-triangle-exclamation fa-lg" />
        </VAvatar>
        <h5 class="text-h5 mb-2">Delete Clause?</h5>
        <p class="text-medium-emphasis mb-0">
          Are you sure you want to remove clause
          <strong>{{ summaryLabel(activeClause?.clause_reference) }}</strong>?
          This action cannot be undone.
        </p>
      </VCardText>
      <VCardText class="d-flex justify-center gap-3 pt-0 pb-6">
        <VBtn variant="outlined" color="secondary" @click="closeDelete">Cancel</VBtn>
        <VBtn color="error" variant="elevated" @click="confirmDelete">
          <i class="fa-solid fa-trash me-2" /> Delete
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>