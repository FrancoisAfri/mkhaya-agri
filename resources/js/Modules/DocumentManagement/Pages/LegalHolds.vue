<script setup lang="ts">
/**
 * The legal hold register.
 *
 * Organised by matter rather than by document, because that is the shape of the question people
 * bring to it: "what are we holding for case 2026-014, and when can we let it go?" A flat list of
 * held documents makes answering that a counting exercise, and counting is where a document gets
 * missed when the matter closes.
 *
 * Lifting a whole matter is a first-class action for the same reason. A matter ends all at once,
 * and releasing its documents one at a time is how three of them stay held for another two years
 * because somebody's list was out of date.
 */
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Matter { reference: string; documents: number; oldest: string | null }
interface HeldDocument {
  uuid: string
  placed_at: string | null
  reason: string | null
  placed_by: string | null
  document: { uuid: string; title: string; category: string | null } | null
}

const props = defineProps<{
  register: Matter[]
  selected: string | null
  documents: HeldDocument[]
  can: { manage: boolean }
}>()

function open(reference: string) {
  router.get(route('document-management.holds.index'), { reference }, {
    preserveState: true, preserveScroll: true,
  })
}

// ── Lifting ────────────────────────────────────────────────────────────────
const releasing = ref<HeldDocument | null>(null)
const closingMatter = ref(false)

const releaseForm = useForm({ reason: '' })
const matterForm = useForm({ reference: '', reason: '' })

function release() {
  if (!releasing.value) return

  releaseForm.post(route('document-management.holds.release', releasing.value.uuid), {
    preserveScroll: true,
    onSuccess: () => { releasing.value = null; releaseForm.reset() },
  })
}

function closeMatter() {
  matterForm.reference = props.selected ?? ''
  matterForm.post(route('document-management.holds.release-matter'), {
    preserveScroll: true,
    onSuccess: () => { closingMatter.value = false; matterForm.reset() },
  })
}
</script>

<template>
  <div class="pa-4" style="max-width:1300px;margin:auto">
    <h1 class="text-h5 font-weight-bold">{{ d('holds_title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ d('holds_subtitle') }}</p>

    <div class="register-layout">
      <!-- ── Matters ───────────────────────────────────────────────────── -->
      <VCard elevation="0" border>
        <VCardText class="d-flex align-center ga-2 pb-2">
          <VIcon size="20" icon="mdi-gavel" />
          <span class="text-body-2 font-weight-medium">{{ d('holds_matters') }}</span>
        </VCardText>
        <VDivider />

        <VList v-if="register.length" density="compact" nav>
          <VListItem
            v-for="matter in register" :key="matter.reference"
            :active="matter.reference === selected"
            @click="open(matter.reference)"
          >
            <VListItemTitle class="text-body-2 font-weight-medium">{{ matter.reference }}</VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ d('holds_document_count', { n: matter.documents }) }}
              <template v-if="matter.oldest"> · {{ d('holds_since', { date: matter.oldest }) }}</template>
            </VListItemSubtitle>
          </VListItem>
        </VList>

        <VCardText v-else class="text-body-2 text-medium-emphasis">
          {{ d('holds_none') }}
        </VCardText>
      </VCard>

      <!-- ── Documents under the chosen matter ─────────────────────────── -->
      <VCard elevation="0" border>
        <template v-if="selected">
          <VCardText class="d-flex align-center flex-wrap ga-2 pb-2">
            <div>
              <div class="text-body-2 font-weight-medium">{{ selected }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ d('holds_document_count', { n: documents.length }) }}
              </div>
            </div>
            <VSpacer />
            <VBtn
              v-if="can.manage && documents.length" size="small" variant="tonal" color="warning"
              prepend-icon="mdi-lock-open-variant-outline" @click="closingMatter = true"
            >{{ d('holds_close_matter') }}</VBtn>
          </VCardText>
          <VDivider />

          <div style="overflow-x:auto">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th>{{ d('document') }}</th>
                  <th>{{ d('holds_placed') }}</th>
                  <th>{{ d('holds_reason') }}</th>
                  <th class="text-end"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="hold in documents" :key="hold.uuid">
                  <td>
                    <a
                      v-if="hold.document"
                      :href="route('document-management.documents.show', hold.document.uuid)"
                      class="text-body-2"
                    >{{ hold.document.title }}</a>
                    <span v-else class="text-body-2 text-medium-emphasis">{{ d('holds_document_gone') }}</span>
                    <div v-if="hold.document?.category" class="text-caption text-medium-emphasis">
                      {{ hold.document.category }}
                    </div>
                  </td>
                  <td class="text-caption">
                    {{ hold.placed_at }}
                    <div v-if="hold.placed_by" class="text-medium-emphasis">{{ hold.placed_by }}</div>
                  </td>
                  <td class="text-caption">{{ hold.reason || '—' }}</td>
                  <td class="text-end">
                    <VBtn
                      v-if="can.manage" size="small" variant="text"
                      @click="releasing = hold"
                    >{{ d('holds_release') }}</VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </template>

        <VCardText v-else class="text-body-2 text-medium-emphasis">
          {{ d('holds_pick_matter') }}
        </VCardText>
      </VCard>
    </div>

    <!-- ── Lift one hold ────────────────────────────────────────────────── -->
    <VDialog :model-value="releasing !== null" max-width="520" @update:model-value="releasing = null">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-medium">{{ d('holds_release') }}</VCardTitle>
        <VCardText>
          <!-- Said plainly, because the consequence is that a document can be destroyed again. -->
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">
            {{ d('holds_release_warning') }}
          </VAlert>
          <VTextarea v-model="releaseForm.reason" :label="d('holds_release_reason')" rows="3" density="comfortable" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="releasing = null">{{ d('cancel') }}</VBtn>
          <VBtn color="warning" :loading="releaseForm.processing" @click="release">{{ d('holds_release') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Lift the whole matter ────────────────────────────────────────── -->
    <VDialog v-model="closingMatter" max-width="520">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-medium">{{ d('holds_close_matter') }}</VCardTitle>
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-3">
            {{ d('holds_close_matter_warning', { n: documents.length, reference: selected }) }}
          </VAlert>
          <VTextarea v-model="matterForm.reason" :label="d('holds_release_reason')" rows="3" density="comfortable" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="closingMatter = false">{{ d('cancel') }}</VBtn>
          <VBtn color="warning" :loading="matterForm.processing" @click="closeMatter">
            {{ d('holds_close_matter') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.register-layout {
  display: grid;
  gap: 1rem;
  grid-template-columns: minmax(0, 1fr);
}

@media (min-width: 960px) {
  .register-layout { grid-template-columns: 20rem minmax(0, 1fr); align-items: start; }
}
</style>
