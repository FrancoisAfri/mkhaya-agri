<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import StatusChip from '../Partials/StatusChip.vue'
import { count, date, dateTime, humanise } from '../Partials/format'

const { t } = useI18n()

const props = defineProps<{
  document: any
  versions: any[]
  links: any[]
  access: any[]
  acknowledgements: any[]
  context: any
}>()

const uploading = ref(false)
const versionForm = useForm({ file: null as File | null, change_note: '' })
const ackForm = useForm({ statement: '' })

const base = computed(() => `/procurement-governance/documents/${props.document.uuid}`)

const expired = computed(() =>
  !!props.document.expires_at && new Date(props.document.expires_at) < new Date())

function addVersion() {
  versionForm.post(`${base.value}/versions`, {
    preserveScroll: true, forceFormData: true,
    onSuccess: () => { uploading.value = false; versionForm.reset() },
  })
}

function acknowledge() {
  ackForm.post(`${base.value}/acknowledge`, { preserveScroll: true })
}

function bytes(size: number | null): string {
  if (!size) return '—'
  const units = ['B', 'KB', 'MB', 'GB']
  let value = size
  let unit = 0

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }

  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-start justify-space-between ga-3 mb-4">
      <div class="min-width-0">
        <Link
          href="/procurement-governance/documents"
          class="text-caption text-primary text-decoration-none d-inline-flex align-center mb-1"
        >
          <VIcon icon="mdi-arrow-left" size="14" class="me-1" />{{ t('procurement_governance.documents.title') }}
        </Link>
        <div class="d-flex align-center ga-2 mb-1 flex-wrap">
          <StatusChip :status="document.confidentiality" />
          <StatusChip :status="document.approval_status" />
          <VChip v-if="expired" size="small" color="error" variant="tonal" label>
            <VIcon icon="mdi-calendar-remove" start size="14" />{{ t('procurement_governance.documents.expired') }}
          </VChip>
        </div>
        <h1 class="text-h5 font-weight-bold">{{ document.title }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ document.reference ?? '—' }}
          · {{ t('procurement_governance.common.version_n', { n: document.version_count }) }}
          <template v-if="document.expires_at">
            · {{ t('procurement_governance.common.expires') }} {{ date(document.expires_at) }}
          </template>
        </p>
      </div>

      <div class="d-flex ga-2">
        <VBtn
          :href="`${base}/download`" color="primary" variant="tonal"
          prepend-icon="mdi-download-outline"
        >
          {{ t('procurement_governance.documents.download') }}
        </VBtn>
        <VBtn variant="text" prepend-icon="mdi-upload-outline" @click="uploading = true">
          {{ t('procurement_governance.documents.new_version') }}
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="document.requires_acknowledgement && !acknowledgements.length"
      type="info" variant="tonal" class="mb-4" icon="mdi-file-sign"
    >
      <div class="d-flex flex-wrap align-center justify-space-between ga-3">
        <div class="text-body-2">{{ t('procurement_governance.documents.acknowledgement_required') }}</div>
        <VBtn size="small" color="primary" :loading="ackForm.processing" @click="acknowledge">
          {{ t('procurement_governance.documents.acknowledge') }}
        </VBtn>
      </div>
    </VAlert>

    <VRow>
      <VCol cols="12" lg="7">
        <!-- §8: versions are rows, not overwrites — an evaluation that scored version 2 must still
             be readable as having scored version 2. -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.documents.versions') }}
          </VCardTitle>
          <div class="procgov-scroll">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th scope="col" style="width: 70px" class="text-end">{{ t('procurement_governance.documents.version') }}</th>
                  <th scope="col">{{ t('procurement_governance.documents.filename') }}</th>
                  <th scope="col" class="text-end d-none d-md-table-cell">{{ t('procurement_governance.documents.size') }}</th>
                  <th scope="col" class="d-none d-md-table-cell">{{ t('procurement_governance.documents.uploaded') }}</th>
                  <th scope="col">{{ t('procurement_governance.documents.current') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="version in versions" :key="version.uuid">
                  <td class="text-end font-weight-medium">{{ version.version_no }}</td>
                  <td>
                    <div class="text-body-2">{{ version.original_filename }}</div>
                    <div v-if="version.change_note" class="text-caption text-medium-emphasis">
                      {{ version.change_note }}
                    </div>
                    <div v-if="version.checksum" class="text-caption text-medium-emphasis procgov-hash">
                      {{ t('procurement_governance.documents.checksum') }}: {{ version.checksum.slice(0, 16) }}…
                    </div>
                  </td>
                  <td class="text-end text-body-2 d-none d-md-table-cell">{{ bytes(version.size_bytes) }}</td>
                  <td class="text-body-2 d-none d-md-table-cell">{{ dateTime(version.created_at) }}</td>
                  <td>
                    <VIcon
                      :icon="version.is_current ? 'mdi-check-circle' : 'mdi-circle-outline'"
                      :color="version.is_current ? 'success' : 'grey'" size="18"
                      :aria-label="version.is_current
                        ? t('procurement_governance.documents.current')
                        : t('procurement_governance.documents.superseded')"
                    />
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>

        <!-- §8: one upload, many contexts. -->
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.documents.linked_to') }}
          </VCardTitle>
          <div v-if="!links.length" class="text-center py-8 px-4 text-medium-emphasis">
            <div class="text-body-2">{{ t('procurement_governance.documents.not_linked') }}</div>
          </div>
          <VList v-else density="compact">
            <VListItem v-for="(link, i) in links" :key="i">
              <VListItemTitle class="text-body-2">
                {{ link.entity_reference ?? humanise(link.entity_type) }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ humanise(link.link_role) }} · {{ link.source_module }}
                <template v-if="link.is_required"> · {{ t('procurement_governance.documents.required_here') }}</template>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="5">
        <!-- §8 asks for upload/download history; denials are logged too, and shown. -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.documents.access_log') }}
          </VCardTitle>
          <div v-if="!access.length" class="text-center py-8 px-4 text-medium-emphasis">
            <div class="text-body-2">{{ t('procurement_governance.documents.no_access') }}</div>
          </div>
          <VList v-else density="compact" max-height="420" class="overflow-y-auto">
            <VListItem v-for="(entry, i) in access" :key="i">
              <template #prepend>
                <VIcon
                  :icon="{
                    uploaded: 'mdi-upload', downloaded: 'mdi-download',
                    viewed: 'mdi-eye-outline', denied: 'mdi-cancel', superseded: 'mdi-file-replace-outline',
                  }[entry.action as string] ?? 'mdi-circle-small'"
                  :color="entry.action === 'denied' ? 'error' : 'grey'"
                  size="18"
                />
              </template>
              <VListItemTitle class="text-body-2">
                {{ t(`procurement_governance.documents.actions.${entry.action}`) }}
                — {{ entry.user_name?.trim() || entry.user_email || t('procurement_governance.common.system') }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ dateTime(entry.created_at) }}
                <template v-if="entry.ip"> · {{ entry.ip }}</template>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>

        <VCard v-if="acknowledgements.length" elevation="0" border rounded="lg">
          <VCardTitle class="text-body-1 font-weight-bold py-3">
            {{ t('procurement_governance.documents.acknowledgements') }}
            <VChip size="x-small" class="ms-2" variant="tonal" label>{{ count(acknowledgements.length) }}</VChip>
          </VCardTitle>
          <VList density="compact">
            <VListItem v-for="(ack, i) in acknowledgements" :key="i">
              <VListItemTitle class="text-body-2">
                {{ ack.user_name?.trim() || ack.user_email }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ dateTime(ack.acknowledged_at) }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="uploading" max-width="520">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold">{{ t('procurement_governance.documents.new_version') }}</VCardTitle>
        <VCardText>
          <VFileInput
            :label="t('procurement_governance.common.file')"
            variant="outlined" density="comfortable" class="mb-3"
            :error-messages="versionForm.errors.file"
            @update:model-value="files => versionForm.file = Array.isArray(files) ? files[0] : files"
          />
          <VTextarea
            v-model="versionForm.change_note"
            :label="t('procurement_governance.documents.change_note')"
            :hint="t('procurement_governance.documents.change_note_hint')" persistent-hint
            variant="outlined" rows="2" auto-grow
          />
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="uploading = false">{{ t('procurement_governance.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="versionForm.processing" :disabled="!versionForm.file" @click="addVersion">
            {{ t('procurement_governance.common.upload') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.procgov-scroll { overflow-x: auto; }
.min-width-0 { min-inline-size: 0; }
.procgov-hash { word-break: break-all; }
</style>
