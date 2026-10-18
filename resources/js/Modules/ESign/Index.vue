<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { ref } from 'vue'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const route = ziggyRoute
const { t } = useI18n()

type Signer = {
  id: number
  name: string
  email: string
  status: string
  status_label: string
  status_color: string
  signed_at: string | null
}

type Envelope = {
  id: number
  uuid: string
  title: string
  document_type: string
  type_label: string
  status: string
  status_label: string
  status_color: string
  expires_at: string | null
  sent_at: string | null
  completed_at: string | null
  signers: Signer[]
  created_at: string
}

const props = defineProps<{
  envelopes: { data: Envelope[]; meta: { current_page: number; last_page: number } }
}>()

function formatDate(val: string | null): string {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

/**
 * Removing an envelope that never went anywhere.
 *
 * Drafts and voided envelopes only. A completed one is signed evidence — the signatures, the audit
 * trail and the certificate are the whole point of the module — and one that is out with signers
 * has live links in people's inboxes that deleting would turn into dead ends with no explanation.
 * Void it first: that tells the signers, and records why.
 */
const confirmer = ref()

const deletable = (status: string) => status === 'draft' || status === 'voided'

function remove(envelope: { uuid: string; title: string }) {
  confirmer.value.open({
    title: t('e_sign.index.confirm_delete_title'),
    message: t('e_sign.index.confirm_delete', { title: envelope.title }),
    confirmText: t('e_sign.index.delete'),
    confirmColor: 'error',
    icon: 'tabler-trash',
  }, () => {
    router.delete(route('esign.envelopes.destroy', envelope.uuid), { preserveScroll: true })
  })
}

const statusColorMap: Record<string, string> = {
  draft:       'default',
  sent:        'info',
  in_progress: 'warning',
  completed:   'success',
  expired:     'error',
  voided:      'error',
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('e_sign.index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('e_sign.index.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('esign.envelopes.create'))">
        {{ t('e_sign.index.new_envelope') }}
      </VBtn>
    </div>

    <VRow>
      <VCol v-for="envelope in envelopes.data" :key="envelope.uuid" cols="12" md="6" lg="4">
        <VCard elevation="0" border class="cursor-pointer" @click="router.visit(route('esign.envelopes.show', envelope.uuid))">
          <VCardText class="pa-5">
            <div class="d-flex align-start justify-space-between mb-3">
              <div class="flex-grow-1 mr-3">
                <p class="text-body-1 font-weight-semibold mb-1 text-wrap">{{ envelope.title }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ envelope.type_label }}</p>
              </div>
              <div class="d-flex align-center ga-1 flex-shrink-0">
                <VChip :color="statusColorMap[envelope.status] ?? 'default'" size="small" variant="tonal">
                  {{ envelope.status_label }}
                </VChip>

                <!-- `.stop`, or deleting would also open the envelope behind the dialog. -->
                <VBtn
                  v-if="deletable(envelope.status)"
                  icon="tabler-trash" size="x-small" variant="text" color="error"
                  :aria-label="t('e_sign.index.delete_envelope', { title: envelope.title })"
                  :title="t('e_sign.index.delete_envelope', { title: envelope.title })"
                  @click.stop="remove(envelope)"
                />
              </div>
            </div>

            <div class="d-flex gap-1 flex-wrap mb-3">
              <VChip v-for="signer in envelope.signers" :key="signer.id" :color="signer.status_color" size="x-small" variant="tonal">
                {{ signer.name.split(' ')[0] }} · {{ signer.status_label }}
              </VChip>
            </div>

            <div class="d-flex gap-4 text-caption text-medium-emphasis">
              <span>{{ t('e_sign.index.sent', { date: formatDate(envelope.sent_at) }) }}</span>
              <span v-if="envelope.completed_at">{{ t('e_sign.index.done', { date: formatDate(envelope.completed_at) }) }}</span>
              <span v-else-if="envelope.expires_at">{{ t('e_sign.index.expires', { date: formatDate(envelope.expires_at) }) }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="!envelopes.data.length" cols="12">
        <VCard elevation="0" border>
          <VCardText class="pa-10 text-center text-medium-emphasis">
            <VIcon icon="tabler-file-signature" size="48" class="mb-3" />
            <p class="text-body-1">{{ t('e_sign.index.empty_title') }}</p>
            <VBtn color="primary" variant="tonal" class="mt-3" @click="router.visit(route('esign.envelopes.create'))">
              {{ t('e_sign.index.empty_button') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <div v-if="envelopes.meta.last_page > 1" class="d-flex justify-center mt-6">
      <VPagination
        :length="envelopes.meta.last_page"
        :model-value="envelopes.meta.current_page"
        @update:model-value="(p) => router.visit(route('esign.envelopes.index', { page: p }))"
      />
    </div>
  </div>

    <ConfirmDialog ref="confirmer" />
</template>
