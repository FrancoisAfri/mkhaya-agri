<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import TiptapEditor from '@/@core/components/TiptapEditor.vue'
import { h } from 'vue'
import MainLayout from '@/Core/Layouts/MainLayout.vue'
import PortalShell from '../Portal/PortalShell.vue'
import SignaturePad from '@/Core/Components/SignaturePad.vue'

/**
 * Which frame this page hangs in depends on who is reading it.
 *
 * A subcontractor reaches their contract from the portal, and dropping them into the business's
 * own application shell — its sidebar, module grid and notifications — undoes the boundary the
 * portal exists to draw. Staff get the ordinary layout. Inertia resolves this per page, so the
 * decision is made from the payload rather than by a second route.
 */
defineOptions({
  layout: (_h: unknown, page: any) => page.props.portal
    ? h(PortalShell, { company: page.props.project?.name, me: page.props.me }, () => page)
    : h(MainLayout, () => page),
})

const { t } = useI18n()
const route = ziggyRoute

/**
 * One contract, start to signature, without leaving this module.
 *
 * Everything the negotiation needs is on this page: the wording as it stands, the amendment editor,
 * the version history, who has signed what, and — when it is this viewer's turn — the panel to sign
 * or refuse. The contractor sees the same page through their portal login; what differs is only
 * which actions they are offered.
 *
 * The body is sanitised server-side (Support\ContractBody), which is what makes the v-html safe.
 */
const props = defineProps<{
  contract: Record<string, any>
  project: { id: string; code: string; name: string }
  contractor: { name: string; email: string | null } | null
  versions: Array<Record<string, any>>
  signatureRounds: Array<{ current: boolean; signatures: Array<Record<string, any>> }>
  mySignature: { id: string; party: string; name: string | null } | null
  // What this viewer already did, so an unsigned-looking page explains itself.
  mySigned: { status: string; at: string | null; name: string | null } | null
  portal: boolean
  document?: { ready: boolean; signed: boolean; url: string | null; sha256: string | null; signed_at: string | null }
  savedSignatures?: Array<Record<string, any>>
  me?: { name: string; role: string } | null
  signatories: Array<{ id: number; label: string; email: string }>
  defaults: { signatory_user_id: number | null }
  costs: boolean
  can: { amend: boolean; submit: boolean; send: boolean; reopen: boolean }
}>()

const STATUS_COLOUR: Record<string, string> = {
  draft: 'grey', in_review: 'info', approved: 'primary', out_for_signature: 'warning',
  signed: 'success', rejected: 'error', declined: 'error', cancelled: 'grey',
}

const SIGNATURE_COLOUR: Record<string, string> = {
  pending: 'warning', signed: 'success', declined: 'error', withdrawn: 'grey',
}

function money(amount: string | null | undefined) {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency', currency: props.contract.currency || 'ZAR',
    }).format(Number(amount))
  } catch {
    return String(amount)
  }
}

// ── Amend ────────────────────────────────────────────────────────────────────
const amendDialog = ref(false)
const amendForm = useForm({
  body: '', title: '', contract_value: '', start_date: '', end_date: '', change_note: '',
})

function openAmend() {
  amendForm.reset()
  amendForm.body = props.contract.body ?? ''
  amendForm.title = props.contract.title ?? ''
  amendForm.contract_value = props.contract.value ?? ''
  amendForm.start_date = props.contract.start_date ?? ''
  amendForm.end_date = props.contract.end_date ?? ''
  amendDialog.value = true
}

function amend() {
  amendForm
    // Only send the value where this viewer may see cost — otherwise an empty box would
    // silently zero a figure they were never shown.
    .transform(data => props.costs ? data : (({ contract_value, ...rest }) => rest)(data))
    .post(route('project-management.contracts.amend', props.contract.id), {
      preserveScroll: true,
      onSuccess: () => { amendDialog.value = false },
    })
}

// ── Review ───────────────────────────────────────────────────────────────────
const submitForm = useForm({ note: '' })
const submitDialog = ref(false)

function submitForReview() {
  submitForm.post(route('project-management.contracts.submit', props.contract.id), {
    preserveScroll: true,
    onSuccess: () => { submitDialog.value = false },
  })
}

// ── Send for signature ───────────────────────────────────────────────────────
const sendDialog = ref(false)
const sendForm = useForm({ signatory_user_id: props.defaults.signatory_user_id })

function send() {
  sendForm.post(route('project-management.contracts.send', props.contract.id), {
    preserveScroll: true,
    onSuccess: () => { sendDialog.value = false },
  })
}

// ── Sign / decline ───────────────────────────────────────────────────────────
// Typed by default — it works on any device — with drawing offered alongside.
const signMode = ref<'type' | 'draw'>('type')

// Prefilled with the name we already hold for this signatory. They can change it — it is their
// signature — but nobody should have to retype a name the system put on the contract itself.
const signForm = useForm({
  typed_name: props.mySignature?.name ?? '',
  drawing: null as string | null,
})

const canSign = computed(() => signForm.typed_name.trim().length > 0
  && (signMode.value === 'type' || !!signForm.drawing))

/**
 * Why the Sign button is disabled, said out loud.
 *
 * A disabled control with no reason is the single most confusing thing on a form — you drew a
 * signature, the button stayed grey, and nothing on screen told you a name was also needed.
 */
const signBlockedBecause = computed(() => {
  if (canSign.value) return null
  if (!signForm.typed_name.trim()) return t('project_management.contracts.need_name')
  if (signMode.value === 'draw' && !signForm.drawing) return t('project_management.contracts.need_drawing')

  return null
})

// ── Reopen a signed contract ─────────────────────────────────────────────────
const reopenDialog = ref(false)
const reopenForm = useForm({ reason: '' })

function reopen() {
  reopenForm.post(route('project-management.contracts.reopen', props.contract.id), {
    preserveScroll: true,
    onSuccess: () => { reopenDialog.value = false },
  })
}
const declineForm = useForm({ reason: '' })
const declineDialog = ref(false)

function sign() {
  signForm
    // Only send a drawing when they actually drew one, so switching to Type and back does not
    // attach a stale image to a typed signature.
    .transform(data => ({ ...data, drawing: signMode.value === 'draw' ? data.drawing : null }))
    .post(route('project-management.contracts.sign', props.contract.id), { preserveScroll: true })
}

function decline() {
  declineForm.post(route('project-management.contracts.decline', props.contract.id), {
    preserveScroll: true,
    onSuccess: () => { declineDialog.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <VBtn
        variant="text" size="small" prepend-icon="tabler-arrow-left" class="mb-2 px-0"
        @click="router.visit(route('project-management.contracts.index'))"
      >
        {{ t('project_management.contracts.back') }}
      </VBtn>
      <div class="d-flex flex-wrap align-center gap-3">
        <h4 class="text-h4 mb-0">{{ props.contract.number }}</h4>
        <VChip :color="STATUS_COLOUR[props.contract.status] ?? 'grey'" variant="tonal">
          {{ t('project_management.contracts.status_' + props.contract.status) }}
        </VChip>
        <VChip variant="outlined" size="small">
          {{ t('project_management.contracts.version_short', { no: props.contract.version }) }}
        </VChip>
      </div>
      <p class="text-body-2 text-medium-emphasis mb-0 mt-1">
        {{ props.project.code }} — {{ props.project.name }}
      </p>
    </div>

    <!-- Errors the service raised, e.g. signing out of turn. -->
    <VAlert v-if="$page.props.errors?.contract" type="error" variant="tonal" class="mb-4">
      {{ $page.props.errors.contract }}
    </VAlert>

    <!-- It is this viewer's turn. The most important thing on the page, so it is at the top. -->
    <VCard v-if="props.mySignature" border color="warning" variant="tonal" class="mb-4">
      <VCardItem>
        <VCardTitle class="text-body-1">
          {{ t('project_management.contracts.your_signature_needed') }}
        </VCardTitle>
        <VCardSubtitle>
          {{ t('project_management.contracts.signing_as_' + props.mySignature.party) }}
        </VCardSubtitle>
      </VCardItem>
      <VCardText>
        <!-- Typed is the default: it works on every device and needs no steady hand. -->
        <VBtnToggle v-model="signMode" mandatory density="compact" variant="outlined" class="mb-4">
          <VBtn value="type" size="small" prepend-icon="tabler-keyboard">
            {{ t('project_management.contracts.sign_typed') }}
          </VBtn>
          <VBtn value="draw" size="small" prepend-icon="tabler-signature">
            {{ t('project_management.contracts.sign_drawn') }}
          </VBtn>
        </VBtnToggle>

        <VRow dense align="center">
          <VCol cols="12" :md="signMode === 'draw' ? 12 : 6">
            <VTextField
              v-model="signForm.typed_name"
              :label="t('project_management.contracts.type_your_name')"
              :hint="signMode === 'draw'
                ? t('project_management.contracts.name_alongside_drawing')
                : t('project_management.contracts.type_your_name_hint')"
              persistent-hint
              :error-messages="signForm.errors.typed_name"
              variant="outlined" density="compact"
            />
          </VCol>

          <VCol v-if="signMode === 'draw'" cols="12">
            <SignaturePad v-model="signForm.drawing" />
            <div v-if="signForm.errors.drawing" class="text-error text-caption mt-1">
              {{ signForm.errors.drawing }}
            </div>
          </VCol>

          <VCol cols="12" :md="signMode === 'draw' ? 12 : 6">
            <div class="d-flex align-center gap-2 flex-wrap">
              <!--
                Signing happens on the document. A signature typed beside a contract is a record;
                one placed on the page is the contract.
              -->
              <VBtn
                v-if="props.document?.ready"
                color="success" prepend-icon="tabler-file-signal"
                @click="router.visit(route('project-management.contracts.sign-view', props.contract.id))"
              >
                {{ t('project_management.contracts.open_to_sign') }}
              </VBtn>
              <VBtn
                v-else
                color="success" prepend-icon="tabler-signature"
                :loading="signForm.processing" :disabled="!canSign"
                @click="sign"
              >
                {{ t('project_management.contracts.sign') }}
              </VBtn>
              <VBtn color="error" variant="outlined" @click="declineDialog = true">
                {{ t('project_management.contracts.decline') }}
              </VBtn>
              <!-- Never leave a disabled button unexplained. -->
              <span v-if="signBlockedBecause" class="text-caption text-medium-emphasis">
                {{ signBlockedBecause }}
              </span>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!--
      They have already acted. Without this the contract reads "Out for signature" with no button
      and no explanation, which looks broken to the one person it matters most to.
    -->
    <VAlert
      v-if="!props.mySignature && props.mySigned"
      :type="props.mySigned.status === 'signed' ? 'success' : 'error'"
      variant="tonal" class="mb-4"
    >
      <div class="font-weight-medium">
        {{ props.mySigned.status === 'signed'
          ? t('project_management.contracts.you_signed', { at: props.mySigned.at ?? '' })
          : t('project_management.contracts.you_declined') }}
      </div>
      <div v-if="props.mySigned.status === 'signed'" class="text-body-2">
        {{ t('project_management.contracts.waiting_on_other_party') }}
      </div>
    </VAlert>

    <VRow>
      <!-- ── The wording ───────────────────────────────────────────────────── -->
      <VCol cols="12" md="8">
        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ props.contract.title }}</VCardTitle>
            <VCardSubtitle v-if="props.contract.summary">{{ props.contract.summary }}</VCardSubtitle>
            <template #append>
              <VBtn
                v-if="props.can.amend" size="small" variant="tonal" prepend-icon="tabler-edit"
                @click="openAmend"
              >
                {{ t('project_management.contracts.amend') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText>
            <!-- eslint-disable-next-line vue/no-v-html -->
            <div v-if="props.contract.body" class="contract-body" v-html="props.contract.body" />
            <div v-else class="text-medium-emphasis text-center py-8">
              <p class="mb-2">{{ t('project_management.contracts.no_body') }}</p>
              <p class="text-caption mb-0">{{ t('project_management.contracts.no_body_hint') }}</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <!-- ── Terms ──────────────────────────────────────────────────────── -->
        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">
              {{ t('project_management.contractors.contract_details') }}
            </VCardTitle>
          </VCardItem>
          <VCardText>
            <VTable density="compact">
              <tbody>
                <tr v-if="props.contractor">
                  <td>{{ t('project_management.contractors.col_contractor') }}</td>
                  <td class="text-end">{{ props.contractor.name }}</td>
                </tr>
                <tr v-if="props.contractor?.email">
                  <td>{{ t('project_management.contractors.field_email') }}</td>
                  <td class="text-end">{{ props.contractor.email }}</td>
                </tr>
                <tr v-if="props.costs">
                  <td>{{ t('project_management.contractors.contract_value') }}</td>
                  <td class="text-end figure">{{ money(props.contract.value) }}</td>
                </tr>
                <tr>
                  <td>{{ t('project_management.contractors.start_date') }}</td>
                  <td class="text-end">{{ props.contract.start_date ?? '—' }}</td>
                </tr>
                <tr>
                  <td>{{ t('project_management.contractors.end_date') }}</td>
                  <td class="text-end">{{ props.contract.end_date ?? '—' }}</td>
                </tr>
              </tbody>
            </VTable>

            <div class="d-flex flex-column gap-2 mt-4">
              <VBtn
                v-if="props.can.submit" block color="primary" variant="tonal"
                prepend-icon="tabler-checkup-list" @click="submitDialog = true"
              >
                {{ t('project_management.contracts.submit_for_review') }}
              </VBtn>
              <VBtn
                v-if="props.can.send" block color="primary"
                prepend-icon="tabler-signature" @click="sendDialog = true"
              >
                {{ t('project_management.contractors.send_for_signature') }}
              </VBtn>
              <!--
                A signed contract is closed to everybody. Reopening is the deliberate way back in:
                it tells every party who signed, and sends the document round again.
              -->
              <VBtn
                v-if="props.can.reopen" block color="warning" variant="tonal"
                prepend-icon="tabler-lock-open" @click="reopenDialog = true"
              >
                {{ t('project_management.contracts.reopen') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>

        <!-- ── Signatures ─────────────────────────────────────────────────── -->
        <VCard v-if="props.signatureRounds.length" border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.contracts.signatures') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <!--
              The current round only. Earlier rounds are the audit trail, not the signature block —
              shown flat they make the same person appear twice with contradictory answers.
            -->
            <div v-for="s in props.signatureRounds[0].signatures" :key="s.id" class="mb-3">
              <div class="d-flex align-center justify-space-between gap-2">
                <div>
                  <div class="text-body-2 font-weight-medium">{{ s.name ?? '—' }}</div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('project_management.contracts.party_' + s.party) }}
                    <template v-if="s.signed_at"> · {{ s.signed_at }}</template>
                  </div>
                </div>
                <VChip :color="SIGNATURE_COLOUR[s.status] ?? 'grey'" size="x-small" variant="tonal">
                  {{ t('project_management.contracts.sig_' + s.status) }}
                </VChip>
              </div>
              <img v-if="s.signature_data" :src="s.signature_data" alt="" class="sig-thumb mt-2">
              <VAlert v-if="s.stale" type="warning" variant="tonal" density="compact" class="mt-2">
                {{ t('project_management.contracts.signature_stale') }}
              </VAlert>
              <!-- Nobody can give this signature; say so rather than wait for ever. -->
              <VAlert v-if="s.unreachable" type="error" variant="tonal" density="compact" class="mt-2">
                {{ t('project_management.contracts.signatory_cannot_sign') }}
              </VAlert>
              <div v-if="s.reason" class="text-caption text-error mt-1">{{ s.reason }}</div>
            </div>

            <!-- Everything before this round, kept but out of the way. -->
            <VExpansionPanels v-if="props.signatureRounds.length > 1" variant="accordion" class="mt-2">
              <VExpansionPanel>
                <VExpansionPanelTitle class="text-caption">
                  {{ t('project_management.contracts.earlier_rounds', { n: props.signatureRounds.length - 1 }) }}
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <div
                    v-for="(round, ri) in props.signatureRounds.slice(1)" :key="ri"
                    class="mb-3"
                  >
                    <div class="text-caption text-medium-emphasis mb-1">
                      {{ t('project_management.contracts.round_n', { n: props.signatureRounds.length - 1 - ri }) }}
                    </div>
                    <div v-for="s in round.signatures" :key="s.id" class="d-flex justify-space-between text-caption py-1">
                      <span>{{ s.name ?? '—' }} · {{ t('project_management.contracts.party_' + s.party) }}</span>
                      <span class="text-medium-emphasis">{{ t('project_management.contracts.sig_' + s.status) }}</span>
                    </div>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
        </VCard>

        <!-- ── History ────────────────────────────────────────────────────── -->
        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.contracts.history') }}</VCardTitle>
          </VCardItem>
          <VCardText>
            <VTimeline side="end" density="compact" truncate-line="both">
              <VTimelineItem
                v-for="v in props.versions" :key="v.id"
                :dot-color="v.current ? 'primary' : 'grey'" size="x-small"
              >
                <div class="text-body-2 font-weight-medium">
                  {{ t('project_management.contracts.version_short', { no: v.no }) }}
                  <VChip v-if="v.current" size="x-small" color="primary" variant="tonal" class="ms-1">
                    {{ t('project_management.contracts.current') }}
                  </VChip>
                </div>
                <div v-if="v.note" class="text-caption">{{ v.note }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ v.author ?? '—' }} · {{ v.created }}
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Amend ──────────────────────────────────────────────────────────── -->
    <VDialog v-model="amendDialog" max-width="900" scrollable>
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contracts.amend') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.contracts.amend_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <!-- Amending a contract that is out for signature tears up what was already given. -->
          <VAlert
            v-if="props.contract.warns_on_amend" type="warning" variant="tonal" class="mb-4"
            density="compact"
          >
            {{ t('project_management.contracts.amend_withdraws') }}
          </VAlert>

          <VRow dense class="mb-2">
            <VCol cols="12" md="6">
              <VTextField
                v-model="amendForm.title"
                :label="t('project_management.contractors.contract_title')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol v-if="props.costs" cols="12" md="6">
              <VTextField
                v-model="amendForm.contract_value" inputmode="decimal"
                :label="t('project_management.contractors.contract_value')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>

          <div class="text-caption text-medium-emphasis mb-1">
            {{ t('project_management.contracts.wording') }}
          </div>
          <TiptapEditor v-model="amendForm.body" :disabled="amendForm.processing" />

          <VTextarea
            v-model="amendForm.change_note" class="mt-4" rows="2" auto-grow
            :label="t('project_management.contracts.change_note')"
            :hint="t('project_management.contracts.change_note_hint')" persistent-hint
            :error-messages="amendForm.errors.change_note"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="amendDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="amendForm.processing"
            :disabled="amendForm.change_note.trim().length < 3"
            @click="amend"
          >
            {{ t('project_management.contracts.save_amendment') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Submit for review ──────────────────────────────────────────────── -->
    <VDialog v-model="submitDialog" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contracts.submit_for_review') }}</VCardTitle>
        </VCardItem>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('project_management.contracts.submit_explainer') }}
          </p>
          <VTextarea
            v-model="submitForm.note" rows="2" auto-grow
            :label="t('project_management.contracts.review_note')"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="submitDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="submitForm.processing" @click="submitForReview">
            {{ t('project_management.contracts.send_for_review') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Send for signature ─────────────────────────────────────────────── -->
    <VDialog v-model="sendDialog" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contractors.send_for_signature') }}</VCardTitle>
          <VCardSubtitle>{{ props.contract.number }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('project_management.contracts.send_explainer', {
              contractor: props.contractor?.name ?? '—',
            }) }}
          </p>
          <VSelect
            v-model="sendForm.signatory_user_id"
            :items="props.signatories.map(s => ({ title: s.label, subtitle: s.email, value: s.id }))"
            :label="t('project_management.contractors.our_signatory')"
            :hint="t('project_management.contractors.our_signatory_hint')" persistent-hint
            :error-messages="sendForm.errors.signatory_user_id"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="sendDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="sendForm.processing" @click="send">
            {{ t('project_management.contractors.send_it') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Reopen a signed contract ───────────────────────────────────────── -->
    <VDialog v-model="reopenDialog" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contracts.reopen') }}</VCardTitle>
          <VCardSubtitle>{{ props.contract.number }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.contracts.reopen_warning') }}
          </VAlert>
          <VTextarea
            v-model="reopenForm.reason" rows="3" auto-grow
            :label="t('project_management.contracts.reopen_reason')"
            :hint="t('project_management.contracts.reopen_reason_hint')" persistent-hint
            :error-messages="reopenForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="reopenDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="warning" :loading="reopenForm.processing"
            :disabled="reopenForm.reason.trim().length < 3" @click="reopen"
          >
            {{ t('project_management.contracts.reopen_confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Decline ────────────────────────────────────────────────────────── -->
    <VDialog v-model="declineDialog" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.contracts.decline') }}</VCardTitle>
        </VCardItem>
        <VCardText>
          <VTextarea
            v-model="declineForm.reason" rows="3" auto-grow
            :label="t('project_management.contracts.decline_reason')"
            :hint="t('project_management.contracts.decline_reason_hint')" persistent-hint
            :error-messages="declineForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="declineDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="error" :loading="declineForm.processing"
            :disabled="declineForm.reason.trim().length < 3" @click="decline"
          >
            {{ t('project_management.contracts.decline') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.figure { font-variant-numeric: tabular-nums; }
.sig-thumb {
  max-inline-size: 200px; max-block-size: 60px;
  border-block-end: 1px solid rgba(var(--v-border-color), 0.5);
}

/*
 * The body is somebody's document, so it keeps document spacing rather than app spacing — and a
 * measure. On a wide screen an unconstrained contract runs to 200 characters a line, which is
 * roughly twice what anyone can read without losing their place returning to the left margin.
 */
.contract-body {
  line-height: 1.7;
  overflow-wrap: anywhere;
  max-inline-size: 72ch;
}

/*
 * A rate schedule can be wider than the column the contract is read in, so the wrapper the
 * sanitiser puts around every table scrolls — and the table stays a table.
 *
 * Making the table itself `display: block` was the first attempt and it collapsed every cell into
 * a run of prose. The signature block at the foot of an agreement is a table, so that landed on
 * exactly the part people sign.
 */
.contract-body :deep(.contract-table) {
  overflow-x: auto;
  max-inline-size: 100%;
  margin-block-end: 0.85rem;
}
.contract-body :deep(.contract-table table) { margin-block-end: 0; }
.contract-body :deep(h1),
.contract-body :deep(h2),
.contract-body :deep(h3) { margin-block: 1.25rem 0.5rem; line-height: 1.3; }
.contract-body :deep(p) { margin-block-end: 0.85rem; }
.contract-body :deep(ul),
.contract-body :deep(ol) { margin-block-end: 0.85rem; padding-inline-start: 1.5rem; }
.contract-body :deep(blockquote) {
  margin-inline-start: 0;
  padding-inline-start: 1rem;
  border-inline-start: 3px solid rgba(var(--v-border-color), 0.4);
}
.contract-body :deep(table) { width: 100%; border-collapse: collapse; margin-block-end: 0.85rem; }
.contract-body :deep(th),
.contract-body :deep(td) {
  border: 1px solid rgba(var(--v-border-color), 0.3);
  padding: 0.4rem 0.6rem;
  text-align: start;
}

/* The version timeline animates in; somebody who asked the OS not to should not see it. */
@media (prefers-reduced-motion: reduce) {
  .pa-4 :deep(*) {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
</style>
