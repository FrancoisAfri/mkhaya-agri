<script setup lang="ts">
import { computed, ref } from 'vue'
import SignaturePlacer from './SignaturePlacer.vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { PDFDocument, StandardFonts, rgb } from 'pdf-lib'
import SignaturePad from '@/Core/Components/SignaturePad.vue'

const route = ziggyRoute
const { t } = useI18n()

/**
 * Sign everything waiting for me, in one pass.
 *
 * Someone joining a company signs an appointment letter, a code of conduct, a policy
 * acknowledgement and an IT agreement on the same morning. One at a time that is four links, four
 * codes and four signatures, and by the third nobody is reading — which is the opposite of what a
 * signature is for. One code and one signature covers the batch; each document still records its
 * own.
 */
interface Pending {
  signer_id: number
  uuid: string
  title: string
  type_label: string
  message: string | null
  sent_at: string | null
  expires_at: string | null
  role_label: string
}

const props = defineProps<{ pending: Pending[]; signerName?: string }>()

const selected = ref<number[]>(props.pending.map(p => p.signer_id))
const step = ref<'choose' | 'code'>('choose')
const signatureType = ref<'typed' | 'drawn'>('typed')
const typedName = ref('')

/**
 * Who signed, and in what capacity, printed under the mark.
 *
 * A signature on its own is worth arguing about — a drawn squiggle says nothing about who made it,
 * and a company is bound by someone signing *as* something. The external signer portal has printed
 * a name under the mark all along; this page, where staff sign, printed only a timestamp.
 *
 * Defaulted from the account and editable, because the name on the letterhead is not always the
 * name on the payroll record, and the capacity is not on the record at all.
 */
const signedName = ref(props.signerName ?? '')
const signedCapacity = ref('')
const drawnData = ref('')
const otp = ref('')
const busy = ref(false)
const progress = ref('')
const note = ref<{ kind: 'success' | 'error'; text: string } | null>(null)

/*
 * ── Where each signature goes ───────────────────────────────────────────────
 *
 * Every document used to be stamped at the same fixed spot — last page, eight per cent from the
 * left, twelve per cent up. That is fine until it lands on top of a clause, and on a batch of
 * twenty it lands on top of a clause twenty times before anybody notices.
 *
 * The default is still that spot, so a signer with nothing to adjust presses one button as before.
 * What is new is that they can look, and move it.
 */
type Placement = { page: number; x: number; y: number; width: number }

/** Keyed by envelope uuid. Absent means "wherever the default puts it". */
const placements = ref<Record<string, Placement>>({})

/** Which document's placement is open. Only one at a time — this is a page, not a workspace. */
const placing = ref<string | null>(null)

const documentUrl = (uuid: string) => route('esign.envelopes.document', uuid)

/**
 * The same placement on every document in the batch.
 *
 * Twenty delivery notes off one template want the signature in one place, and setting it twenty
 * times is the friction this whole screen exists to remove. Offered rather than assumed, because
 * a batch of unlike documents is just as common.
 */
function applyToAll(uuid: string) {
  const chosenPlacement = placements.value[uuid]
  if (!chosenPlacement) return

  for (const item of chosen.value) {
    placements.value[item.uuid] = { ...chosenPlacement }
  }
}

const signature = computed(() => (signatureType.value === 'typed' ? typedName.value : drawnData.value))
const chosen = computed(() => props.pending.filter(p => selected.value.includes(p.signer_id)))
const canRequest = computed(() => chosen.value.length > 0 && !!signature.value)
const allSelected = computed(() => selected.value.length === props.pending.length)

function toggleAll() {
  selected.value = allSelected.value ? [] : props.pending.map(p => p.signer_id)
}

function csrf(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
}

async function post(url: string, body: Record<string, unknown>) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    body: JSON.stringify(body),
  })

  return { ok: res.ok, data: await res.json().catch(() => ({})) }
}

async function requestCode() {
  busy.value = true
  note.value = null
  try {
    const { ok, data } = await post(route('esign.envelopes.mine.otp'), { signer_ids: selected.value })
    if (ok) {
      step.value = 'code'
      note.value = { kind: 'success', text: data.message ?? t('e_sign.mine.code_sent') }
    } else {
      note.value = { kind: 'error', text: data.message ?? t('e_sign.mine.code_failed') }
    }
  } finally {
    busy.value = false
  }
}

/**
 * Put the signature into one document.
 *
 * Fetched, stamped and returned as a data URI, exactly as the single-document portal does — so a
 * batch-signed copy is the same artefact as one signed on its own, not a lesser version of it.
 * A document that cannot be fetched is skipped rather than failing the batch; the signature is
 * still recorded and the server reports which ones had no stamped copy.
 */
async function stamp(item: Pending): Promise<string | null> {
  try {
    const res = await fetch(route('esign.envelopes.document', item.uuid), { headers: { Accept: 'application/pdf' } })
    if (!res.ok) return null

    const doc = await PDFDocument.load(new Uint8Array(await res.arrayBuffer()))
    const pages = doc.getPages()

    /*
     * Where the signer put it, or the old default when they did not look.
     *
     * The placement is carried as fractions of the page rather than points: the preview is drawn at
     * whatever width the screen allowed, and A4 and Letter differ by enough that a pixel measured
     * there would put a signature off the paper here.
     */
    const chosenPlacement = placements.value[item.uuid]
    const page = chosenPlacement
      // 1-based on the way in, clamped: a placement made against a page that no longer exists
      // would otherwise draw nothing at all, silently.
      ? pages[Math.min(Math.max(chosenPlacement.page, 1), pages.length) - 1]
      : pages[pages.length - 1]

    const { width, height } = page.getSize()
    const x = (chosenPlacement?.x ?? 0.08) * width
    const y = (chosenPlacement?.y ?? 0.12) * height

    if (signatureType.value === 'drawn' && drawnData.value) {
      const png = await doc.embedPng(drawnData.value)
      const w = (chosenPlacement?.width ?? 0.28) * width
      page.drawImage(png, { x, y, width: w, height: w * (png.height / png.width) })
    } else {
      const font = await doc.embedFont(StandardFonts.HelveticaOblique)
      page.drawText(typedName.value, { x, y, size: 20, font, color: rgb(0.05, 0.05, 0.3) })
    }

    /*
     * Who, in what capacity, and when — under the mark, in that order.
     *
     * Drawn as separate lines rather than one run: a capacity is often long enough to push a
     * single line off the page, and the date is the one part that must always be legible.
     */
    const meta = await doc.embedFont(StandardFonts.Helvetica)
    const grey = rgb(0.35, 0.35, 0.35)
    let line = y - 14

    const under = [
      [signedName.value.trim(), 9, rgb(0.15, 0.15, 0.15)] as const,
      [signedCapacity.value.trim(), 8, grey] as const,
      [new Date().toLocaleString(), 8, grey] as const,
    ]

    for (const [text, size, colour] of under) {
      if (!text) continue
      page.drawText(text, { x, y: line, size, font: meta, color: colour })
      line -= size + 3
    }

    const bytes = await doc.save()
    let bin = ''
    const chunk = 0x8000
    for (let i = 0; i < bytes.length; i += chunk) {
      bin += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk) as any)
    }

    return 'data:application/pdf;base64,' + btoa(bin)
  } catch {
    return null
  }
}

async function signAll() {
  busy.value = true
  note.value = null
  try {
    const documents: Record<string, string> = {}

    for (const [i, item] of chosen.value.entries()) {
      progress.value = t('e_sign.mine.preparing', { n: i + 1, total: chosen.value.length })
      const stamped = await stamp(item)
      if (stamped) documents[item.uuid] = stamped
    }

    progress.value = t('e_sign.mine.submitting')

    const { ok, data } = await post(route('esign.envelopes.mine.sign'), {
      signer_ids: selected.value,
      otp: otp.value,
      signature_data: signature.value,
      signature_type: signatureType.value,
      documents,
      // Recorded against the envelope, so the audit trail can say where the signature was put and
      // not merely that one was applied.
      placements: placements.value,
    })

    note.value = { kind: ok ? 'success' : 'error', text: data.message ?? (ok ? t('e_sign.mine.done') : t('e_sign.mine.failed')) }
    if (ok) setTimeout(() => window.location.reload(), 1500)
  } finally {
    busy.value = false
    progress.value = ''
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h5 class="text-h5 font-weight-semibold">{{ t('e_sign.mine.title') }}</h5>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('e_sign.mine.subtitle') }}</p>
    </div>

    <VAlert v-if="note" :type="note.kind" variant="tonal" density="comfortable" class="mb-4">{{ note.text }}</VAlert>

    <VCard v-if="!pending.length">
      <VCardText class="text-center py-10 text-medium-emphasis">{{ t('e_sign.mine.nothing') }}</VCardText>
    </VCard>

    <VRow v-else>
      <VCol cols="12" md="7">
        <VCard>
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="font-weight-medium">{{ t('e_sign.mine.waiting', { n: pending.length }) }}</span>
              <VBtn size="small" variant="text" @click="toggleAll">
                {{ allSelected ? t('e_sign.mine.select_none') : t('e_sign.mine.select_all') }}
              </VBtn>
            </div>

            <VList density="comfortable">
              <template v-for="item in pending" :key="item.signer_id">
              <VListItem class="px-0">
                <template #prepend>
                  <VCheckbox v-model="selected" :value="item.signer_id" hide-details density="compact" />
                </template>
                <VListItemTitle class="font-weight-medium">{{ item.title }}</VListItemTitle>
                <VListItemSubtitle>
                  {{ item.type_label }} · {{ item.role_label }}
                  <span v-if="item.expires_at"> · {{ t('e_sign.mine.expires', { date: item.expires_at }) }}</span>
                </VListItemSubtitle>
                <template #append>
                  <!--
                    Offered per document rather than once for the batch: a contract and a delivery
                    note do not want the signature in the same place, and the caption says whether
                    this one has been looked at.
                  -->
                  <VBtn
                    v-if="selected.includes(item.signer_id)"
                    size="small" variant="text"
                    :prepend-icon="placements[item.uuid] ? 'tabler-map-pin-check' : 'tabler-map-pin'"
                    :color="placements[item.uuid] ? 'primary' : undefined"
                    @click="placing = placing === item.uuid ? null : item.uuid"
                  >
                    {{ placements[item.uuid] ? t('e_sign.place.placed') : t('e_sign.place.where') }}
                  </VBtn>
                  <VBtn
                    :href="route('esign.envelopes.document', item.uuid)"
                    target="_blank"
                    size="small"
                    variant="text"
                    prepend-icon="tabler-file-text"
                  >
                    {{ t('e_sign.mine.read') }}
                  </VBtn>
                </template>
              </VListItem>

              <!--
                Opened under the row it belongs to, not in a dialog over the list: the point is to
                see the signature against this document, and a modal hides which one that is.
              -->
              <VListItem v-if="placing === item.uuid" class="px-0 pb-4">
                <SignaturePlacer
                  :key="item.uuid"
                  v-model="placements[item.uuid]"
                  :url="documentUrl(item.uuid)"
                  :preview="signatureType === 'drawn' ? drawnData : typedName"
                  :preview-type="signatureType"
                />

                <div class="d-flex justify-end ga-2 mt-2">
                  <VBtn
                    v-if="placements[item.uuid] && chosen.length > 1"
                    size="small" variant="text" @click="applyToAll(item.uuid)"
                  >{{ t('e_sign.place.apply_to_all') }}</VBtn>
                  <VBtn size="small" variant="tonal" @click="placing = null">
                    {{ t('e_sign.place.done') }}
                  </VBtn>
                </div>
              </VListItem>
              </template>
            </VList>

            <!-- Said plainly: signing is an act, and reading first is the point of it. -->
            <p class="text-caption text-medium-emphasis mb-0">{{ t('e_sign.mine.read_first') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard>
          <VCardText>
            <div class="text-overline mb-2">{{ t('e_sign.mine.your_signature') }}</div>

            <VBtnToggle v-model="signatureType" mandatory density="comfortable" variant="outlined" divided class="mb-3">
              <VBtn value="typed" prepend-icon="tabler-keyboard">{{ t('e_sign.portal.otp.type_name') }}</VBtn>
              <VBtn value="drawn" prepend-icon="tabler-pencil">{{ t('e_sign.portal.otp.draw') }}</VBtn>
            </VBtnToggle>

            <VTextField
              v-if="signatureType === 'typed'"
              v-model="typedName"
              :label="t('e_sign.portal.otp.type_placeholder')"
              variant="outlined"
              density="comfortable"
              class="mb-3"
            />
            <SignaturePad
              v-else
              v-model="drawnData"
              :hint="t('e_sign.portal.otp.draw_hint')"
              :clear-label="t('e_sign.portal.otp.clear')"
            />

            <!--
              Printed under the mark. A drawn signature says nothing about who made it, and a
              company is bound by somebody signing in a stated capacity — so both go on the page
              rather than living only in the audit trail, where a reader of the PDF never sees them.
            -->
            <div class="d-flex ga-3 flex-wrap mt-3">
              <VTextField
                v-model="signedName"
                :label="t('e_sign.mine.signed_name')"
                :hint="t('e_sign.mine.signed_name_hint')" persistent-hint
                variant="outlined" density="comfortable" style="flex: 1 1 220px"
              />
              <VTextField
                v-model="signedCapacity"
                :label="t('e_sign.mine.signed_capacity')"
                :hint="t('e_sign.mine.signed_capacity_hint')" persistent-hint
                variant="outlined" density="comfortable" style="flex: 1 1 220px"
              />
            </div>

            <VDivider class="my-4" />

            <template v-if="step === 'choose'">
              <VBtn color="primary" block :disabled="!canRequest" :loading="busy" prepend-icon="tabler-shield-lock" @click="requestCode">
                {{ t('e_sign.mine.send_code', { n: chosen.length }) }}
              </VBtn>
              <p class="text-caption text-medium-emphasis mt-2 mb-0">{{ t('e_sign.mine.one_code') }}</p>
            </template>

            <template v-else>
              <VTextField
                v-model="otp"
                :label="t('e_sign.portal.otp.code_label')"
                variant="outlined"
                density="comfortable"
                maxlength="6"
                class="mb-3"
              />
              <VBtn color="success" block :disabled="otp.length !== 6 || busy" :loading="busy" prepend-icon="tabler-writing-sign" @click="signAll">
                {{ t('e_sign.mine.sign_all', { n: chosen.length }) }}
              </VBtn>
              <p v-if="progress" class="text-caption text-medium-emphasis mt-2 mb-0">{{ progress }}</p>
              <VBtn variant="text" size="small" class="mt-2" @click="step = 'choose'">{{ t('e_sign.mine.back') }}</VBtn>
            </template>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
