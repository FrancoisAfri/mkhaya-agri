<script setup lang="ts">
/**
 * The audit trail across the repository.
 *
 * Read as a timeline rather than a table, because the question brought to it is nearly always about
 * a sequence — who had this document before it changed, what happened between the upload and the
 * approval — and a grid of columns makes that a reading exercise.
 *
 * The default view is the last thirty days. An audit log opened on its entire history is a scan of
 * the table and a page nobody waits for; the date filters are there for the times somebody genuinely
 * needs 2019.
 */
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Event {
  id: number
  action: string
  at: string | null
  user: string | null
  ip: string | null
  detail: Record<string, unknown> | null
  document: { uuid: string; title: string } | null
  folder: { uuid: string; name: string } | null
}

const props = defineProps<{
  events: { data: Event[]; links: { url: string | null; label: string; active: boolean }[]; total: number }
  filters: { action?: string; document?: string; from?: string; to?: string }
  actions: string[]
  defaultDays: number
}>()

const filtered = computed(() => Object.values(props.filters).some(Boolean))

function apply(patch: Record<string, unknown>) {
  router.get(route('document-management.activity'), { ...props.filters, ...patch }, {
    preserveState: true, preserveScroll: true, replace: true,
  })
}

function clear() {
  router.get(route('document-management.activity'), {}, { preserveState: true, replace: true })
}

/**
 * Icon and colour per action, grouped by what the action does rather than by name.
 *
 * Destruction is red wherever it appears, and reading is quiet — so a page of views does not look
 * as alarming as a page of deletions, which is the distinction somebody is scanning for.
 */
const look = (action: string) => {
  if (['deleted', 'purged'].includes(action)) return { icon: 'mdi-delete-outline', colour: 'error' }
  if (['rejected', 'declined', 'hold_placed'].includes(action)) return { icon: 'mdi-alert-outline', colour: 'warning' }
  if (['approved', 'published', 'signed'].includes(action)) return { icon: 'mdi-check-circle-outline', colour: 'success' }
  if (['shared', 'shared_internally', 'share_accessed'].includes(action)) return { icon: 'mdi-share-variant-outline', colour: 'info' }
  if (['uploaded', 'replaced', 'version_restored'].includes(action)) return { icon: 'mdi-upload-outline', colour: 'primary' }
  if (action === 'folder_permissions_changed') return { icon: 'mdi-account-key-outline', colour: 'warning' }
  if (action.startsWith('folder_')) return { icon: 'mdi-folder-outline', colour: undefined }
  if (['renamed', 'moved', 'updated'].includes(action)) return { icon: 'mdi-pencil-outline', colour: undefined }

  return { icon: 'mdi-eye-outline', colour: undefined }
}

/** The action in words. Falls back to the raw key rather than showing nothing. */
const label = (action: string) => {
  const key = 'document_management.events.' + action

  return t(key) === key ? action.replace(/_/g, ' ') : t(key)
}

/** A one-line summary of the detail, where there is one worth reading. */
function summarise(event: Event): string | null {
  const detail = event.detail

  if (!detail) return null

  if (detail.from !== undefined && detail.to !== undefined) {
    return `${detail.from ?? '—'} → ${detail.to ?? '—'}`
  }

  if (detail.changed && typeof detail.changed === 'object') {
    return Object.keys(detail.changed as Record<string, unknown>).join(', ')
  }

  for (const key of ['subject', 'reference', 'recipient', 'note', 'version', 'name']) {
    if (typeof detail[key] === 'string' && detail[key]) return detail[key] as string
  }

  return null
}
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <h1 class="text-h5 font-weight-bold">{{ d('activity_title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ filtered ? d('activity_subtitle') : d('activity_recent', { days: defaultDays }) }}
    </p>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="d-flex ga-3 flex-wrap align-center">
        <VSelect
          :model-value="filters.action ?? null" :items="actions" clearable
          :label="d('activity_action')" density="compact" hide-details style="max-width:16rem"
          @update:model-value="apply({ action: $event })"
        >
          <template #item="{ props: itemProps, item }">
            <VListItem v-bind="itemProps" :title="label(String(item.value))" />
          </template>
          <template #selection="{ item }">{{ label(String(item.value)) }}</template>
        </VSelect>

        <VTextField
          :model-value="filters.from ?? ''" type="date" :label="d('activity_from')"
          density="compact" hide-details style="max-width:11rem"
          @update:model-value="apply({ from: $event })"
        />
        <VTextField
          :model-value="filters.to ?? ''" type="date" :label="d('activity_to')"
          density="compact" hide-details style="max-width:11rem"
          @update:model-value="apply({ to: $event })"
        />

        <VBtn v-if="filtered" size="small" variant="text" @click="clear">{{ d('activity_clear') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VList v-if="events.data.length" density="comfortable" class="py-0">
        <template v-for="(event, index) in events.data" :key="event.id">
          <VDivider v-if="index > 0" />
          <VListItem>
            <template #prepend>
              <VIcon :icon="look(event.action).icon" :color="look(event.action).colour" size="20" />
            </template>

            <VListItemTitle class="text-body-2">
              <span class="font-weight-medium">{{ event.user ?? d('activity_system') }}</span>
              {{ label(event.action) }}
              <a
                v-if="event.document"
                :href="route('document-management.documents.show', event.document.uuid)"
              >{{ event.document.title }}</a>
              <!-- A folder event names the folder. The deletion ones outlive the folder itself,
                   which is exactly when somebody comes looking for them. -->
              <span v-else-if="event.folder" class="font-weight-medium">{{ event.folder.name }}</span>
              <span v-else class="text-medium-emphasis">{{ d('activity_document_gone') }}</span>
            </VListItemTitle>

            <VListItemSubtitle class="text-caption">
              {{ event.at }}
              <template v-if="summarise(event)"> · {{ summarise(event) }}</template>
              <!-- The address is shown because an external share can be opened by somebody nobody
                   here can name, and it is the only identifying thing there is. -->
              <template v-if="event.ip"> · {{ event.ip }}</template>
            </VListItemSubtitle>
          </VListItem>
        </template>
      </VList>

      <VCardText v-else class="text-body-2 text-medium-emphasis">
        {{ d('activity_none') }}
      </VCardText>
    </VCard>

    <div v-if="events.links.length > 3" class="d-flex ga-1 flex-wrap justify-center mt-4">
      <VBtn
        v-for="link in events.links" :key="link.label"
        size="small" variant="text" :disabled="!link.url" :active="link.active"
        @click="link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
      >
        <span v-html="link.label" />
      </VBtn>
    </div>
  </div>
</template>
