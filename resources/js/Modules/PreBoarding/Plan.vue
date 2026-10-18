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
  created_at: string
}

type Person = {
  id: number
  uuid: string
  status: { value: string; label: string; color: string }
  employee_number: string | null
  started_on: string | null
  candidate: { id: number; full_name: string; email: string } | null
}

const props = defineProps<{ person: Person; plan: Plan }>()

const completingTask = ref<number | null>(null)

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

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function completeTask(task: Task, skip = false) {
  completingTask.value = task.id
  router.post(
    route('pre-boarding.tasks.complete', [props.person.id, props.plan.id, task.id]),
    { skip: skip ? 1 : 0 },
    { preserveScroll: true, onFinish: () => { completingTask.value = null } },
  )
}

const completedCount = computed(() =>
  props.plan.tasks.filter(t => t.status.value !== 'pending').length,
)

const progress = computed(() => {
  if (!props.plan.tasks.length) return 0
  return Math.round((completedCount.value / props.plan.tasks.length) * 100)
})

function ownerRoleColor(role: string | null) {
  if (role === 'hr')      return 'primary'
  if (role === 'it')      return 'info'
  if (role === 'finance') return 'warning'
  return 'default'
}

function ownerRoleLabel(role: string | null) {
  if (!role) return null
  return role.toUpperCase()
}

const grouped = computed(() => {
  const done    = props.plan.tasks.filter(t => t.status.value !== 'pending')
  const pending = props.plan.tasks.filter(t => t.status.value === 'pending')
  return { pending, done }
})

const { t } = useI18n()
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-5">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('pre-boarding.index'))" />
      <span
        class="text-body-2 text-medium-emphasis cursor-pointer"
        @click="router.visit(route('pre-boarding.index'))"
      >
        {{ t('preboarding.title') }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-body-2 text-medium-emphasis cursor-pointer"
        @click="router.visit(route('pre-boarding.show', person.id))"
      >
        {{ person.candidate?.full_name ?? 'New Hire' }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">Plan #{{ plan.id }}</span>
    </div>

    <!-- Page header -->
    <div class="d-flex align-start justify-space-between mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <h4 class="text-h4 font-weight-bold">{{ t('preboarding.plan_title') }}</h4>
          <VChip :color="plan.status.color" size="small" label variant="tonal" class="font-weight-medium">
            {{ plan.status.label }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0">
          {{ person.candidate?.full_name ?? 'New Hire' }}
          <span v-if="plan.due_date"> · Due {{ formatDate(plan.due_date) }}</span>
        </p>
      </div>
    </div>

    <VRow>
      <!-- ── Sidebar ────────────────────────────────────────────── -->
      <VCol cols="12" lg="3">
        <div style="position: sticky; top: 80px;" class="d-flex flex-column gap-4">
          <!-- Progress card -->
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('preboarding.progress') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-body-2 text-medium-emphasis">
                  {{ completedCount }} / {{ plan.tasks.length }} tasks
                </span>
                <span
                  class="text-body-2 font-weight-bold"
                  :style="{ color: `var(--v-theme-${plan.status.color})` }"
                >
                  {{ progress }}%
                </span>
              </div>
              <VProgressLinear
                :model-value="progress"
                :color="plan.status.value === 'completed' ? 'success' : 'primary'"
                rounded
                height="10"
                bg-color="rgba(115,103,240,0.12)"
              />

              <div class="d-flex flex-column gap-2 mt-4">
                <div class="d-flex justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.pending') }}</span>
                  <span class="text-body-2 font-weight-medium">{{ grouped.pending.length }}</span>
                </div>
                <div class="d-flex justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.completed') }}</span>
                  <span class="text-body-2 font-weight-medium text-success">{{ grouped.done.length }}</span>
                </div>
                <VDivider class="my-1" />
                <div v-if="plan.due_date" class="d-flex justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.due_date') }}</span>
                  <span class="text-body-2">{{ formatDate(plan.due_date) }}</span>
                </div>
                <div v-if="plan.completed_at" class="d-flex justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.completed') }}</span>
                  <span class="text-body-2 text-success">{{ formatDate(plan.completed_at) }}</span>
                </div>
              </div>
            </VCardText>
          </VCard>

          <!-- Person card -->
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-3 text-body-1 font-weight-semibold">{{ t('preboarding.new_hire') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex align-center gap-3 mb-3">
                <VAvatar size="44" color="primary" variant="tonal">
                  <span class="text-body-1 font-weight-bold">
                    {{ (person.candidate?.full_name ?? '?')[0].toUpperCase() }}
                  </span>
                </VAvatar>
                <div>
                  <p class="text-body-2 font-weight-semibold mb-0">
                    {{ person.candidate?.full_name ?? '—' }}
                  </p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ person.candidate?.email ?? '' }}</p>
                </div>
              </div>
              <VDivider class="mb-3" />
              <div class="d-flex flex-column gap-2">
                <div class="d-flex justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.status') }}</span>
                  <VChip :color="person.status.color" size="x-small" label variant="tonal">
                    {{ person.status.label }}
                  </VChip>
                </div>
                <div class="d-flex justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.employee_number') }}</span>
                  <span class="text-body-2">{{ person.employee_number ?? '—' }}</span>
                </div>
                <div class="d-flex justify-space-between">
                  <span class="text-caption text-medium-emphasis">{{ t('preboarding.start_date') }}</span>
                  <span class="text-body-2">{{ formatDate(person.started_on) }}</span>
                </div>
              </div>
              <VBtn
                class="mt-4"
                variant="tonal"
                color="primary"
                block
                size="small"
                @click="router.visit(route('pre-boarding.show', person.id))"
              >
                {{ t('preboarding.view_profile') }}
              </VBtn>
            </VCardText>
          </VCard>
        </div>
      </VCol>

      <!-- ── Task list ──────────────────────────────────────────── -->
      <VCol cols="12" lg="9">
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
            {{ t('preboarding.checklist') }}
          </VCardTitle>

          <VCardText v-if="plan.tasks.length" class="pa-5 pt-0">
            <!-- Pending tasks -->
            <div v-if="grouped.pending.length" class="mb-4">
              <p class="text-caption text-medium-emphasis text-uppercase font-weight-semibold mb-3">
                To Do ({{ grouped.pending.length }})
              </p>
              <div class="d-flex flex-column gap-2">
                <div
                  v-for="task in grouped.pending"
                  :key="task.id"
                  class="d-flex align-center gap-3 pa-4 rounded-lg"
                  style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
                >
                  <VIcon icon="tabler-circle" color="warning" size="20" class="flex-shrink-0" />
                  <VIcon :icon="task.type.icon ?? 'tabler-clipboard'" size="18" color="primary" class="flex-shrink-0" />
                  <div class="flex-grow-1">
                    <p class="text-body-2 font-weight-medium mb-0">{{ task.title }}</p>
                    <p v-if="task.description" class="text-caption text-medium-emphasis mb-0">{{ task.description }}</p>
                    <div class="d-flex align-center gap-1 mt-1 flex-wrap">
                      <p v-if="task.due_date" class="text-caption text-medium-emphasis mb-0">
                        Due {{ formatDate(task.due_date) }}
                      </p>
                      <span v-if="task.assigned_to" class="text-caption text-medium-emphasis mb-0">
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
                    {{ ownerRoleLabel(task.owner_role) }}
                  </VChip>
                  <VChip color="warning" size="x-small" label variant="tonal" class="font-weight-medium">
                    {{ task.status.label }}
                  </VChip>
                  <div class="d-flex gap-1 flex-shrink-0">
                    <VBtn
                      size="x-small"
                      color="success"
                      variant="tonal"
                      :loading="completingTask === task.id"
                      @click="completeTask(task, false)"
                    >
                      {{ t('preboarding.done') }}
                    </VBtn>
                    <VBtn
                      size="x-small"
                      color="default"
                      variant="text"
                      :loading="completingTask === task.id"
                      @click="completeTask(task, true)"
                    >
                      {{ t('preboarding.skip') }}
                    </VBtn>
                  </div>
                </div>
              </div>
            </div>

            <!-- Completed / skipped tasks -->
            <div v-if="grouped.done.length">
              <p class="text-caption text-medium-emphasis text-uppercase font-weight-semibold mb-3">
                Completed ({{ grouped.done.length }})
              </p>
              <div class="d-flex flex-column gap-2">
                <div
                  v-for="task in grouped.done"
                  :key="task.id"
                  class="d-flex align-center gap-3 pa-4 rounded-lg opacity-60"
                  style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
                >
                  <VIcon
                    :icon="taskStatusIcon(task.status.value)"
                    :color="taskStatusColor(task.status.value)"
                    size="20"
                    class="flex-shrink-0"
                  />
                  <VIcon :icon="task.type.icon ?? 'tabler-clipboard'" size="18" color="default" class="flex-shrink-0" />
                  <div class="flex-grow-1">
                    <p class="text-body-2 font-weight-medium mb-0 text-decoration-line-through">{{ task.title }}</p>
                    <p v-if="task.completed_at" class="text-caption text-medium-emphasis mb-0">
                      {{ task.status.value === 'skipped' ? 'Skipped' : 'Completed' }}
                      {{ formatDate(task.completed_at) }}
                    </p>
                  </div>
                  <VChip
                    :color="taskStatusColor(task.status.value)"
                    size="x-small"
                    label
                    variant="tonal"
                    class="font-weight-medium"
                  >
                    {{ task.status.label }}
                  </VChip>
                </div>
              </div>
            </div>
          </VCardText>

          <VCardText v-else class="text-center py-12">
            <VIcon icon="tabler-clipboard-check" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('preboarding.no_tasks') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('preboarding.no_tasks_sub') }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
