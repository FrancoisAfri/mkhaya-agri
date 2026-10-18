<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import GanttChart from './GanttChart.vue'
import AssigneeStack from '@/Components/Shared/AssigneeStack.vue'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; code: string; name: string; status: string
  early_start: string | null; early_finish: string | null
  late_start: string | null; late_finish: string | null
  float_days: number | null; free_float_days: number | null
  is_critical: boolean; is_milestone: boolean; duration_days: number; scheduled: boolean
  assignees: Array<{ id: string | null; name: string | null; is_lead: boolean; role: string | null }>
}

const props = defineProps<{
  project: { id: string; code: string; name: string; planned_start: string | null }
  tasks: Row[]
  baseline: { id: string; name: string; captured_at: string | null; finish: string | null } | null
  variance: {
    finish_days: number | null; added: number; removed: number
    tasks: Array<{ code: string | null; name: string; state: string; baseline_end: string | null; current_end: string | null; variance_days: number | null }>
  }
  baselines: Array<{ id: string; name: string; reason: string | null; captured_at: string | null; is_current: boolean }>
  lastRun: string | null
  can: { schedule: boolean; manage: boolean }
}>()

const running = ref(false)

function runSchedule() {
  running.value = true
  router.post(route('project-management.schedule.run', props.project.id), {}, {
    preserveScroll: true,
    onFinish: () => { running.value = false },
  })
}

/** Critical first — the tasks where a day lost is a day off the end of the job. */
const criticalCount = computed(() => props.tasks.filter(t => t.is_critical).length)
const unscheduled = computed(() => props.tasks.filter(t => !t.scheduled).length)

/**
 * Baseline dates by task code, for the ghost bar under each current one.
 *
 * Built from the variance rows the controller already computes, rather than a second query — the
 * comparison is the same comparison, and two sources would eventually disagree.
 */
const baselineByCode = computed(() => {
  const out: Record<string, { start: string | null; end: string | null }> = {}

  for (const row of props.variance.tasks ?? []) {
    if (row.code && row.baseline_end) out[row.code] = { start: null, end: row.baseline_end }
  }

  return out
})

const showCriticalOnly = ref(false)
const visible = computed(() => showCriticalOnly.value ? props.tasks.filter(t => t.is_critical) : props.tasks)

/** Only the tasks that actually moved — a variance table listing everything hides the exceptions. */
const moved = computed(() => props.variance.tasks.filter(v => v.state !== 'on_plan' && v.state !== 'undated'))

const varianceColor: Record<string, string> = {
  late: 'error', early: 'success', added: 'info', removed: 'warning',
}

const baselineDialog = ref(false)
const baselineForm = useForm({ name: '', reason: '' })

function saveBaseline() {
  baselineForm.post(route('project-management.baselines.store', props.project.id), {
    preserveScroll: true,
    onSuccess: () => { baselineDialog.value = false; baselineForm.reset() },
  })
}

function shortDate(value: string | null) {
  return value ? value.slice(0, 10) : '—'
}

function floatLabel(days: number | null) {
  if (days === null) return '—'
  if (days < 0) return t('project_management.schedule.days_behind', { days: Math.abs(days) })
  return t('project_management.schedule.days_spare', { days })
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.show', project.id))">
      {{ project.code }} · {{ project.name }}
    </VBtn>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.schedule.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ lastRun
            ? t('project_management.schedule.last_run', { when: shortDate(lastRun) })
            : t('project_management.schedule.never_run') }}
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.schedule" variant="tonal" prepend-icon="tabler-flag-check" @click="baselineDialog = true">
          {{ t('project_management.schedule.take_baseline') }}
        </VBtn>
        <VBtn v-if="can.schedule" color="primary" prepend-icon="tabler-refresh"
              :loading="running" @click="runSchedule">
          {{ t('project_management.schedule.recalculate') }}
        </VBtn>
      </div>
    </div>

    <!-- Where the project stands -->
    <VRow class="mb-2">
      <VCol cols="12" md="4">
        <!--
      The plan as bars. Above the table because a Gantt answers "when" at a glance and the table
      answers "by how much" — and the first question is the one people arrive with.
    -->
    <GanttChart :tasks="tasks" :baseline-by-code="baselineByCode" class="mb-4" />

    <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('project_management.schedule.critical_tasks') }}</div>
            <div class="text-h5">{{ criticalCount }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('project_management.schedule.critical_explains') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('project_management.schedule.against_baseline') }}</div>
            <template v-if="baseline && variance.finish_days !== null">
              <div class="text-h5" :class="variance.finish_days > 0 ? 'text-error' : 'text-success'">
                {{ floatLabel(-variance.finish_days) }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ baseline.name }} · {{ shortDate(baseline.captured_at) }}
              </div>
            </template>
            <template v-else>
              <div class="text-h5">—</div>
              <!-- Not "0 days": no baseline means nothing to be measured against, which is a
                   different statement from being on time. -->
              <div class="text-caption text-medium-emphasis">{{ t('project_management.schedule.no_baseline') }}</div>
            </template>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('project_management.schedule.scope_change') }}</div>
            <div class="text-h5">
              <span class="text-info">+{{ variance.added }}</span>
              <span class="mx-1 text-disabled">/</span>
              <span class="text-warning">−{{ variance.removed }}</span>
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('project_management.schedule.scope_explains') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VAlert v-if="unscheduled" type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('project_management.schedule.unscheduled_notice', { count: unscheduled }) }}
    </VAlert>

    <!-- The schedule -->
    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.schedule.tasks') }}</VCardTitle>
        <template #append>
          <VSwitch
            v-model="showCriticalOnly" color="primary" density="compact" hide-details
            :label="t('project_management.schedule.critical_only')"
          />
        </template>
      </VCardItem>
      <VDivider />

      <VTable v-if="visible.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ t('project_management.tasks.col_code') }}</th>
            <th>{{ t('project_management.tasks.col_name') }}</th>
            <th>{{ t('project_management.tasks.col_assignee') }}</th>
            <th>{{ t('project_management.schedule.col_earliest') }}</th>
            <th>{{ t('project_management.schedule.col_latest') }}</th>
            <th class="text-right">{{ t('project_management.schedule.col_float') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="task in visible" :key="task.id">
            <td class="text-no-wrap">
              {{ task.code }}
              <VIcon v-if="task.is_critical" icon="tabler-alert-triangle" size="14" color="error" class="ms-1"
                     :title="t('project_management.schedule.on_critical_path')" />
            </td>
            <td>
              {{ task.name }}
              <VIcon v-if="task.is_milestone" icon="tabler-flag" size="14" class="ms-1" />
              <div class="text-caption text-medium-emphasis">
                {{ t('project_management.schedule.duration_days', { days: task.duration_days }) }}
              </div>
            </td>
            <!-- A critical-path task nobody owns is the row worth noticing, and the schedule had
                 no way to say which those were. -->
            <td>
              <AssigneeStack :people="task.assignees" :size="24" show-empty />
            </td>
            <td class="text-no-wrap text-caption">
              {{ shortDate(task.early_start) }} → {{ shortDate(task.early_finish) }}
            </td>
            <td class="text-no-wrap text-caption">
              {{ shortDate(task.late_start) }} → {{ shortDate(task.late_finish) }}
            </td>
            <td class="text-right text-no-wrap">
              <span :class="task.float_days !== null && task.float_days < 0 ? 'text-error' : ''">
                {{ floatLabel(task.float_days) }}
              </span>
              <!-- Free float is what can be used without moving anyone else; showing only total
                   float is how a manager "spends" slack that three other crews were relying on. -->
              <div v-if="task.free_float_days !== null && task.free_float_days !== task.float_days"
                   class="text-caption text-medium-emphasis">
                {{ t('project_management.schedule.free_float', { days: task.free_float_days }) }}
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.schedule.nothing_scheduled') }}
      </VCardText>
    </VCard>

    <!-- What moved -->
    <VCard v-if="baseline" border class="mb-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.schedule.what_moved') }}</VCardTitle>
      </VCardItem>
      <VDivider />

      <VTable v-if="moved.length" density="compact">
        <thead>
          <tr>
            <th>{{ t('project_management.tasks.col_name') }}</th>
            <th>{{ t('project_management.schedule.col_agreed') }}</th>
            <th>{{ t('project_management.schedule.col_now') }}</th>
            <th class="text-right">{{ t('project_management.schedule.col_variance') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in moved" :key="`${row.code}-${row.name}`">
            <td>
              <span class="text-medium-emphasis me-1">{{ row.code ?? '—' }}</span>{{ row.name }}
            </td>
            <td class="text-caption">{{ row.baseline_end ?? '—' }}</td>
            <td class="text-caption">{{ row.current_end ?? '—' }}</td>
            <td class="text-right">
              <VChip size="small" label :color="varianceColor[row.state] ?? 'grey'">
                {{ row.variance_days !== null
                  ? floatLabel(-row.variance_days)
                  : t(`project_management.schedule.state_${row.state}`) }}
              </VChip>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-medium-emphasis">
        {{ t('project_management.schedule.nothing_moved') }}
      </VCardText>
    </VCard>

    <!-- Baseline history -->
    <VCard v-if="baselines.length" border>
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.schedule.baselines') }}</VCardTitle>
      </VCardItem>
      <VDivider />
      <VList density="compact">
        <VListItem v-for="b in baselines" :key="b.id">
          <VListItemTitle>
            {{ b.name }}
            <VChip v-if="b.is_current" size="x-small" color="primary" label class="ms-2">
              {{ t('project_management.schedule.current') }}
            </VChip>
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ b.captured_at }}<template v-if="b.reason"> · {{ b.reason }}</template>
          </VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>

    <!-- Take a baseline -->
    <VDialog v-model="baselineDialog" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.schedule.take_baseline') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('project_management.schedule.baseline_explains') }}</p>
          <VTextField
            v-model="baselineForm.name" :label="t('project_management.schedule.baseline_name')"
            :placeholder="t('project_management.schedule.baseline_name_hint')"
            :error-messages="baselineForm.errors.name"
            variant="outlined" density="compact" class="mb-3"
          />
          <VTextarea
            v-model="baselineForm.reason" rows="2"
            :label="t('project_management.schedule.baseline_reason')"
            :hint="t('project_management.schedule.baseline_reason_hint')" persistent-hint
            :error-messages="baselineForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="baselineDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="baselineForm.processing" :disabled="!baselineForm.name"
                @click="saveBaseline">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
