<script setup lang="ts">
/**
 * Who may use one folder, and who administers it.
 *
 * This screen did not exist. The route behind it did, and had since the module was written, but
 * nothing in the front end ever called it — so "give the auditors the Finance folder" was a thing
 * the system could do and nobody could reach, and the only way to express it was to name people one
 * at a time from the group's own screen.
 *
 * Four kinds of subject, and the order is deliberate. A group first, because that is the answer
 * that survives somebody joining or leaving; then a role and a department, which are the two
 * standing groups the organisation already maintains; a named person last, because it is the one
 * that has to be revisited by hand and the easiest to reach for by habit.
 *
 * "Administers" is the ability worth explaining rather than ticking. Somebody with it decides who
 * else may use this folder — which is how the Finance manager stops needing the person who holds
 * the module permission to approve every request they are not equipped to judge.
 */
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmAction from './ConfirmAction.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Grant {
  uuid?: string
  subject_type: string
  subject_id: number | null
  subject_name: string | null
  can_view: boolean
  can_download: boolean
  can_upload: boolean
  can_edit: boolean
  can_delete: boolean
  can_manage: boolean
}

const props = defineProps<{
  modelValue: boolean
  folder: { uuid: string; name: string } | null
  people: { id: number; name: string | null }[]
  roles: string[]
  departments: { id: number; name: string }[]
  groups: { id: number; name: string }[]
}>()

const emit = defineEmits<{ 'update:modelValue': [boolean] }>()

const grants = ref<Grant[]>([])
const loading = ref(false)
const saving = ref(false)

/*
 * Fetched when the dialog opens rather than sent with the page. A folder tree of two hundred
 * folders would otherwise carry every grant on every one of them into a payload that is read for
 * one folder at most, and usually none.
 */
watch(() => [props.modelValue, props.folder?.uuid], async ([open]) => {
  if (!open || !props.folder) return

  loading.value = true
  grants.value = []

  try {
    const response = await fetch(route('document-management.folders.permissions.show', props.folder.uuid), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })

    if (response.ok) {
      grants.value = (await response.json()).grants ?? []
    }
  } finally {
    loading.value = false
  }
})

const adding = ref<Grant>(blank())

function blank(): Grant {
  return {
    subject_type: 'group',
    subject_id: null,
    subject_name: null,
    // View is what somebody almost always means by "give them the folder"; the rest is opt-in.
    can_view: true,
    can_download: true,
    can_upload: false,
    can_edit: false,
    can_delete: false,
    can_manage: false,
  }
}

const subjectOptions = computed(() => {
  if (adding.value.subject_type === 'group') return props.groups.map(g => ({ value: g.id, title: g.name }))
  if (adding.value.subject_type === 'department') return props.departments.map(x => ({ value: x.id, title: x.name }))
  if (adding.value.subject_type === 'user') return props.people.map(p => ({ value: p.id, title: p.name ?? '' }))

  // A role is matched by name, so a renamed role keeps working for the people in it.
  return props.roles.map(r => ({ value: r, title: r }))
})

const canAdd = computed(() => adding.value.subject_type === 'role'
  ? !!adding.value.subject_name
  : adding.value.subject_id !== null)

function add() {
  const chosen = subjectOptions.value.find(o => o.value === (adding.value.subject_type === 'role'
    ? adding.value.subject_name
    : adding.value.subject_id))

  grants.value.push({
    ...adding.value,
    subject_id: adding.value.subject_type === 'role' ? null : adding.value.subject_id,
    subject_name: chosen?.title ?? adding.value.subject_name,
  })

  adding.value = blank()
}

function save() {
  if (!props.folder) return

  saving.value = true

  router.put(route('document-management.folders.permissions', props.folder.uuid), { grants: grants.value }, {
    preserveScroll: true,
    onFinish: () => { saving.value = false },
    onSuccess: () => emit('update:modelValue', false),
  })
}

const abilities = [
  { key: 'can_view', label: 'perm_view' },
  { key: 'can_download', label: 'downloads_allow' },
  { key: 'can_upload', label: 'perm_upload' },
  { key: 'can_edit', label: 'perm_edit' },
  { key: 'can_delete', label: 'perm_delete' },
  { key: 'can_manage', label: 'perm_manage' },
] as const

const subjectIcon = (type: string) => ({
  group: 'mdi-account-group-outline',
  role: 'mdi-shield-account-outline',
  department: 'mdi-office-building-outline',
  user: 'mdi-account-outline',
}[type] ?? 'mdi-account-outline')
</script>

<template>
  <VDialog
    :model-value="modelValue" max-width="820"
    @update:model-value="(v: boolean) => emit('update:modelValue', v)"
  >
    <VCard v-if="folder">
      <VCardTitle class="text-subtitle-1">{{ d('folder_permissions_title', { name: folder.name }) }}</VCardTitle>
      <VCardSubtitle class="text-caption pb-2">{{ d('folder_permissions_subtitle') }}</VCardSubtitle>

      <VCardText>
        <VProgressLinear v-if="loading" indeterminate class="mb-3" />

        <VTable v-if="grants.length" density="compact" class="mb-4">
          <thead>
            <tr>
              <th>{{ d('folder_permissions_who') }}</th>
              <th v-for="ability in abilities" :key="ability.key" class="text-center text-caption">
                {{ d(ability.label) }}
              </th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="(grant, index) in grants" :key="index">
              <td>
                <VIcon size="16" :icon="subjectIcon(grant.subject_type)" class="me-1" />
                {{ grant.subject_name }}
                <span class="text-caption text-medium-emphasis">· {{ d('folder_subject_' + grant.subject_type) }}</span>
              </td>
              <td v-for="ability in abilities" :key="ability.key" class="text-center">
                <VCheckboxBtn v-model="grant[ability.key]" density="compact" class="d-inline-flex" />
              </td>
              <td class="text-right">
                <VBtn
                  size="x-small" variant="text" icon="mdi-close" color="error"
                  :aria-label="d('folder_permissions_remove')" @click="grants.splice(index, 1)"
                />
              </td>
            </tr>
          </tbody>
        </VTable>

        <p v-else-if="!loading" class="text-body-2 text-medium-emphasis mb-4">
          {{ d('folder_permissions_none') }}
        </p>

        <!--
          Group first: it is the answer that survives somebody joining or leaving, where a list of
          names has to be revisited every time either happens.
        -->
        <VDivider class="mb-3" />
        <div class="d-flex flex-wrap ga-2 align-center">
          <VSelect
            v-model="adding.subject_type" density="compact" hide-details style="max-width:11rem"
            :label="d('folder_permissions_give_to')"
            :items="[
              { value: 'group', title: d('folder_subject_group') },
              { value: 'role', title: d('folder_subject_role') },
              { value: 'department', title: d('folder_subject_department') },
              { value: 'user', title: d('folder_subject_user') },
            ]"
            @update:model-value="adding.subject_id = null; adding.subject_name = null"
          />
          <VAutocomplete
            v-if="adding.subject_type === 'role'"
            v-model="adding.subject_name" density="compact" hide-details style="max-width:16rem"
            :label="d('folder_permissions_which')" :items="subjectOptions"
          />
          <VAutocomplete
            v-else
            v-model="adding.subject_id" density="compact" hide-details style="max-width:16rem"
            :label="d('folder_permissions_which')" :items="subjectOptions"
          />
          <VBtn variant="tonal" prepend-icon="mdi-plus" :disabled="!canAdd" @click="add">
            {{ d('folder_permissions_add') }}
          </VBtn>
        </div>

        <VAlert type="info" variant="tonal" density="compact" class="mt-4">
          {{ d('perm_manage_explainer') }}
        </VAlert>
      </VCardText>

      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="emit('update:modelValue', false)">{{ d('cancel') }}</VBtn>
        <!--
          Saving replaces the folder's rules with what is on screen, so removing a row here removes
          somebody's access — worth asking about once rather than discovering afterwards.
        -->
        <ConfirmAction
          :title="d('folder_permissions_save_title')"
          :body="d('folder_permissions_save_body', { name: folder.name })"
          :consequence="d('folder_permissions_save_consequence')"
          :confirm-label="d('save')"
          :danger="false"
          @confirm="save"
        >
          <template #activator="{ open }">
            <VBtn color="primary" :loading="saving" @click="open">{{ d('save') }}</VBtn>
          </template>
        </ConfirmAction>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
