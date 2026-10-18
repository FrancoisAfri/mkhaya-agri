<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { VDataTable } from 'vuetify/components'

const { t } = useI18n()

const props = defineProps<{
  recommendations: any
  employees: any[]
  plans: any[]
  types: Record<string, string>
  filters: Record<string, any>
}>()

const tab            = ref('recommendations')
const generateDialog = ref(false)
const generating     = ref(false)
const snackbar       = ref(false)
const snackbarText   = ref('')

const filterEmployee = ref(props.filters.user_id ?? null)
const filterType     = ref(props.filters.type ?? null)
const filterStatus   = ref(props.filters.status ?? null)

const genForm = ref({ user_id: null as number | null, type: 'all' })

const typeOptions = [
  { value: null,          label: t('lms_admin_aiRecomandation.recommendations.all_types') },
  { value: 'job_title',   label: t('lms_admin_aiRecomandation.types.job_title') },
  { value: 'performance_gap', label: t('lms_admin_aiRecomandation.types.performance_gap') },
  { value: 'failed_topics',   label: t('lms_admin_aiRecomandation.types.failed_topics') },
  { value: 'career_path',     label: t('lms_admin_aiRecomandation.types.career_path') },
  { value: 'policy_requirement', label: t('lms_admin_aiRecomandation.types.policy_requirement') },
  { value: 'refresher',   label: t('lms_admin_aiRecomandation.types.refresher') },
]

const genTypeOptions = [
  { value: 'all',           label: t('lms_admin_aiRecomandation.dialog.type_comprehensive') },
  { value: 'job_title',     label: t('lms_admin_aiRecomandation.dialog.type_job_title') },
  { value: 'performance_gap', label: t('lms_admin_aiRecomandation.dialog.type_performance_gap') },
  { value: 'failed_topics', label: t('lms_admin_aiRecomandation.dialog.type_failed_topics') },
  { value: 'learning_plan', label: t('lms_admin_aiRecomandation.dialog.type_learning_plan') },
]

const statusOptions = [
  { value: null,       label: t('lms_admin_aiRecomandation.recommendations.all_statuses') },
  { value: 'pending',  label: t('lms_admin_aiRecomandation.statuses.pending') },
  { value: 'accepted', label: t('lms_admin_aiRecomandation.statuses.accepted') },
  { value: 'dismissed',label: t('lms_admin_aiRecomandation.statuses.dismissed') },
]

type DataTableHeader = NonNullable<InstanceType<typeof VDataTable>['$props']['headers']>[number]

const recHeaders: DataTableHeader[] = [
  { title: t('lms_admin_aiRecomandation.recommendations.table.employee'), key: 'learner_name' },
  { title: t('lms_admin_aiRecomandation.recommendations.table.course'),   key: 'course.title' },
  { title: t('lms_admin_aiRecomandation.recommendations.table.type'),     key: 'recommendation_type' },
  { title: t('lms_admin_aiRecomandation.recommendations.table.reason'),   key: 'reason',    sortable: false },
  { title: t('lms_admin_aiRecomandation.recommendations.table.priority'), key: 'priority',  sortable: true },
  { title: t('lms_admin_aiRecomandation.recommendations.table.status'),   key: 'status',    sortable: true },
  { title: '', key: 'actions', sortable: false, align: 'end' },
]

function learnerName(item: any) {
  const p = item.user?.person
  return p ? `${p.first_name} ${p.surname}` : item.user?.email ?? '—'
}

function typeColor(t: string) {
  const map: Record<string, string> = {
    job_title: 'primary', performance_gap: 'warning', failed_topics: 'error',
    career_path: 'info', policy_requirement: 'deep-purple', refresher: 'teal',
  }
  return map[t] ?? 'grey'
}

function priorityColor(p: number) {
  return p <= 1 ? 'error' : p <= 2 ? 'warning' : p <= 3 ? 'info' : 'grey'
}

function statusColor(s: string) {
  return s === 'accepted' ? 'success' : s === 'dismissed' ? 'grey' : 'primary'
}

function planStatusColor(s: string) {
  return s === 'completed' ? 'success' : s === 'active' ? 'primary' : s === 'archived' ? 'grey' : 'warning'
}

function applyFilters() {
  router.get(
    route('lms.admin.ai-recommendations'),
    { user_id: filterEmployee.value, type: filterType.value, status: filterStatus.value },
    { preserveState: true, replace: true }
  )
}

function goPage(page: number) {
  router.get(route('lms.admin.ai-recommendations'), { ...props.filters, page }, { preserveState: true })
}

function setStatus(item: any, status: string) {
  router.patch(
    route('lms.admin.ai-recommendations.admin.status', item.id),
    { status },
    {
      onSuccess: () => { snackbarText.value = t('lms_admin_aiRecomandation.toast.status_updated'); snackbar.value = true },
      preserveScroll: true,
    }
  )
}

function deleteRec(item: any) {
  router.delete(
    route('lms.admin.ai-recommendations.destroy', item.id),
    {
      onSuccess: () => { snackbarText.value = t('lms_admin_aiRecomandation.toast.recommendation_removed'); snackbar.value = true },
      preserveScroll: true,
    }
  )
}

function submitGenerate() {
  generating.value = true
  router.post(
    route('lms.admin.ai-recommendations.generate'),
    genForm.value,
    {
      onSuccess: () => {
        snackbarText.value = t('lms_admin_aiRecomandation.toast.analysis_complete')
        snackbar.value     = true
        generateDialog.value = false
        genForm.value = { user_id: null, type: 'all' }
      },
      onFinish: () => { generating.value = false },
      preserveScroll: true,
    }
  )
}
</script>

<template>
  <div class="p-4">
    <!-- Header -->
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <div>
        <div class="text-h6">{{ t('lms_admin_aiRecomandation.title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('lms_admin_aiRecomandation.subtitle') }}</div>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="mdi-robot" @click="generateDialog = true">
        {{ t('lms_admin_aiRecomandation.generate_button') }}
      </VBtn>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="recommendations">{{ t('lms_admin_aiRecomandation.tabs.recommendations') }}</VTab>
      <VTab value="plans">{{ t('lms_admin_aiRecomandation.tabs.plans') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- ── Recommendations tab ───────────────────────────────────────── -->
      <VWindowItem value="recommendations">
        <VCard>
          <VCardTitle class="d-flex align-center pa-4 flex-wrap gap-3">
            <span class="text-subtitle-1">{{ t('lms_admin_aiRecomandation.recommendations.title') }}</span>
            <VSpacer />
            <VSelect
              v-model="filterEmployee"
              :items="[{ id: null, name: t('lms_admin_aiRecomandation.recommendations.all_employees') }, ...employees]"
              item-title="name"
              item-value="id"
              density="compact"
              hide-details
              style="max-width: 220px;"
              @update:model-value="applyFilters"
            />
            <VSelect
              v-model="filterType"
              :items="typeOptions"
              item-title="label"
              item-value="value"
              density="compact"
              hide-details
              style="max-width: 200px;"
              @update:model-value="applyFilters"
            />
            <VSelect
              v-model="filterStatus"
              :items="statusOptions"
              item-title="label"
              item-value="value"
              density="compact"
              hide-details
              style="max-width: 160px;"
              @update:model-value="applyFilters"
            />
          </VCardTitle>

          <VDataTable
            :headers="recHeaders"
            :items="(recommendations.data as any[])"
            hide-default-footer
          >
            <template #item.learner_name="{ item }: { item: any }">
              <span class="font-weight-medium">{{ learnerName(item) }}</span>
            </template>

            <template #item.recommendation_type="{ item }: { item: any }">
              <VChip :color="typeColor(item.recommendation_type)" size="small" label>
                {{ types[item.recommendation_type] ?? item.recommendation_type }}
              </VChip>
            </template>

            <template #item.priority="{ item }: { item: any }">
              <VChip :color="priorityColor(item.priority)" size="small" variant="tonal">
                P{{ item.priority }}
              </VChip>
            </template>

            <template #item.status="{ item }: { item: any }">
              <VChip :color="statusColor(item.status)" size="small">{{ item.status }}</VChip>
            </template>

            <template #item.actions="{ item }: { item: any }">
              <div class="d-flex gap-1">
                <VBtn
                  v-if="item.status === 'pending'"
                  icon size="x-small" variant="text" color="success"
                  @click="setStatus(item, 'accepted')"
                >
                  <VIcon size="16">mdi-check</VIcon>
                  <VTooltip activator="parent">{{ t('lms_admin_aiRecomandation.recommendations.table.accept') }}</VTooltip>
                </VBtn>
                <VBtn
                  v-if="item.status === 'pending'"
                  icon size="x-small" variant="text" color="warning"
                  @click="setStatus(item, 'dismissed')"
                >
                  <VIcon size="16">mdi-close</VIcon>
                  <VTooltip activator="parent">{{ t('lms_admin_aiRecomandation.recommendations.table.dismiss') }}</VTooltip>
                </VBtn>
                <VBtn
                  icon size="x-small" variant="text" color="error"
                  @click="deleteRec(item)"
                >
                  <VIcon size="16">mdi-delete</VIcon>
                  <VTooltip activator="parent">{{ t('lms_admin_aiRecomandation.recommendations.table.delete') }}</VTooltip>
                </VBtn>
              </div>
            </template>
          </VDataTable>

          <div v-if="recommendations.last_page > 1" class="d-flex justify-center pa-4">
            <VPagination
              :model-value="recommendations.current_page"
              :length="recommendations.last_page"
              density="compact"
              @update:model-value="goPage"
            />
          </div>
        </VCard>
      </VWindowItem>

      <!-- ── Learning Plans tab ────────────────────────────────────────── -->
      <VWindowItem value="plans">
        <VCard>
          <VCardTitle class="pa-4">
            <span class="text-subtitle-1">{{ t('lms_admin_aiRecomandation.plans.title') }}</span>
          </VCardTitle>

          <div v-if="plans.length === 0" class="text-center text-medium-emphasis py-8">
            {{ t('lms_admin_aiRecomandation.plans.empty') }}
          </div>

          <VList v-else lines="two">
            <VListItem
              v-for="plan in plans"
              :key="plan.id"
              rounded="lg"
              class="mx-2 mb-2"
            >
              <template #prepend>
                <VAvatar :color="plan.ai_generated ? 'primary' : 'grey'" variant="tonal" size="40">
                  <VIcon>{{ plan.ai_generated ? 'mdi-robot' : 'mdi-clipboard-list' }}</VIcon>
                </VAvatar>
              </template>

              <VListItemTitle class="font-weight-medium">{{ plan.title }}</VListItemTitle>
              <VListItemSubtitle>
                {{ plan.learner_name }} ·
                {{ t('lms_admin_aiRecomandation.plans.items_count', { count: plan.items_count }) }} ·
                {{ t('lms_admin_aiRecomandation.plans.due') }} {{ plan.target_completion_date ?? t('lms_admin_aiRecomandation.plans.no_date') }}
              </VListItemSubtitle>

              <template #append>
                <div class="d-flex align-center gap-2">
                  <VProgressLinear
                    :model-value="plan.completion_pct"
                    color="primary"
                    rounded
                    height="4"
                    style="width: 80px"
                  />
                  <span class="text-caption">{{ plan.completion_pct }}%</span>
                  <VChip :color="planStatusColor(plan.status)" size="x-small">{{ plan.status }}</VChip>
                </div>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- ── Generate dialog ─────────────────────────────────────────────── -->
    <VDialog v-model="generateDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_admin_aiRecomandation.dialog.generate_title') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="genForm.user_id"
            :items="employees"
            item-title="name"
            item-value="id"
            :label="t('lms_admin_aiRecomandation.dialog.employee_label')"
            density="compact"
          />
          <VSelect
            v-model="genForm.type"
            :items="genTypeOptions"
            item-title="label"
            item-value="value"
            :label="t('lms_admin_aiRecomandation.dialog.type_label')"
            density="compact"
            class="mt-3"
          />
          <VAlert type="info" variant="tonal" density="compact" class="mt-3">
            {{ t('lms_admin_aiRecomandation.dialog.alert') }}
          </VAlert>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="generateDialog = false">{{ t('lms_admin_aiRecomandation.dialog.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :loading="generating"
            :disabled="!genForm.user_id || !genForm.type"
            @click="submitGenerate"
          >
            {{ t('lms_admin_aiRecomandation.dialog.generate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar" :timeout="2500" color="success" location="bottom right">
      {{ snackbarText }}
    </VSnackbar>
  </div>
</template>
