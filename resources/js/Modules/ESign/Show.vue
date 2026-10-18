<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Signer = {
  id: number
  uuid: string
  name: string
  email: string
  phone: string | null
  role: string
  role_label: string
  signing_order: number
  status: string
  status_label: string
  status_color: string
  consent_given: boolean
  signed_at: string | null
  declined_at: string | null
}

type AuditEntry = {
  event: string
  actor_name: string | null
  actor_email: string | null
  metadata: Record<string, any> | null
  created_at: string
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
  message: string | null
  expires_at: string | null
  sent_at: string | null
  completed_at: string | null
  voided_at: string | null
  voided_reason: string | null
  has_certificate: boolean
  has_document: boolean
  has_executed: boolean
  document_hash: string | null
  executed_hash: string | null
  created_at: string
  signers: Signer[]
  audit_entries: AuditEntry[]
}

const props = defineProps<{
  envelope: Envelope
  /** True when the person reading this page is themselves waiting to sign it. */
  awaitingMySignature?: boolean
}>()

const voidDialog = ref(false)
const voidReason = ref('')
const sendLoading = ref(false)
const voidLoading = ref(false)
const confirmDialog = ref()

function formatDate(val: string | null): string {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const eventIcons: Record<string, string> = {
  envelope_created: 'tabler-file-plus',
  sent:             'tabler-send',
  viewed:           'tabler-eye',
  consent_given:    'tabler-check',
  otp_sent:         'tabler-mail',
  otp_failed:       'tabler-alert-circle',
  signed:           'tabler-pen',
  completed:        'tabler-circle-check',
  voided:           'tabler-ban',
  declined:         'tabler-x',
}

function sendEnvelope() {
  confirmDialog.value.open(
    {
      title: t('e_sign.show.confirm_send.title'),
      message: t('e_sign.show.confirm_send.message'),
      confirmText: t('e_sign.show.confirm_send.confirm'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle'
    },
    () => {
      sendLoading.value = true
      router.post(route('esign.envelopes.send', props.envelope.uuid), {}, {
        onFinish: () => { sendLoading.value = false }
      })
    },
  )
}

function confirmVoid() {
  if (!voidReason.value.trim()) return
  voidLoading.value = true
  router.post(route('esign.envelopes.void', props.envelope.uuid), { reason: voidReason.value }, {
    onFinish: () => { voidLoading.value = false; voidDialog.value = false }
  })
}

const canSend          = props.envelope.status === 'draft'
const canVoid          = !['completed', 'voided'].includes(props.envelope.status)
const hasCertificate   = props.envelope.has_certificate
const hasDocument      = props.envelope.has_document

// Attaching the document after the fact. An envelope is often started before the PDF is signed
// off internally, so the draft exists first and the document arrives later.
const documentForm = useForm({ document: null as File | null })

function uploadDocument() {
  documentForm.post(route('esign.envelopes.document.upload', props.envelope.uuid), {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => documentForm.reset(),
  })
}
</script>

<template>
  <div class="pa-6">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn variant="text" icon size="small" @click="router.visit(route('esign.envelopes.index'))">
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <div class="flex-grow-1">
        <h4 class="text-h4 font-weight-bold mb-1">{{ envelope.title }}</h4>
        <p class="text-medium-emphasis mb-0">{{ envelope.type_label }}</p>
      </div>
      <VChip :color="envelope.status_color" variant="tonal">{{ envelope.status_label }}</VChip>
    </div>

    <VRow>
      <VCol cols="12" md="4">
        <VCard elevation="0" border class="mb-4">
          <VCardText class="pa-4">
            <h6 class="text-h6 font-weight-semibold mb-3">{{ t('e_sign.show.details_section') }}</h6>
            <VList density="compact" class="pa-0">
              <VListItem prepend-icon="tabler-calendar-event" :title="formatDate(envelope.created_at)" :subtitle="t('e_sign.show.created')" />
              <VListItem prepend-icon="tabler-send" :title="formatDate(envelope.sent_at)" :subtitle="t('e_sign.show.sent')" />
              <VListItem prepend-icon="tabler-clock" :title="formatDate(envelope.expires_at)" :subtitle="t('e_sign.show.expires')" />
              <VListItem v-if="envelope.completed_at" prepend-icon="tabler-circle-check" :title="formatDate(envelope.completed_at)" :subtitle="t('e_sign.show.completed')" />
              <VListItem v-if="envelope.voided_at" prepend-icon="tabler-ban" :title="formatDate(envelope.voided_at)" :subtitle="t('e_sign.show.voided')" />
            </VList>

            <div v-if="envelope.voided_reason" class="mt-3">
              <VAlert type="error" variant="tonal" density="compact">
                {{ t('e_sign.show.void_reason', { reason: envelope.voided_reason }) }}
              </VAlert>
            </div>

            <div v-if="envelope.message" class="mt-3">
              <p class="text-caption text-medium-emphasis mb-1">{{ t('e_sign.show.message_label') }}</p>
              <p class="text-body-2">{{ envelope.message }}</p>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border class="mb-4">
          <VCardText class="pa-4">
            <h6 class="text-h6 font-weight-semibold mb-3">{{ t('e_sign.show.actions_section') }}</h6>
            <div class="d-flex flex-column gap-2">
              <!--
                Sending is refused server-side without a document, so the reason is given here
                rather than letting somebody press a button that will only bounce them back.
              -->
              <VAlert v-if="canSend && !hasDocument" type="warning" variant="tonal" density="compact" class="mb-1">
                {{ t('e_sign.show.needs_document') }}
              </VAlert>

              <!--
                The way to actually sign it.
                
                This page is the sender's view, and on a small team the sender is usually one of the
                signers too — so somebody who has been asked to sign lands here, finds Send, View
                and Void, and reasonably concludes the system will not let them place a signature.
                It will; it is on another page, and nothing said so.
              -->
              <VBtn
                v-if="awaitingMySignature"
                color="success"
                prepend-icon="tabler-signature"
                :href="route('esign.envelopes.mine')"
                class="mb-1"
              >
                {{ t('e_sign.show.sign_this') }}
              </VBtn>

              <!--
                Drafts only. Once it has gone out people have been asked to sign a particular
                document, and swapping it underneath them is not an edit.
              -->
              <template v-if="canSend">
                <VFileInput
                  v-model="documentForm.document"
                  :label="hasDocument ? t('e_sign.show.replace_document') : t('e_sign.show.upload_document')"
                  accept="application/pdf"
                  prepend-icon=""
                  prepend-inner-icon="tabler-file-type-pdf"
                  variant="outlined"
                  density="compact"
                  hide-details
                  show-size
                  class="mb-2"
                />
                <VBtn
                  v-if="documentForm.document"
                  color="primary"
                  variant="tonal"
                  prepend-icon="tabler-upload"
                  :loading="documentForm.processing"
                  block
                  class="mb-2"
                  @click="uploadDocument"
                >
                  {{ t('e_sign.show.upload_button') }}
                </VBtn>
              </template>
              <VBtn v-if="canSend" color="primary" prepend-icon="tabler-send" :loading="sendLoading" :disabled="!hasDocument" block @click="sendEnvelope">
                {{ t('e_sign.show.send_button') }}
              </VBtn>
              <VBtn v-if="hasDocument" variant="tonal" prepend-icon="tabler-file-text"
                :href="route('esign.envelopes.document', envelope.uuid)" target="_blank" block>
                {{ envelope.has_executed ? t('e_sign.show.view_executed') : t('e_sign.show.view_document') }}
              </VBtn>
              <VBtn v-if="hasCertificate" color="success" variant="tonal" prepend-icon="tabler-file-download"
                :href="route('esign.envelopes.certificate.download', envelope.uuid)" target="_blank" block>
                {{ t('e_sign.show.certificate_button') }}
              </VBtn>
              <VAlert v-else-if="envelope.status === 'completed'" type="info" variant="tonal" density="compact" icon="tabler-loader">
                {{ t('e_sign.show.certificate_generating') }}
              </VAlert>
              <!--
                The hash of the executed copy, shown so somebody can check the file they downloaded
                is the file that was signed. A signature is only worth the proof of what it was on.
              -->
              <div v-if="envelope.executed_hash" class="text-caption text-medium-emphasis mt-2" style="word-break: break-all">
                {{ t('e_sign.show.executed_hash') }}: {{ envelope.executed_hash }}
              </div>
              <VBtn v-if="canVoid" color="error" variant="tonal" prepend-icon="tabler-ban" block @click="voidDialog = true">
                {{ t('e_sign.show.void_button') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard elevation="0" border class="mb-4">
          <VCardText class="pa-4">
            <h6 class="text-h6 font-weight-semibold mb-3">{{ t('e_sign.show.signers_section') }}</h6>
            <div class="d-flex flex-column gap-3">
              <div v-for="signer in envelope.signers" :key="signer.id"
                class="d-flex align-center justify-space-between pa-3 rounded" style="border: 1px solid rgba(0,0,0,0.08);">
                <div class="d-flex align-center gap-3">
                  <VAvatar size="36" color="primary" variant="tonal">
                    <span class="text-caption font-weight-bold">{{ signer.signing_order }}</span>
                  </VAvatar>
                  <div>
                    <p class="text-body-2 font-weight-semibold mb-0">{{ signer.name }}</p>
                    <p class="text-caption text-medium-emphasis mb-0">{{ signer.email }} · {{ signer.role_label }}</p>
                    <p v-if="signer.signed_at" class="text-caption text-success mb-0">
                      {{ t('e_sign.show.signed_at', { date: formatDate(signer.signed_at) }) }}
                    </p>
                  </div>
                </div>
                <VChip :color="signer.status_color" size="small" variant="tonal">{{ signer.status_label }}</VChip>
              </div>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <h6 class="text-h6 font-weight-semibold mb-3">{{ t('e_sign.show.audit_section') }}</h6>
            <div v-if="envelope.audit_entries.length" class="d-flex flex-column gap-2">
              <div v-for="(entry, idx) in envelope.audit_entries" :key="idx" class="d-flex gap-3">
                <VIcon :icon="eventIcons[entry.event] ?? 'tabler-point'" size="18" class="mt-1 flex-shrink-0" color="medium-emphasis" />
                <div>
                  <span class="text-body-2 font-weight-medium">{{ entry.event.replace(/_/g, ' ') }}</span>
                  <span v-if="entry.actor_name" class="text-body-2"> by {{ entry.actor_name }}</span>
                  <p class="text-caption text-medium-emphasis mb-0">{{ formatDate(entry.created_at) }}</p>
                </div>
              </div>
            </div>
            <p v-else class="text-body-2 text-medium-emphasis">{{ t('e_sign.show.audit_empty') }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="voidDialog" max-width="480">
      <VCard>
        <VCardText class="pa-5">
          <h6 class="text-h6 font-weight-semibold mb-4">{{ t('e_sign.show.void_dialog.title') }}</h6>
          <VTextarea v-model="voidReason" :label="t('e_sign.show.void_dialog.reason_label')" variant="outlined" density="comfortable" rows="3" autofocus />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="voidDialog = false">{{ t('e_sign.show.void_dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="voidLoading" :disabled="!voidReason.trim()" @click="confirmVoid">
            {{ t('e_sign.show.void_dialog.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
