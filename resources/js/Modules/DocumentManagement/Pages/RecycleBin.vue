<script setup lang="ts">
/**
 * Deleted documents, and putting them back.
 *
 * Two things are shown that a bin usually hides: who deleted each document and when it stops being
 * recoverable. Both are the questions somebody arrives with — they are here because a document is
 * missing, and they need to know whether they still have time.
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Deleted {
  uuid: string
  title: string
  category: string | null
  folder: string | null
  deleted_at: string | null
  deleted_ago: string | null
  deleted_by: string | null
  reason: string | null
  purgeable_from: string | null
  held: boolean
}

defineProps<{
  documents: { data: Deleted[]; links: { url: string | null; label: string; active: boolean }[] }
  summary: { in_bin: number; purgeable: number; held: number; days: number }
  can: { restore: boolean; purge: boolean }
}>()

const emptying = ref(false)
const everything = ref(false)
const purging = ref<Deleted | null>(null)

function restore(item: Deleted) {
  router.post(route('document-management.recycle-bin.restore', item.uuid), {}, { preserveScroll: true })
}

function purge() {
  if (!purging.value) return

  router.delete(route('document-management.recycle-bin.purge', purging.value.uuid), {
    preserveScroll: true,
    onSuccess: () => { purging.value = null },
  })
}

function emptyBin() {
  router.post(route('document-management.recycle-bin.empty'), { everything: everything.value }, {
    preserveScroll: true,
    onSuccess: () => { emptying.value = false; everything.value = false },
  })
}
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center flex-wrap ga-2 mb-1">
      <h1 class="text-h5 font-weight-bold">{{ d('recycle_title') }}</h1>
      <VSpacer />
      <VBtn
        v-if="can.purge && summary.in_bin" size="small" variant="tonal" color="error"
        prepend-icon="mdi-delete-forever-outline" @click="emptying = true"
      >{{ d('recycle_empty') }}</VBtn>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ d('recycle_subtitle', { days: summary.days }) }}
    </p>

    <VCard elevation="0" border>
      <div v-if="documents.data.length" style="overflow-x:auto">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ d('document') }}</th>
              <th>{{ d('recycle_deleted_by') }}</th>
              <th>{{ d('recycle_deleted_when') }}</th>
              <th>{{ d('recycle_until') }}</th>
              <th class="text-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in documents.data" :key="item.uuid">
              <td>
                <div class="text-body-2">
                  {{ item.title }}
                  <!-- A held document is never purged, whatever its age. Said here because the bin
                       is exactly where somebody would look to get around a hold. -->
                  <VChip v-if="item.held" size="x-small" color="deep-purple" variant="tonal" class="ms-1">
                    {{ d('recycle_held') }}
                  </VChip>
                </div>
                <div v-if="item.folder" class="text-caption text-medium-emphasis">{{ item.folder }}</div>
              </td>
              <td class="text-caption">{{ item.deleted_by ?? '—' }}</td>
              <td class="text-caption">
                {{ item.deleted_ago }}
                <div v-if="item.reason" class="text-medium-emphasis">{{ item.reason }}</div>
              </td>
              <td class="text-caption">{{ item.held ? d('recycle_kept') : item.purgeable_from }}</td>
              <td class="text-end">
                <VBtn v-if="can.restore" size="small" variant="text" @click="restore(item)">
                  {{ d('recycle_restore') }}
                </VBtn>
                <VBtn
                  v-if="can.purge && !item.held" size="small" variant="text" color="error"
                  @click="purging = item"
                >{{ d('recycle_purge') }}</VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('recycle_none') }}</VCardText>
    </VCard>

    <!-- ── Permanent removal of one ─────────────────────────────────────── -->
    <VDialog :model-value="purging !== null" max-width="480" @update:model-value="purging = null">
      <VCard v-if="purging">
        <VCardTitle class="text-body-1 font-weight-medium">{{ d('recycle_purge') }}</VCardTitle>
        <VCardText>
          <VAlert type="error" variant="tonal" density="compact" class="mb-3">
            {{ d('recycle_purge_warning') }}
          </VAlert>
          <div class="text-body-2">{{ purging.title }}</div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="purging = null">{{ d('cancel') }}</VBtn>
          <VBtn color="error" @click="purge">{{ d('recycle_purge') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Emptying ─────────────────────────────────────────────────────── -->
    <VDialog v-model="emptying" max-width="520">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-medium">{{ d('recycle_empty') }}</VCardTitle>
        <VCardText>
          <VAlert type="error" variant="tonal" density="compact" class="mb-3">
            {{ d('recycle_empty_warning') }}
          </VAlert>

          <div class="text-body-2 mb-2">
            {{ d('recycle_empty_counts', { purgeable: summary.purgeable, held: summary.held }) }}
          </div>

          <!-- Off by default: the ordinary meaning of "empty the bin" is everything past its
               window, not everything somebody deleted this morning. -->
          <VSwitch
            v-model="everything" density="compact" hide-details color="error"
            :label="d('recycle_empty_everything')"
          />
          <div class="text-caption text-medium-emphasis">{{ d('recycle_empty_everything_hint') }}</div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="emptying = false">{{ d('cancel') }}</VBtn>
          <VBtn color="error" @click="emptyBin">{{ d('recycle_empty') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
