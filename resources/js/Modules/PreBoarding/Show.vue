<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Task = {
  id: number
  type: { value: string; label: string; icon: string }
  title: string
  description: string | null
  status: { value: string; label: string }
  due_date: string | null
  completed_at: string | null
  sort_order: number
  owner_role: string | null
  assigned_to: { id: number; name: string } | null
}

type Plan = {
  id: number
  uuid: string
  status: { value: string; label: string; color: string }
  due_date: string | null
  completed_at: string | null
  tasks: Task[]
}

type Person = {
  id: number
  uuid: string
  status: { value: string; label: string; color: string }
  employee_number: string | null
  started_on: string | null
  department: string | null
  job_title: string | null
  buddy: { id: number; name: string } | null
  candidate: { id: number; full_name: string; email: string } | null
  pre_boarding_plans: Plan[]
}

const props = defineProps<{
  person: Person
  users: { id: number; name: string }[]
}>()

const activePlan     = ref<Plan | null>(props.person.pre_boarding_plans[0] ?? null)
const completingTask = ref<number | null>(null)

// ─── Start Onboarding dialog ──────────────────────────────────────────────────
const showOnboardingDialog = ref(false)
const onboardingForm = ref({ start_date: '', end_date: '' })
const startingOnboarding = ref(false)

function submitStartOnboarding() {
  startingOnboarding.value = true
  router.post(
    route('onboarding.plans.store', props.person.id),
    onboardingForm.value,
    {
      onFinish: () => { startingOnboarding.value = false },
      onError:  () => { startingOnboarding.value = false },
    },
  )
}

// ─── Buddy assignment ─────────────────────────────────────────────────────────
const buddyUserId  = ref<number | null>(props.person.buddy?.id ?? null)
const savingBuddy  = ref(false)

function saveBuddy() {
  savingBuddy.value = true
  router.patch(
    route('pre-boarding.buddy.assign', props.person.id),
    { buddy_user_id: buddyUserId.value },
    { preserveScroll: true, onFinish: () => { savingBuddy.value = false } },
  )
}

// ─── Details editing ──────────────────────────────────────────────────────────
const detailsForm  = ref({ department: props.person.department ?? '', job_title: props.person.job_title ?? '' })
const savingDetails = ref(false)

function saveDetails() {
  savingDetails.value = true
  router.patch(
    route('pre-boarding.details.update', props.person.id),
    detailsForm.value,
    { preserveScroll: true, onFinish: () => { savingDetails.value = false } },
  )
}

function taskStatusColor(status: string) {
  if (status === 'completed') return 'success'
  if (status === 'skipped')   return 'default'
  return 'warning'
}

function taskStatusIcon(status: string) {
  if (status === 'completed') return 'tabler-circle-check'
  if (status === 'skipped')   return 'tabler-circle-minus'
  return 'tabler-circle'
}

function ownerRoleColor(role: string | null) {
  if (role === 'hr')      return 'primary'
  if (role === 'it')      return 'info'
  if (role === 'finance') return 'warning'
  return 'default'
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function completeTask(task: Task, skip = false) {
  if (!activePlan.value) return
  completingTask.value = task.id
  router.post(
    route('pre-boarding.tasks.complete', [props.person.id, activePlan.value.id, task.id]),
    { skip: skip ? 1 : 0 },
    { preserveScroll: true, onFinish: () => { completingTask.value = null } },
  )
}

const completedCount = (plan: Plan) =>
  plan.tasks.filter(t => t.status.value !== 'pending').length

const planProgress = computed(() => {
  if (!activePlan.value || !activePlan.value.tasks.length) return 0
  return Math.round((completedCount(activePlan.value) / activePlan.value.tasks.length) * 100)
})

const { t } = useI18n()
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        size="small"
        @click="router.visit(route('pre-boarding.index'))"
      />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('pre-boarding.index'))"
      >
        {{ t('preboarding.title') }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">
        {{ person.candidate?.full_name ?? 'New Hire' }}
      </span>
    </div>

    <!-- Header -->
    <div class="d-flex align-start justify-space-between mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <h4 class="text-h4 font-weight-bold">
            {{ person.candidate?.full_name ?? 'New Hire' }}
          </h4>
          <VChip :color="person.status.color" size="small" label variant="tonal" class="font-weight-medium">
            {{ person.status.label }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0">
          {{ person.candidate?.email }}
        </p>
      </div>
      <VBtn
        v-if="person.status.value === 'pre_boarding'"
        color="info"
        variant="tonal"
        prepend-icon="tabler-school"
        @click="showOnboardingDialog = true"
      >
        {{ t('preboarding.start_onboarding') }}
      </VBtn>
    </div>

    <VRow>
      <!-- ── Sidebar: left 3 cols ───────────────────────────────────────── -->
      <VCol cols="12" lg="3">
        <div style="position: sticky; top: 80px;">
          <!-- Person Details -->
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              {{ t('preboarding.details') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-3">
                <div class="d-flex align-center gap-3">
                  <VAvatar size="52" color="primary" variant="tonal">
                    <span class="text-h6 font-weight-bold">
                      {{ (person.candidate?.full_name ?? '?')[0].toUpperCase() }}
                    </span>
                  </VAvatar>
                  <div>
                    <p class="text-body-2 font-weight-semibold mb-0">
                      {{ person.candidate?.full_name ?? '—' }}
                    </p>
                    <p class="text-caption text-medium-emphasis mb-0">
                      {{ person.candidate?.email ?? '' }}
                    </p>
                  </div>
                </div>
                <VDivider />
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.employee_number') }}</span>
                  <span class="text-body-2 font-weight-medium">
                    {{ person.employee_number ?? '—' }}
                  </span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.start_date') }}</span>
                  <span class="text-body-2">{{ formatDate(person.started_on) }}</span>
                </div>
                <div v-if="person.department" class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.department') }}</span>
                  <span class="text-body-2">{{ person.department }}</span>
                </div>
                <div v-if="person.job_title" class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.job_title') }}</span>
                  <span class="text-body-2">{{ person.job_title }}</span>
                </div>
              </div>
            </VCardText>
          </VCard>

          <!-- Buddy Assignment -->
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              {{ t('preboarding.buddy') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div v-if="person.buddy" class="d-flex align-center gap-2 mb-3">
                <VAvatar size="32" color="secondary" variant="tonal">
                  <span class="text-caption font-weight-bold">{{ person.buddy.name[0].toUpperCase() }}</span>
                </VAvatar>
                <span class="text-body-2 font-weight-medium">{{ person.buddy.name }}</span>
              </div>
              <p v-else class="text-caption text-medium-emphasis mb-3">{{ t('preboarding.no_buddy') }}</p>
              <VAutocomplete
                v-model="buddyUserId"
                :items="users"
                item-title="name"
                item-value="id"
                :label="t('preboarding.assign_buddy')"
                clearable
                hide-details
                density="compact"
                variant="outlined"
                class="mb-3"
              />
              <VBtn
                block
                color="primary"
                variant="tonal"
                size="small"
                :loading="savingBuddy"
                @click="saveBuddy"
              >
                {{ t('preboarding.save_buddy') }}
              </VBtn>
            </VCardText>
          </VCard>

          <!-- Edit Details -->
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              {{ t('preboarding.role_details') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0 d-flex flex-column gap-3">
              <VTextField
                v-model="detailsForm.department"
                :label="t('preboarding.department')"
                density="compact"
                variant="outlined"
                hide-details
              />
              <VTextField
                v-model="detailsForm.job_title"
                :label="t('preboarding.job_title')"
                density="compact"
                variant="outlined"
                hide-details
              />
              <VBtn
                block
                color="primary"
                variant="tonal"
                size="small"
                :loading="savingDetails"
                @click="saveDetails"
              >
                {{ t('preboarding.save_details') }}
              </VBtn>
            </VCardText>
          </VCard>

          <!-- Plans selector (if multiple) -->
          <VCard
            v-if="person.pre_boarding_plans.length > 1"
            elevation="0"
            border
          >
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              {{ t('preboarding.plans') }}
            </VCardTitle>
            <VCardText class="pa-5 pt-0 d-flex flex-column gap-2">
              <div
                v-for="plan in person.pre_boarding_plans"
                :key="plan.id"
                class="d-flex align-center justify-space-between pa-2 rounded cursor-pointer"
                :style="activePlan?.id === plan.id ? 'background: rgba(115,103,240,0.1);' : ''"
                @click="activePlan = plan"
              >
                <span class="text-body-2 font-weight-medium">Plan #{{ plan.id }}</span>
                <VChip :color="plan.status.color" size="x-small" label variant="tonal">
                  {{ plan.status.label }}
                </VChip>
              </div>
            </VCardText>
          </VCard>
        </div>
      </VCol>

      <!-- ── Main: right 9 cols ─────────────────────────────────────────── -->
      <VCol cols="12" lg="9">
        <!-- Active Plan -->
        <VCard v-if="activePlan" elevation="0" border>
          <!-- Plan header -->
          <VCardTitle class="pa-5 pb-3 d-flex align-center justify-space-between">
            <div>
              <span class="text-body-1 font-weight-semibold">{{ t('preboarding.checklist_title') }}</span>
              <p class="text-caption text-medium-emphasis mb-0 mt-1">
                {{ completedCount(activePlan) }} of {{ activePlan.tasks.length }} tasks complete
                <span v-if="activePlan.due_date"> · Due {{ formatDate(activePlan.due_date) }}</span>
              </p>
            </div>
            <VChip :color="activePlan.status.color" size="small" label variant="tonal" class="font-weight-medium">
              {{ activePlan.status.label }}
            </VChip>
          </VCardTitle>

          <!-- Progress bar -->
          <div class="px-5 pb-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-body-2 text-medium-emphasis">{{ t('preboarding.progress') }}</span>
              <span class="text-body-2 font-weight-bold text-primary">{{ planProgress }}%</span>
            </div>
            <VProgressLinear
              :model-value="planProgress"
              :color="activePlan.status.value === 'completed' ? 'success' : 'primary'"
              rounded
              height="8"
            />
          </div>

          <VDivider />

          <!-- Task list -->
          <VCardText class="pa-5">
            <div v-if="activePlan.tasks.length" class="d-flex flex-column gap-2">
              <div
                v-for="task in activePlan.tasks"
                :key="task.id"
                class="d-flex align-center gap-3 pa-3 rounded-lg"
                :class="task.status.value !== 'pending' ? 'opacity-60' : ''"
                style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
              >
                <VIcon
                  :icon="taskStatusIcon(task.status.value)"
                  :color="taskStatusColor(task.status.value)"
                  size="20"
                  class="flex-shrink-0"
                />
                <VIcon :icon="task.type.icon" size="18" color="primary" class="flex-shrink-0" />
                <div class="flex-grow-1">
                  <p
                    class="text-body-2 font-weight-medium mb-0"
                    :class="{ 'text-decoration-line-through': task.status.value === 'completed' }"
                  >
                    {{ task.title }}
                  </p>
                  <div class="d-flex align-center gap-1 mt-1 flex-wrap">
                    <span v-if="task.due_date" class="text-caption text-medium-emphasis">
                      Due {{ formatDate(task.due_date) }}
                    </span>
                    <span v-if="task.assigned_to" class="text-caption text-medium-emphasis">
                      · {{ task.assigned_to.name }}
                    </span>
                  </div>
                </div>
                <VChip
                  v-if="task.owner_role"
                  :color="ownerRoleColor(task.owner_role)"
                  size="x-small"
                  label
                  variant="tonal"
                  class="font-weight-medium"
                >
                  {{ task.owner_role.toUpperCase() }}
                </VChip>
                <VChip
                  :color="taskStatusColor(task.status.value)"
                  size="x-small"
                  label
                  variant="tonal"
                  class="font-weight-medium"
                >
                  {{ task.status.label }}
                </VChip>
                <div v-if="task.status.value === 'pending'" class="d-flex gap-1 flex-shrink-0">
                  <VBtn
                    size="x-small"
                    color="success"
                    variant="tonal"
                    :loading="completingTask === task.id"
                    @click.stop="completeTask(task, false)"
                  >
                    {{ t('preboarding.done') }}
                  </VBtn>
                  <VBtn
                    size="x-small"
                    color="default"
                    variant="text"
                    :loading="completingTask === task.id"
                    @click.stop="completeTask(task, true)"
                  >
                    {{ t('preboarding.skip') }}
                  </VBtn>
                </div>
              </div>
            </div>

            <div v-else class="text-center py-8 text-medium-emphasis">
              <VIcon icon="tabler-clipboard-check" size="48" class="mb-3 d-block mx-auto" />
              <p class="text-body-2 mb-0">{{ t('preboarding.no_tasks_dot') }}</p>
            </div>
          </VCardText>
        </VCard>

        <!-- No plan fallback -->
        <VCard v-else elevation="0" border>
          <VCardText class="text-center py-12">
            <VIcon icon="tabler-clipboard-list" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('preboarding.no_plan') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-0">
              {{ t('preboarding.no_plan_sub') }}
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Start Onboarding Dialog -->
    <VDialog v-model="showOnboardingDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          {{ t('preboarding.start_onboarding') }}
        </VCardTitle>
        <VCardText class="pa-5 pt-0">
          <p class="text-body-2 text-medium-emphasis mb-4">
            Set the onboarding period for {{ person.candidate?.full_name ?? 'this new hire' }}.
          </p>
          <VTextField
            v-model="onboardingForm.start_date"
            :label="t('preboarding.start_date')"
            type="date"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :min="new Date().toISOString().split('T')[0]"
          />
          <VTextField
            v-model="onboardingForm.end_date"
            :label="t('preboarding.end_date')"
            type="date"
            variant="outlined"
            density="comfortable"
            :min="onboardingForm.start_date || new Date().toISOString().split('T')[0]"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showOnboardingDialog = false">{{ t('preboarding.cancel') }}</VBtn>
          <VBtn
            color="info"
            :loading="startingOnboarding"
            :disabled="!onboardingForm.start_date || !onboardingForm.end_date"
            @click="submitStartOnboarding"
          >
            {{ t('preboarding.start_onboarding') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
