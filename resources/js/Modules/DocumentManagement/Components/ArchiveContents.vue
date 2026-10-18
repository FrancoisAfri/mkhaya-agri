<script setup lang="ts">
/**
 * What is inside the zip, without taking the zip.
 *
 * A reseller pack of forty images arrives as one archive and somebody wants the third one.
 * Downloading the lot to find it leaves the document system knowing a zip was fetched and nothing
 * about what was actually wanted — so this lists the contents, and each file can be opened or
 * taken on its own.
 *
 * Fetched when the panel is opened rather than with the page: reading an archive means pulling its
 * bytes out of the database, and most visits to a document page are not about what is inside it.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

type Entry = {
  index: number; name: string; basename: string; folder: string | null
  extension: string | null; size: number; size_label: string
  modified: string | null; previewable: boolean; too_large: boolean
}

const props = defineProps<{
  documentUuid: string
  versionUuid: string
  canDownload: boolean
}>()

const open = ref(false)
const loading = ref(false)
const loaded = ref(false)
const reason = ref<string | null>(null)
const entries = ref<Entry[]>([])
const truncated = ref(false)
const total = ref(0)
const filter = ref('')

const shown = computed(() => {
  const q = filter.value.trim().toLowerCase()

  return q ? entries.value.filter(e => e.name.toLowerCase().includes(q)) : entries.value
})

async function load() {
  if (loaded.value || loading.value) return

  loading.value = true

  try {
    const res = await fetch(
      route('document-management.documents.archive-contents', [props.documentUuid, props.versionUuid]),
      { credentials: 'same-origin', headers: { Accept: 'application/json' } },
    )

    const body = await res.json()

    entries.value = body.entries ?? []
    truncated.value = !!body.truncated
    total.value = body.total ?? 0
    reason.value = body.supported ? null : (body.reason ?? d('archive_unreadable'))
    loaded.value = true
  } catch {
    reason.value = d('archive_unreadable')
  } finally {
    loading.value = false
  }
}

function toggle() {
  open.value = !open.value

  if (open.value) load()
}

function entryUrl(entry: Entry, inline: boolean): string {
  const base = route('document-management.documents.archive-entry',
    [props.documentUuid, props.versionUuid, entry.index])

  return inline ? `${base}?inline=1` : base
}

function icon(entry: Entry): string {
  const e = (entry.extension ?? '').toLowerCase()

  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(e)) return 'mdi-file-image-outline'
  if (e === 'pdf') return 'mdi-file-pdf-box'
  if (['doc', 'docx', 'odt'].includes(e)) return 'mdi-file-word-outline'
  if (['xls', 'xlsx', 'ods', 'csv'].includes(e)) return 'mdi-file-excel-outline'
  if (['ppt', 'pptx', 'odp'].includes(e)) return 'mdi-file-powerpoint-outline'
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(e)) return 'mdi-folder-zip-outline'

  return 'mdi-file-outline'
}
</script>

<template>
  <VCard>
    <VCardItem>
      <template #prepend>
        <VIcon icon="mdi-folder-zip-outline" />
      </template>
      <VCardTitle class="text-subtitle-1">{{ d('archive_contents') }}</VCardTitle>
      <VCardSubtitle>{{ d('archive_contents_help') }}</VCardSubtitle>
      <template #append>
        <VBtn variant="tonal" size="small" :loading="loading" @click="toggle">
          {{ open ? d('hide') : d('show_contents') }}
        </VBtn>
      </template>
    </VCardItem>

    <VCardText v-if="open">
      <VAlert v-if="reason" type="warning" variant="tonal" density="compact">
        {{ reason }}
      </VAlert>

      <template v-else-if="loaded">
        <VTextField
          v-if="entries.length > 12"
          v-model="filter" :placeholder="d('find_in_archive')"
          prepend-inner-icon="mdi-magnify" density="compact" variant="outlined"
          hide-details clearable class="mb-3"
        />

        <VAlert v-if="truncated" type="info" variant="tonal" density="compact" class="mb-3">
          {{ d('archive_truncated', { shown: entries.length, total }) }}
        </VAlert>

        <div style="max-height:420px; overflow:auto">
          <VTable density="compact">
            <tbody>
              <tr v-for="e in shown" :key="e.index">
                <td style="width:36px"><VIcon :icon="icon(e)" size="20" /></td>
                <td>
                  {{ e.basename }}
                  <div v-if="e.folder" class="text-caption text-medium-emphasis">{{ e.folder }}</div>
                </td>
                <td class="text-end text-no-wrap text-medium-emphasis">{{ e.size_label }}</td>
                <td class="text-end text-no-wrap">
                  <!-- Too large to pull out of the archive on the fly; the whole file is the way
                       to get it. -->
                  <VChip v-if="e.too_large" size="x-small" variant="tonal" color="warning">
                    {{ d('too_large') }}
                  </VChip>
                  <template v-else>
                    <VBtn
                      v-if="e.previewable" icon="mdi-eye-outline" size="small" variant="text"
                      :aria-label="d('preview_entry', { name: e.basename })"
                      :href="entryUrl(e, true)" target="_blank" rel="noopener"
                    />
                    <VBtn
                      v-if="canDownload" icon="mdi-download-outline" size="small" variant="text"
                      :aria-label="d('download_entry', { name: e.basename })"
                      :href="entryUrl(e, false)"
                    />
                  </template>
                </td>
              </tr>
              <tr v-if="!shown.length">
                <td colspan="4" class="text-center text-medium-emphasis py-6">
                  {{ filter ? d('nothing_matches') : d('archive_empty') }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </template>
    </VCardText>
  </VCard>
</template>
