<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect from '@/@core/components/app-form-elements/AppSelect.vue'
import AppFileInput from '@/@core/components/app-form-elements/AppFileInput.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'
import { formatDate, normalizeDateForInput, normalizeTags } from '@/utils/policyFormatters'

const route = ziggyRoute
const { t } = useI18n()

type CompanyUnit = {
  id: number
  name: string
  parent_id: number | null
  type: string
}

type CompanyUnitsByType = {
  divisions?: CompanyUnit[]
  departments?: CompanyUnit[]
  sections?: CompanyUnit[]
  teams?: CompanyUnit[]
  units?: CompanyUnit[]
}

type OrgLevel = {
  selectedId: number | null
  children: CompanyUnit[]
}

const props = defineProps<{
  policies: {
    data: Array<any>
    links?: Array<{ url: string | null; label: string; active: boolean }>
  }
  filters?: Record<string, any>
  categories: Array<{ id: number; name: string }>
  owners: Array<{ id: number; name: string }>
  reviewers: Array<{ id: number; name: string }>
  companyUnits: CompanyUnitsByType
  ui?: { modal?: string; edit_policy_id?: number | null }
  editPolicy?: any | null
}>()

const search = ref(props.filters?.search ?? '')
const status = ref(props.filters?.status ?? '')
const categoryId = ref(props.filters?.category_id ?? '')
const filterDepartmentId = ref<number | null>(props.filters?.department_id ? Number(props.filters.department_id) : null)

const statuses = ['draft', 'active', 'under_review', 'archived']
const rows = computed(() => props.policies?.data ?? [])
const activeCount = computed(() => rows.value.filter((row: any) => row.status === 'active').length)
const draftCount = computed(() => rows.value.filter((row: any) => row.status === 'draft').length)
const reviewSoonCount = computed(() => rows.value.filter((row: any) => {
  if (!row.review_date) return false
  const days = (new Date(row.review_date).getTime() - Date.now()) / (1000 * 60 * 60 * 24)
  return days >= 0 && days <= 30
}).length)

const deleteDialog = ref(false)
const policyToDelete = ref<any | null>(null)

const policyDialog = ref(false)
const isEditingPolicy = ref(false)
const policyIdBeingEdited = ref<string | null>(null)
const tagsInput = ref('')
const orgLevels = ref<OrgLevel[]>([])
const filterOrgLevels = ref<OrgLevel[]>([])

const allOrgUnits = computed<CompanyUnit[]>(() => [
  ...(props.companyUnits?.divisions ?? []),
  ...(props.companyUnits?.departments ?? []),
  ...(props.companyUnits?.sections ?? []),
  ...(props.companyUnits?.teams ?? []),
  ...(props.companyUnits?.units ?? []),
])

const orgUnitMap = computed(() => {
  const map = new Map<number, CompanyUnit>()
  allOrgUnits.value.forEach(unit => map.set(unit.id, unit))
  return map
})

const orgRoots = computed(() => allOrgUnits.value.filter(unit => unit.parent_id === null))

const policyForm = useForm({
  name: '',
  description: '',
  category_id: null as number | null,
  department_id: null as number | null,
  owner_id: null as number | null,
  reviewer_ids: [] as number[],
  effective_date: '',
  review_date: '',
  status: 'draft',
  requires_attestation: false,
  tags: [] as string[],
  document: null as File | null,
  change_summary: '',
})

function parseTags() {
  policyForm.tags = tagsInput.value
    .split(',')
    .map(tag => tag.trim())
    .filter(Boolean)
}

function onFileChanged(files: File[] | File | null) {
  if (Array.isArray(files)) {
    policyForm.document = files[0] ?? null
    return
  }
  policyForm.document = files
}

function resetPolicyForm() {
  policyForm.reset()
  policyForm.clearErrors()
  policyForm.status = 'draft'
  policyForm.department_id = null
  policyForm.requires_attestation = false
  policyForm.reviewer_ids = []
  policyForm.document = null
  policyForm.change_summary = ''
  tagsInput.value = ''
  policyIdBeingEdited.value = null
  initOrgLevels()
}

function formatUnitTypeLabel(type: string) {
  if (!type) return t('policy_index.unit')
  return type.charAt(0).toUpperCase() + type.slice(1)
}

const unitTypeConfig: Record<string, { label: string; icon: string; mdiIcon: string; color: string; bg: string }> = {
  division: { label: t('policy_index.division'), icon: 'fa-building', mdiIcon: 'tabler-building', color: '#6366f1', bg: '#eef2ff' },
  department: { label: t('policy_index.department'), icon: 'fa-sitemap', mdiIcon: 'tabler-sitemap', color: '#0d9488', bg: '#f0fdfa' },
  section: { label: t('policy_index.section'), icon: 'fa-layer-group', mdiIcon: 'tabler-layout-dashboard', color: '#ec4899', bg: '#fdf2f8' },
  team: { label: t('policy_index.team'), icon: 'fa-people-group', mdiIcon: 'tabler-users', color: '#f59e0b', bg: '#fffbeb' },
  unit: { label: t('policy_index.unit'), icon: 'fa-cube', mdiIcon: 'tabler-cube', color: '#64748b', bg: '#f8fafc' },
}

function getTypeConfig(type: string | undefined) {
  return unitTypeConfig[type ?? ''] ?? unitTypeConfig.unit
}

function levelLabel(level: OrgLevel) {
  const type = level.children[0]?.type ?? 'unit'
  return getTypeConfig(type).label || formatUnitTypeLabel(type)
}

function initOrgLevels() {
  orgLevels.value = orgRoots.value.length
    ? [{ selectedId: null, children: orgRoots.value }]
    : []
}

function initFilterOrgLevels() {
  filterOrgLevels.value = orgRoots.value.length
    ? [{ selectedId: null, children: orgRoots.value }]
    : []
}

function onOrgLevelSelect(levelIndex: number, selectedId: number | null) {
  orgLevels.value[levelIndex].selectedId = selectedId
  orgLevels.value = orgLevels.value.slice(0, levelIndex + 1)

  if (selectedId !== null) {
    const children = allOrgUnits.value.filter(unit => unit.parent_id === selectedId)
    if (children.length) {
      orgLevels.value.push({ selectedId: null, children })
    }
  }

  const chosenIds = orgLevels.value
    .map(level => level.selectedId)
    .filter((id): id is number => id !== null)

  policyForm.department_id = chosenIds.length ? chosenIds[chosenIds.length - 1] : null
}

function onFilterOrgLevelSelect(levelIndex: number, selectedId: number | null) {
  filterOrgLevels.value[levelIndex].selectedId = selectedId
  filterOrgLevels.value = filterOrgLevels.value.slice(0, levelIndex + 1)

  if (selectedId !== null) {
    const children = allOrgUnits.value.filter(unit => unit.parent_id === selectedId)
    if (children.length) {
      filterOrgLevels.value.push({ selectedId: null, children })
    }
  }

  const chosenIds = filterOrgLevels.value
    .map(level => level.selectedId)
    .filter((id): id is number => id !== null)

  filterDepartmentId.value = chosenIds.length ? chosenIds[chosenIds.length - 1] : null
}

function setOrgSelection(selectedId: number | null) {
  initOrgLevels()
  if (selectedId === null || selectedId === undefined) {
    policyForm.department_id = null
    return
  }

  const path: number[] = []
  let cursor = orgUnitMap.value.get(Number(selectedId))
  let guard = 0

  while (cursor && guard < 12) {
    path.push(cursor.id)
    if (cursor.parent_id === null) break
    cursor = orgUnitMap.value.get(cursor.parent_id)
    guard++
  }

  path.reverse()

  path.forEach((id, index) => {
    if (!orgLevels.value[index]) return
    orgLevels.value[index].selectedId = id

    const children = allOrgUnits.value.filter(unit => unit.parent_id === id)
    if (children.length) {
      orgLevels.value.push({ selectedId: null, children })
    }
  })

  policyForm.department_id = path.length ? path[path.length - 1] : null
}

function setFilterOrgSelection(selectedId: number | null) {
  initFilterOrgLevels()
  if (selectedId === null || selectedId === undefined) {
    filterDepartmentId.value = null
    return
  }

  const path: number[] = []
  let cursor = orgUnitMap.value.get(Number(selectedId))
  let guard = 0

  while (cursor && guard < 12) {
    path.push(cursor.id)
    if (cursor.parent_id === null) break
    cursor = orgUnitMap.value.get(cursor.parent_id)
    guard++
  }

  path.reverse()

  path.forEach((id, index) => {
    if (!filterOrgLevels.value[index]) return
    filterOrgLevels.value[index].selectedId = id

    const children = allOrgUnits.value.filter(unit => unit.parent_id === id)
    if (children.length) {
      filterOrgLevels.value.push({ selectedId: null, children })
    }
  })

  filterDepartmentId.value = path.length ? path[path.length - 1] : null
}

function organizationLabel(item: any) {
  const chain = Array.isArray(item?.organization_chain) ? item.organization_chain : []
  if (chain.length) {
    return chain.map((unit: any) => `${unit.name || t('policy_index.unknown')} (${unit.type || t('policy_index.unit_lowercase')})`).join(', ')
  }

  if (typeof item?.organization_profile === 'string' && item.organization_profile.trim()) {
    return item.organization_profile
  }

  const root = item?.departmentUnit || item?.department_unit || item?.department || null
  if (!root) return '-'

  const chainFallback: Array<{ name?: string; type?: string; parent?: any }> = []
  let cursor = root
  let guard = 0

  while (cursor && guard < 12) {
    chainFallback.push(cursor)
    cursor = cursor.parent ?? cursor.parentUnit ?? null
    guard += 1
  }

  return chainFallback
    .reverse()
    .map(unit => `${unit.name || t('policy_index.unknown')}${unit.type ? ` (${unit.type})` : ''}`)
    .join(', ') || root.name || t('policy_index.organisation_scope_not_set')
}

function openCreatePolicyModal() {
  isEditingPolicy.value = false
  resetPolicyForm()
  policyDialog.value = true
}

function openEditPolicyModal(item: any) {
  const normalizeNullableNumber = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null
    const num = Number(value)

    return Number.isFinite(num) ? num : null
  }

  isEditingPolicy.value = true
  policyIdBeingEdited.value = item.uuid

  policyForm.name = item.name ?? ''
  policyForm.description = item.description ?? ''
  policyForm.category_id = normalizeNullableNumber(item.category_id)
  policyForm.department_id = normalizeNullableNumber(item.department_id)
  policyForm.owner_id = normalizeNullableNumber(item.owner_id)
  policyForm.reviewer_ids = (item.reviewers ?? []).map((entry: any) => Number(entry.user_id)).filter(Boolean)
  policyForm.effective_date = normalizeDateForInput(item.effective_date)
  policyForm.review_date = normalizeDateForInput(item.review_date)
  policyForm.status = item.status ?? 'draft'
  policyForm.requires_attestation = !!item.requires_attestation
  policyForm.tags = normalizeTags(item.tags)
  policyForm.document = null
  policyForm.change_summary = ''
  setOrgSelection(policyForm.department_id)

  tagsInput.value = policyForm.tags.join(', ')
  policyForm.clearErrors()
  policyDialog.value = true
}

function closePolicyDialog() {
  policyDialog.value = false
  resetPolicyForm()
}

function submitPolicyForm() {
  parseTags()

  if (isEditingPolicy.value && policyIdBeingEdited.value) {
    policyForm.transform(data => ({
      ...data,
      redirect_to: 'index',
    })).patch(route('policies.update', policyIdBeingEdited.value), {
      forceFormData: true,
      preserveScroll: true,
      preserveState: false,
      onSuccess: closePolicyDialog,
    })
    return
  }

  policyForm.post(route('policies.store'), {
    forceFormData: true,
    preserveScroll: true,
    preserveState: true,
    onSuccess: closePolicyDialog,
  })
}

function applyFilters() {
  router.get(
    route('policies.index'),
    {
      search: search.value || undefined,
      status: status.value || undefined,
      category_id: categoryId.value || undefined,
      department_id: filterDepartmentId.value || undefined,
    },
    { preserveState: true, preserveScroll: true },
  )
}

function resetFilters() {
  search.value = ''
  status.value = ''
  categoryId.value = ''
  setFilterOrgSelection(null)
  applyFilters()
}

function goToView(uuid: string) {
  router.visit(route('policies.show', uuid))
}

function openEditPolicyModalById(item: any) {
  openEditPolicyModal(item)
}

function goToClauseView(uuid: string) {
  router.visit(route('policies.clauses.index', uuid))
}

function goToRuleView(uuid: string) {
  router.visit(route('policies.rules.index', uuid))
}

function goToCategories() {
  router.visit(route('policy-categories.index'))
}

function togglePolicyStatus(item: any) {
  const nextStatus = item.status === 'active' ? 'archived' : 'active'
  router.patch(
    route('policies.update', item.uuid),
    {
      status: nextStatus,
      change_summary: `Status changed to ${nextStatus}`,
      redirect_to: 'index',
    },
    {
      preserveScroll: true,
      preserveState: false,
    },
  )
}

function confirmDelete(item: any) {
  policyToDelete.value = item
  deleteDialog.value = true
}

function doDelete() {
  if (!policyToDelete.value) return
  router.delete(route('policies.destroy', policyToDelete.value.uuid), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      deleteDialog.value = false
      policyToDelete.value = null
    },
  })
}

function getPolicyName(item: any | null) {
  return item?.name ?? ''
}

function statusColor(value: string) {
  if (value === 'active') return 'success'
  if (value === 'under_review') return 'warning'
  if (value === 'archived') return 'default'
  return 'info'
}

onMounted(() => {
  setFilterOrgSelection(filterDepartmentId.value)

  if (props.ui?.modal === 'create') {
    openCreatePolicyModal()
    return
  }

  if (props.ui?.modal === 'edit' && props.editPolicy) {
    openEditPolicyModal(props.editPolicy)
  }
})
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ t('policy_index.policy_repository') }}</h3>
          <p class="text-medium-emphasis mb-0">{{ t('policy_index.manage_policy_lifecycle') }}</p>
        </div>

        <div class="d-flex align-center gap-2">
          <VBtn color="secondary" variant="tonal" @click="goToCategories">
            <i class="fa-solid fa-tags me-2" /> {{ t('policy_index.categories') }}
          </VBtn>
          <VBtn color="primary" @click="openCreatePolicyModal">
            <i class="fa-solid fa-plus me-2" /> {{ t('policy_index.new_policy') }}
          </VBtn>
        </div>
      </div>

      <VCard elevation="0" border class="mb-4">
        <VCardText class="pa-4">
          <VRow>
            <VCol cols="12" sm="4">
              <VCard color="success" variant="tonal">
                <VCardText>
                  <div class="text-caption text-medium-emphasis">{{ t('policy_index.active_policies') }}</div>
                  <div class="text-h5 font-weight-semibold">{{ activeCount }}</div>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="12" sm="4">
              <VCard color="info" variant="tonal">
                <VCardText>
                  <div class="text-caption text-medium-emphasis">{{ t('policy_index.draft_policies') }}</div>
                  <div class="text-h5 font-weight-semibold">{{ draftCount }}</div>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="12" sm="4">
              <VCard color="warning" variant="tonal">
                <VCardText>
                  <div class="text-caption text-medium-emphasis">{{ t('policy_index.review_due_30_days') }}</div>
                  <div class="text-h5 font-weight-semibold">{{ reviewSoonCount }}</div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard elevation="0" border class="mb-4">
        <VCardText class="pa-4">
          <div class="d-flex flex-wrap gap-3 align-end">
            <AppTextField v-model="search" :label="t('policy_index.search')" density="comfortable" hide-details style="max-width: 280px" />

            <AppSelect
              v-model="status"
              :items="statuses"
              :label="t('policy_index.status')"
              density="comfortable"
              hide-details
              clearable
              style="max-width: 220px"
            />

            <AppSelect
              v-model="categoryId"
              :items="categories"
              item-title="name"
              item-value="id"
              :label="t('policy_index.category')"
              density="comfortable"
              hide-details
              clearable
              style="max-width: 240px"
            />

            <AppSelect
              v-for="(level, levelIndex) in filterOrgLevels"
              :key="`filter-org-level-${levelIndex}`"
              v-model="level.selectedId"
              :items="level.children"
              item-title="name"
              item-value="id"
              :label="levelLabel(level)"
              density="comfortable"
              hide-details
              clearable
              style="max-width: 240px"
              @update:model-value="value => onFilterOrgLevelSelect(levelIndex, (value as number | null) ?? null)"
            />

            <VBtn color="primary" @click="applyFilters">{{ t('policy_index.apply') }}</VBtn>
            <VBtn variant="text" @click="resetFilters">{{ t('policy_index.reset') }}</VBtn>
          </div>
        </VCardText>
      </VCard>

      <VCard elevation="0" border>
        <VDataTable :headers="[
          { title: t('policy_index.title'), key: 'name' },
          { title: t('policy_index.organisation_scope'), key: 'department_id' },
          { title: t('policy_index.owner'), key: 'owner' },
          { title: t('policy_index.status'), key: 'status' },
          { title: t('policy_index.policy_ui'), key: 'policy_ui', sortable: false },
          { title: t('policy_index.effective'), key: 'effective_date' },
          { title: t('policy_index.review'), key: 'review_date' },
          { title: t('policy_index.versions'), key: 'versions_count' },
          { title: t('policy_index.actions'), key: 'actions', sortable: false, align: 'center' },
        ]" :items="rows" item-value="id" hover>
          <template #item.name="{ item }">
            <div>
              <p class="mb-0 font-weight-medium">{{ item.name }}</p>
              <p class="mb-0 text-caption text-medium-emphasis">{{ item.description || t('policy_index.no_description') }}</p>
            </div>
          </template>

          <template #item.owner="{ item }">
            {{ item.owner?.name || '-' }}
          </template>

          <template #item.department_id="{ item }">
            {{ organizationLabel(item) }}
          </template>

          <template #item.status="{ item }">
            <VChip :color="statusColor(item.status)" size="small" variant="tonal" label>
              {{ item.status }}
            </VChip>
          </template>

          <template #item.policy_ui="{ item }">
            <div class="d-flex gap-1 flex-wrap">
              <VBtn size="x-small" variant="tonal" color="primary" @click="goToView(item.uuid)">
                {{ t('policy_index.detail') }}
              </VBtn>
              <VBtn size="x-small" variant="tonal" color="secondary" @click="goToClauseView(item.uuid)">
                {{ t('policy_index.clause') }}
              </VBtn>
              <VBtn size="x-small" variant="tonal" color="info" @click="goToRuleView(item.uuid)">
                {{ t('policy_index.rule') }}
              </VBtn>
            </div>
          </template>

          <template #item.effective_date="{ item }">{{ formatDate(item.effective_date) }}</template>
          <template #item.review_date="{ item }">{{ formatDate(item.review_date) }}</template>
          <template #item.versions_count="{ item }">{{ item.versions_count ?? 0 }}</template>

          <template #item.actions="{ item }">
            <div class="d-flex justify-center">
              <VMenu location="bottom end">
                <template #activator="{ props: menuProps }">
                  <VBtn v-bind="menuProps" icon size="small" variant="text">
                    <i class="fa-solid fa-ellipsis-vertical" />
                  </VBtn>
                </template>

                <VList density="compact" min-width="190">
                  <VListItem @click="goToView(item.uuid)">
                    <template #prepend>
                      <i class="fa-solid fa-eye me-2" style="font-size:13px;color:rgb(var(--v-theme-primary))" />
                    </template>
                    <template #title>
                      <span>{{ t('policy_index.view') }}</span>
                    </template>
                  </VListItem>

                  <VListItem @click="openEditPolicyModalById(item)">
                    <template #prepend>
                      <i class="fa-solid fa-pen me-2" style="font-size:13px;color:rgb(var(--v-theme-primary))" />
                    </template>
                    <template #title>
                      <span>{{ t('policy_index.edit') }}</span>
                    </template>
                  </VListItem>

                  <VDivider />

                  <VListItem @click="togglePolicyStatus(item)">
                    <template #prepend>
                      <i
                        :class="['fa-solid me-2', item.status === 'active' ? 'fa-toggle-off' : 'fa-toggle-on']"
                        :style="{ fontSize: '13px', color: item.status === 'active' ? '#64748b' : '#22c55e' }"
                      />
                    </template>
                    <template #title>
                      <span :style="{ color: item.status === 'active' ? '#64748b' : '#22c55e' }" class="font-weight-medium">
                        {{ item.status === 'active' ? t('policy_index.deactivate') : t('policy_index.activate') }}
                      </span>
                    </template>
                  </VListItem>

                  <VDivider />

                  <VListItem @click="confirmDelete(item)">
                    <template #prepend>
                      <i class="fa-solid fa-trash me-2" style="font-size:13px;color:rgb(var(--v-theme-error))" />
                    </template>
                    <template #title>
                      <span style="color:rgb(var(--v-theme-error))">{{ t('policy_index.delete') }}</span>
                    </template>
                  </VListItem>
                </VList>
              </VMenu>
            </div>
          </template>

          <template #no-data>
            <div class="py-10 text-center text-medium-emphasis">
              {{ t('policy_index.no_policies_found') }}
            </div>
          </template>
        </VDataTable>

        <VCardText v-if="policies?.links?.length" class="pt-0">
          <div class="d-flex flex-wrap gap-2">
            <template v-for="(link, idx) in policies.links" :key="idx">
              <VBtn
                v-if="link.url"
                size="small"
                :variant="link.active ? 'flat' : 'text'"
                :color="link.active ? 'primary' : undefined"
                @click="router.visit(link.url, { preserveState: true, preserveScroll: true })"
                v-html="link.label"
              />
            </template>
          </div>
        </VCardText>
      </VCard>

      <VDialog v-model="policyDialog" max-width="980" scrollable persistent>
        <VCard elevation="0">
          <div class="px-6 py-5 border-b">
            <div class="d-flex align-center justify-space-between gap-3">
              <div class="d-flex align-center gap-3">
                <div class="d-flex align-center justify-center rounded-lg" style="width:40px;height:40px;background:#eef2ff;">
                  <i class="fa-solid fa-file-shield" style="color:#4f46e5;font-size:16px" />
                </div>
                <div>
                  <p class="text-h6 font-weight-semibold mb-0">{{ isEditingPolicy ? t('policy_index.edit_policy') : t('policy_index.create_policy') }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ t('policy_index.fill_in_policy_details_below') }}</p>
                </div>
              </div>
              <VBtn icon variant="text" @click="closePolicyDialog">
                <i class="fa-solid fa-xmark" />
              </VBtn>
            </div>
          </div>

          <div class="px-6 py-5" style="max-height:72vh;overflow-y:auto;">
            <VRow>
              <VCol cols="12" md="6">
                <AppTextField v-model="policyForm.name" :label="t('policy_index.title_required')" :error-messages="policyForm.errors.name" />
              </VCol>
              <VCol cols="12" md="6">
                <AppSelect
                  v-model="policyForm.category_id"
                  :items="categories"
                  item-title="name"
                  item-value="id"
                  :label="t('policy_index.category_required')"
                  :error-messages="policyForm.errors.category_id"
                />
              </VCol>

              <VCol cols="12" md="6">
                <AppSelect
                  v-model="policyForm.owner_id"
                  :items="owners"
                  item-title="name"
                  item-value="id"
                  :label="t('policy_index.owner_required')"
                  :error-messages="policyForm.errors.owner_id"
                />
              </VCol>

              <VCol cols="12" md="6">
                <AppSelect
                  v-model="policyForm.reviewer_ids"
                  :items="reviewers"
                  item-title="name"
                  item-value="id"
                  :label="t('policy_index.reviewers')"
                  chips
                  multiple
                  clearable
                  :error-messages="policyForm.errors.reviewer_ids"
                />
              </VCol>

              <VCol cols="12">
                <div class="org-block mb-1">
                  <p class="org-block-label">{{ t('policy_index.organization') }}</p>

                  <template v-if="orgLevels.length">
                    <div
                      v-for="(level, levelIndex) in orgLevels"
                      :key="`org-level-${levelIndex}`"
                      class="mb-3"
                    >
                      <div class="d-flex align-center gap-2 mb-2">
                        <i
                          v-if="levelIndex > 0"
                          class="fa-solid fa-arrow-turn-down-right"
                          style="font-size:10px;color:#94a3b8;margin-left:4px"
                        />
                        <div
                          class="org-level-badge"
                          :style="{
                            background: getTypeConfig(level.children[0]?.type).bg,
                            color: getTypeConfig(level.children[0]?.type).color,
                          }"
                        >
                          <i
                            :class="`fa-solid ${getTypeConfig(level.children[0]?.type).icon}`"
                            style="font-size:10px"
                          />
                          {{ levelLabel(level) }}
                        </div>
                      </div>

                      <AppSelect
                        v-model="level.selectedId"
                        :items="level.children"
                        item-title="name"
                        item-value="id"
                        :label="t('policy_index.select_level', { level: levelLabel(level) })"
                        :prepend-inner-icon="getTypeConfig(level.children[0]?.type).mdiIcon"
                        clearable
                        :error-messages="levelIndex === 0 ? policyForm.errors.department_id : undefined"
                        @update:model-value="value => onOrgLevelSelect(levelIndex, (value as number | null) ?? null)"
                      />
                    </div>
                  </template>

                  <p v-else class="text-caption text-medium-emphasis text-center py-2 mb-0">
                    {{ t('policy_index.no_organization_units_available') }}
                  </p>
                </div>
              </VCol>

              <VCol cols="12" md="6">
                <AppSelect v-model="policyForm.status" :items="statuses" :label="t('policy_index.status_required')" :error-messages="policyForm.errors.status" />
              </VCol>

              <VCol cols="12" md="6">
                <AppTextField v-model="policyForm.effective_date" type="date" :label="t('policy_index.effective_date_required')" :error-messages="policyForm.errors.effective_date" />
              </VCol>

              <VCol cols="12" md="6">
                <AppTextField v-model="policyForm.review_date" type="date" :label="t('policy_index.review_date')" :error-messages="policyForm.errors.review_date" />
              </VCol>

              <VCol cols="12" md="6">
                <AppTextField
                  v-model="tagsInput"
                  :label="t('policy_index.tags_comma_separated')"
                  :hint="t('policy_index.tags_example_hint')"
                  persistent-hint
                  :error-messages="policyForm.errors.tags"
                />
              </VCol>

              <VCol cols="12" md="6">
                <AppFileInput
                  :label="t('policy_index.policy_document_pdf_word')"
                  accept=".pdf,.doc,.docx"
                  prepend-icon="tabler-file-upload"
                  @update:model-value="onFileChanged"
                  :error-messages="policyForm.errors.document"
                />
              </VCol>

              <VCol cols="12" v-if="isEditingPolicy">
                <AppTextarea
                  v-model="policyForm.change_summary"
                  :label="t('policy_index.change_summary')"
                  rows="2"
                  :hint="t('policy_index.change_summary_hint')"
                  persistent-hint
                  :error-messages="policyForm.errors.change_summary"
                />
              </VCol>

              <VCol cols="12">
                <VCheckbox v-model="policyForm.requires_attestation" :label="t('policy_index.requires_attestation')" />
              </VCol>

              <VCol cols="12">
                <AppTextarea v-model="policyForm.description" :label="t('policy_index.description')" rows="4" :error-messages="policyForm.errors.description" />
              </VCol>
            </VRow>
          </div>

          <div class="d-flex gap-3 px-6 pb-5 pt-3 border-t">
            <VBtn variant="tonal" class="flex-grow-1" @click="closePolicyDialog">
              <i class="fa-solid fa-xmark me-2" />
              {{ t('policy_index.cancel') }}
            </VBtn>
            <VBtn color="success" class="flex-grow-1" :loading="policyForm.processing" @click="submitPolicyForm">
              <i class="fa-solid fa-floppy-disk me-2" />
              {{ isEditingPolicy ? t('policy_index.save_changes') : t('policy_index.create_policy') }}
            </VBtn>
          </div>
        </VCard>
      </VDialog>

      <VDialog v-model="deleteDialog" max-width="420" persistent>
        <VCard elevation="0">
          <div class="px-6 py-5">
            <div class="d-flex align-center gap-3 mb-3">
              <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:44px;height:44px;background:#fef2f2;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;font-size:18px" />
              </div>
              <div>
                <p class="text-body-1 font-weight-semibold mb-0">{{ t('policy_index.delete_policy') }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ t('policy_index.this_action_cannot_be_undone') }}</p>
              </div>
            </div>
            <p class="text-body-2 text-medium-emphasis">
              {{ t('policy_index.are_you_sure_you_want_to_delete') }}
              <strong>{{ getPolicyName(policyToDelete) }}</strong> ?
            </p>
          </div>
          <div class="d-flex gap-3 px-6 pb-5">
            <VBtn variant="tonal" class="flex-grow-1" @click="deleteDialog = false">{{ t('policy_index.cancel') }}</VBtn>
            <VBtn color="error" class="flex-grow-1" @click="doDelete">
              <i class="fa-solid fa-trash me-2" />
              {{ t('policy_index.delete') }}
            </VBtn>
          </div>
        </VCard>
      </VDialog>
    </VContainer>
  </section>
</template>

<style scoped>
.org-block {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  background: #fafbff;
  padding: 14px;
}

.org-block-label {
  margin-bottom: 10px;
  color: #64748b;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}

.org-level-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 12px;
  font-weight: 600;
}
</style>
