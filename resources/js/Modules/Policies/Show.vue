<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { route as ziggyRoute } from 'ziggy-js'
import { formatDate, normalizeTags } from '@/utils/policyFormatters'

const route = ziggyRoute
const { t } = useI18n()

type UserOption = { id: number; name: string; email?: string }
type UnitOption = { id: number; name: string; parent_id: number | null; type: string }
type CompanyUnitsByType = {
  divisions?: UnitOption[]
  departments?: UnitOption[]
  sections?: UnitOption[]
  teams?: UnitOption[]
  units?: UnitOption[]
}

const props = defineProps<{
  policy: any
  users?: UserOption[]
  owners?: UserOption[]
  reviewers?: UserOption[]
  categories?: Array<{ id: number; name: string }>
  companyUnits?: CompanyUnitsByType
}>()

const editDialog = ref(false)
const assignmentDialog = ref(false)
const selectedUserIds = ref<number[]>([])
const selectedDivisionIds = ref<number[]>([])
const selectedDepartmentIds = ref<number[]>([])
const selectedSectionIds = ref<number[]>([])
const selectedTeamIds = ref<number[]>([])

const statuses = ['draft', 'active', 'under_review', 'archived']

const editForm = useForm({
  name: '',
  description: '',
  category_id: null as number | null,
  owner_id: null as number | null,
  reviewer_ids: [] as number[],
  effective_date: '',
  review_date: '',
  status: 'draft',
  requires_attestation: false,
  tags: [] as string[],
  change_summary: '',
  redirect_to: 'show',
})

const assignmentForm = useForm({
  user_ids: [] as number[],
  division_ids: [] as number[],
  department_ids: [] as number[],
  section_ids: [] as number[],
  team_ids: [] as number[],
  deadline: '',
})

const usersForSelect = computed(() =>
  (props.users ?? []).map(u => ({ title: `${u.name}${u.email ? ` (${u.email})` : ''}`, value: u.id })),
)

const selectedTargetsCount = computed(() =>
  selectedUserIds.value.length
  + selectedDivisionIds.value.length
  + selectedDepartmentIds.value.length
  + selectedSectionIds.value.length
  + selectedTeamIds.value.length,
)

function openEditDialog() {
  editForm.name = props.policy.name ?? ''
  editForm.description = props.policy.description ?? ''
  editForm.category_id = props.policy.category_id ?? null
  editForm.owner_id = props.policy.owner_id ?? null
  editForm.reviewer_ids = (props.policy.reviewers ?? []).map((r: any) => Number(r.user_id)).filter(Boolean)
  editForm.effective_date = (props.policy.effective_date ?? '').slice(0, 10)
  editForm.review_date = (props.policy.review_date ?? '').slice(0, 10)
  editForm.status = props.policy.status ?? 'draft'
  editForm.requires_attestation = !!props.policy.requires_attestation
  editForm.tags = normalizeTags(props.policy.tags)
  editForm.change_summary = ''
  editForm.clearErrors()
  editDialog.value = true
}

function submitEdit() {
  editForm.patch(route('policies.update', props.policy.uuid), {
    preserveScroll: true,
    onSuccess: () => {
      editDialog.value = false
      router.reload({ only: ['policy'] })
    },
  })
}

function openAssignmentDialog() {
  selectedUserIds.value = []
  selectedDivisionIds.value = []
  selectedDepartmentIds.value = []
  selectedSectionIds.value = []
  selectedTeamIds.value = []
  assignmentForm.reset()
  assignmentDialog.value = true
}

function closeAssignmentDialog() {
  assignmentDialog.value = false
  assignmentForm.reset()
}

function submitAssignment() {
  assignmentForm.user_ids = selectedUserIds.value
  assignmentForm.division_ids = selectedDivisionIds.value
  assignmentForm.department_ids = selectedDepartmentIds.value
  assignmentForm.section_ids = selectedSectionIds.value
  assignmentForm.team_ids = selectedTeamIds.value

  assignmentForm.post(route('policies.attestations.assign', props.policy.uuid), {
    preserveScroll: true,
    onSuccess: () => closeAssignmentDialog(),
  })
}

function formatUnitTypeLabel(type: string) {
  if (!type) return t('policy_show.unit')
  return type.charAt(0).toUpperCase() + type.slice(1)
}

function orgLevelColor(type: string) {
  if (type === 'division') return 'indigo'
  if (type === 'department') return 'teal'
  if (type === 'section') return 'pink'
  if (type === 'team') return 'warning'
  return 'default'
}

function buildOrgChain(policy: any) {
  const structured = Array.isArray(policy?.organization_chain) ? policy.organization_chain : []
  if (structured.length) {
    return structured.map((unit: any, index: number) => ({
      id: unit.id ?? index,
      name: unit.name ?? 'Unknown',
      type: unit.type ?? 'unit',
    }))
  }

  const chain: Array<{ id: number; name: string; type: string }> = []
  let cursor = policy?.departmentUnit || policy?.department_unit || policy?.department || null
  let guard = 0
  while (cursor && guard < 12) {
    chain.push({ id: cursor.id, name: cursor.name, type: cursor.type ?? 'unit' })
    cursor = cursor.parent ?? cursor.parentUnit ?? null
    guard += 1
  }

  return chain.reverse()
}

function currentChangeSummary(policy: any) {
  return policy?.current_version?.change_summary
    || policy?.currentVersion?.change_summary
    || policy?.versions?.[0]?.change_summary
    || t('policy_show.no_summary_provided')
}

function latestDocument(policy: any) {
  const docs = Array.isArray(policy?.documents) ? [...policy.documents] : []
  return docs.sort((a: any, b: any) => (new Date(b.created_at ?? 0).getTime()) - (new Date(a.created_at ?? 0).getTime()))[0] ?? null
}

function goBack() { router.visit(route('policies.index')) }
function goRuleBuilder(uuid: string) { router.visit(route('policies.rules.index', uuid)) }
function goReviewClauses(uuid: string) { router.visit(route('policies.clauses.index', uuid)) }
function goViolations() { router.visit(route('policy-violations.index', { policy_uuid: props.policy.uuid })) }
function goQuality() { router.visit(route('policy-quality.index')) }
function goQuizManage(uuid: string) { router.visit(route('policies.quiz.manage', uuid)) }
function goQuizTake(uuid: string) { router.visit(route('policies.quiz.take', uuid)) }
function goQuizHistory(uuid: string) { router.visit(route('policies.quiz.history', uuid)) }

function runAiExtraction() {
  const doc = latestDocument(props.policy)
  if (!doc) {
    router.visit(route('policies.clauses.index', props.policy.uuid))
    return
  }

  router.post(route('policies.documents.extract', [props.policy.uuid, doc.id]), {}, {
    preserveScroll: true,
    onSuccess: () => router.visit(route('policies.clauses.index', props.policy.uuid)),
  })
}

function viewDocument(policyUuid: string, documentId: number) {
  window.open(route('policies.documents.view', [policyUuid, documentId]), '_blank')
}

function downloadDocument(policyUuid: string, documentId: number) {
  window.open(route('policies.documents.download', [policyUuid, documentId]), '_blank')
}

function statusColor(value: string) {
  if (value === 'active') return 'success'
  if (value === 'under_review') return 'warning'
  if (value === 'archived') return 'default'
  return 'info'
}

function countOf(key: 'clauses' | 'rules' | 'documents' | 'versions' | 'reviewers') {
  return Array.isArray((props.policy as any)?.[key]) ? (props.policy as any)[key].length : 0
}
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ policy.name }}</h3>
          <p class="text-medium-emphasis mb-0">{{ t('policy_show.header_subtitle') }}</p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
          <VBtn variant="text" @click="goBack"><i class="fa-solid fa-arrow-left me-2" /> {{ t('policy_show.back') }}</VBtn>
          <VBtn variant="tonal" color="secondary" @click="runAiExtraction"><i class="fa-solid fa-robot me-2" /> {{ t('policy_show.review_ai_extraction') }}</VBtn>
          <VBtn variant="tonal" color="primary" @click="goRuleBuilder(policy.uuid)"><i class="fa-solid fa-diagram-project me-2" /> {{ t('policy_show.rule_builder') }}</VBtn>
          <VBtn
            v-if="policy.requires_attestation && policy.status === 'active'"
            variant="tonal"
            color="success"
            @click="openAssignmentDialog"
          >
            <i class="fa-solid fa-user-check me-2" /> {{ t('policy_show.assign_to_employees') }}
          </VBtn>
          <VBtn color="primary" @click="openEditDialog"><i class="fa-solid fa-pen me-2" /> {{ t('policy_show.edit') }}</VBtn>
        </div>
      </div>

      <VRow>
        <VCol cols="12" md="8">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('policy_show.overview') }}</VCardTitle>
            <VCardText>
              <p class="mb-4">{{ policy.description || t('policy_show.no_description_provided') }}</p>
              <VRow>
                <VCol cols="12" md="6"><strong>{{ t('policy_show.category') }}:</strong> {{ policy.category?.name || '-' }}</VCol>
                <VCol cols="12" md="6"><strong>{{ t('policy_show.owner') }}:</strong> {{ policy.owner?.name || '-' }}</VCol>
                <VCol cols="12" md="6">
                  <strong>{{ t('policy_show.organisation_scope') }}:</strong>
                  <div class="d-flex flex-wrap gap-2 mt-2">
                    <template v-if="buildOrgChain(policy).length">
                      <VChip v-for="unit in buildOrgChain(policy)" :key="unit.id" size="small" :color="orgLevelColor(unit.type)" variant="tonal" label>
                        {{ formatUnitTypeLabel(unit.type) }}: {{ unit.name }}
                      </VChip>
                    </template>
                    <span v-else class="text-medium-emphasis">{{ t('policy_show.organisation_scope_not_set') }}</span>
                  </div>
                </VCol>
                <VCol cols="12" md="6">
                  <strong>{{ t('policy_show.status') }}:</strong>
                  <VChip :color="statusColor(policy.status)" size="small" variant="tonal" label class="ms-2">{{ policy.status }}</VChip>
                </VCol>
                <VCol cols="12" md="6"><strong>{{ t('policy_show.effective_date') }}:</strong> {{ formatDate(policy.effective_date) }}</VCol>
                <VCol cols="12" md="6"><strong>{{ t('policy_show.review_date') }}:</strong> {{ formatDate(policy.review_date) }}</VCol>
                <VCol cols="12"><strong>{{ t('policy_show.latest_change_summary') }}:</strong> {{ currentChangeSummary(policy) }}</VCol>
              </VRow>

              <div class="mt-3">
                <strong>{{ t('policy_show.tags') }}:</strong>
                <span v-if="!normalizeTags(policy.tags).length" class="text-medium-emphasis"> {{ t('policy_show.none') }}</span>
                <VChip v-for="(tag, idx) in normalizeTags(policy.tags)" :key="idx" size="small" class="ms-2" variant="tonal">{{ tag }}</VChip>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="4">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('policy_show.policy_ui_quick_access') }}</VCardTitle>
            <VCardText>
              <div class="d-flex flex-wrap gap-2 mb-3">
                <VChip color="primary" variant="tonal" label>{{ t('policy_show.clauses_count', { count: countOf('clauses') }) }}</VChip>
                <VChip color="secondary" variant="tonal" label>{{ t('policy_show.rules_count', { count: countOf('rules') }) }}</VChip>
                <VChip color="info" variant="tonal" label>{{ t('policy_show.documents_count', { count: countOf('documents') }) }}</VChip>
                <VChip color="warning" variant="tonal" label>{{ t('policy_show.versions_count', { count: countOf('versions') }) }}</VChip>
              </div>

              <div class="d-flex flex-wrap gap-2">
                <VBtn variant="tonal" color="primary" @click="goReviewClauses(policy.uuid)">{{ t('policy_show.clause_view') }}</VBtn>
                <VBtn variant="tonal" color="secondary" @click="goRuleBuilder(policy.uuid)">{{ t('policy_show.rule_view') }}</VBtn>
                <VBtn variant="tonal" color="warning" @click="goQuizManage(policy.uuid)">{{ t('policy_show.quiz_admin') }}</VBtn>
                <VBtn variant="tonal" color="info" @click="goQuizTake(policy.uuid)">{{ t('policy_show.take_quiz') }}</VBtn>
                <VBtn variant="tonal" color="success" @click="goQuizHistory(policy.uuid)">{{ t('policy_show.quiz_history') }}</VBtn>
                <VBtn variant="tonal" color="error" @click="goViolations">{{ t('policy_show.violations') }}</VBtn>
                <VBtn variant="tonal" color="success" @click="goQuality">{{ t('policy_show.quality') }}</VBtn>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VContainer>

    <VDialog v-model="editDialog" max-width="820" scrollable>
      <VCard :title="t('policy_show.edit_policy')">
        <VCardText>
          <VRow>
            <VCol cols="12" md="6"><VTextField v-model="editForm.name" :label="t('policy_show.title')" :error-messages="editForm.errors.name" /></VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="editForm.category_id"
                :items="props.categories ?? []"
                item-title="name"
                item-value="id"
                :label="t('policy_show.category')"
                :error-messages="editForm.errors.category_id"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="editForm.owner_id"
                :items="props.owners ?? []"
                item-title="name"
                item-value="id"
                :label="t('policy_show.owner')"
                :error-messages="editForm.errors.owner_id"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="editForm.reviewer_ids"
                :items="props.reviewers ?? []"
                item-title="name"
                item-value="id"
                :label="t('policy_show.reviewers')"
                multiple
                chips
                clearable
                :error-messages="editForm.errors.reviewer_ids"
              />
            </VCol>
            <VCol cols="12" md="6"><VSelect v-model="editForm.status" :items="statuses" :label="t('policy_show.status')" :error-messages="editForm.errors.status" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="editForm.effective_date" type="date" :label="t('policy_show.effective_date')" :error-messages="editForm.errors.effective_date" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="editForm.review_date" type="date" :label="t('policy_show.review_date')" :error-messages="editForm.errors.review_date" /></VCol>
            <VCol cols="12"><VCheckbox v-model="editForm.requires_attestation" :label="t('policy_show.requires_attestation')" /></VCol>
            <VCol cols="12"><VTextarea v-model="editForm.change_summary" rows="2" :label="t('policy_show.change_summary')" :error-messages="editForm.errors.change_summary" /></VCol>
            <VCol cols="12"><VTextarea v-model="editForm.description" rows="4" :label="t('policy_show.description')" :error-messages="editForm.errors.description" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="editDialog = false">{{ t('policy_show.cancel') }}</VBtn>
          <VBtn color="primary" :loading="editForm.processing" @click="submitEdit">{{ t('policy_show.save_changes') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="assignmentDialog" max-width="900" scrollable>
      <VCard :title="t('policy_show.assign_policy_attestation')">
        <VCardText>
          <VRow>
            <VCol cols="12" md="6">
              <VSelect
                v-model="selectedDivisionIds"
                :items="props.companyUnits?.divisions ?? []"
                item-title="name"
                item-value="id"
                :label="t('policy_show.divisions')"
                multiple
                chips
                clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="selectedDepartmentIds"
                :items="props.companyUnits?.departments ?? []"
                item-title="name"
                item-value="id"
                :label="t('policy_show.departments')"
                multiple
                chips
                clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="selectedSectionIds"
                :items="props.companyUnits?.sections ?? []"
                item-title="name"
                item-value="id"
                :label="t('policy_show.sections')"
                multiple
                chips
                clearable
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="selectedTeamIds"
                :items="props.companyUnits?.teams ?? []"
                item-title="name"
                item-value="id"
                :label="t('policy_show.teams')"
                multiple
                chips
                clearable
              />
            </VCol>
            <VCol cols="12">
              <VAutocomplete
                v-model="selectedUserIds"
                :items="usersForSelect"
                :label="t('policy_show.select_many_users')"
                multiple
                chips
                closable-chips
                clearable
              />
            </VCol>
            <VCol cols="12" md="6"><VTextField v-model="assignmentForm.deadline" type="date" :label="t('policy_show.deadline_optional')" /></VCol>
            <VCol cols="12"><div class="text-caption text-medium-emphasis">{{ t('policy_show.selected_targets', { count: selectedTargetsCount }) }}</div></VCol>
            <VCol cols="12" v-if="assignmentForm.errors.user_ids"><VAlert type="error" variant="tonal">{{ assignmentForm.errors.user_ids }}</VAlert></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="closeAssignmentDialog">{{ t('policy_show.cancel') }}</VBtn>
          <VBtn color="success" :loading="assignmentForm.processing" @click="submitAssignment">{{ t('policy_show.assign') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>
