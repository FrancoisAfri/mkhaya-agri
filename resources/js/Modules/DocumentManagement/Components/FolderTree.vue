<script setup lang="ts">
/**
 * The filing structure, as a tree you can work in.
 *
 * Rendered from one flat list ordered by path. `depth` gives the indent and `path` already encodes
 * the nesting, so nothing here walks children — which also means a folder can be collapsed by
 * hiding the rows whose path starts with its own, in one pass rather than a recursive component.
 *
 * Every action is a route, not a flag on an update: a move cannot be mistaken for a rename by a
 * request carrying both, and each can be refused with a reason the person can act on.
 *
 * Folders can be dragged, and everything dragging does is also reachable from the keyboard — the
 * "Move" menu item opens the same operation with a destination list. That is not a fallback for
 * accessibility's sake: dragging a folder eight levels down a scrolling tree is unpleasant with a
 * mouse too, and the list is often the faster path.
 */
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface Folder {
  uuid: string
  name: string
  path: string
  depth: number
  is_confidential: boolean
  scope: string
  archived: boolean
  documents: number
  can: { view: boolean; upload: boolean; edit: boolean; delete: boolean; manage?: boolean }
}

const props = defineProps<{
  folders: Folder[]
  selected: string | null
  canManage: boolean
}>()

const emit = defineEmits<{
  (e: 'select', uuid: string | null): void
  (e: 'permissions', folder: Folder): void
}>()

/** Folders the person has collapsed. Collapsing is a view preference, so it lives here. */
const collapsed = ref<Set<string>>(new Set())
const showArchived = ref(false)

/**
 * Everything starts closed.
 *
 * An expanded tree is fine with the five folders a demo has and unusable with the hundred a real
 * organisation grows: the list runs off the screen and the top-level structure — the thing somebody
 * opened the panel to see — is buried under its own contents.
 *
 * The exception is wherever the person already is. Landing on a document inside
 * Procurement › Quotations with the tree shut would show no sign of where that folder sits, so the
 * ancestors of the selected folder are opened and nothing else is.
 */
function collapseAll(except: string | null = null) {
  const shut = new Set<string>()

  for (const folder of props.folders) {
    if (props.folders.some(f => f.path.startsWith(folder.path + '/'))) {
      shut.add(folder.path)
    }
  }

  if (except) {
    const current = props.folders.find(f => f.uuid === except)

    if (current) {
      // Every ancestor path is a prefix of this one, so opening the trail is one pass rather than
      // a walk up a parent chain the component does not have.
      for (const path of [...shut]) {
        if (current.path === path || current.path.startsWith(path + '/')) {
          shut.delete(path)
        }
      }
    }
  }

  collapsed.value = shut
}

collapseAll(props.selected)

/*
 * Re-shut the tree when the folder list itself changes — a folder created, moved or archived
 * reloads the list, and a rebuild that left everything open would undo the collapse the moment
 * somebody added a folder.
 */
watch(() => props.folders, () => collapseAll(props.selected))

// Opening a folder from elsewhere on the page reveals it here rather than leaving the tree shut
// on a selection the person can no longer see.
watch(() => props.selected, uuid => {
  if (!uuid) return

  const current = props.folders.find(f => f.uuid === uuid)

  if (!current) return

  const open = new Set(collapsed.value)

  for (const path of [...open]) {
    if (current.path === path || current.path.startsWith(path + '/')) {
      open.delete(path)
    }
  }

  collapsed.value = open
})

function toggle(folder: Folder) {
  collapsed.value.has(folder.path)
    ? collapsed.value.delete(folder.path)
    : collapsed.value.add(folder.path)
  // A Set mutated in place is not reactive; replacing it is.
  collapsed.value = new Set(collapsed.value)
}

const hasChildren = (folder: Folder) =>
  props.folders.some(f => f.path.startsWith(folder.path + '/'))

/**
 * What is actually on screen.
 *
 * A row is hidden when any ancestor is collapsed — checked against the collapsed paths rather than
 * by walking parents, so this stays one pass over the list however deep the structure goes.
 */
const visible = computed(() =>
  props.folders.filter(folder => {
    if (folder.archived && !showArchived.value) return false

    for (const path of collapsed.value) {
      if (folder.path.startsWith(path + '/')) return false
    }

    return true
  }),
)

const archivedCount = computed(() => props.folders.filter(f => f.archived).length)

// ── Dialogs ─────────────────────────────────────────────────────────────────

type Mode = 'create' | 'rename' | 'move' | 'copy' | null

const mode = ref<Mode>(null)
const working = ref<Folder | null>(null)
const name = ref('')
const parent = ref<string | null>(null)
const scope = ref('company')
const confidential = ref(false)
const busy = ref(false)

/** Somewhere to move or copy to — never into itself or its own subtree. */
const destinations = computed(() => {
  /*
   * A folder cannot be moved or copied into itself or into its own subtree — but it is exactly
   * where a new subfolder goes.
   *
   * Excluding it for create as well left the dialog with a parent selected that was not in its own
   * list, so the picker had no title to show and printed the raw uuid instead:
   * "Inside e23790e0-bfe8-44ee-bb05-f3979640d2d1". The value was right the whole time and only the
   * label was missing, which is why it still filed the folder in the right place.
   */
  const forbidden = mode.value === 'create' ? null : working.value

  const options = props.folders
    .filter(f => !f.archived)
    .filter(f => !forbidden || (f.uuid !== forbidden.uuid && !f.path.startsWith(forbidden.path + '/')))
    // The indent is what makes a flat list read as a tree; without it "Contracts" appears twice
    // with nothing to say which is Legal's and which is HR's.
    .map(f => ({ value: f.uuid, title: '　'.repeat(f.depth) + f.name }))

  return [{ value: null, title: d('folder_root') }, ...options]
})

function open(next: Mode, folder: Folder | null = null) {
  mode.value = next
  working.value = folder
  name.value = next === 'rename' ? (folder?.name ?? '') : next === 'copy' ? `${folder?.name ?? ''} (copy)` : ''
  parent.value = next === 'create' ? (folder?.uuid ?? null) : (folder ? null : null)
  scope.value = folder?.scope ?? 'company'
  confidential.value = false
}

function close() {
  mode.value = null
  working.value = null
  busy.value = false
}

function submit() {
  busy.value = true
  const done = { preserveScroll: true, onFinish: close }

  if (mode.value === 'create') {
    router.post(route('document-management.folders.store'), {
      name: name.value, parent: parent.value, scope: scope.value, is_confidential: confidential.value,
    }, done)
    return
  }

  if (!working.value) return
  const uuid = working.value.uuid

  if (mode.value === 'rename') {
    router.put(route('document-management.folders.update', uuid), { name: name.value }, done)
  } else if (mode.value === 'move') {
    router.post(route('document-management.folders.move', uuid), { parent: parent.value }, done)
  } else if (mode.value === 'copy') {
    router.post(route('document-management.folders.copy', uuid), { parent: parent.value, name: name.value }, done)
  }
}

// ── Dragging ────────────────────────────────────────────────────────────────

const dragged = ref<Folder | null>(null)
const over = ref<string | null>(null)

/**
 * Whether a folder is somewhere this one could go.
 *
 * Checked while dragging so an impossible drop never highlights — being allowed to drop somewhere
 * the server then refuses is a worse experience than the target simply not responding.
 */
function canDropOn(target: Folder | null): boolean {
  const moving = dragged.value
  if (!moving) return false

  // Its own parent already: the drop would be a no-op, so it is not offered.
  if (target?.uuid === moving.uuid) return false
  if (!target) return moving.path.includes('/')

  return !target.archived
    && target.can.edit
    && !target.path.startsWith(moving.path + '/')
}

function startDrag(folder: Folder, event: DragEvent) {
  dragged.value = folder
  over.value = null

  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    // Some browsers refuse to start a drag without payload, and the uuid is the honest thing to
    // carry — anything reading it gets something meaningful rather than a placeholder.
    event.dataTransfer.setData('text/plain', folder.uuid)
  }
}

function dragOver(target: Folder | null, event: DragEvent) {
  if (!canDropOn(target)) return

  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move'
  over.value = target?.uuid ?? '__root__'
}

function drop(target: Folder | null) {
  const moving = dragged.value
  over.value = null
  dragged.value = null

  if (!moving || !canDropOn(target)) return

  router.post(route('document-management.folders.move', moving.uuid),
    { parent: target?.uuid ?? null }, { preserveScroll: true })
}

function endDrag() {
  dragged.value = null
  over.value = null
}

function act(action: 'archive' | 'restore', folder: Folder) {
  router.post(route(`document-management.folders.${action}`, folder.uuid), {}, { preserveScroll: true })
}

/**
 * Deleting is confirmed here and refused by the server when the folder still holds anything.
 *
 * The dialog says what will happen rather than asking "are you sure": a folder with subfolders is a
 * different decision from an empty one, and the person should know which they are making.
 */
const confirmingDelete = ref<Folder | null>(null)

function destroy() {
  if (!confirmingDelete.value) return
  router.delete(route('document-management.folders.destroy', confirmingDelete.value.uuid), {
    preserveScroll: true,
    onFinish: () => { confirmingDelete.value = null },
  })
}

const dialogTitle = computed(() => ({
  create: d('folder_new'),
  rename: d('folder_rename'),
  move: d('folder_move'),
  copy: d('folder_copy'),
}[mode.value ?? 'create']))
</script>

<template>
  <VCard elevation="0" border>
    <VCardText class="d-flex align-center ga-2 pb-2">
      <VIcon size="20" icon="mdi-file-tree" />
      <span class="text-body-2 font-weight-medium">{{ d('folders') }}</span>
      <VSpacer />
      <VTooltip v-if="canManage" location="bottom" :text="d('drag_hint')">
        <template #activator="{ props: tip }">
          <VIcon v-bind="tip" size="16" icon="mdi-information-outline" style="opacity:.5" />
        </template>
      </VTooltip>
      <VBtn
        v-if="canManage" size="x-small" variant="tonal" icon="mdi-folder-plus-outline"
        :aria-label="d('folder_new')" @click="open('create')"
      />
    </VCardText>

    <VDivider />

    <VList density="compact" class="py-1" role="tree">
      <!-- Everything, which is also how you get back after clicking into a folder. -->
      <VListItem
        :active="selected === null" role="treeitem"
        :class="{ 'drop-target': over === '__root__' }"
        @click="emit('select', null)"
        @dragover="dragOver(null, $event)"
        @dragleave="over = null"
        @drop.prevent="drop(null)"
      >
        <template #prepend><VIcon size="18" icon="mdi-inbox-multiple-outline" /></template>
        <VListItemTitle class="text-body-2">
          {{ d('all_documents') }}
          <!-- Says what the drop would do, while it is being considered. -->
          <span v-if="over === '__root__'" class="text-caption text-medium-emphasis ms-1">
            {{ d('drop_to_root') }}
          </span>
        </VListItemTitle>
      </VListItem>

      <VListItem
        v-for="folder in visible" :key="folder.uuid"
        :active="selected === folder.uuid"
        :style="{ paddingInlineStart: `${12 + folder.depth * 16}px` }"
        role="treeitem" :aria-level="folder.depth + 1"
        :draggable="canManage && folder.can.edit && !folder.archived"
        :class="{
          'drop-target': over === folder.uuid,
          'is-dragging': dragged?.uuid === folder.uuid,
        }"
        @click="emit('select', folder.uuid)"
        @dragstart="startDrag(folder, $event)"
        @dragover="dragOver(folder, $event)"
        @dragleave="over = null"
        @drop.prevent="drop(folder)"
        @dragend="endDrag"
      >
        <template #prepend>
          <!-- The twisty only exists where there is something to expand. -->
          <VBtn
            v-if="hasChildren(folder)" size="x-small" variant="text" density="comfortable"
            :icon="collapsed.has(folder.path) ? 'mdi-chevron-right' : 'mdi-chevron-down'"
            :aria-label="d(collapsed.has(folder.path) ? 'folder_expand' : 'folder_collapse')"
            @click.stop="toggle(folder)"
          />
          <span v-else class="twisty-spacer" />
          <VIcon
            size="18"
            :icon="folder.is_confidential ? 'mdi-folder-lock-outline' : 'mdi-folder-outline'"
            :color="folder.is_confidential ? 'error' : undefined"
          />
        </template>

        <VListItemTitle class="text-body-2 d-flex align-center ga-1">
          <span :class="{ 'text-medium-emphasis': folder.archived }">{{ folder.name }}</span>
          <VChip v-if="folder.scope === 'department'" size="x-small" variant="tonal" label>
            {{ d('scope_department') }}
          </VChip>
          <VChip v-if="folder.archived" size="x-small" variant="tonal" label>{{ d('archived') }}</VChip>
        </VListItemTitle>

        <template #append>
          <span v-if="folder.documents" class="text-caption text-medium-emphasis me-1">{{ folder.documents }}</span>

          <VMenu v-if="(canManage && folder.can.edit) || folder.can.manage">
            <template #activator="{ props: menu }">
              <VBtn
                v-bind="menu" size="x-small" variant="text" icon="mdi-dots-vertical"
                :aria-label="d('folder_actions', { name: folder.name })" @click.stop
              />
            </template>
            <VList density="compact">
              <VListItem prepend-icon="mdi-folder-plus-outline" @click="open('create', folder)">
                <VListItemTitle>{{ d('folder_new_child') }}</VListItemTitle>
              </VListItem>
              <VListItem prepend-icon="mdi-rename-outline" @click="open('rename', folder)">
                <VListItemTitle>{{ d('folder_rename') }}</VListItemTitle>
              </VListItem>
              <!--
                The screen that decides who else may use this folder. Shown to a folder's own
                administrator as well as to whoever administers the repository — that is the point
                of the ability.
              -->
              <VListItem prepend-icon="mdi-account-key-outline" @click="emit('permissions', folder)">
                <VListItemTitle>{{ d('folder_permissions') }}</VListItemTitle>
              </VListItem>
              <VListItem prepend-icon="mdi-folder-move-outline" @click="open('move', folder)">
                <VListItemTitle>{{ d('folder_move') }}</VListItemTitle>
              </VListItem>
              <VListItem prepend-icon="mdi-content-copy" @click="open('copy', folder)">
                <VListItemTitle>{{ d('folder_copy') }}</VListItemTitle>
              </VListItem>
              <VDivider />
              <VListItem
                v-if="!folder.archived" prepend-icon="mdi-archive-arrow-down-outline"
                @click="act('archive', folder)"
              >
                <VListItemTitle>{{ d('folder_archive') }}</VListItemTitle>
              </VListItem>
              <VListItem v-else prepend-icon="mdi-archive-arrow-up-outline" @click="act('restore', folder)">
                <VListItemTitle>{{ d('folder_restore') }}</VListItemTitle>
              </VListItem>
              <VListItem
                v-if="folder.can.delete" prepend-icon="mdi-delete-outline"
                base-color="error" @click="confirmingDelete = folder"
              >
                <VListItemTitle>{{ d('folder_delete') }}</VListItemTitle>
              </VListItem>
            </VList>
          </VMenu>
        </template>
      </VListItem>

      <VListItem v-if="!visible.length" class="text-medium-emphasis">
        <VListItemTitle class="text-body-2">{{ d('folders_empty') }}</VListItemTitle>
      </VListItem>
    </VList>

    <!-- Archived folders are out of the way but findable: hiding them entirely makes an archived
         branch look deleted to whoever needs to restore it. -->
    <template v-if="archivedCount">
      <VDivider />
      <VCardText class="py-2">
        <VBtn variant="text" size="small" density="comfortable" @click="showArchived = !showArchived">
          {{ showArchived ? d('folder_hide_archived') : d('folder_show_archived', { n: archivedCount }) }}
        </VBtn>
      </VCardText>
    </template>
  </VCard>

  <!-- ── Create / rename / move / copy ──────────────────────────────────── -->
  <VDialog :model-value="mode !== null" max-width="520" @update:model-value="close">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-medium">{{ dialogTitle }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <VTextField
          v-if="mode !== 'move'" v-model="name" :label="d('folder_name')" autofocus
          density="comfortable" @keyup.enter="submit"
        />

        <VSelect
          v-if="mode !== 'rename'" v-model="parent" :items="destinations"
          :label="mode === 'create' ? d('folder_parent') : d('folder_destination')"
          density="comfortable"
        />

        <template v-if="mode === 'create'">
          <VSelect
            v-model="scope" density="comfortable" :label="d('folder_scope')"
            :items="[
              { value: 'company', title: d('scope_company_full') },
              { value: 'department', title: d('scope_department_full') },
            ]"
          />
          <VSwitch v-model="confidential" :label="d('folder_confidential')" density="compact" hide-details inset />
          <div class="text-caption text-medium-emphasis">{{ d('folder_confidential_hint') }}</div>
        </template>

        <VAlert v-if="mode === 'copy'" type="info" variant="tonal" density="compact">
          {{ d('folder_copy_hint') }}
        </VAlert>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="close">{{ d('cancel') }}</VBtn>
        <VBtn
          color="primary" :loading="busy"
          :disabled="mode !== 'move' && !name.trim()"
          @click="submit"
        >{{ d('save') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Delete ────────────────────────────────────────────────────────── -->
  <VDialog :model-value="confirmingDelete !== null" max-width="480" @update:model-value="confirmingDelete = null">
    <VCard v-if="confirmingDelete">
      <VCardTitle class="text-body-1 font-weight-medium">{{ d('folder_delete') }}</VCardTitle>
      <VCardText>
        <!-- Says what happens rather than asking "are you sure". -->
        <p class="text-body-2">{{ d('folder_delete_confirm', { name: confirmingDelete.name }) }}</p>
        <VAlert
          v-if="confirmingDelete.documents" type="warning" variant="tonal"
          density="compact" class="mt-2"
        >{{ d('folder_delete_blocked', { count: confirmingDelete.documents }) }}</VAlert>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="confirmingDelete = null">{{ d('cancel') }}</VBtn>
        <VBtn color="error" variant="tonal" @click="destroy">{{ d('folder_delete') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
/* Keeps the folder icons in one column whether or not a row has a twisty. */
.twisty-spacer {
  display: inline-block;
  inline-size: 28px;
}

/* A line rather than a fill: the row underneath is where it lands, and a filled row reads as
   "selected", which it is not. */
.drop-target {
  box-shadow: inset 0 0 0 2px rgb(var(--v-theme-primary));
  border-radius: 4px;
}

.is-dragging { opacity: 0.45; }

/* Dragging is an enhancement; the same move is on the row's menu. Nothing here is the only way to
   do anything, so pointer-only affordances stay subtle. */
[draggable='true'] { cursor: grab; }
[draggable='true']:active { cursor: grabbing; }

@media (prefers-reduced-motion: reduce) {
  .is-dragging { opacity: 1; }
}
</style>
