<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// A dialog capped at 820px is a usable form on a laptop and a letterbox on a phone. The people
// this module is for are frequently holding one, so every dialog here goes full-screen below the
// `sm` breakpoint rather than shrinking a multi-column layout into a column of clipped fields.
const { smAndDown } = useDisplay()

type Entry = {
  id: string; member: string | null; task: string | null
  worked_on: string | null; hours: number; status: string; note: string | null; reason: string | null
}

type Expense = {
  id: string; category: string; description: string; amount: number; currency: string
  incurred_on: string | null; status: string; task: string | null
  invoice: string | null; reason: string | null; editable: boolean
}

type Cost = {
  currency: string | null; budget: number; committed: number; actual: number; labour: number
  remaining: number; spent_pct: number | null; is_over: boolean
  tasks: Array<{ id: string; code: string; name: string; budget: number; actual: number; variance: number; is_over: boolean; progress: number }>
}

const props = defineProps<{
  project: { id: string; code: string; name: string; timesheet_approval_required: boolean }
  window: { from: string; to: string }
  team: Array<{ id: string; name: string | null; role: string; tasks: number; overloaded: number; peak: number
    booked: number; approved: number; awaiting: number }>
  members: Array<{ id: string; name: string | null; role: string }>
  tasks: Array<{ id: string; label: string }>
  entries: Entry[]
  hours: { approved_hours: number; pending_hours: number; cost: number; currency: string | null }
  cost: Cost | null
  expenses: Expense[]
  expenseSummary: { committed: number; actual: number; by_category: Array<{ category: string; amount: number }> } | null
  categories: string[]
  roles: string[]
  /** True when an employee directory is installed, so the picker has more than free text. */
  directory: boolean
  can: { assign: boolean; approve: boolean; members: boolean; costs: boolean }
}>()

const tab = ref('people')

const awaiting = computed(() => props.entries.filter(e => e.status === 'submitted'))

const statusColor: Record<string, string> = {
  draft: 'grey', submitted: 'info', approved: 'success', rejected: 'error',
}

function money(value: number | null) {
  if (value === null) return '—'
  return `${props.cost?.currency ?? props.hours.currency ?? ''} ${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

// ── Time ─────────────────────────────────────────────────────────────────────

const timeForm = useForm({
  member_id: '', task_id: '', worked_on: new Date().toISOString().slice(0, 10),
  hours: 8, note: '', rate_per_hour: null as number | null,
})

function logTime() {
  timeForm.transform(d => ({ ...d, task_id: d.task_id || null }))
    .post(route('project-management.time.store', props.project.id), {
      preserveScroll: true,
      onSuccess: () => { timeForm.reset('hours', 'note') },
    })
}

function approve(entry: Entry) {
  router.post(route('project-management.time.approve', [props.project.id, entry.id]), {}, { preserveScroll: true })
}

const rejecting = ref<Entry | null>(null)
const rejectForm = useForm({ reason: '' })

function reject() {
  rejectForm.post(route('project-management.time.reject', [props.project.id, rejecting.value!.id]), {
    preserveScroll: true,
    onSuccess: () => { rejecting.value = null; rejectForm.reset() },
  })
}

// ── People ───────────────────────────────────────────────────────────────────
//
// Everything else on this page depends on somebody being here first: you cannot assign a task to
// nobody, book hours against nobody, or report labour cost over an empty team.

const confirmer = ref()
const memberDialog = ref(false)
const editingMember = ref<{ id: string; name: string | null; role: string } | null>(null)

const memberForm = useForm({
  display_name: '', employee_id: '', email: '', role_key: 'team_member',
})

const candidates = ref<Array<{ id: string; label: string; email: string | null }>>([])
const candidateSearch = ref('')
const searchingDirectory = ref(false)

/**
 * Look up the employee directory, when there is one.
 *
 * Typing a name has to keep working regardless: a subcontractor's foreman is a person on the job
 * whether or not anybody has given him an HR record.
 */
async function searchDirectory(query: string) {
  if (!props.directory) return

  searchingDirectory.value = true
  try {
    const response = await fetch(
      route('project-management.members.candidates', props.project.id) + '?q=' + encodeURIComponent(query),
      { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    )
    const data = await response.json()
    candidates.value = data.candidates ?? []
  } finally {
    searchingDirectory.value = false
  }
}

function openAddMember() {
  editingMember.value = null
  memberForm.reset()
  candidates.value = []
  memberDialog.value = true
  searchDirectory('')
}

/**
 * Inviting a client or a subcontractor into the portal.
 *
 * The address is asked for rather than assumed: the member record may carry none, and the one on
 * file is often the site contact rather than the person who should actually be given a login.
 */
const inviteDialog = ref(false)
const invitee = ref<{ id: string; name: string; email: string | null } | null>(null)
const inviteForm = useForm({ email: '' })

function openInvite(member: { id: string; name: string; email: string | null }) {
  invitee.value = member
  inviteForm.email = member.email ?? ''
  inviteForm.clearErrors()
  inviteDialog.value = true
}

/**
 * The issued link, shown after sending.
 *
 * An email that does not arrive used to be the end of it — the invitation existed, was valid, and
 * nobody could see it. The link is handed back to whoever issued it either way, so a mail server
 * that is refusing connections slows the work down instead of stopping it.
 */
const issued = ref<{ url: string; email: string; name: string; delivered: boolean; expires: string } | null>(null)
const copied = ref(false)

function sendInvite() {
  if (!invitee.value) return

  inviteForm.post(route('project-management.members.invite', [props.project.id, invitee.value.id]), {
    preserveScroll: true,
    onSuccess: page => {
      inviteDialog.value = false
      copied.value = false
      issued.value = (page.props.flash as any)?.inviteLink ?? null
    },
  })
}

async function copyInvite() {
  if (!issued.value) return

  try {
    await navigator.clipboard.writeText(issued.value.url)
    copied.value = true
  } catch {
    // Clipboard access can be refused outright — an insecure origin, a locked-down browser. The
    // link is on screen and selectable either way, so this is a nicety, not the mechanism.
    copied.value = false
  }
}

function openEditMember(member: { id: string; name: string | null; role: string }) {
  editingMember.value = member
  memberForm.reset()
  memberForm.display_name = member.name ?? ''
  memberForm.role_key = member.role
  memberDialog.value = true
}

/** Picking somebody from the directory fills the name, which stays editable. */
function pickCandidate(id: string) {
  const found = candidates.value.find(c => c.id === id)
  if (!found) return
  memberForm.display_name = found.label
  memberForm.email = found.email ?? ''
}

function saveMember() {
  const options = { preserveScroll: true, onSuccess: () => { memberDialog.value = false; memberForm.reset() } }

  editingMember.value
    ? memberForm.patch(route('project-management.members.update', [props.project.id, editingMember.value.id]), options)
    : memberForm.post(route('project-management.members.store', props.project.id), options)
}

function removeMember(member: { id: string; name: string | null }) {
  confirmer.value.open({
    title: t('project_management.delivery.confirm_remove_member_title'),
    message: t('project_management.delivery.confirm_remove_member_body', { name: member.name ?? '—' }),
    confirmText: t('project_management.delivery.remove_member'),
    icon: 'tabler-user-minus',
  }, () => router.delete(
    route('project-management.members.destroy', [props.project.id, member.id]),
    { preserveScroll: true },
  ))
}

// ── Non-labour cost ──────────────────────────────────────────────────────────

const expenseDialog = ref(false)

const expenseForm = useForm({
  category: 'material', description: '', amount: null as number | null,
  incurred_on: new Date().toISOString().slice(0, 10),
  task_id: '', invoice_number: '', is_billable: true,
  // Not `submit`: Inertia's useForm has a `submit()` method, and a data field of that name is
  // silently overwritten by it — the checkbox would send a function and always read as true.
  submit_for_approval: true,
})

function saveExpense() {
  expenseForm.transform(d => ({ ...d, task_id: d.task_id || null }))
    .post(route('project-management.expenses.store', props.project.id), {
      preserveScroll: true,
      onSuccess: () => { expenseDialog.value = false; expenseForm.reset() },
    })
}

function markPaid(expense: Expense) {
  confirmer.value.open({
    title: t('project_management.delivery.confirm_paid_title'),
    message: t('project_management.delivery.confirm_paid_body', { description: expense.description }),
    confirmText: t('project_management.delivery.mark_paid'),
    confirmColor: 'success',
    icon: 'tabler-cash',
  }, () => router.post(
    route('project-management.expenses.paid', [props.project.id, expense.id]),
    {},
    { preserveScroll: true },
  ))
}

function removeExpense(expense: Expense) {
  confirmer.value.open({
    title: t('project_management.delivery.confirm_remove_cost_title'),
    message: t('project_management.delivery.confirm_remove_cost_body', { description: expense.description }),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(
    route('project-management.expenses.destroy', [props.project.id, expense.id]),
    { preserveScroll: true },
  ))
}

const expenseStatusColor: Record<string, string> = {
  draft: 'grey', submitted: 'info', approved: 'success', rejected: 'error', paid: 'primary',
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.show', project.id))">
      {{ project.code }} · {{ project.name }}
    </VBtn>

    <h4 class="text-h4 mb-4">{{ t('project_management.delivery.title') }}</h4>

    <VTabs v-model="tab" density="compact" class="mb-4">
      <VTab value="people">{{ t('project_management.delivery.people') }}</VTab>
      <VTab value="time">
        {{ t('project_management.delivery.time') }}
        <VChip v-if="awaiting.length" size="x-small" color="info" class="ms-1">{{ awaiting.length }}</VChip>
      </VTab>
      <VTab v-if="can.costs" value="cost">{{ t('project_management.delivery.cost') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- People -->
      <VWindowItem value="people">
        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.delivery.the_team') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.delivery.window', window) }}</VCardSubtitle>
            <template #append>
              <VBtn v-if="can.members" color="primary" size="small" prepend-icon="tabler-user-plus"
                    @click="openAddMember">
                {{ t('project_management.delivery.add_member') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />

          <VTable v-if="members.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.delivery.col_person') }}</th>
                <th>{{ t('project_management.delivery.col_role') }}</th>
                <th>{{ t('project_management.delivery.col_tasks') }}</th>
                <th class="text-right">{{ t('project_management.delivery.col_hours') }}</th>
                <th class="text-right">{{ t('project_management.delivery.col_peak') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <!--
                Driven by `members`, not by `team`: `team` only carries people who have work in the
                window, so somebody added this morning would not appear on the page that added them.
              -->
              <tr v-for="person in members" :key="person.id">
                <td>{{ person.name }}</td>
                <td>
                  <VChip size="x-small" label>{{ t(`project_management.roles.${person.role}`) }}</VChip>
                </td>
                <td>{{ team.find(x => x.id === person.id)?.tasks ?? 0 }}</td>
                <!--
                  Hours booked in the window, and how many of them have been signed off. Approving
                  somebody's timesheet used to change nothing anybody could see here: the row showed
                  what they were committed to and what HR recorded, never the hours themselves, so
                  an approver had no way to tell a full timesheet from an empty one.
                -->
                <td class="text-right" style="font-variant-numeric: tabular-nums">
                  <template v-if="team.find(x => x.id === person.id)?.booked">
                    {{ team.find(x => x.id === person.id)?.approved }}
                    <span class="text-medium-emphasis">/ {{ team.find(x => x.id === person.id)?.booked }}</span>
                    <div v-if="team.find(x => x.id === person.id)?.awaiting" class="text-caption text-warning">
                      {{ t('project_management.delivery.hours_awaiting', {
                        hours: team.find(x => x.id === person.id)?.awaiting,
                      }) }}
                    </div>
                  </template>
                  <span v-else class="text-caption text-disabled">—</span>
                </td>
                <td class="text-right">
                  <template v-if="team.find(x => x.id === person.id)">
                    <VChip size="small" label
                           :color="(team.find(x => x.id === person.id)?.peak ?? 0) > 100 ? 'error' : 'default'">
                      {{ team.find(x => x.id === person.id)?.peak }}%
                    </VChip>
                    <!-- The days that will actually go wrong, named rather than averaged away. -->
                    <div v-if="team.find(x => x.id === person.id)?.overloaded" class="text-caption text-error">
                      {{ t('project_management.delivery.overloaded_days', {
                        count: team.find(x => x.id === person.id)?.overloaded,
                      }) }}
                    </div>
                  </template>
                  <span v-else class="text-caption text-medium-emphasis">
                    {{ t('project_management.delivery.no_work_in_window') }}
                  </span>
                </td>
                <td class="text-right text-no-wrap">
                  <template v-if="can.members">
                    <!--
                      A portal login for a client or a subcontractor.
                      
                      The invitation endpoint existed and no screen called it, so a client could be
                      added to a project and then had no way to be let in — the only route was the
                      console. Offered only where it would work: an employee signs in through the
                      front door, and somebody who already has a login needs nothing.
                    -->
                    <!--
                      Always offered to a client or a subcontractor, whether or not they already
                      have a login. A link that never arrived, or one that was followed and did not
                      appear to do anything, both need the same answer — and hiding the button once
                      a login exists left nobody able to help them.
                    -->
                    <VBtn
                      v-if="person.external"
                      size="small" variant="text" icon="tabler-mail-forward" color="primary"
                      :title="person.has_login
                        ? t('project_management.delivery.resend_invite', { name: person.name })
                        : t('project_management.delivery.invite_member', { name: person.name })"
                      @click="openInvite(person)"
                    />
                    <VIcon
                      v-if="person.external && person.has_login"
                      icon="tabler-user-check" size="18" color="success" class="me-1"
                      :title="t('project_management.delivery.has_login')"
                    />
                    <VBtn size="small" variant="text" icon="tabler-edit"
                          :title="t('project_management.actions.edit')" @click="openEditMember(person)" />
                    <VBtn size="small" variant="text" icon="tabler-user-minus" color="error"
                          :title="t('project_management.delivery.remove_member')" @click="removeMember(person)" />
                  </template>
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            <p class="mb-3">{{ t('project_management.delivery.no_team') }}</p>
            <!-- Says what to do next, because an empty team is the reason every other tab on this
                 page is empty too. -->
            <p class="text-caption mb-4">{{ t('project_management.delivery.no_team_hint') }}</p>
            <VBtn v-if="can.members" color="primary" prepend-icon="tabler-user-plus" @click="openAddMember">
              {{ t('project_management.delivery.add_member') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- Time -->
      <VWindowItem value="time">
        <VRow>
          <VCol cols="12" md="4">
            <VCard border>
              <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.delivery.book_hours') }}</VCardTitle></VCardItem>
              <VDivider />
              <VCardText>
                <VAutocomplete
                  v-model="timeForm.member_id"
                  :items="members.map(m => ({ title: m.name ?? '—', value: m.id }))"
                  :label="t('project_management.delivery.col_person')"
                  :error-messages="timeForm.errors.member_id"
                  variant="outlined" density="compact" class="mb-3"
                />
                <VAutocomplete
                  v-model="timeForm.task_id"
                  :items="[{ title: t('project_management.delivery.whole_project'), value: '' }, ...tasks.map(x => ({ title: x.label, value: x.id }))]"
                  :label="t('project_management.tasks.col_name')"
                  variant="outlined" density="compact" class="mb-3"
                />
                <VTextField
                  v-model="timeForm.worked_on" type="date"
                  :label="t('project_management.delivery.worked_on')"
                  :error-messages="timeForm.errors.worked_on"
                  variant="outlined" density="compact" class="mb-3"
                />
                <VTextField
                  v-model.number="timeForm.hours" type="number" inputmode="decimal" step="0.25" min="0.25" max="24"
                  :label="t('project_management.delivery.hours')"
                  :error-messages="timeForm.errors.hours"
                  variant="outlined" density="compact" class="mb-3"
                />
                <VTextField
                  v-if="can.costs"
                  v-model.number="timeForm.rate_per_hour" type="number" inputmode="decimal" min="0"
                  :label="t('project_management.delivery.rate')"
                  :hint="project.timesheet_approval_required
                    ? t('project_management.delivery.rate_hint')
                    : t('project_management.delivery.rate_hint_no_approval')" persistent-hint
                  variant="outlined" density="compact" class="mb-3"
                />
                <VTextarea
                  v-model="timeForm.note" rows="2"
                  :label="t('project_management.delivery.note')"
                  variant="outlined" density="compact" class="mb-3"
                />
                <!--
                  The button says what will actually happen. On a project that does not require
                  sign-off the hours are approved as they are booked, and calling that "submit for
                  approval" would describe a queue nobody is waiting in.
                -->
                <VBtn color="primary" block :loading="timeForm.processing"
                      :disabled="!timeForm.member_id" @click="logTime">
                  {{ project.timesheet_approval_required
                      ? t('project_management.delivery.submit_hours')
                      : t('project_management.delivery.book_hours_direct') }}
                </VBtn>
              </VCardText>
            </VCard>

            <VCard border class="mt-4">
              <VCardText>
                <div class="d-flex justify-space-between text-body-2">
                  <span>{{ t('project_management.delivery.approved_hours') }}</span>
                  <strong>{{ hours.approved_hours }}</strong>
                </div>
                <div class="d-flex justify-space-between text-body-2">
                  <span>{{ t('project_management.delivery.pending_hours') }}</span>
                  <strong>{{ hours.pending_hours }}</strong>
                </div>
                <!-- Kept apart on purpose: pending hours are a claim, not a cost. -->
                <p class="text-caption text-medium-emphasis mt-2 mb-0">
                  {{ t('project_management.delivery.pending_explains') }}
                </p>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" md="8">
            <VCard border>
              <VCardItem>
                <VCardTitle class="text-body-1">{{ t('project_management.delivery.the_hours') }}</VCardTitle>
              </VCardItem>
              <VDivider />

              <VTable v-if="entries.length" density="comfortable">
                <thead>
                  <tr>
                    <th>{{ t('project_management.delivery.worked_on') }}</th>
                    <th>{{ t('project_management.delivery.col_person') }}</th>
                    <th>{{ t('project_management.tasks.col_name') }}</th>
                    <th class="text-right">{{ t('project_management.delivery.hours') }}</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="entry in entries" :key="entry.id">
                    <td class="text-no-wrap text-caption">{{ entry.worked_on }}</td>
                    <td>{{ entry.member }}</td>
                    <td class="text-caption">
                      {{ entry.task ?? t('project_management.delivery.whole_project') }}
                      <div v-if="entry.reason" class="text-caption text-error">{{ entry.reason }}</div>
                    </td>
                    <td class="text-right">{{ entry.hours }}</td>
                    <td class="text-right text-no-wrap">
                      <VChip size="x-small" label :color="statusColor[entry.status] ?? 'grey'" class="me-1">
                        {{ t(`project_management.delivery.status_${entry.status}`) }}
                      </VChip>
                      <template v-if="can.approve && entry.status === 'submitted'">
                        <VBtn size="small" variant="text" icon="tabler-check" color="success"
                              :title="t('project_management.delivery.approve')" @click="approve(entry)" />
                        <VBtn size="small" variant="text" icon="tabler-x" color="error"
                              :title="t('project_management.delivery.reject')" @click="rejecting = entry" />
                      </template>
                    </td>
                  </tr>
                </tbody>
              </VTable>

              <VCardText v-else class="text-center text-medium-emphasis py-8">
                {{ t('project_management.delivery.no_hours') }}
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- Cost -->
      <VWindowItem v-if="can.costs && cost" value="cost">
        <VRow class="mb-2">
          <VCol cols="12" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.delivery.budget') }}</div>
              <div class="text-h6">{{ money(cost.budget) }}</div>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.delivery.actual') }}</div>
              <div class="text-h6">{{ money(cost.actual) }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ t('project_management.delivery.of_which_labour', { amount: money(cost.labour) }) }}
              </div>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.delivery.committed') }}</div>
              <div class="text-h6">{{ money(cost.committed) }}</div>
              <!-- Money that is gone but has not left: the difference between "we have spent 60%"
                   and "we have spent 60% and owe another 30%". -->
              <div class="text-caption text-medium-emphasis">{{ t('project_management.delivery.committed_explains') }}</div>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" md="3">
            <VCard border><VCardText>
              <div class="text-caption text-medium-emphasis">{{ t('project_management.delivery.remaining') }}</div>
              <div class="text-h6" :class="cost.is_over ? 'text-error' : ''">{{ money(cost.remaining) }}</div>
              <div v-if="cost.spent_pct !== null" class="text-caption text-medium-emphasis">
                {{ t('project_management.delivery.spent_pct', { pct: cost.spent_pct }) }}
              </div>
              <div v-else class="text-caption text-medium-emphasis">{{ t('project_management.delivery.no_budget') }}</div>
            </VCardText></VCard>
          </VCol>
        </VRow>

        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.delivery.by_task') }}</VCardTitle></VCardItem>
          <VDivider />

          <VTable v-if="cost.tasks.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.tasks.col_name') }}</th>
                <th class="text-right">{{ t('project_management.delivery.budget') }}</th>
                <th class="text-right">{{ t('project_management.delivery.actual') }}</th>
                <th class="text-right">{{ t('project_management.tasks.col_progress') }}</th>
                <th class="text-right">{{ t('project_management.schedule.col_variance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in cost.tasks" :key="row.id">
                <td><span class="text-medium-emphasis me-1">{{ row.code }}</span>{{ row.name }}</td>
                <td class="text-right">{{ money(row.budget) }}</td>
                <td class="text-right">{{ money(row.actual) }}</td>
                <!-- Cost beside progress: 80% spent on 20% of the work is the number that matters,
                     and neither figure says it alone. -->
                <td class="text-right">{{ row.progress }}%</td>
                <td class="text-right" :class="row.is_over ? 'text-error' : ''">{{ money(row.variance) }}</td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.delivery.no_costs') }}
          </VCardText>
        </VCard>

        <!--
          Non-labour cost, entered here. Without it "actual" only ever counts hours, and a project
          with a three-million budget reports zero spent while the materials invoices pile up.
        -->
        <VCard border class="mt-4">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.delivery.other_costs') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.delivery.other_costs_hint') }}</VCardSubtitle>
            <template #append>
              <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="expenseDialog = true">
                {{ t('project_management.delivery.add_cost') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />

          <VCardText v-if="expenseSummary && expenseSummary.by_category.length" class="d-flex flex-wrap gap-2 pb-0">
            <VChip v-for="row in expenseSummary.by_category" :key="row.category" size="small" label>
              {{ t(`project_management.delivery.category_${row.category}`) }} · {{ money(row.amount) }}
            </VChip>
          </VCardText>

          <VTable v-if="expenses.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.delivery.col_date') }}</th>
                <th>{{ t('project_management.delivery.col_description') }}</th>
                <th>{{ t('project_management.delivery.col_category') }}</th>
                <th class="text-right">{{ t('project_management.delivery.col_amount') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="expense in expenses" :key="expense.id">
                <td class="text-no-wrap text-caption">{{ expense.incurred_on }}</td>
                <td>
                  {{ expense.description }}
                  <div v-if="expense.task" class="text-caption text-medium-emphasis">{{ expense.task }}</div>
                  <div v-if="expense.invoice" class="text-caption text-medium-emphasis">{{ expense.invoice }}</div>
                  <div v-if="expense.reason" class="text-caption text-error">{{ expense.reason }}</div>
                </td>
                <td class="text-caption">{{ t(`project_management.delivery.category_${expense.category}`) }}</td>
                <td class="text-right">{{ money(expense.amount) }}</td>
                <td class="text-right text-no-wrap">
                  <VChip size="x-small" label :color="expenseStatusColor[expense.status] ?? 'grey'" class="me-1">
                    {{ t(`project_management.delivery.cost_status_${expense.status}`) }}
                  </VChip>
                  <VBtn
                    v-if="can.approve && expense.status === 'approved'"
                    size="small" variant="text" icon="tabler-cash" color="success"
                    :title="t('project_management.delivery.mark_paid')" @click="markPaid(expense)"
                  />
                  <VBtn
                    v-if="expense.editable" size="small" variant="text" icon="tabler-trash" color="error"
                    :title="t('project_management.actions.delete')" @click="removeExpense(expense)"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.delivery.no_other_costs') }}
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- Adding somebody to the project -->
    <VDialog :fullscreen="smAndDown" v-model="memberDialog" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editingMember
              ? t('project_management.delivery.edit_member')
              : t('project_management.delivery.add_member') }}
          </VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VAutocomplete
            v-if="directory && !editingMember"
            :items="candidates.map(c => ({ title: c.label, value: c.id }))"
            :loading="searchingDirectory"
            :label="t('project_management.delivery.find_employee')"
            :hint="t('project_management.delivery.find_employee_hint')" persistent-hint
            variant="outlined" density="compact" class="mb-3" clearable
            @update:search="searchDirectory"
            @update:model-value="pickCandidate"
          />

          <VTextField
            v-model="memberForm.display_name"
            :label="t('project_management.delivery.member_name')"
            :hint="directory && !editingMember ? t('project_management.delivery.member_name_hint') : ''"
            :persistent-hint="directory && !editingMember"
            :error-messages="memberForm.errors.display_name"
            variant="outlined" density="compact" class="mb-3"
          />
          <VTextField
            v-model="memberForm.email" type="email"
            :label="t('project_management.delivery.member_email')"
            :error-messages="memberForm.errors.email"
            variant="outlined" density="compact" class="mb-3"
          />
          <VSelect
            v-model="memberForm.role_key"
            :items="roles.map(r => ({ title: t(`project_management.roles.${r}`), value: r }))"
            :label="t('project_management.delivery.col_role')"
            :hint="t('project_management.delivery.role_hint')" persistent-hint
            :error-messages="memberForm.errors.role_key"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="memberDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="memberForm.processing" :disabled="!memberForm.display_name"
                @click="saveMember">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Recording a non-labour cost -->
    <VDialog :fullscreen="smAndDown" v-model="expenseDialog" max-width="620">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.delivery.add_cost') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="expenseForm.category"
                :items="categories.map(c => ({ title: t(`project_management.delivery.category_${c}`), value: c }))"
                :label="t('project_management.delivery.col_category')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="expenseForm.incurred_on" type="date"
                :label="t('project_management.delivery.col_date') "
                :error-messages="expenseForm.errors.incurred_on"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="expenseForm.description"
                :label="t('project_management.delivery.col_description')"
                :error-messages="expenseForm.errors.description"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="expenseForm.amount" inputmode="decimal"
                :label="t('project_management.delivery.col_amount')"
                :error-messages="expenseForm.errors.amount"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="expenseForm.invoice_number"
                :label="t('project_management.delivery.invoice_number')"
                :hint="t('project_management.delivery.invoice_hint')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VAutocomplete
                v-model="expenseForm.task_id"
                :items="[
                  { title: t('project_management.delivery.whole_project'), value: '' },
                  ...tasks.map(x => ({ title: x.label, value: x.id })),
                ]"
                :label="t('project_management.tasks.col_name')"
                :hint="t('project_management.delivery.cost_task_hint')" persistent-hint
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VCheckbox v-model="expenseForm.is_billable" density="compact" hide-details
                         :label="t('project_management.delivery.billable')" />
              <VCheckbox v-model="expenseForm.submit_for_approval" density="compact" hide-details
                         :label="t('project_management.delivery.submit_for_approval')" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="expenseDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="expenseForm.processing"
                :disabled="!expenseForm.description || !expenseForm.amount" @click="saveExpense">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :fullscreen="smAndDown" :model-value="rejecting !== null" max-width="480" @update:model-value="rejecting = null">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.delivery.reject') }}</VCardTitle></VCardItem>
        <VDivider />
        <VCardText>
          <VTextarea
            v-model="rejectForm.reason" rows="3"
            :label="t('project_management.delivery.reject_reason')"
            :hint="t('project_management.delivery.reject_reason_hint')" persistent-hint
            :error-messages="rejectForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="rejecting = null">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" :disabled="!rejectForm.reason" @click="reject">
            {{ t('project_management.delivery.reject') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>

    <!-- ── Invite a portal login ──────────────────────────────────────────── -->
    <VDialog v-model="inviteDialog" max-width="480">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.delivery.invite_title') }}</VCardTitle>
          <VCardSubtitle class="text-wrap">
            {{ t('project_management.delivery.invite_sub', { name: invitee?.name }) }}
          </VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VTextField
            v-model="inviteForm.email" type="email" autofocus
            :label="t('project_management.delivery.invite_email')"
            :hint="t('project_management.delivery.invite_email_hint')" persistent-hint
            :error-messages="inviteForm.errors.email"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="inviteDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="inviteForm.processing" :disabled="!inviteForm.email.trim()"
            @click="sendInvite"
          >{{ t('project_management.delivery.send_invite') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── The issued invitation ──────────────────────────────────────────── -->
    <VDialog :model-value="issued !== null" max-width="620" @update:model-value="issued = null">
      <VCard v-if="issued">
        <VCardItem>
          <VCardTitle>
            {{ issued.delivered
              ? t('project_management.delivery.invite_on_its_way')
              : t('project_management.delivery.invite_not_emailed') }}
          </VCardTitle>
          <VCardSubtitle class="text-wrap">
            {{ issued.delivered
              ? t('project_management.delivery.invite_sent_to', { name: issued.name, email: issued.email })
              : t('project_management.delivery.invite_send_yourself', { name: issued.name }) }}
          </VCardSubtitle>
        </VCardItem>
        <VDivider />

        <VCardText>
          <!-- Said plainly rather than as a red error: the invitation is fine, only the post failed. -->
          <VAlert
            v-if="!issued.delivered" type="warning" variant="tonal" density="compact" class="mb-4"
          >{{ t('project_management.delivery.invite_mail_failed') }}</VAlert>

          <VTextarea
            :model-value="issued.url" readonly rows="2" auto-grow
            :label="t('project_management.delivery.invite_link')"
            variant="outlined" density="compact" hide-details
            @focus="(e: any) => e.target.select()"
          />

          <div class="d-flex align-center ga-2 mt-3">
            <VBtn
              :color="copied ? 'success' : 'primary'"
              :prepend-icon="copied ? 'tabler-check' : 'tabler-copy'"
              @click="copyInvite"
            >
              {{ copied ? t('project_management.delivery.copied') : t('project_management.delivery.copy_link') }}
            </VBtn>
            <span class="text-caption text-medium-emphasis">
              {{ t('project_management.delivery.invite_expires', { date: issued.expires }) }}
            </span>
          </div>
        </VCardText>

        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="issued = null">{{ t('project_management.actions.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
</template>
