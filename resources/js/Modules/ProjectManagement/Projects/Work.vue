<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import TaskDrawer from './TaskDrawer.vue'
import AssigneeStack from '@/Components/Shared/AssigneeStack.vue'

const { t } = useI18n()
const route = ziggyRoute

// A dialog capped at 820px is a usable form on a laptop and a letterbox on a phone. The people
// this module is for are frequently holding one, so every dialog here goes full-screen below the
// `sm` breakpoint rather than shrinking a multi-column layout into a column of clipped fields.
const { smAndDown } = useDisplay()

type Node = {
  id: string; name: string; node_type: string; wbs_code: string
  depth: number; is_milestone: boolean; children: Node[]
}

type TaskRow = {
  status_key: string | null
  assignees: Array<{ id: string | null; name: string | null; is_lead: boolean; role: string | null }>
  id: string; code: string; name: string; status: string; priority: string
  wbs_node_id: string | null; parent_id: string | null
  planned_start: string | null; planned_end: string | null
  progress_pct: number; progress_source: string
  is_milestone: boolean; is_critical: boolean; is_occurrence: boolean; duration_minutes: number
  payment_amount: number | null
  predecessors: Array<{ id: string; task: string; type: string; lag: number }>
}

const props = defineProps<{
  project: { id: string; code: string; name: string }
  tree: Node[]
  tasks: TaskRow[]
  nodeTypes: string[]
  members: Array<{ id: string; name: string; role: string | null }>
  statuses: string[]
  // The project's own board columns (T-0008). The dropdowns offer these; `statuses` stays for the
  // colour lookup, which is keyed on meaning rather than on a name somebody typed.
  steps: Array<{ id: string | null; key: string; label: string | null; category: string; is_default: boolean; colour: string | null; custom: boolean }>
  priorities: string[]
  linkTypes: string[]
  taskDetail: Record<string, any> | null
  can: { createTasks: boolean; editTasks: boolean; schedule: boolean }
}>()

/** What this task's column is called on this project — its step's name, or the built-in one. */
function stepLabel(task: TaskRow): string {
  const step = props.steps.find(s => s.key === (task.status_key || task.status))

  // A built-in column carries no label — the module names those, in twelve languages.
  return step?.label || t(`project_management.task_status.${step?.key ?? task.status}`)
}

// ── Task drawer ──────────────────────────────────────────────────────────────

const drawer = ref(false)

/**
 * Opening a task fetches only its drawer payload. The task list, the tree and the scroll position
 * stay exactly as they were — on a 5,000-task job, re-sending all of it to read one comment would
 * be the difference between instant and a spinner.
 */
function openDetail(task: TaskRow) {
  router.get(route('project-management.work', props.project.id), { task: task.id }, {
    only: ['taskDetail'],
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onSuccess: () => { drawer.value = true },
  })
}

// ── Breakdown ────────────────────────────────────────────────────────────────

/** Flattened for rendering, with an expansion state per branch. */
const collapsed = ref<Set<string>>(new Set())

const flatTree = computed(() => {
  const rows: Array<Node & { hidden: boolean }> = []

  const walk = (nodes: Node[], hiddenAncestor: boolean) => {
    for (const node of nodes) {
      rows.push({ ...node, hidden: hiddenAncestor })
      // Children of a collapsed branch are not rendered at all — the whole point of collapsing a
      // 5,000-node tree is to stop drawing it.
      walk(node.children, hiddenAncestor || collapsed.value.has(node.id))
    }
  }

  walk(props.tree, false)
  return rows.filter(r => !r.hidden)
})

function toggle(id: string) {
  const next = new Set(collapsed.value)
  next.has(id) ? next.delete(id) : next.add(id)
  collapsed.value = next
}

/** Null means "everything" — the task list is unfiltered until a branch is picked. */
const selectedNode = ref<string | null>(null)

const visibleTasks = computed(() => selectedNode.value === null
  ? props.tasks
  : props.tasks.filter(t => t.wbs_node_id === selectedNode.value))

// ── Selection for bulk edit ──────────────────────────────────────────────────

const selected = ref<string[]>([])
const bulkStatus = ref<string | null>(null)
const bulkMember = ref<string | null>(null)

/**
 * Apply one change to everything ticked.
 *
 * Status and assignee go in the same request because they are the same gesture — "these twenty are
 * Sipho's, and they start on Monday" — and splitting it into two round trips is how a half-applied
 * batch happens.
 */
function applyBulk() {
  if ((!bulkStatus.value && !bulkMember.value) || selected.value.length === 0) return

  router.patch(route('project-management.tasks.bulk', props.project.id), {
    uuids: selected.value,
    status: bulkStatus.value,
    member_id: bulkMember.value,
  }, {
    preserveScroll: true,
    onSuccess: () => { selected.value = []; bulkStatus.value = null; bulkMember.value = null },
  })
}

// ── Dialogs ──────────────────────────────────────────────────────────────────

const nodeDialog = ref(false)
const nodeForm = useForm({ name: '', node_type: 'activity', parent_id: '', is_milestone: false })

function saveNode() {
  nodeForm.transform(d => ({ ...d, parent_id: d.parent_id || null }))
    .post(route('project-management.wbs.store', props.project.id), {
      preserveScroll: true,
      onSuccess: () => { nodeDialog.value = false; nodeForm.reset() },
    })
}

const taskDialog = ref(false)
const editingTask = ref<TaskRow | null>(null)
/*
 * `member_id` and `attachments` are creation-only companions rather than task columns: the server
 * assigns and files them after the task exists, and ignores them on an edit — where the drawer is
 * the right place to change who is on the work and what is filed against it.
 */
const taskForm = useForm({
  name: '', description: '', status: 'not_started', priority: 'medium',
  wbs_node_id: '', parent_task_id: '', planned_start: '', planned_end: '',
  duration_minutes: 0, is_milestone: false, payment_amount: null as number | null,
  member_id: null as string | null, attachments: [] as File[],
})

function openTask(task: TaskRow | null) {
  editingTask.value = task
  taskForm.reset()

  if (task) {
    Object.assign(taskForm, {
      name: task.name, status: task.status, priority: task.priority,
      wbs_node_id: task.wbs_node_id ?? '', parent_task_id: task.parent_id ?? '',
      planned_start: task.planned_start ?? '', planned_end: task.planned_end ?? '',
      duration_minutes: task.duration_minutes, is_milestone: task.is_milestone,
      payment_amount: task.payment_amount ?? null,
    })
  } else if (selectedNode.value) {
    // Creating from a selected branch puts the task there — the common case, without asking.
    taskForm.wbs_node_id = selectedNode.value
  }

  taskDialog.value = true
}

function saveTask() {
  const editing = editingTask.value !== null

  const payload = taskForm.transform(d => ({
    ...d,
    wbs_node_id: d.wbs_node_id || null,
    parent_task_id: d.parent_task_id || null,
    planned_start: d.planned_start || null,
    planned_end: d.planned_end || null,
    // Dropped on an edit rather than sent and ignored: an assignee silently discarded by the
    // server is worse than a field that was never offered.
    member_id: editing ? undefined : (d.member_id || null),
    attachments: editing ? undefined : d.attachments,
  }))

  const options = {
    // Multipart only when there is a file, so the ordinary save stays an ordinary save.
    forceFormData: !editing && taskForm.attachments.length > 0,
    preserveScroll: true,
    onSuccess: () => { taskDialog.value = false; taskForm.reset() },
  }

  editingTask.value
    ? payload.patch(route('project-management.tasks.update', [props.project.id, editingTask.value.id]), options)
    : payload.post(route('project-management.tasks.store', props.project.id), options)
}

const confirmer = ref()

function removeTask(task: TaskRow) {
  confirmer.value.open({
    title: t('project_management.tasks.confirm_delete_title'),
    message: t('project_management.tasks.confirm_delete', { name: task.name }),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(
    route('project-management.tasks.destroy', [props.project.id, task.id]),
    { preserveScroll: true },
  ))
}

// ── Dependencies ─────────────────────────────────────────────────────────────

const linkDialog = ref(false)
const linkForm = useForm({ predecessor_id: '', successor_id: '', dep_type: 'fs', lag_minutes: 0 })

/*
 * Bulk import.
 *
 * A plan that already exists in a spreadsheet should not have to be retyped a row at a time. The
 * template download sits inside this dialog rather than somewhere else on the page, because a
 * template somebody has to go and find is a template they will guess at — and a guessed column
 * name is the whole file rejected.
 */
const page = usePage()
const importDialog = ref(false)
const importForm = useForm({ file: null as File | null })

// Rows the server could not use, kept on the page after the dialog closes so they can be read
// against the spreadsheet that is still open in the other window.
const importSkipped = computed(() => (page.props.flash as any)?.taskImport?.skipped ?? [])

function submitImport() {
  importForm.post(route('project-management.tasks.import', props.project.id), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { importDialog.value = false; importForm.reset() },
  })
}

function saveLink() {
  linkForm.post(route('project-management.dependencies.store', props.project.id), {
    preserveScroll: true,
    onSuccess: () => { linkDialog.value = false; linkForm.reset() },
  })
}

function removeLink(id: string) {
  router.delete(route('project-management.dependencies.destroy', [props.project.id, id]), { preserveScroll: true })
}

const taskOptions = computed(() => props.tasks.map(t => ({ title: `${t.code} · ${t.name}`, value: t.id })))

const statusColor: Record<string, string> = {
  not_started: 'grey', in_progress: 'info', blocked: 'error', done: 'success', cancelled: 'grey',
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
        <h4 class="text-h4">{{ t('project_management.work.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.work.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.editTasks" variant="tonal" prepend-icon="tabler-sitemap" @click="nodeDialog = true">
          {{ t('project_management.work.add_node') }}
        </VBtn>
        <VBtn v-if="can.schedule" variant="tonal" prepend-icon="tabler-link" @click="linkDialog = true">
          {{ t('project_management.work.link_tasks') }}
        </VBtn>
        <VBtn v-if="can.createTasks" variant="tonal" prepend-icon="tabler-file-spreadsheet" @click="importDialog = true">
          {{ t('project_management.tasks.import.button') }}
        </VBtn>
        <VBtn v-if="can.createTasks" color="primary" prepend-icon="tabler-plus" @click="openTask(null)">
          {{ t('project_management.work.add_task') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- Breakdown -->
      <VCol cols="12" md="4">
        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.work.breakdown') }}</VCardTitle>
          </VCardItem>
          <VDivider />
          <VList density="compact" nav>
            <VListItem
              :active="selectedNode === null" color="primary"
              @click="selectedNode = null"
            >
              <VListItemTitle class="text-body-2">
                {{ t('project_management.work.all_work') }} ({{ tasks.length }})
              </VListItemTitle>
            </VListItem>

            <VListItem
              v-for="node in flatTree" :key="node.id"
              :active="selectedNode === node.id" color="primary"
              :style="{ paddingInlineStart: `${12 + node.depth * 16}px` }"
              @click="selectedNode = node.id"
            >
              <template #prepend>
                <VIcon
                  v-if="node.children.length"
                  size="18" class="me-1"
                  :icon="collapsed.has(node.id) ? 'tabler-chevron-right' : 'tabler-chevron-down'"
                  @click.stop="toggle(node.id)"
                />
                <VIcon v-else size="18" class="me-1" icon="tabler-point" />
              </template>
              <VListItemTitle class="text-body-2">
                <span class="text-medium-emphasis me-1">{{ node.wbs_code }}</span>{{ node.name }}
                <VIcon v-if="node.is_milestone" icon="tabler-flag" size="14" class="ms-1" />
              </VListItemTitle>
              <template #append>
                <VBtn
                  v-if="can.editTasks" size="small" variant="text" icon="tabler-trash"
                  :aria-label="t('project_management.work.remove_node', { name: node.name })"
                  @click.stop="router.delete(route('project-management.wbs.destroy', [project.id, node.id]), { preserveScroll: true })"
                />
              </template>
            </VListItem>

            <VListItem v-if="!tree.length">
              <VListItemSubtitle>{{ t('project_management.work.no_breakdown') }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Tasks -->
      <VCol cols="12" md="8">
        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.work.tasks') }}</VCardTitle>
            <template #append>
              <div v-if="selected.length" class="d-flex align-center gap-2 flex-wrap">
                <VSelect
                  v-model="bulkStatus"
                  :items="steps.map(s => ({ title: s.label || t(`project_management.task_status.${s.key}`), value: s.key }))"
                  :label="t('project_management.tasks.bulk_set_status', { count: selected.length })"
                  density="compact" variant="outlined" hide-details clearable style="min-width:200px"
                />
                <!--
                  Assigning in bulk is the point: putting one person on twenty tasks one dialog at a
                  time is the reason nobody did it. Empty when a project has no team yet, with a hint
                  saying where to add one, because a disabled control with no explanation reads as a
                  bug.
                -->
                <VSelect
                  v-model="bulkMember"
                  :items="members.map(m => ({ title: m.name, subtitle: m.role, value: m.id }))"
                  :label="t('project_management.tasks.bulk_assign_to', { count: selected.length })"
                  :no-data-text="t('project_management.tasks.no_members')"
                  density="compact" variant="outlined" hide-details clearable style="min-width:220px"
                >
                  <template #item="{ props: ip, item }">
                    <VListItem v-bind="ip" :subtitle="item.raw.subtitle" />
                  </template>
                </VSelect>
                <VBtn size="small" color="primary" :disabled="!bulkStatus && !bulkMember" @click="applyBulk">
                  {{ t('project_management.actions.save') }}
                </VBtn>
              </div>
            </template>
          </VCardItem>
          <VDivider />

          <VTable v-if="visibleTasks.length" density="comfortable">
            <thead>
              <tr>
                <th style="width:36px" />
                <th>{{ t('project_management.tasks.col_code') }}</th>
                <th>{{ t('project_management.tasks.col_name') }}</th>
                <th>{{ t('project_management.tasks.col_assignee') }}</th>
                <th>{{ t('project_management.tasks.col_status') }}</th>
                <th>{{ t('project_management.tasks.col_dates') }}</th>
                <th class="text-right">{{ t('project_management.tasks.col_progress') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="task in visibleTasks" :key="task.id">
                <td>
                  <VCheckbox
                    v-model="selected" :value="task.id" density="compact" hide-details
                    :aria-label="task.name"
                  />
                </td>
                <td class="text-no-wrap">
                  {{ task.code }}
                  <VIcon v-if="task.is_critical" icon="tabler-alert-triangle" size="14" color="error" class="ms-1" />
                </td>
                <td>
                  <a
                    class="cursor-pointer text-high-emphasis" :class="{ 'ps-4': task.parent_id }"
                    @click="openDetail(task)"
                  >{{ task.name }}</a>
                  <VIcon v-if="task.is_milestone" icon="tabler-flag" size="14" class="ms-1" />
                  <VIcon
                    v-if="task.is_occurrence" icon="tabler-repeat" size="14" class="ms-1"
                    :title="t('project_management.tasks.is_occurrence')"
                  />
                  <div v-if="task.predecessors.length" class="text-caption text-medium-emphasis">
                    <span v-for="p in task.predecessors" :key="p.id" class="me-2">
                      {{ t('project_management.tasks.after', { task: p.task, type: p.type.toUpperCase() }) }}
                      <VBtn
                        v-if="can.schedule" size="small" variant="text" icon="tabler-x"
                        :aria-label="t('project_management.work.remove_link', { task: p.task })"
                        @click="removeLink(p.id)"
                      />
                    </span>
                  </div>
                </td>
                <!--
                  Who is on it. The list could show a task's status and its progress and never say
                  whose task it was, so the one thing that makes a row actionable was the one thing
                  it did not carry.
                -->
                <td>
                  <AssigneeStack :people="task.assignees" :size="24" show-empty show-name />
                </td>
                <td>
                  <!-- The column's own name where the project named it, and the colour of what it
                       means either way. -->
                  <VChip size="small" :color="statusColor[task.status] ?? 'grey'" label>
                    {{ stepLabel(task) }}
                  </VChip>
                </td>
                <td class="text-no-wrap text-caption">
                  {{ task.planned_start ?? '—' }} → {{ task.planned_end ?? '—' }}
                </td>
                <td class="text-right">
                  {{ task.progress_pct }}%
                  <VIcon
                    v-if="task.progress_source === 'derived'" icon="tabler-clipboard-check" size="14"
                    class="ms-1" :title="t('project_management.tasks.progress_derived')"
                  />
                </td>
                <td class="text-right text-no-wrap">
                  <VBtn
                    v-if="can.editTasks" size="small" variant="text" icon="tabler-pencil"
                    :aria-label="t('project_management.work.edit_task')" @click="openTask(task)"
                  />
                  <VBtn
                    v-if="can.editTasks" size="small" variant="text" color="error" icon="tabler-trash"
                    :aria-label="t('project_management.tasks.confirm_delete', { name: task.name })"
                    @click="removeTask(task)"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.work.no_tasks') }}
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <TaskDrawer
      v-model="drawer" :project-id="project.id" :detail="taskDetail as any"
      :can="{ editTasks: can.editTasks, createTasks: can.createTasks }"
    />

    <!-- Add a breakdown node -->
    <VDialog :fullscreen="smAndDown" v-model="nodeDialog" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.work.add_node') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VTextField v-model="nodeForm.name" :label="t('project_management.work.node_name')"
                      :error-messages="nodeForm.errors.name" variant="outlined" density="compact" class="mb-3" />
          <VSelect v-model="nodeForm.node_type"
                   :items="nodeTypes.map(n => ({ title: t(`project_management.node_type.${n}`), value: n }))"
                   :label="t('project_management.work.node_type')" variant="outlined" density="compact" class="mb-3" />
          <VAutocomplete
            v-model="nodeForm.parent_id"
            :items="[{ title: t('project_management.work.top_level'), value: '' },
                     ...flatTree.map(n => ({ title: `${n.wbs_code} ${n.name}`, value: n.id }))]"
            :label="t('project_management.work.under')" variant="outlined" density="compact"
            :error-messages="nodeForm.errors.parent_id"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="nodeDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="nodeForm.processing" :disabled="!nodeForm.name" @click="saveNode">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Task -->
    <VDialog :fullscreen="smAndDown" v-model="taskDialog" max-width="720">
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editingTask ? t('project_management.work.edit_task') : t('project_management.work.add_task') }}
          </VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VRow>
            <VCol cols="12">
              <VTextField v-model="taskForm.name" :label="t('project_management.tasks.col_name')"
                          :error-messages="taskForm.errors.name" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="taskForm.status"
                       :items="steps.map(s => ({ title: s.label || t(`project_management.task_status.${s.key}`), value: s.key }))"
                       :label="t('project_management.tasks.col_status')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="taskForm.priority"
                       :items="priorities.map(p => ({ title: t(`project_management.priority.${p}`), value: p }))"
                       :label="t('project_management.projects.field_priority')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="taskForm.wbs_node_id"
                :items="[{ title: t('project_management.work.unassigned'), value: '' },
                         ...flatTree.map(n => ({ title: `${n.wbs_code} ${n.name}`, value: n.id }))]"
                :label="t('project_management.work.breakdown')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="taskForm.parent_task_id"
                :items="[{ title: t('project_management.work.no_parent'), value: '' }, ...taskOptions]"
                :label="t('project_management.work.parent_task')" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="taskForm.planned_start" type="date"
                          :label="t('project_management.projects.field_planned_start')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="taskForm.planned_end" type="date"
                          :label="t('project_management.projects.field_planned_end')"
                          :error-messages="taskForm.errors.planned_end" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="taskForm.duration_minutes" type="number" inputmode="numeric" min="0"
                          :label="t('project_management.tasks.field_duration')"
                          :hint="t('project_management.tasks.hint_duration')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6" class="d-flex align-center">
              <VSwitch v-model="taskForm.is_milestone" color="primary" density="compact" hide-details
                       :label="t('project_management.tasks.field_milestone')" />
            </VCol>
            <!-- Only on a milestone, because it means nothing anywhere else. This is what a
                 milestone-based contract actually pays out, and the Site page's claim dialog
                 reads it. -->
            <VCol v-if="taskForm.is_milestone" cols="12" md="6">
              <VTextField v-model.number="taskForm.payment_amount" type="number" step="0.01"
                          :label="t('project_management.tasks.field_payment_amount')"
                          :hint="t('project_management.tasks.field_payment_amount_hint')" persistent-hint
                          :error-messages="taskForm.errors.payment_amount"
                          variant="outlined" density="compact" />
            </VCol>

            <!--
              Creation only. On an edit the drawer owns both of these, and it can do more with them
              than a dialog can: remove somebody, set an allocation, delete a file.
            -->
            <template v-if="!editingTask">
              <VCol cols="12" md="6">
                <VAutocomplete
                  v-model="taskForm.member_id"
                  :items="members.map(m => ({ title: m.name, value: m.id }))"
                  :label="t('project_management.views.assign_to')"
                  :error-messages="taskForm.errors.member_id"
                  variant="outlined" density="compact" clearable
                />
              </VCol>
              <VCol cols="12" md="6">
                <VFileInput
                  v-model="taskForm.attachments" multiple chips
                  :label="t('project_management.views.attach')"
                  :error-messages="taskForm.errors.attachments"
                  prepend-icon="" prepend-inner-icon="tabler-paperclip"
                  variant="outlined" density="compact"
                />
              </VCol>
            </template>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="taskDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="taskForm.processing" :disabled="!taskForm.name" @click="saveTask">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Link -->
    <VDialog :fullscreen="smAndDown" v-model="linkDialog" max-width="620">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.work.link_tasks') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VAutocomplete v-model="linkForm.predecessor_id" :items="taskOptions"
                   :label="t('project_management.work.predecessor')"
                   :error-messages="linkForm.errors.predecessor_id"
                   variant="outlined" density="compact" class="mb-3" />
          <VAutocomplete v-model="linkForm.successor_id" :items="taskOptions"
                   :label="t('project_management.work.successor')"
                   :error-messages="linkForm.errors.successor_id"
                   variant="outlined" density="compact" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VSelect v-model="linkForm.dep_type"
                       :items="linkTypes.map(l => ({ title: t(`project_management.link_type.${l}`), value: l }))"
                       :label="t('project_management.work.link_type')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="linkForm.lag_minutes" type="number" inputmode="numeric"
                          :label="t('project_management.work.lag')"
                          :hint="t('project_management.work.hint_lag')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="linkDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="linkForm.processing"
                :disabled="!linkForm.predecessor_id || !linkForm.successor_id" @click="saveLink">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      Import. Three numbered steps because they genuinely are a sequence: the template has to exist
      before it can be filled in, and be filled in before it can be sent back.
    -->
    <VDialog v-model="importDialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('project_management.tasks.import.title') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-4">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.tasks.import.intro') }}</p>

          <div class="d-flex align-center ga-3">
            <VAvatar size="24" color="primary" variant="tonal" class="text-caption">1</VAvatar>
            <div class="flex-grow-1 text-body-2">{{ t('project_management.tasks.import.step_download') }}</div>
            <VBtn
              size="small" variant="tonal" prepend-icon="tabler-download"
              :href="route('project-management.tasks.import.template', props.project.id)"
            >
              {{ t('project_management.tasks.import.download') }}
            </VBtn>
          </div>

          <div class="d-flex align-center ga-3">
            <VAvatar size="24" color="primary" variant="tonal" class="text-caption">2</VAvatar>
            <div class="text-body-2">{{ t('project_management.tasks.import.step_fill') }}</div>
          </div>

          <div class="d-flex align-start ga-3">
            <VAvatar size="24" color="primary" variant="tonal" class="text-caption mt-1">3</VAvatar>
            <VFileInput
              v-model="importForm.file"
              :label="t('project_management.tasks.import.file')"
              accept=".csv,.xlsx,.xls"
              prepend-icon=""
              prepend-inner-icon="tabler-paperclip"
              variant="outlined"
              density="compact"
              class="flex-grow-1"
              :error-messages="importForm.errors.file"
            />
          </div>

          <VAlert type="info" variant="tonal" density="compact" class="text-caption">
            {{ t('project_management.tasks.import.help') }}
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="importDialog = false">{{ t('project_management.tasks.import.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="importForm.processing" :disabled="!importForm.file"
            @click="submitImport"
          >
            {{ t('project_management.tasks.import.upload') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      What the import could not use. On the page rather than in a toast that disappears: these are
      lines somebody has to go and correct in a file, and a message they cannot re-read is a message
      that costs them the whole upload again.
    -->
    <VCard v-if="importSkipped.length" border class="mt-4">
      <VCardTitle class="text-subtitle-1">
        {{ t('project_management.tasks.import.skipped_title') }}
      </VCardTitle>
      <VTable density="compact">
        <thead>
          <tr>
            <th>{{ t('project_management.tasks.import.col_row') }}</th>
            <th>{{ t('project_management.tasks.import.col_task') }}</th>
            <th>{{ t('project_management.tasks.import.col_reason') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in importSkipped" :key="i">
            <td class="text-medium-emphasis">{{ row.row }}</td>
            <td>{{ row.name }}</td>
            <td>{{ row.reason }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
