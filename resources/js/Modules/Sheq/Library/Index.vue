<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '../Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Document = {
  uuid: string; doc_number: string; title: string; doc_type: string | null
  version: number; published_at: string | null; site_name: string | null
  requires_acknowledgement: boolean; acknowledged: boolean; file_uuid: string | null
}

const props = defineProps<{ documents: Document[]; noCompany?: boolean }>()

const search = ref('')

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.documents
  return props.documents.filter(d =>
    [d.title, d.doc_number, d.doc_type, d.site_name]
      .some(v => String(v ?? '').toLowerCase().includes(q)))
})

/** Unread first, because the ones asking something of you are the point of the page. */
const ordered = computed(() => [...filtered.value].sort((a, b) => {
  const owing = (d: Document) => (d.requires_acknowledgement && !d.acknowledged ? 0 : 1)
  return owing(a) - owing(b) || a.title.localeCompare(b.title)
}))

const owing = computed(() =>
  props.documents.filter(d => d.requires_acknowledgement && !d.acknowledged).length)

function acknowledge(document: Document) {
  router.post(`/sheq/library/${document.uuid}/acknowledge`, {}, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.library.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.library.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!-- What this page is asking of the person, before the list of what it holds. -->
      <VAlert v-if="owing" type="info" variant="tonal" class="mb-4">
        {{ t('sheq.library.owing', { count: owing }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg">
        <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-2">
          <span class="text-body-1 font-weight-semibold">{{ t('sheq.library.documents') }}</span>
          <VTextField
            v-model="search"
            :placeholder="t('sheq.library.search')"
            prepend-inner-icon="mdi-magnify"
            variant="outlined" density="compact" hide-details clearable
            style="max-width: 320px"
          />
        </VCardTitle>
        <VDivider />

        <VCardText v-if="!documents.length" class="text-center py-10 text-medium-emphasis">
          {{ t('sheq.library.empty') }}
        </VCardText>
        <VCardText v-else-if="!ordered.length" class="text-center py-8 text-medium-emphasis">
          {{ t('sheq.library.no_match') }}
        </VCardText>

        <VTable v-else density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.library.document') }}</th>
              <th scope="col">{{ t('sheq.library.type') }}</th>
              <th scope="col">{{ t('sheq.library.version') }}</th>
              <th scope="col" class="text-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in ordered" :key="d.uuid">
              <td>
                <div class="font-weight-medium">{{ d.title }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ d.doc_number }}
                  <span v-if="d.site_name"> · {{ d.site_name }}</span>
                </div>
              </td>
              <td class="text-medium-emphasis">{{ d.doc_type ?? '—' }}</td>
              <td>
                <!-- The version in force, which is the only one a reader is shown. -->
                <VChip size="x-small" variant="tonal">v{{ d.version }}</VChip>
                <!--
                  The marking itself, not a promise of one. It carries the version, the publish
                  date and the uncontrolled-copy wording, and it is what ends up on a printout.
                -->
                <div class="text-caption text-medium-emphasis">{{ d.watermark }}</div>
              </td>
              <td class="text-end">
                <VBtn
                  v-if="d.file_uuid"
                  :href="`/sheq/files/${d.file_uuid}/download`"
                  size="small" variant="tonal" prepend-icon="mdi-download" class="me-2"
                >
                  {{ t('sheq.library.read') }}
                </VBtn>
                <span v-else class="text-caption text-medium-emphasis me-2">
                  {{ t('sheq.library.no_file') }}
                </span>

                <VChip v-if="d.requires_acknowledgement && d.acknowledged"
                       size="small" variant="tonal" color="success" prepend-icon="mdi-check">
                  {{ t('sheq.library.acknowledged') }}
                </VChip>
                <VBtn v-else-if="d.requires_acknowledgement"
                      size="small" color="primary" variant="flat" @click="acknowledge(d)">
                  {{ t('sheq.library.acknowledge') }}
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <p class="text-caption text-medium-emphasis mt-3">
        {{ t('sheq.library.watermark_note') }}
      </p>
    </template>
  </div>
</template>
