<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import FilePreview from '@/Modules/DocumentManagement/Components/FilePreview.vue'
import ArchiveContents from '@/Modules/DocumentManagement/Components/ArchiveContents.vue'
import { useI18n } from 'vue-i18n'
import ConfirmAction from '../Components/ConfirmAction.vue'
import MetadataFields from '../Components/MetadataFields.vue'
import DocumentLinks from '../Components/DocumentLinks.vue'
import ApprovalPanel from '../Components/ApprovalPanel.vue'
import SignaturePanel from '../Components/SignaturePanel.vue'
import SharePanel from '../Components/SharePanel.vue'
import CommentPanel from '../Components/CommentPanel.vue'
import AiPanel from '../Components/AiPanel.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Version {
  uuid: string; version: number; label: string; name: string; size: string; mime: string | null
  change_note: string | null; uploaded_by: string | null; uploaded_at: string | null
  previewable: boolean; is_current: boolean; restored_from: string | null
}

const props = defineProps<{
  document: {
    uuid: string; title: string; description: string | null; notes: string | null
    status: string; folder: string | null; folder_uuid: string | null
    is_confidential: boolean; is_legally_sensitive: boolean; sensitivity_note: string | null
    download_disabled: boolean
    superseded_by: string | null; superseded_by_title: string | null; superseded_at: string | null
    can_delete: boolean; versions: number; extension: string | null; mime: string | null
    size: string | null; previewable: boolean; updated_at: string | null
    archived_at: string | null; archive_reason: string | null
    versions_list?: Version[]
    current_version_uuid?: string | null
    is_archive?: boolean

    document_number: string | null; document_type: string | null
    category: string | null; category_uuid: string | null; category_colour: string | null
    confidentiality: string
    document_date: string | null; effective_date: string | null; expiry_date: string | null
    is_expired: boolean; in_force: boolean
    owner: string | null; uploaded_by: string | null; uploaded_at: string | null
    tags: string[]
    links: Record<string, any[]>
    approval_status: string
    published_at: string | null
  }
  approval: any | null
  signature: any | null
  workflows: { uuid: string; name: string }[]
  people?: { id: number; name: string }[]
  folders: { uuid: string; name: string; depth: number; archived: boolean }[]
  categories: { uuid: string; name: string; colour: string | null }[]
  relations: { value: string; label: string }[]
  confidentiality_levels: string[]
  history: { action: string; at: string | null; user: string | null; detail: Record<string, any> | null }[]
  shares: import('../Components/SharePanel.vue').Share[]
  shortcuts: { uuid: string; folder: string; folder_uuid: string }[]
  custom_fields: {
    key: string; label: string; type: string; options: string[]
    hint: string | null; required: boolean; value: unknown
  }[]
  comments: import('../Components/CommentPanel.vue').Thread[]
  lock: {
    held: boolean; mine: boolean; by: string | null; since: string | null
    until: string | null; note: string | null; can_break: boolean; hours: number
  }
  downloading: {
    allowed: boolean; can_request: boolean; can_set_policy: boolean
    restricted: boolean; by_folder: boolean; requested: boolean
  }
  sharing: { enabled: boolean; max_days: number; can_share: boolean }
  grants: import('../Components/SharePanel.vue').Grant[]
  share_subjects: { roles: string[]; departments: { id: number; name: string }[] }
  extraction: { status: string; needs_attention: boolean; words: number; pages?: number | null }
  ai: {
    available: boolean
    reason: string | null
    summary: import('../Components/AiPanel.vue').Insight | null
    history: import('../Components/AiPanel.vue').Insight[]
    fields: import('../Components/AiPanel.vue').FieldSuggestion | null
    applicable_fields: string[]
  }
}>()

// The list, not the count: `versions` is how many there are.
const versionList = computed<Version[]>(() => props.document.versions_list ?? [])

const replaceInput = ref<HTMLInputElement | null>(null)
const replaceForm = useForm({ file: null as File | null, change_note: '' })

function chooseReplacement(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  replaceForm.file = file
  replacing.value = true
}
const replacing = ref(false)

function submitReplacement() {
  replaceForm.post(route('document-management.documents.replace', props.document.uuid), {
    forceFormData: true, preserveScroll: true,
    onSuccess: () => { replacing.value = false; replaceForm.reset() },
  })
}

// ── Restoring a version ─────────────────────────────────────────────────────

const restoring = ref<Version | null>(null)
const restoreReason = ref('')

/**
 * Restoring adds a version rather than rewinding to one.
 *
 * The dialog says so, because "restore" reads like an undo and somebody expecting the newer version
 * to disappear should be told before they click rather than after.
 */
function confirmRestore() {
  if (!restoring.value) return

  router.post(route('document-management.documents.versions.restore', [props.document.uuid, restoring.value.uuid]),
    { reason: restoreReason.value }, {
      preserveScroll: true,
      onFinish: () => { restoring.value = null; restoreReason.value = '' },
    })
}

const detailsForm = useForm({
  title: props.document.title,
  description: props.document.description ?? '',
  notes: props.document.notes ?? '',
  folder: props.document.folder_uuid ?? '',

  // The same fields the upload form offers, so the two cannot drift.
  category: props.document.category_uuid,
  document_number: props.document.document_number ?? '',
  document_type: props.document.document_type ?? '',
  document_date: props.document.document_date ?? '',
  effective_date: props.document.effective_date ?? '',
  expiry_date: props.document.expiry_date ?? '',
  confidentiality: props.document.confidentiality,
  tags: props.document.tags ?? [],
})

function saveDetails() {
  detailsForm.put(route('document-management.documents.update', props.document.uuid), {
    preserveScroll: true,
  })
}

/**
 * What the dates add up to.
 *
 * A document can be active, current and not yet applicable at once, and saying so plainly is more
 * use than three dates the reader has to compare themselves.
 */
const lifecycle = computed(() => {
  if (props.document.is_expired) return { colour: 'error', text: d('lifecycle_expired', { date: props.document.expiry_date }) }
  if (!props.document.in_force && props.document.effective_date) {
    return { colour: 'info', text: d('lifecycle_pending', { date: props.document.effective_date }) }
  }
  if (props.document.expiry_date) return { colour: 'success', text: d('lifecycle_until', { date: props.document.expiry_date }) }
  return null
})

/*
 * Asking for a copy. The reason is optional but asked for, because the person answering has to
 * decide something and "no reason given" is most of the way to a refusal.
 */
const askingForCopy = ref(false)
const copyForm = useForm({ reason: '' })

function askForCopy() {
  copyForm.post(route('document-management.documents.request-download', props.document.uuid), {
    preserveScroll: true,
    onSuccess: () => { askingForCopy.value = false; copyForm.reset() },
  })
}

function setDownloadPolicy(restricted: boolean) {
  router.post(
    route('document-management.documents.download-policy', props.document.uuid),
    { restricted },
    { preserveScroll: true },
  )
}

/*
 * The extra fields this kind of document carries. Seeded from the payload rather than kept in the
 * details form, because they change shape when the category changes and a form declared once would
 * carry the old category's keys into the save.
 */
const customValues = ref<Record<string, unknown>>(
  Object.fromEntries(props.custom_fields.map(f => [f.key, f.value])),
)

function saveCustomFields() {
  router.put(
    route('document-management.documents.update', props.document.uuid),
    { custom_fields: customValues.value },
    { preserveScroll: true },
  )
}

const addingShortcut = ref(false)
const shortcutForm = useForm({ folder: '' })

function addShortcut() {
  shortcutForm.post(route('document-management.documents.shortcut', props.document.uuid), {
    preserveScroll: true,
    onSuccess: () => { addingShortcut.value = false; shortcutForm.reset() },
  })
}

const sensitivityForm = useForm({
  is_confidential: props.document.is_confidential,
  is_legally_sensitive: props.document.is_legally_sensitive,
  sensitivity_note: props.document.sensitivity_note ?? '',
})

/** Reads better than the raw verb, and the audit trail is the thing people scan fastest. */
const actionLabel = (action: string) => d('event_' + action)
const actionColour = (action: string) =>
  ({ purged: 'error', deleted: 'error', archived: 'warning', restored: 'success',
     replaced: 'primary', uploaded: 'success', sensitivity_changed: 'deep-purple' } as Record<string, string>)[action]
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center flex-wrap ga-2 mb-1">
      <a class="text-caption text-medium-emphasis back-link" :href="route('document-management.index')">
        ← {{ d('back_to_library') }}
      </a>
    </div>

    <div class="d-flex align-start justify-space-between flex-wrap ga-3 mb-4">
      <div style="min-width:0">
        <h1 class="text-h5 font-weight-bold">{{ document.title }}</h1>
        <div class="d-flex flex-wrap ga-1 mt-1">
          <VChip v-if="document.is_confidential" size="small" color="error" variant="tonal" prepend-icon="mdi-lock-outline">
            {{ d('confidential') }}
          </VChip>
          <VChip v-if="document.is_legally_sensitive" size="small" color="deep-purple" variant="tonal" prepend-icon="mdi-gavel">
            {{ d('legal_hold') }}
          </VChip>
          <VChip v-if="document.status === 'archived'" size="small" variant="tonal">{{ d('archived') }}</VChip>
          <VChip v-if="document.folder" size="small" variant="tonal" prepend-icon="mdi-folder-outline">
            {{ document.folder }}
          </VChip>
          <VChip v-if="document.category" size="small" variant="tonal" label>{{ document.category }}</VChip>
          <!-- Three dates said as one sentence: a document can be active, current and not yet
               applicable at once, and comparing them is the reader's job otherwise. -->
          <VChip
            v-if="lifecycle" size="small" :color="lifecycle.colour" variant="tonal"
            prepend-icon="mdi-calendar-clock"
          >{{ lifecycle.text }}</VChip>
        </div>

        <div class="text-caption text-medium-emphasis mt-2 d-flex flex-wrap ga-3">
          <span v-if="document.document_number">{{ d('field_document_number') }}: {{ document.document_number }}</span>
          <span v-if="document.document_type">{{ document.document_type }}</span>
          <span v-if="document.owner">{{ d('field_owner') }}: {{ document.owner }}</span>
          <span v-if="document.uploaded_by">{{ d('uploaded_by_on', { user: document.uploaded_by, at: document.uploaded_at }) }}</span>
        </div>
      </div>

      <div class="d-flex flex-wrap ga-2">
        <VBtn
          v-if="document.previewable" variant="tonal" prepend-icon="mdi-eye-outline"
          :href="route('document-management.documents.preview', document.uuid)" target="_blank" rel="noopener"
        >{{ d('preview') }}</VBtn>
        <VBtn
          v-if="!lock.held" variant="tonal" prepend-icon="mdi-lock-outline"
          @click="router.post(route('document-management.documents.check-out', document.uuid), {}, { preserveScroll: true })"
        >{{ d('lock_check_out') }}</VBtn>
        <VBtn
          v-if="downloading.allowed"
          color="primary" prepend-icon="mdi-download-outline"
          :href="route('document-management.documents.download', document.uuid)"
        >{{ d('download') }}</VBtn>
        <!--
          Offered instead of the download rather than beside it. Showing a button that answers with
          a 403 teaches nothing, least of all that there is a way to ask.
        -->
        <VBtn
          v-else-if="downloading.requested" variant="tonal" disabled prepend-icon="mdi-clock-outline"
        >{{ d('downloads_request_pending') }}</VBtn>
        <VBtn
          v-else-if="downloading.can_request" color="primary" variant="tonal"
          prepend-icon="mdi-file-download-outline" @click="askingForCopy = true"
        >{{ d('downloads_request_copy') }}</VBtn>
      </div>
    </div>

    <!--
      Said first, above everything else. Somebody arriving from a link sent last year sees only this
      page, and reading a superseded policy as though it were current is the worst thing that can
      happen here — worse than any of the restrictions below it.
    -->
    <VAlert
      v-if="document.superseded_by" type="warning" variant="tonal" density="compact" class="mb-4"
      icon="mdi-file-replace-outline"
    >
      <div class="d-flex align-center flex-wrap ga-2">
        <span>{{ d('superseded_banner', { date: document.superseded_at }) }}</span>
        <VSpacer />
        <VBtn
          size="small" variant="tonal" color="warning"
          :href="route('document-management.documents.show', document.superseded_by)"
        >{{ d('superseded_open', { title: document.superseded_by_title }) }}</VBtn>
      </div>
    </VAlert>

    <!--
      Who is working on this, said before anything else that could be edited. A person who reads
      "checked out by Thandi since this morning" does not spend the afternoon on a copy that will
      lose to hers.
    -->
    <VAlert
      v-if="lock.held" :type="lock.mine ? 'success' : 'warning'" variant="tonal" density="compact"
      class="mb-4" :icon="lock.mine ? 'mdi-lock-open-check-outline' : 'mdi-lock-outline'"
    >
      <div class="d-flex align-center flex-wrap ga-2">
        <span>
          {{ lock.mine ? d('lock_yours', { until: lock.until }) : d('lock_theirs', { who: lock.by, since: lock.since }) }}
          <template v-if="lock.note"> — {{ lock.note }}</template>
        </span>
        <VSpacer />
        <VBtn
          v-if="lock.mine" size="small" variant="text"
          @click="router.post(route('document-management.documents.check-in', document.uuid), {}, { preserveScroll: true })"
        >{{ d('lock_check_in') }}</VBtn>
        <VBtn
          v-else-if="lock.can_break" size="small" variant="text" color="warning"
          @click="router.post(route('document-management.documents.check-in', document.uuid), { force: true }, { preserveScroll: true })"
        >{{ d('lock_break') }}</VBtn>
      </div>
    </VAlert>

    <VAlert
      v-if="downloading.restricted" type="info" variant="tonal" density="compact" class="mb-4"
      icon="mdi-eye-lock-outline"
    >
      {{ downloading.by_folder ? d('downloads_read_only_folder') : d('downloads_read_only_hint') }}
    </VAlert>

    <!-- A hold changes what may be done, so it is said before anything else. -->
    <VAlert v-if="document.is_legally_sensitive" type="info" variant="tonal" density="compact" class="mb-4">
      {{ d('hold_explainer') }}
      <span v-if="document.sensitivity_note"> — {{ document.sensitivity_note }}</span>
    </VAlert>
    <VAlert v-if="document.status === 'archived'" type="warning" variant="tonal" density="compact" class="mb-4">
      {{ d('archived_explainer') }}
      <span v-if="document.archive_reason"> — {{ document.archive_reason }}</span>
      <template #append>
        <VBtn size="small" variant="text" @click="router.post(route('document-management.documents.restore', document.uuid), {}, { preserveScroll: true })">
          {{ d('restore') }}
        </VBtn>
      </template>
    </VAlert>

    <VRow>
      <VCol cols="12" md="7">
        <!-- ── The document itself ──────────────────────────────────────────
             Shown before anything about it: deciding whether this is the right file should not
             mean opening it in another tab and losing the page that describes it. -->
        <VCard v-if="document.previewable && document.current_version_uuid" elevation="0" border class="mb-4">
          <VCardText>
            <FilePreview
              :src="route('document-management.documents.preview', [document.uuid, document.current_version_uuid])"
              :mime="document.mime"
              :name="document.title"
            />
          </VCardText>
        </VCard>

        <!-- ── Inside the archive ───────────────────────────────────────── -->
        <ArchiveContents
          v-if="document.is_archive && document.current_version_uuid"
          class="mb-4"
          :document-uuid="document.uuid"
          :version-uuid="document.current_version_uuid"
          :can-download="!document.download_disabled"
        />

        <!-- ── Versions ─────────────────────────────────────────────────── -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 d-flex align-center">
            {{ d('versions') }}
            <VSpacer />
            <VBtn
              size="small" variant="tonal" prepend-icon="mdi-file-replace-outline"
              :disabled="document.status === 'archived'"
              @click="replaceInput?.click()"
            >{{ d('replace') }}</VBtn>
            <input ref="replaceInput" type="file" class="d-none" :aria-label="d('choose_replacement')" @change="chooseReplacement">
          </VCardTitle>
          <VCardSubtitle class="pb-2">{{ d('versions_help') }}</VCardSubtitle>

          <div v-if="replacing" class="px-4 pb-3">
            <VAlert type="info" variant="tonal" density="compact" class="mb-2">
              {{ d('replacing_with', { name: replaceForm.file?.name }) }}
            </VAlert>
            <VTextField v-model="replaceForm.change_note" :label="d('change_note')" :hint="d('change_note_hint')" persistent-hint />
            <div class="d-flex justify-end ga-2 mt-2">
              <VBtn variant="text" @click="replacing = false; replaceForm.reset()">{{ d('cancel') }}</VBtn>
              <VBtn color="primary" :loading="replaceForm.processing" @click="submitReplacement">{{ d('save_version') }}</VBtn>
            </div>
          </div>

          <div style="overflow-x:auto">
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ d('col_version') }}</th><th>{{ d('col_file') }}</th>
                  <th class="text-end">{{ d('col_size') }}</th><th>{{ d('col_uploaded') }}</th>
                  <th class="text-end">&nbsp;</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="v in versionList" :key="v.uuid">
                  <td>
                    <VChip size="x-small" :color="v.is_current ? 'primary' : undefined" variant="tonal">
                      {{ v.label }}
                    </VChip>
                  </td>
                  <td>
                    {{ v.name }}
                    <div v-if="v.change_note" class="text-caption text-medium-emphasis">{{ v.change_note }}</div>
                    <!-- Said plainly, so a restoration does not read as an ordinary upload. -->
                    <div v-if="v.restored_from" class="text-caption text-medium-emphasis">
                      <VIcon size="12" icon="mdi-backup-restore" /> {{ d('restored_from', { version: v.restored_from }) }}
                    </div>
                  </td>
                  <td class="text-end">{{ v.size }}</td>
                  <td class="text-medium-emphasis">
                    {{ v.uploaded_at }}
                    <div v-if="v.uploaded_by" class="text-caption">{{ v.uploaded_by }}</div>
                  </td>
                  <td class="text-end">
                    <VBtn
                      v-if="v.previewable" icon="mdi-eye-outline" size="small" variant="text" class="row-action"
                      :aria-label="d('preview_version', { n: v.version })"
                      :href="route('document-management.documents.preview', [document.uuid, v.uuid])" target="_blank" rel="noopener"
                    />
                    <VBtn
                      icon="mdi-download-outline" size="small" variant="text" class="row-action"
                      :aria-label="d('download_version', { n: v.version })"
                      :href="route('document-management.documents.download', [document.uuid, v.uuid])"
                    />
                    <!-- Only on the versions that are not already current: restoring the current
                         one would add a version and change nothing. -->
                    <VBtn
                      v-if="!v.is_current && document.status !== 'archived'"
                      icon="mdi-backup-restore" size="small" variant="text" class="row-action"
                      :aria-label="d('restore_version', { version: v.label })"
                      @click="restoring = v"
                    />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>

        <!-- ── History ──────────────────────────────────────────────────── -->
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ d('history') }}</VCardTitle>
          <VCardSubtitle class="pb-2">{{ d('history_help') }}</VCardSubtitle>
          <VCardText>
            <VTimeline density="compact" side="end" truncate-line="both">
              <VTimelineItem
                v-for="(event, i) in history" :key="i" size="x-small"
                :dot-color="actionColour(event.action)"
              >
                <div class="text-body-2">
                  <strong>{{ actionLabel(event.action) }}</strong>
                  <span v-if="event.user" class="text-medium-emphasis"> · {{ event.user }}</span>
                </div>
                <div class="text-caption text-medium-emphasis">{{ event.at }}</div>
              </VTimelineItem>
            </VTimeline>
            <p v-if="!history.length" class="text-body-2 text-medium-emphasis mb-0">{{ d('no_history') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <ApprovalPanel
          class="mb-4"
          :approval="approval" :approval-status="document.approval_status"
          :document-uuid="document.uuid" :workflows="workflows"
          :can-manage="document.status !== 'archived'" :people="people ?? []"
        />

        <SignaturePanel
          class="mb-4"
          :signature="signature" :document-uuid="document.uuid"
          :is-pdf="document.mime === 'application/pdf'"
          :can-send="document.status !== 'archived'"
        />

        <!-- Said where somebody can act on it. A scan nobody can search is not obviously different
             from an ordinary document until you try to search for something in it. -->
        <VAlert
          v-if="extraction.needs_attention"
          type="warning" variant="tonal" density="compact" class="mb-4"
        >
          {{ d('extraction_' + extraction.status) }}
        </VAlert>

        <AiPanel
          class="mb-4"
          :document-uuid="document.uuid" :summary="ai.summary" :history="ai.history"
          :fields="ai.fields" :applicable-fields="ai.applicable_fields"
          :available="ai.available" :reason="ai.reason"
        />

        <SharePanel
          class="mb-4"
          :document-uuid="document.uuid" :shares="shares" :grants="grants"
          :subjects="share_subjects" :people="people ?? []"
          :can-share="sharing.can_share && document.status !== 'archived'"
          :sharing-enabled="sharing.enabled" :max-days="sharing.max_days"
        />

        <CommentPanel
          class="mb-4"
          :document-uuid="document.uuid" :threads="comments"
          :people="people ?? []"
          :versions="(document.versions_list ?? []).map(v => ({ uuid: v.uuid, label: v.label }))"
          :current-user="null"
        />

        <!-- ── Also filed in ────────────────────────────────────────────── -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1 d-flex align-center">
            {{ d('shortcuts_title') }}
            <VSpacer />
            <VBtn size="small" variant="text" prepend-icon="mdi-folder-plus-outline" @click="addingShortcut = true">
              {{ d('shortcuts_add') }}
            </VBtn>
          </VCardTitle>
          <!--
            A second place to find the same document, not a second document. Uploading it twice is
            the oldest failure a repository has: one copy gets a new version, the other quietly
            becomes wrong, and nobody can tell which they are reading.
          -->
          <VCardSubtitle class="text-caption pb-2">{{ d('shortcuts_hint') }}</VCardSubtitle>
          <VCardText v-if="shortcuts.length" class="d-flex flex-wrap ga-2">
            <VChip
              v-for="shortcut in shortcuts" :key="shortcut.uuid"
              size="small" variant="tonal" closable
              @click:close="router.post(
                route('document-management.documents.shortcut', document.uuid),
                { remove: true, shortcut: shortcut.uuid },
                { preserveScroll: true })"
            >{{ shortcut.folder }}</VChip>
          </VCardText>
          <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('shortcuts_none') }}</VCardText>
        </VCard>

        <!-- ── What this kind of document carries ───────────────────────── -->
        <VCard v-if="custom_fields.length" elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ d('category_fields') }}</VCardTitle>
          <VCardText class="d-flex flex-column ga-3">
            <template v-for="field in custom_fields" :key="field.key">
              <VTextField
                v-if="field.type === 'text'"
                v-model="customValues[field.key] as string" density="comfortable"
                :label="field.label" :hint="field.hint ?? undefined" :persistent-hint="!!field.hint"
                :suffix="field.required ? '*' : undefined"
              />
              <VTextField
                v-else-if="field.type === 'number'"
                v-model.number="customValues[field.key] as number" type="number" density="comfortable"
                :label="field.label" :hint="field.hint ?? undefined" :persistent-hint="!!field.hint"
              />
              <VTextField
                v-else-if="field.type === 'date'"
                v-model="customValues[field.key] as string" type="date" density="comfortable"
                :label="field.label" :hint="field.hint ?? undefined" :persistent-hint="!!field.hint"
              />
              <VSwitch
                v-else-if="field.type === 'boolean'"
                v-model="customValues[field.key] as boolean" density="compact" hide-details inset
                :label="field.label"
              />
              <VSelect
                v-else-if="field.type === 'select'"
                v-model="customValues[field.key] as string" density="comfortable" clearable
                :label="field.label" :items="field.options"
                :hint="field.hint ?? undefined" :persistent-hint="!!field.hint"
              />
            </template>
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn color="primary" @click="saveCustomFields">{{ d('save') }}</VBtn>
          </VCardActions>
        </VCard>

        <!-- ── Copies ───────────────────────────────────────────────────── -->
        <VCard v-if="downloading.can_set_policy" elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ d('downloads_read_only') }}</VCardTitle>
          <VCardText>
            <VSwitch
              :model-value="document.download_disabled" density="compact" hide-details inset
              :label="document.download_disabled ? d('downloads_unrestrict') : d('downloads_restrict')"
              :disabled="downloading.by_folder"
              @update:model-value="(v: boolean | null) => setDownloadPolicy(!!v)"
            />
            <div class="text-caption text-medium-emphasis mt-1">
              {{ downloading.by_folder ? d('downloads_read_only_folder') : d('downloads_read_only_hint') }}
            </div>
          </VCardText>
        </VCard>

        <!-- ── Details ──────────────────────────────────────────────────── -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ d('details') }}</VCardTitle>
          <VCardText class="d-flex flex-column ga-3">
            <VTextField v-model="detailsForm.title" :label="d('field_title')" :error-messages="detailsForm.errors.title" />
            <VTextarea v-model="detailsForm.description" :label="d('field_description')" rows="3" />

            <VSelect
              v-model="detailsForm.folder" density="comfortable" :label="d('field_folder')"
              :items="[
                { value: '', title: d('no_folder') },
                ...folders.filter(f => !f.archived).map(f => ({ value: f.uuid, title: '　'.repeat(f.depth) + f.name })),
              ]"
            />

            <MetadataFields
              v-model="detailsForm" :categories="categories" :levels="confidentiality_levels"
              :errors="detailsForm.errors as Record<string, string>" expanded
            />

            <VTextarea v-model="detailsForm.notes" :label="d('field_notes')" rows="3" :hint="d('notes_hint')" persistent-hint />
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn color="primary" :loading="detailsForm.processing" @click="saveDetails">
              {{ d('save') }}
            </VBtn>
          </VCardActions>
        </VCard>

        <!-- ── What it is about ─────────────────────────────────────────── -->
        <DocumentLinks
          class="mb-4"
          :links="document.links" :relations="relations"
          :document-uuid="document.uuid" :can-edit="document.status !== 'archived'"
        />

        <!-- ── Expiry ───────────────────────────────────────────────────── -->
        <VCard v-if="document.expiry_date" elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ d('expiry') }}</VCardTitle>
          <VCardText class="d-flex flex-column ga-2">
            <VAlert
              :type="document.is_expired ? 'error' : 'info'" variant="tonal" density="compact"
            >{{ document.is_expired
                ? d('lifecycle_expired', { date: document.expiry_date })
                : d('lifecycle_until', { date: document.expiry_date }) }}</VAlert>

            <div v-if="document.expired_at" class="text-caption text-medium-emphasis">
              {{ d('expiry_noticed', { at: document.expired_at }) }}
            </div>

            <VSwitch
              :model-value="document.blocks_when_expired" density="compact" hide-details inset
              :label="d('expiry_blocking')"
              @update:model-value="(v: boolean | null) => router.put(
                route('document-management.documents.update', document.uuid),
                { blocks_when_expired: !!v }, { preserveScroll: true })"
            />
            <!-- Off by default and worth explaining: this stops work elsewhere. -->
            <div class="text-caption text-medium-emphasis">{{ d('expiry_blocking_hint') }}</div>

            <VBtn
              v-if="document.is_expired" variant="tonal" color="primary" size="small"
              prepend-icon="mdi-upload-outline" class="align-self-start mt-1"
              @click="replaceInput?.click()"
            >{{ d('expiry_replace') }}</VBtn>
          </VCardText>
        </VCard>

        <!-- ── Sensitivity ──────────────────────────────────────────────── -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="text-subtitle-1">{{ d('sensitivity') }}</VCardTitle>
          <VCardSubtitle class="pb-2">{{ d('sensitivity_help') }}</VCardSubtitle>
          <VCardText class="d-flex flex-column ga-2">
            <VSwitch v-model="sensitivityForm.is_confidential" :label="d('mark_confidential')" density="compact" hide-details inset />
            <VSwitch v-model="sensitivityForm.is_legally_sensitive" :label="d('mark_legal')" density="compact" hide-details inset />
            <VTextField v-model="sensitivityForm.sensitivity_note" :label="d('sensitivity_note')" :hint="d('sensitivity_note_hint')" persistent-hint />
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn color="primary" :loading="sensitivityForm.processing"
              @click="sensitivityForm.put(route('document-management.documents.sensitivity', document.uuid), { preserveScroll: true })">
              {{ d('save') }}
            </VBtn>
          </VCardActions>
        </VCard>

        <!-- ── Destructive ──────────────────────────────────────────────── -->
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ d('remove') }}</VCardTitle>
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">{{ d('remove_help') }}</p>
            <VAlert v-if="!document.can_delete" type="info" variant="tonal" density="compact">
              {{ d('held_cannot_delete') }}
            </VAlert>
          </VCardText>
          <VCardActions>
            <VBtn
              v-if="document.status !== 'archived'" variant="text"
              :disabled="!document.can_delete"
              @click="router.post(route('document-management.documents.archive', document.uuid), {}, { preserveScroll: true })"
            >{{ d('archive') }}</VBtn>
            <VSpacer />
            <ConfirmAction
              :title="d('documents_delete_title')"
              :body="d('documents_delete_body', { title: document.title })"
              :consequence="d('documents_delete_consequence')"
              :confirm-label="d('delete')"
              :disabled="!document.can_delete"
              @confirm="router.delete(route('document-management.documents.destroy', document.uuid))"
            >
              <template #activator="{ open }">
                <VBtn color="error" variant="text" :disabled="!document.can_delete" @click="open">
                  {{ d('delete') }}
                </VBtn>
              </template>
            </ConfirmAction>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>

  <!-- ── Restore a version ─────────────────────────────────────────────── -->
  <VDialog :model-value="restoring !== null" max-width="480" @update:model-value="restoring = null">
    <VCard v-if="restoring">
      <VCardTitle class="text-body-1 font-weight-medium">
        {{ d('restore_version_title', { version: restoring.label }) }}
      </VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <!-- Says what will happen. "Restore" reads like an undo, and somebody expecting the newer
             version to disappear should learn otherwise before clicking, not after. -->
        <p class="text-body-2">{{ d('restore_version_explain', { version: restoring.label }) }}</p>
        <VTextField
          v-model="restoreReason" :label="d('restore_reason')" density="comfortable"
          :hint="d('restore_reason_hint')" persistent-hint autofocus
          @keyup.enter="confirmRestore"
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="restoring = null">{{ d('cancel') }}</VBtn>
        <VBtn color="primary" @click="confirmRestore">{{ d('restore_version_confirm') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>


    <!-- Asking for a copy of something readable but not downloadable. -->
    <VDialog v-model="askingForCopy" max-width="480">
      <VCard>
        <VCardTitle class="text-subtitle-1">{{ d('downloads_request_copy') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ downloading.by_folder ? d('downloads_read_only_folder') : d('downloads_read_only_hint') }}
          </p>
          <VTextarea
            v-model="copyForm.reason" rows="3" autofocus
            :label="d('downloads_ask_reason')" :error-messages="copyForm.errors.reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="askingForCopy = false">{{ d('cancel') }}</VBtn>
          <VBtn color="primary" :loading="copyForm.processing" @click="askForCopy">
            {{ d('downloads_request_copy') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="addingShortcut" max-width="480">
      <VCard>
        <VCardTitle class="text-subtitle-1">{{ d('shortcuts_add') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ d('shortcuts_dialog_hint') }}</p>
          <VSelect
            v-model="shortcutForm.folder" :label="d('field_folder')"
            :error-messages="shortcutForm.errors.folder"
            :items="folders.filter(f => !f.archived && f.uuid !== document.folder_uuid)
              .map(f => ({ value: f.uuid, title: '　'.repeat(f.depth) + f.name }))"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="addingShortcut = false">{{ d('cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="shortcutForm.processing"
            :disabled="!shortcutForm.folder" @click="addShortcut"
          >{{ d('save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
</template>

<style scoped>
.back-link { color: inherit; text-decoration: none; }
.back-link:hover { text-decoration: underline; }
.row-action { min-inline-size: 44px; min-block-size: 44px; }
</style>
