<script setup lang="ts">
/**
 * Groups, their members, and the folders they reach.
 *
 * The screen is built around the thing groups exist for: giving several folders to a set of people
 * in one act, with a date on it. Four separate permission edits is how three get set correctly and
 * the fourth gets forgotten.
 */
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmAction from '../Components/ConfirmAction.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Group {
  uuid: string
  name: string
  description: string | null
  expires_at: string | null
  expired: boolean
  members: { id: number; name: string | null; expires_at: string | null }[]
  folders: { uuid: string; name: string; can: Record<string, boolean>; expires_at: string | null }[]
}

defineProps<{
  groups: Group[]
  folders: { uuid: string; name: string; depth: number }[]
  people: { id: number; name: string | null }[]
}>()

const creating = ref(false)
const managing = ref<Group | null>(null)
const granting = ref<Group | null>(null)

const groupForm = useForm({ name: '', description: '', expires_at: '' })
const memberForm = useForm({ users: [] as number[], expires_at: '' })
/*
 * `may_delete`, not `delete`.
 *
 * An Inertia form owns `delete` — it is how you send a DELETE — so a data field of that name is
 * shadowed by the method. The checkbox was bound to a function rather than a boolean, ticking it
 * replaced the method, and the permission the box exists to grant never reached the server
 * correctly. Inertia says so in the console; nothing on the screen did.
 *
 * The field is renamed and mapped back on submit, because the endpoint has always taken `delete`
 * and renaming the wire format would be a second change for no gain.
 */
const grantForm = useForm({
  folders: [] as string[],
  view: true, upload: false, edit: false, may_delete: false,
  expires_at: '',
})

function createGroup() {
  groupForm.post(route('document-management.groups.store'), {
    preserveScroll: true,
    onSuccess: () => { creating.value = false; groupForm.reset() },
  })
}

function addMembers() {
  if (!managing.value) return

  memberForm.post(route('document-management.groups.members', managing.value.uuid), {
    preserveScroll: true,
    onSuccess: () => memberForm.reset(),
  })
}

function removeMember(group: Group, userId: number) {
  router.delete(route('document-management.groups.members.remove', [group.uuid, userId]), { preserveScroll: true })
}

function grantFolders() {
  if (!granting.value) return

  grantForm
    .transform(data => ({ ...data, delete: data.may_delete }))
    .post(route('document-management.groups.folders', granting.value.uuid), {
      preserveScroll: true,
      onSuccess: () => { granting.value = null; grantForm.reset(); grantForm.view = true },
    })
}

function revokeFolder(group: Group, folderUuid: string) {
  router.delete(route('document-management.groups.folders.revoke', [group.uuid, folderUuid]), { preserveScroll: true })
}

/*
 * A closable chip and an icon button have nowhere to put an activator slot, so these two raise the
 * shared dialog through state instead. The confirmation is the same one — what must not differ is
 * whether there is one.
 */
const confirmingMember = ref<{ group: Group; member: { id: number; name: string | null } } | null>(null)
const confirmingFolder = ref<{ group: Group; folder: { uuid: string; name: string } } | null>(null)

function confirmRemoveMember(group: Group, member: { id: number; name: string | null }) {
  confirmingMember.value = { group, member }
}

function confirmRevokeFolder(group: Group, folder: { uuid: string; name: string }) {
  confirmingFolder.value = { group, folder }
}

function removeGroup(group: Group) {
  router.delete(route('document-management.groups.destroy', group.uuid), { preserveScroll: true })
}

/** What a folder grant allows, in words rather than four ticks to decode. */
const abilities = (can: Record<string, boolean>) =>
  Object.entries(can).filter(([, on]) => on).map(([name]) => name).join(', ')
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center flex-wrap ga-2 mb-1">
      <h1 class="text-h5 font-weight-bold">{{ d('groups_title') }}</h1>
      <VSpacer />
      <VBtn size="small" color="primary" variant="tonal" prepend-icon="mdi-plus" @click="creating = true">
        {{ d('groups_new') }}
      </VBtn>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ d('groups_subtitle') }}</p>

    <VCard v-for="group in groups" :key="group.uuid" elevation="0" border class="mb-3">
      <VCardText class="d-flex align-center flex-wrap ga-2 pb-2">
        <VIcon size="20" icon="mdi-account-group-outline" />
        <span class="text-body-2 font-weight-medium">{{ group.name }}</span>

        <!-- An expired group grants nothing. Said on the row, because the access it used to give
             disappears without anything else changing. -->
        <VChip v-if="group.expired" size="x-small" color="warning" variant="tonal">
          {{ d('groups_expired') }}
        </VChip>
        <VChip v-else-if="group.expires_at" size="x-small" variant="tonal">
          {{ d('groups_until', { date: group.expires_at }) }}
        </VChip>

        <VSpacer />
        <VBtn size="small" variant="text" @click="managing = group">{{ d('groups_members') }}</VBtn>
        <VBtn size="small" variant="text" @click="granting = group">{{ d('groups_give_folders') }}</VBtn>
        <ConfirmAction
          :title="d('groups_delete_title')"
          :body="d('groups_delete_body', { name: group.name })"
          :consequence="d('groups_delete_consequence')"
          :confirm-label="d('groups_delete')"
          @confirm="removeGroup(group)"
        >
          <template #activator="{ open }">
            <VBtn size="small" variant="text" color="error" @click="open">{{ d('groups_delete') }}</VBtn>
          </template>
        </ConfirmAction>
      </VCardText>

      <VDivider />

      <VCardText class="d-flex ga-6 flex-wrap">
        <div style="min-inline-size:14rem">
          <div class="text-caption font-weight-medium text-medium-emphasis mb-1">
            {{ d('groups_members') }} ({{ group.members.length }})
          </div>
          <div v-if="group.members.length" class="d-flex flex-wrap ga-1">
            <VChip
              v-for="member in group.members" :key="member.id"
              size="small" variant="tonal" closable
              @click:close="confirmRemoveMember(group, member)"
            >
              {{ member.name }}<template v-if="member.expires_at"> · {{ member.expires_at }}</template>
            </VChip>
          </div>
          <div v-else class="text-caption text-medium-emphasis">{{ d('groups_no_members') }}</div>
        </div>

        <div style="min-inline-size:16rem">
          <div class="text-caption font-weight-medium text-medium-emphasis mb-1">
            {{ d('groups_folders') }} ({{ group.folders.length }})
          </div>
          <div v-if="group.folders.length">
            <div v-for="folder in group.folders" :key="folder.uuid" class="text-body-2 d-flex align-center ga-1">
              <span>{{ folder.name }}</span>
              <span class="text-caption text-medium-emphasis">{{ abilities(folder.can) }}</span>
              <span v-if="folder.expires_at" class="text-caption text-medium-emphasis">
                · {{ d('groups_until', { date: folder.expires_at }) }}
              </span>
              <VBtn
                size="x-small" variant="text" icon="mdi-close"
                :aria-label="d('groups_revoke_folder')" @click="confirmRevokeFolder(group, folder)"
              />
            </div>
          </div>
          <div v-else class="text-caption text-medium-emphasis">{{ d('groups_no_folders') }}</div>
        </div>
      </VCardText>
    </VCard>

    <VCard v-if="!groups.length" elevation="0" border>
      <VCardText class="text-body-2 text-medium-emphasis">{{ d('groups_none') }}</VCardText>
    </VCard>

    <!-- ── New group ────────────────────────────────────────────────────── -->
    <VDialog v-model="creating" max-width="480">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-medium">{{ d('groups_new') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <VTextField v-model="groupForm.name" :label="d('groups_name')" density="comfortable" autofocus
            :error-messages="groupForm.errors.name" />
          <VTextField v-model="groupForm.description" :label="d('groups_description')" density="comfortable" />
          <VTextField
            v-model="groupForm.expires_at" type="date" :label="d('groups_expires')"
            density="comfortable" :hint="d('groups_expires_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="creating = false">{{ d('cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!groupForm.name.trim()" @click="createGroup">{{ d('save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Members ──────────────────────────────────────────────────────── -->
    <VDialog :model-value="managing !== null" max-width="520" @update:model-value="managing = null">
      <VCard v-if="managing">
        <VCardTitle class="text-body-1 font-weight-medium">{{ managing.name }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <VAutocomplete
            v-model="memberForm.users" :items="people" item-title="name" item-value="id"
            multiple chips closable-chips density="comfortable" :label="d('groups_add_people')"
          />
          <VTextField
            v-model="memberForm.expires_at" type="date" :label="d('groups_member_expires')"
            density="comfortable" :hint="d('groups_member_expires_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="managing = null">{{ d('cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!memberForm.users.length" @click="addMembers">{{ d('groups_add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Folders, several at once ─────────────────────────────────────── -->
    <VDialog :model-value="granting !== null" max-width="560" @update:model-value="granting = null">
      <VCard v-if="granting">
        <VCardTitle class="text-body-1 font-weight-medium">
          {{ d('groups_give_folders') }} — {{ granting.name }}
        </VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <!-- One set of abilities across all of them: that is what makes this one decision rather
               than several, and a screen where each folder differed would be the per-folder editor
               with extra steps. -->
          <div class="text-caption text-medium-emphasis">{{ d('groups_folders_hint') }}</div>

          <VAutocomplete
            v-model="grantForm.folders" :items="folders" item-title="name" item-value="uuid"
            multiple chips closable-chips density="comfortable" :label="d('groups_choose_folders')"
          />

          <div class="d-flex ga-4 flex-wrap">
            <VCheckbox v-model="grantForm.view" :label="d('perm_view')" density="compact" hide-details />
            <VCheckbox v-model="grantForm.upload" :label="d('perm_upload')" density="compact" hide-details />
            <VCheckbox v-model="grantForm.edit" :label="d('perm_edit')" density="compact" hide-details />
            <VCheckbox v-model="grantForm.may_delete" :label="d('perm_delete')" density="compact" hide-details />
          </div>

          <VTextField
            v-model="grantForm.expires_at" type="date" :label="d('groups_access_expires')"
            density="comfortable" :hint="d('groups_access_expires_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="granting = null">{{ d('cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!grantForm.folders.length" @click="grantFolders">
            {{ d('groups_give') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>

    <!-- The two actions that had nowhere to hang an activator slot. -->
    <VDialog :model-value="confirmingMember !== null" max-width="440" @update:model-value="confirmingMember = null">
      <VCard v-if="confirmingMember">
        <VCardTitle class="text-subtitle-1">{{ d('groups_remove_member_title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 mb-2">
            {{ d('groups_remove_member_body', { name: confirmingMember.member.name, group: confirmingMember.group.name }) }}
          </p>
          <VAlert type="warning" variant="tonal" density="compact">
            {{ d('groups_remove_member_consequence') }}
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmingMember = null">{{ d('cancel') }}</VBtn>
          <VBtn
            color="error" variant="tonal"
            @click="removeMember(confirmingMember.group, confirmingMember.member.id); confirmingMember = null"
          >{{ d('groups_remove_member') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="confirmingFolder !== null" max-width="440" @update:model-value="confirmingFolder = null">
      <VCard v-if="confirmingFolder">
        <VCardTitle class="text-subtitle-1">{{ d('groups_revoke_folder_title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 mb-2">
            {{ d('groups_revoke_folder_body', { folder: confirmingFolder.folder.name, group: confirmingFolder.group.name }) }}
          </p>
          <VAlert type="warning" variant="tonal" density="compact">
            {{ d('groups_revoke_folder_consequence') }}
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="confirmingFolder = null">{{ d('cancel') }}</VBtn>
          <VBtn
            color="error" variant="tonal"
            @click="revokeFolder(confirmingFolder.group, confirmingFolder.folder.uuid); confirmingFolder = null"
          >{{ d('groups_revoke_folder') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
</template>
