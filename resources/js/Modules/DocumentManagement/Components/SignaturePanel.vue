<script setup lang="ts">
/**
 * Sending a document for signature, and watching it come back.
 *
 * The list of signatories doubles as the progress view once it is sent — the same names in the same
 * order, gaining a state each. Somebody chasing a signature wants to know who has not signed, and
 * that is the same question as who was asked.
 *
 * Signing order is the row order, so it can be rearranged by moving a row rather than by typing
 * numbers into a column and hoping they agree.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface SignatureProgress {
  status: string | null
  uuid: string
  sent_at: string | null
  expires_at: string | null
  signed: number
  total: number
  signers: {
    name: string; email: string; order: number
    status: string; signed_at: string | null; declined_reason: string | null
  }[]
}

const props = defineProps<{
  signature: SignatureProgress | null
  documentUuid: string
  isPdf: boolean
  canSend: boolean
}>()

const sending = ref(false)
const busy = ref(false)

const signatories = ref<{ name: string; email: string }[]>([{ name: '', email: '' }])
const message = ref('')
const expiresAt = ref('')

// Placed fields. Kept as a list rather than a drag-on-page canvas — see the note in the template.
const fields = ref<{ type: string; page: number; signer: number }[]>([])

const canSubmit = computed(() =>
  signatories.value.some(s => s.name.trim() && s.email.trim()),
)

function addSignatory() {
  signatories.value.push({ name: '', email: '' })
}

function removeSignatory(index: number) {
  signatories.value.splice(index, 1)
  // Fields pointing at a removed person would attach to whoever moved into that slot.
  fields.value = fields.value.filter(f => f.signer !== index).map(f => ({
    ...f, signer: f.signer > index ? f.signer - 1 : f.signer,
  }))
}

function addField(type: string) {
  fields.value.push({ type, page: 1, signer: 0 })
}

function send() {
  busy.value = true
  router.post(route('document-management.signing.send', props.documentUuid), {
    signatories: signatories.value
      .filter(s => s.name.trim() && s.email.trim())
      .map((s, i) => ({ ...s, order: i + 1 })),
    fields: fields.value,
    message: message.value,
    expires_at: expiresAt.value || null,
  }, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; sending.value = false },
  })
}

function cancel() {
  busy.value = true
  router.post(route('document-management.signing.cancel', props.documentUuid), {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

/** Icon, colour and word together — a signing status carried by colour alone excludes people. */
const overall = computed(() => ({
  awaiting_signature: { icon: 'mdi-clock-outline', colour: 'info' },
  partially_signed: { icon: 'mdi-progress-check', colour: 'warning' },
  fully_signed: { icon: 'mdi-check-decagram', colour: 'success' },
  declined: { icon: 'mdi-close-circle', colour: 'error' },
  expired: { icon: 'mdi-calendar-remove', colour: 'error' },
  cancelled: { icon: 'mdi-cancel', colour: undefined },
}[props.signature?.status ?? ''] ?? { icon: 'mdi-draw-pen', colour: undefined }))

const signerLook = (status: string) => ({
  signed: { icon: 'mdi-check-circle', colour: 'success', label: d('signer_signed') },
  declined: { icon: 'mdi-close-circle', colour: 'error', label: d('signer_declined') },
  viewed: { icon: 'mdi-eye-outline', colour: 'info', label: d('signer_viewed') },
}[status] ?? { icon: 'mdi-circle-outline', colour: undefined, label: d('signer_waiting') })

const isOpen = computed(() =>
  ['awaiting_signature', 'partially_signed'].includes(props.signature?.status ?? ''),
)
</script>

<template>
  <VCard elevation="0" border>
    <VCardText class="d-flex align-center ga-2 pb-2">
      <VIcon size="20" icon="mdi-draw-pen" />
      <span class="text-body-2 font-weight-medium">{{ d('signature') }}</span>
      <VSpacer />
      <VChip v-if="signature?.status" size="small" :color="overall.colour" variant="tonal" :prepend-icon="overall.icon">
        {{ d('signing.' + signature.status) }}
      </VChip>
    </VCardText>

    <VDivider />

    <!-- Not sent -->
    <VCardText v-if="!signature">
      <p class="text-body-2 text-medium-emphasis mb-3">{{ d('signature_none') }}</p>

      <!-- Said before the button rather than after pressing it. -->
      <VAlert v-if="!isPdf" type="info" variant="tonal" density="compact" class="mb-3">
        {{ d('signing.pdf_only') }}
      </VAlert>

      <VBtn
        v-if="canSend" color="primary" variant="tonal" size="small" :disabled="!isPdf"
        prepend-icon="mdi-send-outline" @click="sending = true"
      >{{ d('send_for_signature') }}</VBtn>
    </VCardText>

    <template v-else>
      <VCardText class="pb-0">
        <div class="d-flex align-center ga-2 mb-2">
          <VProgressLinear
            :model-value="signature.total ? (signature.signed / signature.total) * 100 : 0"
            height="6" rounded :color="overall.colour ?? 'primary'"
            :aria-label="d('signature_progress', { signed: signature.signed, total: signature.total })"
          />
          <span class="text-caption text-medium-emphasis flex-shrink-0">
            {{ signature.signed }}/{{ signature.total }}
          </span>
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ d('signature_sent', { at: signature.sent_at }) }}
          <template v-if="signature.expires_at"> · {{ d('signature_expires', { date: signature.expires_at }) }}</template>
        </div>
      </VCardText>

      <VList density="compact">
        <VListItem v-for="signer in signature.signers" :key="signer.email">
          <template #prepend>
            <VIcon :icon="signerLook(signer.status).icon" :color="signerLook(signer.status).colour" size="20" />
          </template>
          <VListItemTitle class="text-body-2">
            {{ signer.order }}. {{ signer.name }}
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ signerLook(signer.status).label }}
            <template v-if="signer.signed_at"> · {{ signer.signed_at }}</template>
            <template v-if="signer.declined_reason"> · {{ signer.declined_reason }}</template>
          </VListItemSubtitle>
        </VListItem>
      </VList>

      <template v-if="isOpen && canSend">
        <VDivider />
        <VCardText class="py-2">
          <VBtn size="small" variant="text" :loading="busy" @click="cancel">{{ d('signature_cancel') }}</VBtn>
        </VCardText>
      </template>
    </template>
  </VCard>

  <!-- ── Send ─────────────────────────────────────────────────────────────── -->
  <VDialog v-model="sending" max-width="620">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-medium">{{ d('send_for_signature') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <div>
          <div class="text-body-2 font-weight-medium mb-1">{{ d('signatories') }}</div>
          <!-- Row order is signing order, so it is changed by moving a row rather than by typing
               numbers into a column and hoping they agree with each other. -->
          <div class="text-caption text-medium-emphasis mb-2">{{ d('signatories_order_hint') }}</div>

          <div v-for="(person, index) in signatories" :key="index" class="signatory-row">
            <span class="text-caption text-medium-emphasis order-number">{{ index + 1 }}</span>
            <VTextField
              v-model="person.name" :label="d('signatory_name')" density="compact" hide-details
            />
            <VTextField
              v-model="person.email" type="email" :label="d('signatory_email')" density="compact" hide-details
            />
            <VBtn
              size="small" variant="text" icon="mdi-close" :disabled="signatories.length === 1"
              :aria-label="d('signatory_remove', { n: index + 1 })" @click="removeSignatory(index)"
            />
          </div>

          <VBtn size="small" variant="text" prepend-icon="mdi-plus" class="mt-1" @click="addSignatory">
            {{ d('signatory_add') }}
          </VBtn>
        </div>

        <VDivider />

        <div>
          <div class="text-body-2 font-weight-medium mb-1">{{ d('signature_fields') }}</div>
          <!-- Honest about what this is: placement by page and person, not by dragging onto a
               rendered page. Dragging needs the PDF rendered here and a keyboard equivalent, and
               half of that is worse than neither. -->
          <div class="text-caption text-medium-emphasis mb-2">{{ d('signature_fields_hint') }}</div>

          <div v-for="(field, index) in fields" :key="index" class="signatory-row">
            <VSelect
              v-model="field.type" density="compact" hide-details :label="d('field_type')"
              :items="[
                { value: 'signature', title: d('field_signature') },
                { value: 'initials', title: d('field_initials') },
                { value: 'date', title: d('field_date') },
                { value: 'text', title: d('field_text') },
              ]"
            />
            <VSelect
              v-model="field.signer" density="compact" hide-details :label="d('field_for')"
              :items="signatories.map((s, i) => ({ value: i, title: s.name || d('signatory_n', { n: i + 1 }) }))"
            />
            <VTextField
              v-model.number="field.page" type="number" min="1" density="compact" hide-details
              :label="d('field_page')" style="max-width:6rem"
            />
            <VBtn
              size="small" variant="text" icon="mdi-close"
              :aria-label="d('field_remove')" @click="fields.splice(index, 1)"
            />
          </div>

          <div class="d-flex flex-wrap ga-1 mt-1">
            <VBtn size="small" variant="text" prepend-icon="mdi-draw" @click="addField('signature')">
              {{ d('field_signature') }}
            </VBtn>
            <VBtn size="small" variant="text" prepend-icon="mdi-format-letter-case" @click="addField('initials')">
              {{ d('field_initials') }}
            </VBtn>
            <VBtn size="small" variant="text" prepend-icon="mdi-calendar" @click="addField('date')">
              {{ d('field_date') }}
            </VBtn>
          </div>
        </div>

        <VDivider />

        <VTextarea v-model="message" :label="d('signature_message')" rows="2" density="comfortable" />
        <VTextField
          v-model="expiresAt" type="date" :label="d('signature_expiry')" density="comfortable"
          :hint="d('signature_expiry_hint')" persistent-hint
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="sending = false">{{ d('cancel') }}</VBtn>
        <VBtn color="primary" :loading="busy" :disabled="!canSubmit" @click="send">
          {{ d('send_for_signature') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.signatory-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  margin-block-end: 0.5rem;
}

.signatory-row > :deep(.v-input) { flex: 1 1 8rem; min-inline-size: 0; }

.order-number {
  flex: 0 0 1.25rem;
  text-align: end;
  font-variant-numeric: tabular-nums;
}
</style>
