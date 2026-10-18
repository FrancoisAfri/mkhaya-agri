<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import AssigneeStack from '@/Components/Shared/AssigneeStack.vue'

const { t } = useI18n()

/** One dialog for the drawer's two destructive actions; opened imperatively. */
const confirmer = ref()
const route = ziggyRoute

type Detail = {
  id: string; code: string; name: string; status: string
  description: string | null; priority: string | null
  planned_start: string | null; planned_end: string | null
  checklist: Array<{ id: string; text: string; is_done: boolean; done_by: string | null; done_at: string | null }>
  comments: Array<{ id: string; parent_id: string | null; body: string; author: string; is_mine: boolean; created_at: string; edited: boolean }>
  assignees: Array<{ id: string; name: string | null; role: string | null; allocation_pct: number; is_lead: boolean }>
  team: Array<{ id: string; name: string | null; role: string | null }>
  // Members with a login, keyed by user id — a mention is a user, not a membership.
  taggable: Array<{ id: number; name: string | null }>
  watchers: Array<{ id: number; name: string; source: string; is_muted: boolean }>
  is_watching: boolean
  attachments: Array<{ id: string; filename: string; size: number | null; by: string | null; is_mine: boolean }>
  recurrence: { id: string; rrule: string; description: string; is_active: boolean; generated: number; next_after: string | null } | null
  occurrence_of: { date: string | null } | null
}

const props = defineProps<{
  modelValue: boolean
  projectId: string
  detail: Detail | null
  can: { editTasks: boolean; createTasks: boolean }
}>()

const emit = defineEmits<{ 'update:modelValue': [boolean] }>()

const open = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

const tab = ref('people')

/** Every action reloads only this prop, so the page around the drawer is left alone. */
const reload = { preserveScroll: true, preserveState: true, only: ['taskDetail'] as string[] }

// ── Checklist ────────────────────────────────────────────────────────────────

const newItem = ref('')

function addItem() {
  if (!newItem.value.trim()) return

  router.post(
    route('project-management.checklist.store', [props.projectId, props.detail!.id]),
    { text: newItem.value },
    { ...reload, onSuccess: () => { newItem.value = '' } },
  )
}

function toggleItem(id: string, isDone: boolean) {
  router.patch(route('project-management.checklist.update', [props.projectId, id]), { is_done: isDone }, reload)
}

function removeItem(id: string) {
  router.delete(route('project-management.checklist.destroy', [props.projectId, id]), reload)
}

const checklistDone = computed(() => props.detail?.checklist.filter(i => i.is_done).length ?? 0)

// ── Archiving ────────────────────────────────────────────────────────────────

/**
 * Take a task off the board without losing it.
 *
 * A project accumulates work that is neither done nor cancelled — superseded, deferred, raised
 * twice — and leaving it in a column buries what is live. Deleting it loses the reason it existed,
 * which is exactly what somebody asks about months later.
 *
 * It lives here rather than in the status dropdown because the board deliberately draws no archived
 * column, so there is nowhere else on any screen to make this particular change.
 */
const archiveBusy = ref(false)

function setArchived(archived: boolean) {
  archiveBusy.value = true

  router.patch(
    route('project-management.board.move', [props.projectId, props.detail!.id]),
    { status: archived ? 'archived' : 'not_started' },
    {
      preserveScroll: true,
      onFinish: () => { archiveBusy.value = false },
      onSuccess: () => { open.value = false },
    },
  )
}

// ── Assignment ───────────────────────────────────────────────────────────────

const assignForm = useForm({ member_id: null as string | null, allocation_pct: 100, is_lead: false })

/**
 * The team minus whoever is already on this task.
 *
 * Re-picking someone already assigned would silently update their allocation rather than add them,
 * which reads as the button doing nothing. Leaving them out makes the list mean "who else".
 */
const assignable = computed(() => {
  const already = new Set((props.detail?.assignees ?? []).map(a => a.id))

  return (props.detail?.team ?? []).filter(m => !already.has(m.id))
})

function assign() {
  if (!assignForm.member_id || !props.detail) return

  assignForm.post(route('project-management.assign.store', [props.projectId, props.detail.id]), {
    preserveScroll: true,
    // The drawer is loaded on its own with ?task=…, so only that part comes back.
    only: ['taskDetail', 'tasks'],
    onSuccess: () => assignForm.reset(),
  })
}

function unassign(memberId: string) {
  if (!props.detail) return

  router.delete(route('project-management.assign.destroy', [props.projectId, props.detail.id, memberId]), {
    preserveScroll: true,
    only: ['taskDetail', 'tasks'],
  })
}

// ── Conversation ─────────────────────────────────────────────────────────────

/**
 * Tagging somebody on a comment.
 *
 * Typing `@` opens the project's people and picking one writes their name into the comment. The id
 * is recorded as they are picked rather than parsed back out of the text afterwards — two people
 * called Thabo on a site is exactly the case where re-matching a name silently sends the question
 * to the wrong one, and a mention that resolves to nobody looks identical to one that went unread.
 *
 * Only people with an account are offered. Somebody with no login cannot be notified, so tagging
 * them would produce a mention that reaches nobody.
 */
const commentForm = useForm({ body: '', reply_to: '', mentions: [] as number[] })

const commentBox = ref<any>(null)
const mentionOpen = ref(false)
const mentionQuery = ref('')
const mentionIndex = ref(0)

/** Where the `@` that opened the menu sits, so the right fragment is replaced on pick. */
const mentionAt = ref(-1)

/** The people already tagged, so the comment box can show who will actually be notified. */
const tagged = computed(() =>
  (props.detail?.taggable ?? []).filter(p => commentForm.mentions.includes(p.id)))

const mentionMatches = computed(() => {
  const q = mentionQuery.value.toLowerCase()

  return (props.detail?.taggable ?? [])
    .filter(p => !commentForm.mentions.includes(p.id))
    .filter(p => !q || (p.name ?? '').toLowerCase().includes(q))
    .slice(0, 6)
})

function textarea(): HTMLTextAreaElement | null {
  return commentBox.value?.$el?.querySelector('textarea') ?? null
}

/**
 * Decide, after every keystroke, whether the caret is inside an `@…` fragment.
 *
 * Read from the caret backwards rather than from a keypress handler: that way pasting, deleting
 * and clicking into the middle of an existing word all behave the same as typing, and the menu
 * closes on its own when the caret leaves.
 */
function syncMention() {
  const el = textarea()

  if (!el) return

  /*
   * Read from the element, not from the form.
   *
   * Vuetify's v-model update and the native input event do not have a guaranteed order, so the
   * bound value can be one keystroke behind — which for a menu triggered by a single character
   * means it never opens on the character that triggers it.
   */
  const upto = el.value.slice(0, el.selectionStart)
  const at = upto.lastIndexOf('@')

  // Only when `@` starts a word — an email address in the middle of a sentence is not a mention.
  const startsWord = at === 0 || /\s/.test(upto[at - 1] ?? '')
  const fragment = at === -1 ? '' : upto.slice(at + 1)

  if (at === -1 || !startsWord || /\s/.test(fragment)) {
    mentionOpen.value = false

    return
  }

  mentionAt.value = at
  mentionQuery.value = fragment
  mentionIndex.value = 0
  mentionOpen.value = true
}

function pickMention(person: { id: number; name: string | null }) {
  const el = textarea()
  const caret = el ? el.selectionStart : commentForm.body.length
  const name = (person.name ?? '').trim()

  // The written name is what the reader sees; the id is what gets notified.
  commentForm.body =
    commentForm.body.slice(0, mentionAt.value) + '@' + name + ' ' + commentForm.body.slice(caret)

  if (!commentForm.mentions.includes(person.id)) {
    commentForm.mentions.push(person.id)
  }

  mentionOpen.value = false

  nextTick(() => {
    const box = textarea()

    if (box) {
      const pos = mentionAt.value + name.length + 2
      box.focus()
      box.setSelectionRange(pos, pos)
    }
  })
}

/** Untag somebody, and take their name out of the text with them. */
function untag(person: { id: number; name: string | null }) {
  commentForm.mentions = commentForm.mentions.filter(id => id !== person.id)
  commentForm.body = commentForm.body.replace('@' + (person.name ?? '').trim(), '').replace(/\s{2,}/g, ' ')
}

function mentionKeydown(e: KeyboardEvent) {
  if (!mentionOpen.value || !mentionMatches.value.length) return

  if (e.key === 'ArrowDown') {
    e.preventDefault()
    mentionIndex.value = (mentionIndex.value + 1) % mentionMatches.value.length
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    mentionIndex.value = (mentionIndex.value - 1 + mentionMatches.value.length) % mentionMatches.value.length
  } else if (e.key === 'Enter' || e.key === 'Tab') {
    // Only while the menu is open — Enter is otherwise a newline, which is what a comment box owes
    // anybody writing more than one sentence.
    e.preventDefault()
    pickMention(mentionMatches.value[mentionIndex.value])
  } else if (e.key === 'Escape') {
    mentionOpen.value = false
  }
}
const editingComment = ref<string | null>(null)
const editBody = ref('')

/** Root comments with their replies attached — one level, which is all there is. */
const threaded = computed(() => {
  const roots = (props.detail?.comments ?? []).filter(c => !c.parent_id)

  return roots.map(root => ({
    ...root,
    replies: (props.detail?.comments ?? []).filter(c => c.parent_id === root.id),
  }))
})

function postComment(replyTo?: string) {
  commentForm.transform(d => ({ ...d, reply_to: replyTo || null }))
    .post(route('project-management.comments.store', [props.projectId, props.detail!.id]), {
      ...reload,
      onSuccess: () => { commentForm.reset(); replyingTo.value = null },
    })
}

const replyingTo = ref<string | null>(null)
const replyBody = ref('')

function postReply(rootId: string) {
  if (!replyBody.value.trim()) return

  router.post(
    route('project-management.comments.store', [props.projectId, props.detail!.id]),
    { body: replyBody.value, reply_to: rootId },
    { ...reload, onSuccess: () => { replyBody.value = ''; replyingTo.value = null } },
  )
}

function saveEdit(id: string) {
  router.patch(
    route('project-management.comments.update', [props.projectId, id]),
    { body: editBody.value },
    { ...reload, onSuccess: () => { editingComment.value = null } },
  )
}

function removeComment(id: string) {
  confirmer.value.open({
    title: t('project_management.tasks.confirm_delete_comment_title'),
    message: t('project_management.tasks.confirm_delete_comment'),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-message-off',
  }, () => router.delete(route('project-management.comments.destroy', [props.projectId, id]), reload))
}

// ── Watchers ─────────────────────────────────────────────────────────────────

const watchBusy = ref(false)

function toggleWatch() {
  const args = [props.projectId, props.detail!.id]
  const done = { ...reload, onFinish: () => { watchBusy.value = false } }

  watchBusy.value = true

  props.detail!.is_watching
    ? router.delete(route('project-management.watch.destroy', args), done)
    : router.post(route('project-management.watch.store', args), {}, done)
}

// ── Files ────────────────────────────────────────────────────────────────────

const uploadForm = useForm<{ file: File | null }>({ file: null })

function upload() {
  if (!uploadForm.file) return

  uploadForm.post(route('project-management.attachments.store', [props.projectId, props.detail!.id]), {
    ...reload,
    forceFormData: true,
    onSuccess: () => uploadForm.reset(),
  })
}

function removeAttachment(id: string) {
  router.delete(route('project-management.attachments.destroy', [props.projectId, id]), reload)
}

function humanSize(bytes: number | null) {
  if (!bytes) return ''
  return bytes < 1024 * 1024
    ? `${Math.round(bytes / 1024)} KB`
    : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

// ── Recurrence ───────────────────────────────────────────────────────────────

const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']

const recurrenceForm = useForm({
  freq: 'WEEKLY',
  interval: 1,
  byday: [] as string[],
  bymonthday: null as number | null,
  count: null as number | null,
  starts_at: new Date().toISOString().slice(0, 10),
  ends_at: '',
  horizon_days: 60,
})

function saveRecurrence() {
  recurrenceForm.transform(d => ({
    ...d,
    ends_at: d.ends_at || null,
    byday: d.freq === 'WEEKLY' ? d.byday : [],
    bymonthday: d.freq === 'MONTHLY' ? d.bymonthday : null,
  })).post(route('project-management.recurrence.store', [props.projectId, props.detail!.id]), {
    preserveScroll: true,
  })
}

function toggleRecurrence(active: boolean) {
  router.patch(
    route('project-management.recurrence.update', [props.projectId, props.detail!.recurrence!.id]),
    { is_active: active },
    reload,
  )
}

// ── Editing the task itself ──────────────────────────────────────────────────

/**
 * Correcting the task where it is read.
 *
 * The drawer showed a task's conversation, its files and who was on it, and offered no way to fix
 * the two things people most often need to: the wording, and when it is due. The only route was to
 * delete and retype — which throws away the comments, the files and the history hanging off it.
 */
const editing = ref(false)

const editForm = useForm({
  name: '', description: '', priority: 'medium',
  planned_start: '', planned_end: '',
})

function startEditing() {
  if (!props.detail) return

  editForm.name          = props.detail.name
  editForm.description   = props.detail.description ?? ''
  editForm.priority      = props.detail.priority ?? 'medium'
  editForm.planned_start = props.detail.planned_start ?? ''
  editForm.planned_end   = props.detail.planned_end ?? ''
  editForm.clearErrors()

  editing.value = true
}

function saveEdits() {
  if (!props.detail || !editForm.name.trim()) return

  editForm
    .transform(d => ({
      ...d,
      description:   d.description || null,
      // Cleared rather than omitted: leaving a field out means "unchanged", and somebody who
      // emptied the due date meant to remove it.
      planned_start: d.planned_start || null,
      planned_end:   d.planned_end || null,
    }))
    .patch(route('project-management.tasks.update', [props.projectId, props.detail.id]), {
      preserveScroll: true,
      // The board behind the drawer shows the name and the date too, so both have to come back.
      only: ['taskDetail', 'tasks'],
      onSuccess: () => { editing.value = false },
    })
}

const stopping = ref(false)

function stopRecurrence() {
  confirmer.value.open({
    title: t('project_management.tasks.confirm_stop_recurrence_title'),
    message: t('project_management.tasks.confirm_stop_recurrence'),
    confirmText: t('project_management.tasks.stop_recurrence'),
    confirmColor: 'warning',
    icon: 'tabler-repeat-off',
  }, () => {
    stopping.value = true

    router.delete(route('project-management.recurrence.destroy', [props.projectId, props.detail!.recurrence!.id]), {
      preserveScroll: true,
      onFinish: () => { stopping.value = false },
    })
  })
}

// Reset to the first tab whenever a different task is opened, so the drawer never opens on a tab
// that made sense for the last task.
watch(() => props.detail?.id, () => {
  tab.value = 'people'
  replyingTo.value = null
  editingComment.value = null
  editing.value = false
})
</script>

<template>
  <VNavigationDrawer v-model="open" location="end" temporary width="520">
    <template v-if="detail">
      <div class="pa-4 d-flex align-start justify-space-between">
        <div class="flex-grow-1 pe-2">
          <div class="text-caption text-medium-emphasis">{{ detail.code }}</div>

          <!-- ── Reading ────────────────────────────────────────────────── -->
          <template v-if="!editing">
            <h6 class="text-h6">{{ detail.name }}</h6>

            <!--
              A description block that is there whether or not there is a description.

              It was `v-if="detail.description"`, so a task without one showed nothing at all and
              the field looked as though it did not exist — the only route to it was guessing that
              the pencil hid a second textarea. An empty state that invites the first sentence is
              the difference between a feature people use and one they ask to have built.
            -->
            <p v-if="detail.description" class="text-body-2 text-medium-emphasis mt-1 mb-0"
               style="white-space:pre-wrap">{{ detail.description }}</p>
            <button
              v-else-if="can.editTasks" type="button"
              class="text-body-2 text-disabled mt-1 pm-add-description"
              @click="startEditing"
            >
              {{ t('project_management.tasks.add_description') }}
            </button>

            <div class="d-flex align-center flex-wrap ga-2 mt-2">
              <VChip v-if="detail.occurrence_of" size="x-small" label prepend-icon="tabler-repeat">
                {{ t('project_management.tasks.occurrence_of', { date: detail.occurrence_of.date }) }}
              </VChip>

              <!-- When it is due, said on the face of the task rather than only on the card. -->
              <VChip
                v-if="detail.planned_end" size="x-small" label prepend-icon="tabler-calendar-event"
                :title="t('project_management.tasks.due_on', { date: detail.planned_end })"
              >{{ detail.planned_end }}</VChip>

              <VChip v-if="detail.priority" size="x-small" label variant="tonal">
                {{ t(`project_management.priority.${detail.priority}`) }}
              </VChip>
            </div>

            <!-- Answered in the header rather than behind a tab: whose task this is should not cost
                 a click, and the same faces and colours appear on the card it was opened from. -->
            <AssigneeStack :people="detail.assignees" :size="26" show-empty show-name class="mt-2" />
          </template>

          <!-- ── Correcting ─────────────────────────────────────────────── -->
          <div v-else class="mt-1">
            <VTextarea
              v-model="editForm.name" rows="2" auto-grow autofocus
              :label="t('project_management.tasks.col_name')"
              :error-messages="editForm.errors.name"
              variant="outlined" density="compact" class="mb-2"
            />
            <VTextarea
              v-model="editForm.description" rows="2" auto-grow
              :label="t('project_management.tasks.field_description')"
              :error-messages="editForm.errors.description"
              variant="outlined" density="compact" hide-details="auto" class="mb-2"
            />
            <div class="d-flex ga-2 mb-2">
              <VTextField
                v-model="editForm.planned_start" type="date"
                :label="t('project_management.projects.field_planned_start')"
                variant="outlined" density="compact" hide-details
              />
              <VTextField
                v-model="editForm.planned_end" type="date"
                :label="t('project_management.tasks.field_due')"
                :error-messages="editForm.errors.planned_end"
                variant="outlined" density="compact" hide-details="auto"
              />
            </div>
            <VSelect
              v-model="editForm.priority"
              :items="['low', 'medium', 'high', 'critical'].map(p => ({
                title: t(`project_management.priority.${p}`), value: p,
              }))"
              :label="t('project_management.tasks.field_priority')"
              variant="outlined" density="compact" hide-details class="mb-2"
            />
            <div class="d-flex ga-2">
              <VBtn
                size="small" color="primary" :loading="editForm.processing"
                :disabled="!editForm.name.trim()" @click="saveEdits"
              >{{ t('project_management.actions.save') }}</VBtn>
              <VBtn size="small" variant="text" @click="editing = false">
                {{ t('project_management.actions.cancel') }}
              </VBtn>
            </div>
          </div>
        </div>
        <div class="d-flex align-center">
          <VBtn
            v-if="can.editTasks && !editing"
            icon="tabler-pencil" variant="text" size="small"
            :title="t('project_management.work.edit_task')"
            :aria-label="t('project_management.work.edit_task')"
            @click="startEditing"
          />
          <VBtn
            :loading="watchBusy"
            :icon="detail.is_watching ? 'tabler-eye' : 'tabler-eye-off'"
            :color="detail.is_watching ? 'primary' : undefined"
            variant="text" size="small"
            :title="t(detail.is_watching ? 'project_management.tasks.unwatch' : 'project_management.tasks.watch')"
            @click="toggleWatch"
          />
          <VBtn
            v-if="can.editTasks"
            :loading="archiveBusy"
            :icon="detail.status === 'archived' ? 'tabler-archive-off' : 'tabler-archive'"
            variant="text" size="small"
            :title="t(detail.status === 'archived'
              ? 'project_management.tasks.unarchive'
              : 'project_management.tasks.archive')"
            @click="setArchived(detail.status !== 'archived')"
          />
          <VBtn icon="tabler-x" variant="text" size="small"
               :aria-label="t('project_management.actions.close')" @click="open = false" />
        </div>
      </div>

      <VDivider />

      <VTabs v-model="tab" density="compact" grow>
        <VTab value="people">
          {{ t('project_management.tasks.people') }}
          <VChip v-if="detail.assignees.length" size="x-small" class="ms-1">{{ detail.assignees.length }}</VChip>
        </VTab>
        <VTab value="checklist">
          {{ t('project_management.tasks.checklist') }}
          <VChip v-if="detail.checklist.length" size="x-small" class="ms-1">
            {{ checklistDone }}/{{ detail.checklist.length }}
          </VChip>
        </VTab>
        <VTab value="comments">
          {{ t('project_management.tasks.comments') }}
          <VChip v-if="detail.comments.length" size="x-small" class="ms-1">{{ detail.comments.length }}</VChip>
        </VTab>
        <VTab value="files">
          {{ t('project_management.tasks.files') }}
          <VChip v-if="detail.attachments.length" size="x-small" class="ms-1">{{ detail.attachments.length }}</VChip>
        </VTab>
        <VTab value="repeat">{{ t('project_management.tasks.repeat') }}</VTab>
      </VTabs>

      <VDivider />

      <VWindow v-model="tab" class="pa-4">
        <!--
          Who is doing this task. The drawer could show a task's checklist, its conversation, its
          files and who was watching it — and not who it belonged to, which is the first question
          anybody asks about a task.
        -->
        <VWindowItem value="people">
          <VList v-if="detail.assignees.length" density="compact" class="py-0">
            <VListItem v-for="a in detail.assignees" :key="a.id" class="px-0">
              <template #prepend>
                <AssigneeStack :people="[a]" :size="30" class="me-3" />
              </template>
              <VListItemTitle class="text-body-2">{{ a.name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                <span v-if="a.role">{{ a.role }}</span>
                <span v-if="a.allocation_pct && a.allocation_pct !== 100">
                  · {{ t('project_management.tasks.allocation_of', { pct: a.allocation_pct }) }}
                </span>
                <span v-if="a.is_lead"> · {{ t('project_management.tasks.lead') }}</span>
              </VListItemSubtitle>
              <template #append>
                <VBtn
                  v-if="can.editTasks" icon="tabler-x" size="x-small" variant="text" color="error"
                  :aria-label="t('project_management.tasks.unassign', { name: a.name })"
                  :title="t('project_management.tasks.unassign', { name: a.name })"
                  @click="unassign(a.id)"
                />
              </template>
            </VListItem>
          </VList>

          <p v-else class="text-body-2 text-medium-emphasis">
            {{ t('project_management.tasks.nobody_assigned') }}
          </p>

          <template v-if="can.editTasks">
            <VDivider class="my-3" />
            <VRow dense class="align-center">
              <VCol cols="12" sm="7">
                <VSelect
                  v-model="assignForm.member_id"
                  :items="assignable.map(m => ({ title: m.name, subtitle: m.role, value: m.id }))"
                  :label="t('project_management.tasks.assign_to')"
                  :no-data-text="t('project_management.tasks.no_members')"
                  :error-messages="assignForm.errors.member_id"
                  density="compact" variant="outlined" hide-details="auto"
                >
                  <template #item="{ props: ip, item }">
                    <VListItem v-bind="ip" :subtitle="item.raw.subtitle" />
                  </template>
                </VSelect>
              </VCol>
              <VCol cols="7" sm="3">
                <VTextField
                  v-model.number="assignForm.allocation_pct" type="number" min="1" max="100"
                  :label="t('project_management.tasks.allocation')"
                  density="compact" variant="outlined" hide-details
                />
              </VCol>
              <VCol cols="5" sm="2">
                <VBtn color="primary" block :loading="assignForm.processing"
                      :disabled="!assignForm.member_id" @click="assign">
                  {{ t('project_management.tasks.assign') }}
                </VBtn>
              </VCol>
            </VRow>
            <VCheckbox
              v-model="assignForm.is_lead" density="compact" hide-details
              :label="t('project_management.tasks.make_lead')"
            />
          </template>
        </VWindowItem>

        <!-- Checklist -->
        <VWindowItem value="checklist">
          <VList density="compact" class="py-0">
            <VListItem v-for="item in detail.checklist" :key="item.id" class="px-0">
              <template #prepend>
                <VCheckbox
                  :model-value="item.is_done" density="compact" hide-details color="primary"
                  :aria-label="item.text"
                  @update:model-value="v => toggleItem(item.id, !!v)"
                />
              </template>
              <VListItemTitle :class="{ 'text-decoration-line-through text-medium-emphasis': item.is_done }">
                {{ item.text }}
              </VListItemTitle>
              <VListItemSubtitle v-if="item.is_done && item.done_by" class="text-caption">
                {{ t('project_management.tasks.ticked_by', { name: item.done_by }) }}
              </VListItemSubtitle>
              <template #append>
                <VBtn
                  v-if="can.editTasks" icon="tabler-trash" variant="text" size="x-small"
                  :aria-label="t('project_management.tasks.remove_item', { text: item.text })"
                  @click="removeItem(item.id)"
                />
              </template>
            </VListItem>
          </VList>

          <p v-if="!detail.checklist.length" class="text-body-2 text-medium-emphasis">
            {{ t('project_management.tasks.no_checklist') }}
          </p>

          <VTextField
            v-if="can.editTasks"
            v-model="newItem" :placeholder="t('project_management.tasks.add_checklist_item')"
            variant="outlined" density="compact" hide-details class="mt-3"
            append-inner-icon="tabler-plus"
            @keyup.enter="addItem" @click:append-inner="addItem"
          />
        </VWindowItem>

        <!-- Conversation -->
        <VWindowItem value="comments">
          <div v-for="thread in threaded" :key="thread.id" class="mb-4">
            <div class="d-flex align-center gap-2">
              <strong class="text-body-2">{{ thread.author }}</strong>
              <span class="text-caption text-medium-emphasis">{{ thread.created_at }}</span>
              <span v-if="thread.edited" class="text-caption text-medium-emphasis">
                · {{ t('project_management.tasks.edited') }}
              </span>
            </div>

            <template v-if="editingComment === thread.id">
              <VTextarea
                v-model="editBody" rows="2" variant="outlined" density="compact" hide-details class="mt-1"
                :aria-label="t('project_management.actions.edit')"
              />
              <div class="d-flex gap-2 mt-1">
                <VBtn size="x-small" color="primary" @click="saveEdit(thread.id)">
                  {{ t('project_management.actions.save') }}
                </VBtn>
                <VBtn size="x-small" variant="text" @click="editingComment = null">
                  {{ t('project_management.actions.cancel') }}
                </VBtn>
              </div>
            </template>
            <p v-else class="text-body-2 mb-1" style="white-space:pre-wrap">{{ thread.body }}</p>

            <div class="d-flex gap-1">
              <VBtn size="x-small" variant="text" @click="replyingTo = replyingTo === thread.id ? null : thread.id">
                {{ t('project_management.tasks.reply') }}
              </VBtn>
              <VBtn
                v-if="thread.is_mine" size="x-small" variant="text"
                @click="editingComment = thread.id; editBody = thread.body"
              >
                {{ t('project_management.actions.edit') }}
              </VBtn>
              <VBtn
                v-if="thread.is_mine || can.editTasks" size="x-small" variant="text" color="error"
                @click="removeComment(thread.id)"
              >
                {{ t('project_management.actions.delete') }}
              </VBtn>
            </div>

            <div v-for="reply in thread.replies" :key="reply.id" class="ps-4 mt-2 border-s">
              <div class="d-flex align-center gap-2">
                <strong class="text-body-2">{{ reply.author }}</strong>
                <span class="text-caption text-medium-emphasis">{{ reply.created_at }}</span>
              </div>
              <p class="text-body-2 mb-0" style="white-space:pre-wrap">{{ reply.body }}</p>
              <VBtn
                v-if="reply.is_mine || can.editTasks" size="x-small" variant="text" color="error"
                @click="removeComment(reply.id)"
              >
                {{ t('project_management.actions.delete') }}
              </VBtn>
            </div>

            <div v-if="replyingTo === thread.id" class="ps-4 mt-2">
              <VTextarea
                v-model="replyBody" rows="2" variant="outlined" density="compact" hide-details
                :placeholder="t('project_management.tasks.write_a_reply')"
              />
              <VBtn size="small" color="primary" class="mt-2" :disabled="!replyBody.trim()" @click="postReply(thread.id)">
                {{ t('project_management.tasks.post') }}
              </VBtn>
            </div>
          </div>

          <p v-if="!detail.comments.length" class="text-body-2 text-medium-emphasis">
            {{ t('project_management.tasks.no_comments') }}
          </p>

          <VDivider class="my-3" />

          <!--
            Type `@` to tag somebody. The menu is positioned under the box rather than at the caret:
            a drawer this narrow wraps its lines, so a caret-anchored menu lands somewhere different
            on every line and reads as a glitch.
          -->
          <div class="position-relative">
            <VTextarea
              ref="commentBox"
              v-model="commentForm.body" rows="2" variant="outlined" density="compact"
              :placeholder="detail.taggable.length
                ? t('project_management.tasks.write_a_comment_mention')
                : t('project_management.tasks.write_a_comment')"
              :error-messages="commentForm.errors.body"
              @input="syncMention"
              @click="syncMention"
              @keyup="syncMention"
              @keydown="mentionKeydown"
              @blur="() => setTimeout(() => { mentionOpen = false }, 150)"
            />

            <VCard v-if="mentionOpen" class="mention-menu" elevation="8" border>
              <!--
                Shown even when there is nobody to offer. A menu that never appears reads as a
                broken feature; one that appears and explains itself reads as a project whose
                people have not been given logins yet — which is the actual reason.
              -->
              <div v-if="!detail.taggable.length" class="pa-3 text-caption text-medium-emphasis">
                {{ t('project_management.tasks.nobody_to_tag') }}
              </div>
              <div v-else-if="!mentionMatches.length" class="pa-3 text-caption text-medium-emphasis">
                {{ t('project_management.tasks.no_match') }}
              </div>

              <VList v-else density="compact" class="py-1">
                <VListItem
                  v-for="(person, i) in mentionMatches" :key="person.id"
                  :active="i === mentionIndex"
                  @mousedown.prevent="pickMention(person)"
                >
                  <template #prepend>
                    <AssigneeStack :people="[{ id: String(person.id), name: person.name }]" :size="24" class="me-2" />
                  </template>
                  <VListItemTitle class="text-body-2">{{ person.name }}</VListItemTitle>
                </VListItem>
              </VList>
            </VCard>
          </div>

          <!-- Who this will actually reach, and a way to take it back. -->
          <div v-if="tagged.length" class="d-flex flex-wrap align-center ga-1 mb-3">
            <span class="text-caption text-medium-emphasis me-1">
              {{ t('project_management.tasks.will_be_notified') }}
            </span>
            <VChip
              v-for="person in tagged" :key="person.id"
              size="x-small" closable
              @click:close="untag(person)"
            >{{ person.name }}</VChip>
          </div>

          <VBtn
            color="primary" size="small" :loading="commentForm.processing"
            :disabled="!commentForm.body.trim()" @click="postComment()"
          >
            {{ t('project_management.tasks.post') }}
          </VBtn>
        </VWindowItem>

        <!-- Files -->
        <VWindowItem value="files">
          <VList density="compact" class="py-0">
            <VListItem v-for="file in detail.attachments" :key="file.id" class="px-0">
              <template #prepend><VIcon icon="tabler-paperclip" size="18" class="me-2" /></template>
              <VListItemTitle>
                <a :href="route('project-management.attachments.show', [projectId, file.id])" target="_blank">
                  {{ file.filename }}
                </a>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ humanSize(file.size) }}<template v-if="file.by"> · {{ file.by }}</template>
              </VListItemSubtitle>
              <template #append>
                <VBtn
                  v-if="file.is_mine || can.editTasks" icon="tabler-trash" variant="text" size="x-small"
                  :aria-label="t('project_management.tasks.remove_file', { name: file.filename })"
                  @click="removeAttachment(file.id)"
                />
              </template>
            </VListItem>
          </VList>

          <p v-if="!detail.attachments.length" class="text-body-2 text-medium-emphasis">
            {{ t('project_management.tasks.no_files') }}
          </p>

          <VFileInput
            v-model="uploadForm.file"
            :label="t('project_management.tasks.attach_a_file')"
            :error-messages="uploadForm.errors.file"
            variant="outlined" density="compact" prepend-icon="" prepend-inner-icon="tabler-upload"
            class="mt-3"
          />
          <VBtn
            color="primary" size="small" :loading="uploadForm.processing"
            :disabled="!uploadForm.file" @click="upload"
          >
            {{ t('project_management.tasks.upload') }}
          </VBtn>
        </VWindowItem>

        <!-- Recurrence -->
        <VWindowItem value="repeat">
          <template v-if="detail.recurrence">
            <VAlert :type="detail.recurrence.is_active ? 'info' : 'warning'" variant="tonal" density="compact">
              <div class="text-body-2">{{ detail.recurrence.description }}</div>
              <div class="text-caption mt-1">
                {{ t('project_management.tasks.generated_so_far', { count: detail.recurrence.generated }) }}
                <template v-if="detail.recurrence.next_after">
                  · {{ t('project_management.tasks.filled_to', { date: detail.recurrence.next_after }) }}
                </template>
              </div>
            </VAlert>

            <div class="d-flex gap-2 mt-3">
              <VBtn
                v-if="can.editTasks" size="small" variant="tonal"
                @click="toggleRecurrence(!detail.recurrence.is_active)"
              >
                {{ t(detail.recurrence.is_active ? 'project_management.tasks.pause' : 'project_management.tasks.resume') }}
              </VBtn>
              <VBtn
                v-if="can.editTasks" size="small" variant="tonal" color="error"
                :loading="stopping" @click="stopRecurrence"
              >
                {{ t('project_management.tasks.stop_repeating') }}
              </VBtn>
            </div>

            <p class="text-caption text-medium-emphasis mt-3">
              {{ t('project_management.tasks.stop_explains') }}
            </p>
          </template>

          <template v-else-if="can.createTasks">
            <p class="text-body-2 text-medium-emphasis mb-3">{{ t('project_management.tasks.repeat_intro') }}</p>

            <VAutocomplete
              v-model="recurrenceForm.freq"
              :items="['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'].map(f => ({ title: t(`project_management.freq.${f.toLowerCase()}`), value: f }))"
              :label="t('project_management.tasks.repeats')"
              :error-messages="recurrenceForm.errors.freq"
              variant="outlined" density="compact" class="mb-3"
            />

            <VTextField
              v-model.number="recurrenceForm.interval" type="number" inputmode="numeric" min="1" max="52"
              :label="t('project_management.tasks.every_n')"
              variant="outlined" density="compact" class="mb-3"
            />

            <VAutocomplete
              v-if="recurrenceForm.freq === 'WEEKLY'"
              v-model="recurrenceForm.byday" multiple chips
              :items="WEEKDAYS.map(d => ({ title: t(`project_management.weekday.${d.toLowerCase()}`), value: d }))"
              :label="t('project_management.tasks.on_days')"
              variant="outlined" density="compact" class="mb-3"
            />

            <VTextField
              v-if="recurrenceForm.freq === 'MONTHLY'"
              v-model.number="recurrenceForm.bymonthday" type="number" inputmode="numeric" min="-31" max="31"
              :label="t('project_management.tasks.on_day_of_month')"
              :hint="t('project_management.tasks.hint_day_of_month')" persistent-hint
              variant="outlined" density="compact" class="mb-3"
            />

            <VTextField
              v-model="recurrenceForm.starts_at" type="date"
              :label="t('project_management.tasks.first_on')"
              :error-messages="recurrenceForm.errors.starts_at"
              variant="outlined" density="compact" class="mb-3"
            />

            <VTextField
              v-model="recurrenceForm.ends_at" type="date"
              :label="t('project_management.tasks.until_optional')"
              :error-messages="recurrenceForm.errors.ends_at"
              variant="outlined" density="compact" class="mb-3"
            />

            <VBtn color="primary" size="small" :loading="recurrenceForm.processing" @click="saveRecurrence">
              {{ t('project_management.tasks.start_repeating') }}
            </VBtn>
          </template>

          <p v-else class="text-body-2 text-medium-emphasis">{{ t('project_management.tasks.no_recurrence') }}</p>
        </VWindowItem>
      </VWindow>

      <VDivider />

      <div v-if="detail.watchers.length" class="pa-4">
        <div class="text-caption text-medium-emphasis mb-1">
          {{ t('project_management.tasks.watching_count', { count: detail.watchers.length }) }}
        </div>
        <VChip v-for="w in detail.watchers" :key="w.id" size="x-small" class="me-1 mb-1" label>
          {{ w.name }}
        </VChip>
      </div>
    </template>

    <ConfirmDialog ref="confirmer" />
  </VNavigationDrawer>
</template>

<style scoped>
/* A bare button carries none of the app's type styling; this is the underline that says it acts. */
.pm-add-description {
  background: none;
  border: 0;
  padding: 0;
  cursor: pointer;
  text-align: start;
  text-decoration: underline dotted;
}

.pm-add-description:hover { color: rgb(var(--v-theme-primary)); }

/*
  Sits under the comment box and above everything else in the drawer. `position: absolute` inside
  the wrapper rather than a teleported overlay, so it scrolls with the box instead of hanging in
  place when the conversation above it moves.
*/
.mention-menu {
  position: absolute;
  inset-block-start: 100%;
  inset-inline: 0;
  z-index: 10;
  max-block-size: 240px;
  overflow-y: auto;
}
</style>
