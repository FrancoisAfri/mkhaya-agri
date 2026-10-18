<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmAction from '../Components/ConfirmAction.vue'
import FolderPermissions from '../Components/FolderPermissions.vue'
import FolderTree from '../Components/FolderTree.vue'
import MetadataFields from '../Components/MetadataFields.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Doc {
  uuid: string; title: string; description: string | null; status: string
  folder: string | null; folder_uuid: string | null
  is_confidential: boolean; is_legally_sensitive: boolean; can_delete: boolean
  document_number: string | null; document_type: string | null
  category: string | null; category_colour: string | null
  confidentiality: string; expiry_date: string | null; is_expired: boolean
  tags: string[]
  versions: number; extension: string | null; mime: string | null; size: string | null
  previewable: boolean; updated_at: string | null
  archived_at: string | null; archive_reason: string | null
}
interface Folder {
  uuid: string; name: string; path: string; depth: number; is_confidential: boolean
  scope: string; archived: boolean; documents: number
  can: { view: boolean; upload: boolean; edit: boolean; delete: boolean }
}
interface Category { uuid: string; name: string; colour: string | null }

const props = defineProps<{
  documents: { data: Doc[]; current_page: number; last_page: number; total: number } & Record<string, any>
  folders: Folder[]
  categories: Category[]
  relations: { value: string; label: string }[]
  confidentiality_levels: string[]
  filters: { q: string; status: string; folder: string | null }
  can?: { manage_folders?: boolean }
  expiry: { expired: number; within_7: number; within_30: number; within_90: number }
  awaiting_me: { uuid: string; document_uuid: string; title: string; step: string; due_at: string | null; is_overdue: boolean }[]
  /** Which slice of the library this is, and whether uploading belongs on it. */
  view: { key: string | null; uploadable: boolean }
  settings: { max_file_size_mb: number; max_files_per_upload: number; allowed_extensions: string[]; require_description: boolean }
  totals: { active: number; archived: number; confidential: number; legal_hold: number }
  /** Who a folder can be given to. Sent with the page so the permissions dialog has them to hand. */
  permission_subjects?: {
    people: { id: number; name: string | null }[]
    roles: string[]
    departments: { id: number; name: string }[]
    groups: { id: number; name: string }[]
  }
}>()

// ── Who may use a folder ───────────────────────────────────────────────────

const editingPermissions = ref(false)
const permissionsFor = ref<{ uuid: string; name: string } | null>(null)

const search = ref(props.filters.q ?? '')
const status = ref(props.filters.status ?? 'active')

/** Narrows the list to what is lapsing, which is a different question from the search box. */
const showExpiring = ref(Boolean((props.filters as any).expiring))

/**
 * The filter panel, folded away until wanted.
 *
 * Most searching is a word in the box. The panel is for the times somebody knows exactly what they
 * are narrowing to, and putting eleven controls on screen permanently would make the common case
 * harder to serve the rare one.
 */
const showFilters = ref(false)

const advanced = ref<Record<string, any>>({
  category: (props.filters as any).category ?? null,
  document_type: (props.filters as any).document_type ?? '',
  confidentiality: (props.filters as any).confidentiality ?? null,
  relation: (props.filters as any).relation ?? null,
  expiry_from: (props.filters as any).expiry_from ?? '',
  expiry_to: (props.filters as any).expiry_to ?? '',
  uploaded_from: (props.filters as any).uploaded_from ?? '',
  uploaded_to: (props.filters as any).uploaded_to ?? '',
  sort: (props.filters as any).sort ?? '',
})

const activeFilterCount = computed(() =>
  Object.values(advanced.value).filter(v => v !== null && v !== '' && v !== undefined).length,
)

function clearFilters() {
  for (const key of Object.keys(advanced.value)) {
    advanced.value[key] = typeof advanced.value[key] === 'string' ? '' : null
  }
  apply()
}

const folder = ref<string | null>(props.filters.folder ?? null)

function apply() {
  router.get(route('document-management.index'),
    {
      q: search.value, status: status.value, folder: folder.value,
      expiring: showExpiring.value ? 1 : undefined,
      // Only what has been set. Empty parameters in the URL make a shared search link unreadable
      // and every filter look active.
      ...Object.fromEntries(Object.entries(advanced.value).filter(([, v]) => v !== null && v !== '')),
    },
    { preserveState: true, preserveScroll: true })
}

/** Choosing a folder filters the list and pre-selects it for the next upload. */
function selectFolder(uuid: string | null) {
  folder.value = uuid
  form.folder = uuid ?? ''
  apply()
}

/** The folder currently being looked at, for the heading above the list. */
const currentFolder = computed(() => props.folders.find(f => f.uuid === folder.value) ?? null)

/** An icon that says what the file is at a glance, rather than a generic page. */
const iconFor = (doc: Doc) => {
  const e = (doc.extension ?? '').toLowerCase()
  if (e === 'pdf') return 'mdi-file-pdf-box'
  if (['doc', 'docx', 'rtf', 'odt'].includes(e)) return 'mdi-file-word-outline'
  if (['xls', 'xlsx', 'csv', 'ods'].includes(e)) return 'mdi-file-excel-outline'
  if (['ppt', 'pptx', 'odp'].includes(e)) return 'mdi-file-powerpoint-outline'
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic'].includes(e)) return 'mdi-file-image-outline'
  if (['zip', 'rar', '7z'].includes(e)) return 'mdi-folder-zip-outline'
  return 'mdi-file-document-outline'
}

// ── Upload ──────────────────────────────────────────────────────────────────
const dragging = ref(false)
const picked = ref<File[]>([])
const fileInput = ref<HTMLInputElement | null>(null)
const cameraInput = ref<HTMLInputElement | null>(null)
const archiveInput = ref<HTMLInputElement | null>(null)

/*
 * A zipped folder, posted on its own rather than added to the picked list.
 *
 * It is a different operation from an upload: the server expands it, makes the folders and files
 * every entry through the ordinary upload path. Mixing it into the file tray would suggest the two
 * are the same thing and leave somebody wondering why their zip became one document.
 */
const archiveSkipped = ref<Array<{ name: string; reason: string }>>([])
const archiveForm = useForm({ archive: null as File | null, folder: '' })

function uploadArchive(files: FileList | null) {
  const file = files?.[0]
  if (!file) return

  archiveSkipped.value = []
  archiveForm.archive = file
  archiveForm.folder = form.folder

  archiveForm.post(route('document-management.documents.archive.store'), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: page => {
      archiveSkipped.value = (page.props.flash as any)?.archiveSkipped ?? []
      archiveForm.reset()
    },
  })
}

/**
 * Whether this device can actually open a camera.
 *
 * Feature-detected rather than guessed from the screen width: a narrow browser window on a laptop
 * is not a phone, and a button that opens a file picker labelled "take a photo" is worse than no
 * button at all.
 */
const canUseCamera = computed(() =>
  typeof document !== 'undefined' && 'capture' in document.createElement('input'),
)

const form = useForm({
  files: [] as File[], folder: props.filters.folder ?? '',
  title: '', description: '', notes: '',
  is_confidential: false, is_legally_sensitive: false,

  // Metadata, all optional. An upload blocked because nobody knew the expiry date is an upload
  // that happens by email instead.
  category: null as string | null,
  document_number: '', document_type: '',
  document_date: '', effective_date: '', expiry_date: '',
  confidentiality: 'internal',
  tags: [] as string[],
})

/** Extra metadata is folded away until asked for: most uploads are a file and a folder. */
const showMetadata = ref(false)

const accept = computed(() => props.settings.allowed_extensions.map(e => '.' + e).join(','))
const maxBytes = computed(() => props.settings.max_file_size_mb * 1024 * 1024)

/**
 * Anything obviously wrong is caught here so the person is told immediately rather than after an
 * upload. The server checks all of it again — this is courtesy, not security.
 */
function addFiles(list: FileList | File[] | null) {
  if (!list) return
  const incoming = Array.from(list)
  const room = props.settings.max_files_per_upload - picked.value.length

  for (const file of incoming.slice(0, Math.max(0, room))) {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
    if (!props.settings.allowed_extensions.includes(extension)) {
      rejected.value.push(d('rejected_type', { name: file.name, ext: extension.toUpperCase() }))
      continue
    }
    if (file.size > maxBytes.value) {
      rejected.value.push(d('rejected_size', { name: file.name, max: props.settings.max_file_size_mb }))
      continue
    }
    picked.value.push(file)
  }

  if (incoming.length > room) rejected.value.push(d('rejected_count', { max: props.settings.max_files_per_upload }))
  form.files = picked.value
}

const rejected = ref<string[]>([])

function onDrop(event: DragEvent) {
  dragging.value = false
  addFiles(event.dataTransfer?.files ?? null)
}

function removePicked(index: number) {
  picked.value.splice(index, 1)
  form.files = picked.value
}

function submit() {
  form.post(route('document-management.documents.store'), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => { picked.value = []; rejected.value = []; form.reset() },
  })
}

const readableSize = (bytes: number) => {
  const units = ['B', 'KB', 'MB', 'GB']
  let n = bytes, i = 0
  while (n >= 1024 && i < units.length - 1) { n /= 1024; i++ }
  return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

function act(name: string, uuid: string, data: Record<string, unknown> = {}) {
  router.post(route(name, uuid), data, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4" style="max-width:1300px;margin:auto">
    <!-- The slice this is, said plainly. Every one of these screens rendered as "Documents"
         with an upload box, so somebody sent to Awaiting approval saw the page they had just come
         from and reasonably concluded the link was broken. -->
    <div class="d-flex align-center flex-wrap ga-2 mb-1">
      <h1 class="text-h5 font-weight-bold">{{ view.key ? d('view_' + view.key) : d('title') }}</h1>
      <VBtn
        v-if="view.key" size="small" variant="text" prepend-icon="mdi-arrow-left"
        :href="route('document-management.index')"
      >{{ d('view_all_documents') }}</VBtn>
    </div>
    <p class="text-body-2 text-medium-emphasis">
      {{ view.key ? d('view_' + view.key + '_hint') : d('subtitle') }}
    </p>

    <!-- What is waiting on this person, first. An approval assigned to you outranks a certificate
         lapsing somewhere else, because only one of them stops when you act. -->
    <VCard v-if="awaiting_me.length" elevation="0" border class="mb-3 awaiting">
      <VCardText class="py-2 d-flex align-center ga-2">
        <VIcon size="20" icon="mdi-clipboard-check-outline" color="primary" />
        <span class="text-body-2 font-weight-medium">{{ d('awaiting_you') }}</span>
        <VChip size="x-small" color="primary" variant="flat">{{ awaiting_me.length }}</VChip>
      </VCardText>
      <VDivider />
      <VList density="compact" class="py-0">
        <VListItem
          v-for="item in awaiting_me" :key="item.uuid"
          :href="route('document-management.documents.show', item.document_uuid)"
        >
          <template #prepend>
            <!-- Icon and word, so overdue is not carried by colour alone. -->
            <VIcon
              size="18"
              :icon="item.is_overdue ? 'mdi-alert-circle' : 'mdi-progress-clock'"
              :color="item.is_overdue ? 'error' : 'primary'"
            />
          </template>
          <VListItemTitle class="text-body-2">{{ item.title }}</VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ item.step }}
            <template v-if="item.due_at">
              · <span :class="{ 'text-error font-weight-medium': item.is_overdue }">
                {{ item.is_overdue ? d('approval_overdue') : d('approval_due', { date: item.due_at }) }}
              </span>
            </template>
          </VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>

    <!-- Above the statistics, because a lapsed certificate is something to act on rather than
         something to know. Only shown when there is something to say. -->
    <VAlert
      v-if="expiry.expired || expiry.within_30"
      :type="expiry.expired ? 'error' : 'warning'"
      variant="tonal" class="mb-3" density="comfortable"
    >
      <div class="d-flex flex-wrap align-center ga-2">
        <span class="text-body-2">
          <template v-if="expiry.expired">{{ d('expiry_banner_expired', { n: expiry.expired }) }}</template>
          <template v-else>{{ d('expiry_banner_soon', { n: expiry.within_30 }) }}</template>
        </span>
        <VBtn
          size="small" variant="text" density="comfortable"
          @click="search = ''; status = 'active'; showExpiring = true; apply()"
        >{{ d('expiry_banner_show') }}</VBtn>
      </div>
    </VAlert>

    <VRow v-if="!view.key" class="mb-2">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ d('stat_active') }}</div>
          <div class="text-h6">{{ totals.active }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ d('stat_archived') }}</div>
          <div class="text-h6">{{ totals.archived }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ d('stat_confidential') }}</div>
          <div class="text-h6">{{ totals.confidential }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ d('stat_legal_hold') }}</div>
          <div class="text-h6">{{ totals.legal_hold }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <!-- ── Upload ───────────────────────────────────────────────────────────
         Only where uploading means something. A dropzone on "Awaiting approval" invites somebody
         to drop a file and then wonder why it did not appear there: it went to the library, which
         is the only place it could have gone. -->
    <VCard v-if="view.uploadable" elevation="0" border class="mb-4">
      <VCardText>
        <div
          class="dropzone" :class="{ 'dropzone--active': dragging }"
          role="button" tabindex="0"
          :aria-label="d('dropzone_label')"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
          @click="fileInput?.click()"
          @keydown.enter.prevent="fileInput?.click()"
          @keydown.space.prevent="fileInput?.click()"
        >
          <VIcon size="34" class="mb-2" style="opacity:.55">mdi-cloud-upload-outline</VIcon>
          <div class="text-body-1 font-weight-medium">{{ d('drop_here') }}</div>
          <div class="text-caption text-medium-emphasis mt-1">
            {{ d('drop_hint', { max: settings.max_file_size_mb, count: settings.max_files_per_upload }) }}
          </div>
          <div class="text-caption text-medium-emphasis mt-1">
            {{ settings.allowed_extensions.slice(0, 14).join(', ') }}
            <span v-if="settings.allowed_extensions.length > 14">…</span>
          </div>
          <input
            ref="fileInput" type="file" multiple class="d-none"
            :accept="accept" :aria-label="d('choose_files')"
            @change="addFiles(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''"
          >

          <!--
            Photographing a document on a phone.

            A separate input with `capture`, rather than the same one: `capture` on the main input
            would open the camera on every phone upload and take away the ability to choose a file
            already on the device, which is what most mobile uploads actually are. Two inputs, one
            question each.

            Offered only where the device has a camera to open — on a desktop the button would look
            like something broken.
          -->
          <input
            ref="cameraInput" type="file" accept="image/*" capture="environment" class="d-none"
            :aria-label="d('take_photo')"
            @change="addFiles(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''"
          >
        </div>

        <div class="d-flex justify-center flex-wrap ga-2 mt-2">
          <VBtn
            v-if="canUseCamera"
            size="small" variant="text" prepend-icon="mdi-camera-outline"
            @click.stop="cameraInput?.click()"
          >{{ d('take_photo') }}</VBtn>

          <!--
            A whole folder, as a zip.

            A browser cannot hand over a directory in a form post, and dragging four hundred files
            in one at a time is not a workflow. The structure inside is rebuilt rather than
            flattened, so a filing cabinet arrives as the folders it was filed in.
          -->
          <VBtn
            size="small" variant="text" prepend-icon="mdi-folder-zip-outline"
            @click.stop="archiveInput?.click()"
          >{{ d('upload_folder') }}</VBtn>

          <input
            ref="archiveInput" type="file" accept=".zip,application/zip" class="d-none"
            :aria-label="d('upload_folder')"
            @change="uploadArchive(($event.target as HTMLInputElement).files); ($event.target as HTMLInputElement).value = ''"
          >
        </div>

        <!-- What the archive could not take, named rather than counted. -->
        <VAlert
          v-if="archiveSkipped.length" type="warning" variant="tonal" density="compact"
          class="mt-3" closable @click:close="archiveSkipped = []"
        >
          <div class="text-body-2 font-weight-medium mb-1">
            {{ d('archive_skipped', { n: archiveSkipped.length }) }}
          </div>
          <div v-for="(row, i) in archiveSkipped" :key="i" class="text-caption">
            {{ row.name }} — {{ row.reason }}
          </div>
        </VAlert>

        <!-- Said as soon as it happens, not after a failed upload. -->
        <VAlert v-if="rejected.length" type="warning" variant="tonal" density="compact" class="mt-3" closable
          @click:close="rejected = []">
          <div v-for="(r, i) in rejected" :key="i" class="text-body-2">{{ r }}</div>
        </VAlert>

        <div v-if="picked.length" class="mt-3">
          <div class="text-caption text-medium-emphasis mb-1">{{ d('ready', { n: picked.length }) }}</div>
          <div class="d-flex flex-wrap ga-1 mb-3">
            <VChip
              v-for="(file, i) in picked" :key="i" size="small" closable
              :aria-label="d('remove_file', { name: file.name })" @click:close="removePicked(i)"
            >{{ file.name }} · {{ readableSize(file.size) }}</VChip>
          </div>

          <div class="field-row">
            <VTextField
              v-if="picked.length === 1" v-model="form.title"
              :label="d('field_title')" :hint="d('title_hint')" persistent-hint
            />
            <VSelect
              v-model="form.folder"
              :items="[{ uuid: '', name: d('no_folder') }, ...folders.filter(f => !f.archived && f.can.upload)]"
              item-title="name" item-value="uuid" :label="d('field_folder')"
            />
          </div>
          <VTextarea
            v-model="form.description" :label="d('field_description')" rows="2"
            :error-messages="form.errors.description"
            :hint="settings.require_description ? d('description_required') : undefined" persistent-hint
          />

          <!-- Folded away by default: most uploads are a file and a folder, and a form that asks
               for twelve things before accepting one is a form people work around. -->
          <VBtn
            variant="text" size="small" density="comfortable" class="mt-1 px-1"
            :append-icon="showMetadata ? 'mdi-chevron-up' : 'mdi-chevron-down'"
            @click="showMetadata = !showMetadata"
          >{{ showMetadata ? d('metadata_hide') : d('metadata_show') }}</VBtn>

          <VExpandTransition>
            <div v-show="showMetadata" class="mt-2">
              <MetadataFields
                v-model="form" :categories="categories" :levels="confidentiality_levels"
                :errors="form.errors as Record<string, string>" expanded
              />
            </div>
          </VExpandTransition>

          <div class="d-flex flex-wrap ga-4 mt-2">
            <VSwitch v-model="form.is_confidential" :label="d('mark_confidential')" density="compact" hide-details inset />
            <VSwitch v-model="form.is_legally_sensitive" :label="d('mark_legal')" density="compact" hide-details inset />
          </div>
          <div class="text-caption text-medium-emphasis mt-1">{{ d('sensitivity_hint') }}</div>

          <div class="d-flex justify-end ga-2 mt-3">
            <VBtn variant="text" @click="picked = []; form.reset()">{{ d('clear') }}</VBtn>
            <VBtn color="primary" :loading="form.processing" :disabled="!picked.length" @click="submit">
              {{ d('upload_n', { n: picked.length }) }}
            </VBtn>
          </div>
          <VProgressLinear v-if="form.progress" :model-value="form.progress.percentage" class="mt-2" height="6" rounded />
        </div>
      </VCardText>
    </VCard>

    <!-- ── Library ────────────────────────────────────────────────────────── -->
    <div class="library-layout">
      <!-- The filing structure sits beside the list rather than above it: it is navigation, and
           navigation that pushes the content down the page stops being used. -->
      <FolderTree
        :folders="folders" :selected="folder" :can-manage="can?.manage_folders !== false"
        @select="selectFolder"
        @permissions="(f) => { permissionsFor = { uuid: f.uuid, name: f.name }; editingPermissions = true }"
      />

      <VCard elevation="0" border>
      <VCardText v-if="currentFolder" class="d-flex align-center ga-2 pb-0">
        <VIcon size="18" icon="mdi-folder-outline" />
        <span class="text-body-2 font-weight-medium">{{ currentFolder.path }}</span>
        <VBtn size="x-small" variant="text" @click="selectFolder(null)">{{ d('clear_folder') }}</VBtn>
      </VCardText>

      <VCardText class="d-flex flex-wrap ga-2 align-center">
        <VTextField
          v-model="search" :label="d('search')" density="compact" variant="outlined"
          hide-details prepend-inner-icon="mdi-magnify" style="min-width:16rem;flex:1 1 16rem"
          @keyup.enter="apply"
        />
        <VSelect
          v-model="status" :items="[
            { value: 'active', title: d('status_active') },
            { value: 'archived', title: d('status_archived') },
            { value: 'all', title: d('status_all') },
          ]" density="compact" variant="outlined" hide-details
          :label="d('status')" style="min-width:11rem" @update:model-value="apply"
        />
        <VBtn
          :variant="showExpiring ? 'flat' : 'tonal'" :color="showExpiring ? 'warning' : undefined"
          prepend-icon="mdi-calendar-clock"
          @click="showExpiring = !showExpiring; apply()"
        >{{ d('filter_expiring') }}</VBtn>
        <VBtn
          :variant="showFilters ? 'flat' : 'tonal'" prepend-icon="mdi-filter-variant"
          @click="showFilters = !showFilters"
        >
          {{ d('filters') }}
          <VChip v-if="activeFilterCount" size="x-small" class="ms-1">{{ activeFilterCount }}</VChip>
        </VBtn>
        <VBtn variant="tonal" @click="apply">{{ d('apply') }}</VBtn>
      </VCardText>

      <VExpandTransition>
        <div v-show="showFilters">
          <VDivider />
          <VCardText class="filter-grid">
            <VSelect
              v-model="advanced.category" :label="d('field_category')" density="compact"
              :items="[{ value: null, title: d('any') }, ...categories.map(c => ({ value: c.uuid, title: c.name }))]"
            />
            <VTextField v-model="advanced.document_type" :label="d('field_document_type')" density="compact" />
            <VSelect
              v-model="advanced.confidentiality" :label="d('field_confidentiality')" density="compact"
              :items="[{ value: null, title: d('any') }, ...confidentiality_levels.map(l => ({ value: l, title: d('confidentiality_' + l) }))]"
            />
            <VSelect
              v-model="advanced.relation" :label="d('search_about')" density="compact"
              :items="[{ value: null, title: d('any') }, ...relations.map(r => ({ value: r.value, title: r.label }))]"
            />
            <VTextField v-model="advanced.expiry_from" type="date" :label="d('search_expiry_from')" density="compact" />
            <VTextField v-model="advanced.expiry_to" type="date" :label="d('search_expiry_to')" density="compact" />
            <VTextField v-model="advanced.uploaded_from" type="date" :label="d('search_uploaded_from')" density="compact" />
            <VTextField v-model="advanced.uploaded_to" type="date" :label="d('search_uploaded_to')" density="compact" />
            <VSelect
              v-model="advanced.sort" :label="d('sort')" density="compact"
              :items="[
                { value: '', title: d('sort_updated') },
                { value: 'expiry', title: d('sort_expiry') },
                { value: 'title', title: d('sort_title') },
                { value: 'oldest', title: d('sort_oldest') },
              ]"
            />
          </VCardText>
          <VCardText class="d-flex justify-end ga-2 pt-0">
            <VBtn variant="text" size="small" @click="clearFilters">{{ d('filters_clear') }}</VBtn>
            <VBtn color="primary" size="small" @click="apply">{{ d('apply') }}</VBtn>
          </VCardText>
        </div>
      </VExpandTransition>

      <VDivider />

      <!-- Capped height, so a folder of two hundred documents does not push the pagination and
           everything under it off the bottom of a page nobody can get back up. The header stays
           put while the rows move, which is the only reason a long list is readable at all. -->
      <div class="document-list">
        <VTable density="comfortable" fixed-header>
          <thead>
            <tr>
              <th>{{ d('col_document') }}</th>
              <th>{{ d('col_folder') }}</th>
              <th class="text-end">{{ d('col_size') }}</th>
              <th class="text-end">{{ d('col_versions') }}</th>
              <th>{{ d('col_updated') }}</th>
              <th class="text-end">{{ d('col_actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="doc in documents.data" :key="doc.uuid">
              <td>
                <div class="d-flex align-center ga-2">
                  <VIcon :icon="iconFor(doc)" size="22" />
                  <div style="min-width:0">
                    <a
                      class="text-body-2 font-weight-medium doc-link"
                      :href="route('document-management.documents.show', doc.uuid)"
                    >{{ doc.title }}</a>
                    <div v-if="doc.description" class="text-caption text-medium-emphasis text-truncate" style="max-width:34rem">
                      {{ doc.description }}
                    </div>
                    <div class="d-flex flex-wrap ga-1 mt-1">
                      <!-- The category is what people scan a list by, so it leads. -->
                      <VChip
                        v-if="doc.category" size="x-small" variant="tonal" label
                        :style="doc.category_colour ? { color: doc.category_colour } : undefined"
                      >{{ doc.category }}</VChip>
                      <VChip v-if="doc.document_number" size="x-small" variant="text" label class="px-1">
                        {{ doc.document_number }}
                      </VChip>
                      <!-- An expired certificate is the thing somebody needs to see from the list
                           rather than discover after opening it. -->
                      <VChip
                        v-if="doc.is_expired" size="x-small" color="error" variant="flat"
                        prepend-icon="mdi-calendar-remove-outline"
                      >{{ d('expired_on', { date: doc.expiry_date }) }}</VChip>
                      <VChip
                        v-else-if="doc.expiry_date" size="x-small" color="warning" variant="tonal"
                        prepend-icon="mdi-calendar-clock"
                      >{{ d('expires_on', { date: doc.expiry_date }) }}</VChip>
                      <VChip v-if="doc.is_confidential" size="x-small" color="error" variant="tonal" prepend-icon="mdi-lock-outline">
                        {{ d('confidential') }}
                      </VChip>
                      <VChip v-if="doc.is_legally_sensitive" size="x-small" color="deep-purple" variant="tonal" prepend-icon="mdi-gavel">
                        {{ d('legal_hold') }}
                      </VChip>
                      <VChip v-if="doc.status === 'archived'" size="x-small" variant="tonal">
                        {{ d('archived') }}
                      </VChip>
                    </div>
                  </div>
                </div>
              </td>
              <td class="text-medium-emphasis">{{ doc.folder ?? '—' }}</td>
              <td class="text-end">{{ doc.size ?? '—' }}</td>
              <td class="text-end">{{ doc.versions }}</td>
              <td class="text-medium-emphasis">{{ doc.updated_at }}</td>
              <td class="text-end">
                <VBtn
                  v-if="doc.previewable" icon="mdi-eye-outline" size="small" variant="text" class="row-action"
                  :aria-label="d('preview_of', { title: doc.title })"
                  :href="route('document-management.documents.preview', doc.uuid)" target="_blank" rel="noopener"
                />
                <VBtn
                  icon="mdi-download-outline" size="small" variant="text" class="row-action"
                  :aria-label="d('download_of', { title: doc.title })"
                  :href="route('document-management.documents.download', doc.uuid)"
                />
                <VMenu>
                  <template #activator="{ props: menu }">
                    <VBtn
                      v-bind="menu" icon="mdi-dots-vertical" size="small" variant="text" class="row-action"
                      :aria-label="d('more_for', { title: doc.title })"
                    />
                  </template>
                  <VList density="compact">
                    <!-- The one that was missing entirely. Everything about a document — its
                         versions, who has looked at it, approval, signature, what is inside it —
                         lives on its own page, and the only way there was to notice the title
                         happened to be a link. -->
                    <VListItem
                      prepend-icon="mdi-open-in-new" :title="d('open_document')"
                      :href="route('document-management.documents.show', doc.uuid)"
                    />
                    <VListItem
                      v-if="doc.previewable"
                      prepend-icon="mdi-eye-outline" :title="d('preview')"
                      :href="route('document-management.documents.preview', doc.uuid)"
                      target="_blank" rel="noopener"
                    />
                    <VListItem
                      v-if="doc.can?.download && !doc.download_disabled"
                      prepend-icon="mdi-download-outline" :title="d('download')"
                      :href="route('document-management.documents.download', doc.uuid)"
                    />
                    <!-- Offered where the file cannot be taken directly, so the answer to a
                         greyed-out download is a way to ask rather than nothing. -->
                    <VListItem
                      v-else-if="doc.download_disabled"
                      prepend-icon="mdi-lock-outline" :title="d('request_download')"
                      @click="act('document-management.documents.request-download', doc.uuid)"
                    />

                    <VDivider class="my-1" />

                    <VListItem
                      prepend-icon="mdi-star-outline" :title="d('favourite')"
                      @click="act('document-management.documents.favourite', doc.uuid)"
                    />
                    <VListItem
                      v-if="doc.can?.share"
                      prepend-icon="mdi-share-variant-outline" :title="d('share')"
                      :href="route('document-management.documents.show', doc.uuid)"
                    />
                    <VListItem
                      v-if="doc.can?.replace && doc.status !== 'archived'"
                      prepend-icon="mdi-file-replace-outline" :title="d('replace_file')"
                      :href="route('document-management.documents.show', doc.uuid)"
                    />

                    <VDivider v-if="doc.can?.archive || doc.can?.restore || doc.can_delete" class="my-1" />

                    <VListItem
                      v-if="doc.status !== 'archived' && doc.can?.archive" prepend-icon="mdi-archive-outline"
                      :title="d('archive')" @click="act('document-management.documents.archive', doc.uuid)"
                    />
                    <VListItem
                      v-else-if="doc.status === 'archived' && doc.can?.restore"
                      prepend-icon="mdi-archive-arrow-up-outline"
                      :title="d('restore')" @click="act('document-management.documents.restore', doc.uuid)"
                    />
                    <ConfirmAction
                      :title="d('documents_delete_title')"
                      :body="d('documents_delete_body', { title: doc.title })"
                      :consequence="d('documents_delete_consequence')"
                      :confirm-label="d('delete')"
                      :disabled="!doc.can_delete"
                      @confirm="router.delete(route('document-management.documents.destroy', doc.uuid), { preserveScroll: true })"
                    >
                      <template #activator="{ open }">
                        <VListItem
                          prepend-icon="mdi-delete-outline" :title="d('delete')"
                          :disabled="!doc.can_delete"
                          :subtitle="doc.can_delete ? undefined : d('held_cannot_delete')"
                          @click="open"
                        />
                      </template>
                    </ConfirmAction>
                  </VList>
                </VMenu>
              </td>
            </tr>
            <tr v-if="!documents.data.length">
              <td colspan="6" class="text-center text-medium-emphasis py-10">{{ d('empty') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <VCardText v-if="documents.last_page > 1" class="d-flex justify-center">
        <VPagination
          :model-value="documents.current_page" :length="documents.last_page" :total-visible="6"
          @update:model-value="(p: number) => router.get(route('document-management.index'),
            { q: search, status, folder, page: p }, { preserveState: true, preserveScroll: true })"
        />
      </VCardText>
      </VCard>
    </div>
  </div>

    <FolderPermissions
      v-model="editingPermissions"
      :folder="permissionsFor"
      :people="permission_subjects?.people ?? []"
      :roles="permission_subjects?.roles ?? []"
      :departments="permission_subjects?.departments ?? []"
      :groups="permission_subjects?.groups ?? []"
    />
</template>

<style scoped>
/* A quiet left edge rather than a filled card: this is a list to work through, not an alarm. */
.awaiting { border-inline-start: 3px solid rgb(var(--v-theme-primary)); }

.filter-grid {
  display: grid;
  gap: 0.5rem;
  grid-template-columns: 1fr;
}

@media (min-width: 700px) { .filter-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1100px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } }

.library-layout {
  display: grid;
  gap: 1rem;
  grid-template-columns: 1fr;
  align-items: start;
}

/* The tree gets a fixed column once there is room for one; below that it stacks above the list,
   which is the right order on a phone — you pick a folder, then you look in it. */
@media (min-width: 960px) {
  .library-layout { grid-template-columns: 19rem minmax(0, 1fr); }
}

.dropzone {
  border: 2px dashed rgba(127, 127, 127, 0.45);
  border-radius: 8px;
  padding: 2rem 1rem;
  text-align: center;
  cursor: pointer;
  transition: border-color 150ms ease, background-color 150ms ease;
}
.dropzone:hover,
.dropzone--active { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.06); }
.dropzone:focus-visible { outline: 3px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }

.doc-link { color: inherit; text-decoration: none; }
.doc-link:hover { text-decoration: underline; }

.field-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }
.field-row > * { flex: 1 1 12rem; min-width: 0; }

.row-action { min-inline-size: 44px; min-block-size: 44px; }

/*
 * The list scrolls, the page does not grow without end.
 *
 * `dvh` rather than `vh`: on a phone the address bar collapses as you scroll, and a list sized in
 * `vh` jumps by the height of that bar at the moment somebody is reading it.
 */
.document-list {
  overflow: auto;
  max-block-size: min(62dvh, 720px);
}
</style>
