<script setup lang="ts">
/**
 * Reviewing access rather than granting it.
 *
 * Every other permissions screen in this module answers "let this person in". This one answers the
 * questions asked afterwards — who is in, why, and what is about to lapse — and lets them be acted
 * on here, because a review screen that only reports sends somebody to four other screens and the
 * review stops happening.
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmAction from '../Components/ConfirmAction.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface TemporaryRow {
  type: string
  uuid: string
  who: string | null
  what: string | null
  target: string | null
  ends: string | null
  ends_in: string | null
  can_download?: boolean
  folders?: number
  user_id?: number
}

const props = defineProps<{
  summary: Record<string, number>
  temporary: TemporaryRow[]
  checked_out: { uuid: string; title: string; by: string | null; since: string | null; until: string | null }[]
  refusals: { who: string | null; document: string; title: string; attempts: number; last: string }[]
  downloads: { who: string | null; document: string; title: string; at: string }[]
  heavily_granted: { who: string | null; user_id: number; grants: number }[]
  people: { id: number; name: string | null }[]
  subject: { id: number; name: string | null } | null
  access: {
    folders: { uuid: string; name: string; depth: number; can: Record<string, boolean>; why: string }[]
    documents: { uuid: string; title: string; can_download: boolean; ends: string | null; grant: string }[]
    groups: { uuid: string; name: string; ends: string | null }[]
  }
}>()

const looking = ref<number | null>(props.subject?.id ?? null)

function look() {
  // Kept in the URL so the answer can be sent to somebody rather than described to them.
  router.get(route('document-management.access-management'), looking.value ? { user: looking.value } : {}, {
    preserveState: true,
    preserveScroll: true,
  })
}

function revoke(row: TemporaryRow) {
  router.post(
    route('document-management.access-management.revoke'),
    { type: row.type, uuid: row.uuid, user_id: row.user_id ?? null },
    { preserveScroll: true },
  )
}

const typeLabels: Record<string, string> = {
  document_share: 'access_map_type_document',
  folder_grant: 'access_map_type_folder',
  group: 'access_map_type_group',
  membership: 'access_map_type_membership',
}

// The label is named rather than derived from the key: two of these read differently as a heading
// and as a tile ("Checked out right now" against "Checked out"), and deriving would have quietly
// given one of them the other's wording.
const tiles = [
  { key: 'pending_access', label: 'access_map_pending_access', icon: 'mdi-account-key-outline', colour: 'purple', to: 'document-management.access-requests' },
  { key: 'pending_downloads', label: 'access_map_pending_downloads', icon: 'mdi-file-download-outline', colour: 'primary', to: 'document-management.access-requests' },
  { key: 'temporary', label: 'access_map_temporary', icon: 'mdi-clock-outline', colour: 'orange', to: null },
  { key: 'groups', label: 'access_map_groups', icon: 'mdi-account-group-outline', colour: 'indigo', to: 'document-management.groups' },
  { key: 'checked_out', label: 'access_map_checked_out_count', icon: 'mdi-lock-outline', colour: 'blue-grey', to: null },
  { key: 'read_only_documents', label: 'access_map_read_only_documents', icon: 'mdi-eye-lock-outline', colour: 'teal', to: null },
]
</script>

<template>
  <div class="pa-4" style="max-width:1200px;margin:auto">
    <h1 class="text-h5 font-weight-bold">{{ d('access_map_title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ d('access_map_subtitle') }}</p>

    <!-- The shape of it, before any of the detail. -->
    <VRow dense class="mb-2">
      <VCol v-for="tile in tiles" :key="tile.key" cols="6" sm="4" md="2">
        <VCard
          elevation="0" border class="pa-3 h-100"
          :class="tile.to ? 'cursor-pointer' : ''"
          @click="tile.to ? router.get(route(tile.to)) : undefined"
        >
          <VIcon :icon="tile.icon" :color="tile.colour" size="20" />
          <div class="text-h6 font-weight-bold mt-1">{{ summary[tile.key] ?? 0 }}</div>
          <div class="text-caption text-medium-emphasis">{{ d(tile.label) }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── What ends by itself ──────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('access_map_ending') }}</VCardTitle>
      <VCardSubtitle class="text-caption pb-2">{{ d('access_map_ending_hint') }}</VCardSubtitle>

      <VTable v-if="temporary.length" density="comfortable">
        <thead>
          <tr>
            <th>{{ d('access_map_who') }}</th>
            <th>{{ d('access_map_what') }}</th>
            <th>{{ d('access_map_kind') }}</th>
            <th>{{ d('access_map_ends') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in temporary" :key="row.type + row.uuid">
            <td class="font-weight-medium">{{ row.who }}</td>
            <td>
              <template v-if="row.type === 'group'">{{ d('access_map_n_folders', { n: row.folders ?? 0 }) }}</template>
              <template v-else>{{ row.what }}</template>
            </td>
            <td>
              <VChip size="x-small" variant="tonal">{{ d(typeLabels[row.type]) }}</VChip>
            </td>
            <td>
              {{ row.ends }}
              <span class="text-caption text-medium-emphasis d-block">{{ row.ends_in }}</span>
            </td>
            <td class="text-right">
              <ConfirmAction
                :title="d('access_map_revoke_title')"
                :body="d('access_map_revoke_body', { who: row.who })"
                :consequence="d('access_map_revoke_consequence')"
                :confirm-label="d('access_map_revoke')"
                @confirm="revoke(row)"
              >
                <template #activator="{ open }">
                  <VBtn size="small" variant="text" color="error" @click="open">
                    {{ d('access_map_revoke') }}
                  </VBtn>
                </template>
              </ConfirmAction>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('access_map_nothing_ending') }}</VCardText>
    </VCard>

    <!-- ── Why this person can see what they can ────────────────────────── -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('access_map_look_up') }}</VCardTitle>
      <VCardText>
        <VAutocomplete
          v-model="looking" density="comfortable" clearable
          :label="d('access_map_choose_person')"
          :items="people.map(p => ({ value: p.id, title: p.name ?? '' }))"
          @update:model-value="look"
        />

        <template v-if="subject">
          <div v-if="access.groups.length" class="mb-3">
            <div class="text-caption text-medium-emphasis mb-1">{{ d('access_map_in_groups') }}</div>
            <VChip
              v-for="group in access.groups" :key="group.uuid"
              size="small" variant="tonal" class="me-1"
            >
              {{ group.name }}
              <template v-if="group.ends"> · {{ group.ends }}</template>
            </VChip>
          </div>

          <div class="text-caption text-medium-emphasis mb-1">{{ d('access_map_folders_reachable') }}</div>
          <VTable v-if="access.folders.length" density="compact">
            <tbody>
              <tr v-for="folder in access.folders" :key="folder.uuid">
                <td>
                  <span :style="{ paddingInlineStart: folder.depth * 16 + 'px' }">{{ folder.name }}</span>
                </td>
                <td class="text-caption text-medium-emphasis">{{ folder.why }}</td>
                <td class="text-right">
                  <VChip v-if="folder.can.download" size="x-small" variant="tonal" color="primary" class="me-1">
                    {{ d('downloads_allow') }}
                  </VChip>
                  <VChip v-if="folder.can.edit" size="x-small" variant="tonal" color="amber">{{ d('perm_edit') }}</VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
          <p v-else class="text-body-2 text-medium-emphasis">{{ d('access_map_no_folders') }}</p>

          <template v-if="access.documents.length">
            <div class="text-caption text-medium-emphasis mt-4 mb-1">{{ d('access_map_documents_shared') }}</div>
            <VTable density="compact">
              <tbody>
                <tr v-for="doc in access.documents" :key="doc.grant">
                  <td>{{ doc.title }}</td>
                  <td class="text-caption text-medium-emphasis">{{ doc.ends }}</td>
                </tr>
              </tbody>
            </VTable>
          </template>
        </template>
      </VCardText>
    </VCard>

    <!-- ── After the fact ───────────────────────────────────────────────── -->
    <VRow dense class="mb-4">
      <VCol cols="12" md="6">
        <VCard elevation="0" border class="h-100">
          <VCardTitle class="text-subtitle-1">{{ d('access_map_refusals') }}</VCardTitle>
          <!--
            Grouped by person and document rather than listed one by one. One refusal is a stale
            link somebody was sent; nine in a week is a conversation worth having, and only the
            count shows the difference.
          -->
          <VCardSubtitle class="text-caption pb-2">{{ d('access_map_refusals_hint') }}</VCardSubtitle>
          <VList v-if="refusals.length" density="compact">
            <VListItem v-for="row in refusals" :key="(row.who ?? '') + row.document">
              <VListItemTitle class="text-body-2">{{ row.who }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ row.title }} · {{ row.last }}</VListItemSubtitle>
              <template #append>
                <VChip size="x-small" variant="tonal" :color="row.attempts > 3 ? 'warning' : undefined">
                  {{ row.attempts }}
                </VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('access_map_no_refusals') }}</VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard elevation="0" border class="h-100">
          <VCardTitle class="text-subtitle-1">{{ d('access_map_downloads') }}</VCardTitle>
          <!-- A download is the moment a document stops being under this system's control. -->
          <VCardSubtitle class="text-caption pb-2">{{ d('access_map_downloads_hint') }}</VCardSubtitle>
          <VList v-if="downloads.length" density="compact">
            <VListItem
              v-for="(row, index) in downloads" :key="index"
              :href="route('document-management.documents.show', row.document)"
            >
              <VListItemTitle class="text-body-2">{{ row.title }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ row.who }} · {{ row.at }}</VListItemSubtitle>
            </VListItem>
          </VList>
          <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('access_map_no_downloads') }}</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard v-if="heavily_granted.length" elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ d('access_map_heavily_granted') }}</VCardTitle>
      <!--
        Not a judgement, a prompt. Somebody with fourteen shares is either doing something that
        needs them or has accumulated them one exception at a time, and nobody can tell which
        without being shown the number.
      -->
      <VCardSubtitle class="text-caption pb-2">{{ d('access_map_heavily_granted_hint') }}</VCardSubtitle>
      <VList density="compact">
        <VListItem
          v-for="row in heavily_granted" :key="row.user_id"
          @click="looking = row.user_id; look()"
        >
          <VListItemTitle class="text-body-2">{{ row.who }}</VListItemTitle>
          <template #append>
            <VChip size="x-small" variant="tonal">{{ d('access_map_n_shares', { n: row.grants }) }}</VChip>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <!-- ── What is checked out right now ────────────────────────────────── -->
    <VCard v-if="checked_out.length" elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ d('access_map_checked_out') }}</VCardTitle>
      <VCardSubtitle class="text-caption pb-2">{{ d('access_map_checked_out_hint') }}</VCardSubtitle>
      <VList density="compact">
        <VListItem
          v-for="doc in checked_out" :key="doc.uuid"
          :href="route('document-management.documents.show', doc.uuid)"
        >
          <VListItemTitle class="text-body-2">{{ doc.title }}</VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ d('lock_theirs', { who: doc.by, since: doc.since }) }}
          </VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>
  </div>
</template>
