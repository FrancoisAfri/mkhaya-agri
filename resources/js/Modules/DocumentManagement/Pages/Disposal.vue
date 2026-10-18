<script setup lang="ts">
/**
 * The disposal queue: documents whose retention period has run out.
 *
 * Nothing on this page destroys anything. Each row offers two answers — keep it, or agree it may
 * go — and the second only marks the document as approved. Carrying the destruction out is a
 * different permission on a different screen, which is the whole reason the queue exists: an
 * organisation that cannot name who authorised a destruction has not followed a retention policy,
 * it has lost files on a timer.
 *
 * The three tabs are the three answers, so "what did we decide about this?" is a place to look
 * rather than a search.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Row {
  uuid: string
  title: string
  category: string | null
  folder: string | null
  due_at: string | null
  reviewed_at: string | null
  note: string | null
  held: boolean
}

const props = defineProps<{
  documents: { data: Row[]; links: unknown[]; total: number }
  state: string
  summary: { due: number; approved: number; held: number; scheduled: number }
  can: { purge: boolean }
}>()

const tabs = computed(() => [
  { value: 'due', label: d('disposal_due'), count: props.summary.due },
  { value: 'approved', label: d('disposal_approved'), count: props.summary.approved },
  { value: 'retained', label: d('disposal_retained'), count: null },
])

function show(state: string) {
  router.get(route('document-management.disposal'), { state }, { preserveState: true, preserveScroll: true })
}

// ── Deciding ───────────────────────────────────────────────────────────────
const deciding = ref<Row | null>(null)
const decision = ref<'retain' | 'approve'>('retain')

const form = useForm({ note: '' })

function ask(row: Row, choice: 'retain' | 'approve') {
  deciding.value = row
  decision.value = choice
  form.reset()
}

function submit() {
  if (!deciding.value) return

  const name = decision.value === 'retain'
    ? 'document-management.disposal.retain'
    : 'document-management.disposal.approve'

  form.post(route(name, deciding.value.uuid), {
    preserveScroll: true,
    onSuccess: () => { deciding.value = null; form.reset() },
  })
}
</script>

<template>
  <div class="pa-4" style="max-width:1200px;margin:auto">
    <h1 class="text-h5 font-weight-bold">{{ d('disposal_title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ d('disposal_subtitle') }}</p>

    <!-- The promise this page makes, stated where it is read rather than in a tooltip. -->
    <VAlert type="info" variant="tonal" density="compact" class="mb-4">
      {{ d('disposal_nothing_automatic') }}
    </VAlert>

    <VCard elevation="0" border class="mb-4">
      <VTabs :model-value="state" density="comfortable" @update:model-value="show(String($event))">
        <VTab v-for="tab in tabs" :key="tab.value" :value="tab.value">
          {{ tab.label }}
          <VChip v-if="tab.count" size="x-small" class="ms-2" variant="tonal">{{ tab.count }}</VChip>
        </VTab>
      </VTabs>
    </VCard>

    <VCard elevation="0" border>
      <div v-if="documents.data.length" style="overflow-x:auto">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ d('document') }}</th>
              <th>{{ d('category') }}</th>
              <th>{{ d('disposal_due_at') }}</th>
              <th v-if="state !== 'due'">{{ d('disposal_reviewed') }}</th>
              <th class="text-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in documents.data" :key="row.uuid">
              <td>
                <a :href="route('document-management.documents.show', row.uuid)" class="text-body-2">{{ row.title }}</a>
                <div v-if="row.folder" class="text-caption text-medium-emphasis">{{ row.folder }}</div>
              </td>
              <td class="text-caption">{{ row.category || '—' }}</td>
              <td class="text-caption">
                {{ row.due_at || '—' }}
                <!-- A held document should never reach this queue; if one does, say so rather than
                     offering a destroy button the service would refuse anyway. -->
                <VChip v-if="row.held" size="x-small" color="deep-purple" variant="tonal" class="ms-1">
                  {{ d('disposal_held') }}
                </VChip>
              </td>
              <td v-if="state !== 'due'" class="text-caption">
                {{ row.reviewed_at || '—' }}
                <div v-if="row.note" class="text-medium-emphasis">{{ row.note }}</div>
              </td>
              <td class="text-end">
                <template v-if="state === 'due'">
                  <VBtn size="small" variant="text" @click="ask(row, 'retain')">{{ d('disposal_keep') }}</VBtn>
                  <VBtn
                    size="small" variant="text" color="warning" :disabled="row.held"
                    @click="ask(row, 'approve')"
                  >{{ d('disposal_approve') }}</VBtn>
                </template>
                <VBtn
                  v-else-if="state === 'approved' && can.purge" size="small" variant="text" color="error"
                  :href="route('document-management.documents.show', row.uuid)"
                >{{ d('disposal_go_destroy') }}</VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <VCardText v-else class="text-body-2 text-medium-emphasis">
        {{ state === 'due' ? d('disposal_queue_empty') : d('disposal_none_in_state') }}
      </VCardText>
    </VCard>

    <!-- ── The decision ─────────────────────────────────────────────────── -->
    <VDialog :model-value="deciding !== null" max-width="520" @update:model-value="deciding = null">
      <VCard v-if="deciding">
        <VCardTitle class="text-body-1 font-weight-medium">
          {{ decision === 'retain' ? d('disposal_keep') : d('disposal_approve') }}
        </VCardTitle>
        <VCardText>
          <div class="text-body-2 mb-3">{{ deciding.title }}</div>

          <VAlert
            :type="decision === 'retain' ? 'info' : 'warning'"
            variant="tonal" density="compact" class="mb-3"
          >
            {{ decision === 'retain' ? d('disposal_keep_explained') : d('disposal_approve_explained') }}
          </VAlert>

          <VTextarea
            v-model="form.note" :label="d('disposal_note')" rows="3" density="comfortable"
            :hint="d('disposal_note_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deciding = null">{{ d('cancel') }}</VBtn>
          <VBtn
            :color="decision === 'retain' ? 'primary' : 'warning'"
            :loading="form.processing" @click="submit"
          >
            {{ decision === 'retain' ? d('disposal_keep') : d('disposal_approve') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
