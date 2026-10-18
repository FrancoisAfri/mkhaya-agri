<template>
  <div class="p-4">
    <div class="d-flex align-center gap-3 mb-4">
      <div>
        <div class="text-h6">{{ t('lms_learning_plan.page_title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('lms_learning_plan.page_description') }}</div>
      </div>
    </div>

    <VRow>
      <!-- ── Left: Active learning plan ────────────────────────────────── -->
      <VCol cols="12" md="8">
        <!-- No plan state -->
        <VCard v-if="!plan" class="mb-4">
          <VCardText class="text-center py-10">
            <VIcon size="64" color="grey" class="mb-3">mdi-robot-outline</VIcon>
            <div class="text-h6 mb-2">{{ t('lms_learning_plan.no_plan_title') }}</div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              {{ t('lms_learning_plan.no_plan_message') }}
            </div>
          </VCardText>
        </VCard>

        <!-- Active plan -->
        <template v-else>
          <VCard class="mb-4">
            <VCardTitle class="d-flex align-center pa-4 pb-2">
              <VIcon color="primary" class="mr-2">mdi-robot</VIcon>
              <div>
                <div class="text-subtitle-1 font-weight-bold">{{ plan.title }}</div>
                <div class="text-caption text-medium-emphasis" v-if="plan.description">{{ plan.description }}</div>
              </div>
              <VSpacer />
              <VChip :color="planStatusColor(plan.status)" size="small">
                {{ t(`lms_learning_plan.plan_statuses.${plan.status}`) }}
              </VChip>
            </VCardTitle>

            <VCardText>
              <!-- Progress bar -->
              <div class="d-flex align-center gap-3 mb-4">
                <VProgressLinear
                  :model-value="plan.completion_pct"
                  color="primary"
                  rounded
                  height="8"
                  class="flex-grow-1"
                />
                <span class="text-body-2 font-weight-medium" style="white-space: nowrap">
                  {{ t('lms_learning_plan.complete', { pct: plan.completion_pct }) }}
                </span>
              </div>
              <div v-if="plan.target_completion_date" class="text-caption text-medium-emphasis mb-3">
                {{ t('lms_learning_plan.target_date', { date: plan.target_completion_date }) }}
              </div>

              <!-- Plan items -->
              <VList lines="two" density="compact">
                <VListItem
                  v-for="item in plan.items"
                  :key="item.id"
                  rounded="lg"
                  class="mb-1 px-2"
                  :class="{ 'opacity-50': item.status === 'skipped' }"
                >
                  <template #prepend>
                    <VAvatar :color="itemStatusColor(item.status)" variant="tonal" size="32">
                      <VIcon size="16">{{ itemIcon(item.item_type) }}</VIcon>
                    </VAvatar>
                  </template>

                  <VListItemTitle class="text-body-2 font-weight-medium">
                    {{ item.title }}
                    <VChip :color="priorityColor(item.priority)" size="x-small" class="ml-1" variant="tonal">
                      {{ t('lms_learning_plan.priority_short', { priority: item.priority }) }}
                    </VChip>
                  </VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    <span v-if="item.reason">{{ item.reason }}</span>
                    <span v-if="item.due_date" class="ml-2">· {{ t('lms_learning_plan.target_date', { date: item.due_date }) }}</span>
                  </VListItemSubtitle>

                  <template #append>
                    <VMenu>
                      <template #activator="{ props: menuProps }">
                        <VBtn icon size="x-small" variant="text" v-bind="menuProps">
                          <VIcon size="16">mdi-dots-vertical</VIcon>
                        </VBtn>
                      </template>
                      <VList density="compact">
                        <VListItem
                          v-for="s in itemStatuses"
                          :key="s.value"
                          :value="s.value"
                          @click="updateItemStatus(item.id, s.value)"
                        >
                          <template #prepend>
                            <VIcon size="16" :color="s.color">{{ s.icon }}</VIcon>
                          </template>
                          <VListItemTitle>{{ s.label }}</VListItemTitle>
                        </VListItem>
                        <VDivider />
                        <VListItem @click="openCourse(item.course_id)" v-if="item.course_id">
                          <template #prepend><VIcon size="16">mdi-open-in-new</VIcon></template>
                          <VListItemTitle>{{ t('lms_learning_plan.go_to_course') }}</VListItemTitle>
                        </VListItem>
                      </VList>
                    </VMenu>
                  </template>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </template>

        <!-- Revision plans -->
        <template v-if="revisionPlans.length">
          <div class="text-subtitle-2 mb-2">{{ t('lms_learning_plan.revision_plans') }}</div>
          <VCard
            v-for="rp in revisionPlans"
            :key="rp.id"
            class="mb-3"
          >
            <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
              <VIcon color="orange">mdi-refresh</VIcon>
              <span class="text-body-1 font-weight-medium">{{ rp.course_title }} — {{ t('lms_learning_plan.item_types.revision') }}</span>
              <VSpacer />
              <span v-if="rp.estimated_hours" class="text-caption text-medium-emphasis">
                {{ t('lms_learning_plan.estimated_hours', { hours: rp.estimated_hours }) }}
              </span>
            </VCardTitle>
            <VCardText>
              <!-- Topic areas -->
              <div v-if="rp.topic_areas.length" class="mb-3">
                <div class="text-caption font-weight-medium mb-1">{{ t('lms_learning_plan.topic_revise') }}</div>
                <div class="d-flex flex-wrap gap-1">
                  <VChip v-for="t in rp.topic_areas" :key="t" size="x-small" color="orange" variant="tonal">{{ t }}</VChip>
                </div>
              </div>
              <!-- AI suggestions -->
              <VList density="compact" v-if="rp.ai_suggestions.length">
                <VListItem
                  v-for="(s, i) in rp.ai_suggestions"
                  :key="i"
                  rounded="lg"
                  class="mb-1 px-1"
                >
                  <template #prepend>
                    <VAvatar color="warning" variant="tonal" size="28">
                      <span class="text-caption font-weight-bold">{{ s.priority }}</span>
                    </VAvatar>
                  </template>
                  <VListItemTitle class="text-body-2">{{ s.activity }}</VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    {{ s.topic }}
                    <span v-if="s.estimated_minutes"> · {{ t('lms_learning_plan.estimated_minutes', { minutes: s.estimated_minutes }) }}</span>
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </template>
      </VCol>

      <!-- ── Right: AI Recommendations ──────────────────────────────────── -->
      <VCol cols="12" md="4">
        <VCard>
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
            <VIcon color="primary">mdi-lightbulb-on</VIcon>
            <span class="text-subtitle-1">{{ t('lms_learning_plan.ai_recommendations') }}</span>
            <VChip v-if="recommendations.length" size="x-small" color="primary">{{ recommendations.length }}</VChip>
          </VCardTitle>

          <div v-if="recommendations.length === 0" class="text-center text-medium-emphasis py-6 text-body-2">
            {{ t('lms_learning_plan.no_recommendations') }}
          </div>

          <VList v-else density="compact">
            <VListItem
              v-for="r in recommendations"
              :key="r.id"
              rounded="lg"
              class="mx-2 mb-2"
            >
              <template #prepend>
                <VAvatar :color="recTypeColor(r.recommendation_type)" variant="tonal" size="32">
                  <VIcon size="16">{{ recTypeIcon(r.recommendation_type) }}</VIcon>
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium text-wrap">
                {{ r.course_title ?? t('lms_learning_plan.general_recommendation') }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption text-wrap">{{ r.reason }}</VListItemSubtitle>
              <template #append>
                <div class="d-flex flex-column gap-1 align-end">
                  <VChip :color="recTypeColor(r.recommendation_type)" size="x-small" label>
                    {{ t(`lms_learning_plan.recommendation_types.${r.recommendation_type}`) }}
                  </VChip>
                  <div class="d-flex gap-1">
                    <VBtn
                      icon size="x-small" variant="text" color="success"
                      @click="acceptRec(r.id)"
                    >
                      <VIcon size="14">mdi-check</VIcon>
                      <VTooltip activator="parent">{{ t('lms_learning_plan.actions.accept') }}</VTooltip>
                    </VBtn>
                    <VBtn
                      icon size="x-small" variant="text" color="grey"
                      @click="dismissRec(r.id)"
                    >
                      <VIcon size="14">mdi-close</VIcon>
                      <VTooltip activator="parent">{{ t('lms_learning_plan.actions.dismiss') }}</VTooltip>
                    </VBtn>
                  </div>
                </div>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VSnackbar v-model="snackbar" :timeout="2500" color="success" location="bottom right">
      {{ snackbarText }}
    </VSnackbar>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  plan: any | null
  revisionPlans: any[]
  recommendations: any[]
}>()

const snackbar = ref(false)
const snackbarText = ref('')

const itemStatuses = computed(() => [
  { value: 'pending',     label: t('lms_learning_plan.actions.mark_pending'),     icon: 'mdi-clock-outline',    color: 'grey' },
  { value: 'in_progress', label: t('lms_learning_plan.actions.mark_in_progress'), icon: 'mdi-progress-clock',   color: 'primary' },
  { value: 'completed',   label: t('lms_learning_plan.actions.mark_completed'),   icon: 'mdi-check-circle',     color: 'success' },
  { value: 'skipped',     label: t('lms_learning_plan.actions.skip'),             icon: 'mdi-skip-next-circle', color: 'warning' },
])

function planStatusColor(s: string) {
  return s === 'completed' ? 'success' : s === 'active' ? 'primary' : 'grey'
}

function itemStatusColor(s: string) {
  return s === 'completed' ? 'success' : s === 'in_progress' ? 'primary' : s === 'skipped' ? 'grey' : 'default'
}

function itemIcon(type: string) {
  const map: Record<string, string> = {
    course: 'mdi-book-open', assessment: 'mdi-file-question', revision: 'mdi-refresh',
    reading: 'mdi-text-box', practice: 'mdi-dumbbell',
  }
  return map[type] ?? 'mdi-circle-small'
}

function priorityColor(p: number) {
  return p <= 1 ? 'error' : p <= 2 ? 'warning' : p <= 3 ? 'info' : 'grey'
}

function recTypeColor(t: string) {
  const map: Record<string, string> = {
    job_title: 'primary', performance_gap: 'warning', failed_topics: 'error',
    career_path: 'info', policy_requirement: 'deep-purple', refresher: 'teal',
  }
  return map[t] ?? 'grey'
}

function recTypeIcon(t: string) {
  const map: Record<string, string> = {
    job_title: 'mdi-briefcase', performance_gap: 'mdi-chart-line-variant',
    failed_topics: 'mdi-alert-circle', career_path: 'mdi-ladder',
    policy_requirement: 'mdi-shield-check', refresher: 'mdi-refresh',
  }
  return map[t] ?? 'mdi-lightbulb'
}

function updateItemStatus(itemId: number, status: string) {
  router.patch(
    route('lms.learning-plan-items.status', itemId),
    { status },
    {
      onSuccess: () => {
        snackbarText.value = t('lms_learning_plan.snackbar.progress_saved')
        snackbar.value = true
      },
      preserveScroll: true,
    }
  )
}

function openCourse(courseId: number | null) {
  if (courseId) router.visit(route('lms.courses.show', courseId))
}

function acceptRec(id: number) {
  router.patch(
    route('lms.ai-recommendations.status', id),
    { status: 'accepted' },
    {
      onSuccess: () => {
        snackbarText.value = t('lms_learning_plan.snackbar.recommendation_accepted')
        snackbar.value = true
      },
      preserveScroll: true,
    }
  )
}

function dismissRec(id: number) {
  router.patch(
    route('lms.ai-recommendations.status', id),
    { status: 'dismissed' },
    {
      onSuccess: () => {
        snackbarText.value = t('lms_learning_plan.snackbar.recommendation_dismissed')
        snackbar.value = true
      },
      preserveScroll: true,
    }
  )
}
</script>
