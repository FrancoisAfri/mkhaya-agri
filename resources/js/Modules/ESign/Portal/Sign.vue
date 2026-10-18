<script setup lang="ts">
import { ref, computed, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import * as pdfjsLib from 'pdfjs-dist'
import PdfjsWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?worker'
import { PDFDocument, StandardFonts, rgb } from 'pdf-lib'
import SignaturePad from '@/Core/Components/SignaturePad.vue'
import MainLayout from '@/Core/Layouts/MainLayout.vue'

pdfjsLib.GlobalWorkerOptions.workerPort = new PdfjsWorker()

const { t } = useI18n()

/*
 * An outside signer never gets the business's own application shell.
 *
 * The page draws its own full-height frame, and rendering it inside MainLayout put a MKHAYA HUB
 * navbar through the middle of the signing card and a dark product footer under it — chrome for an
 * application the signer has no account for and cannot use. A colleague is already inside the
 * system, so they keep the shell they are used to.
 */
defineOptions({
  layout: (h: any, page: any) => page.props.isInternal ? h(MainLayout, () => page) : page,
})

type Props = {
  envelope: {
    uuid: string
    title: string
    document_type: string
    type_label: string
    message: string | null
    status: string
    expires_at: string | null
    has_document: boolean
  }
  signer: {
    uuid: string
    name: string
    role: string
    role_label: string
    status: string
    consent_given: boolean
    is_signed: boolean
    is_declined: boolean
  }
  token: string
  /** Whose paper this is — shown to an outside signer, who may only half recognise the sender. */
  company: { name: string; logo: string | null }
  isInternal: boolean
}

const props = defineProps<Props>()

const step        = ref<'consent' | 'otp' | 'sign' | 'done'>(
  props.signer.is_signed ? 'done' : (props.signer.consent_given ? 'otp' : 'consent')
)
const consentGiven = ref(props.signer.consent_given)
const otpSent     = ref(false)
const otpCode     = ref('')
const signatureType = ref<'typed' | 'drawn'>('typed')
const typedName   = ref('')
const drawnData   = ref('')
const loading     = ref<string | null>(null)
const confirmDialog = ref()
const message     = ref<{ type: 'success' | 'error'; text: string } | null>(null)
let msgTimer: ReturnType<typeof setTimeout>

const baseUrl = `/esign/sign/${props.token}`

function csrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
}

function setMessage(type: 'success' | 'error', text: string) {
  clearTimeout(msgTimer)
  message.value = { type, text }
  if (type === 'success') msgTimer = setTimeout(() => { message.value = null }, 8000)
}

async function giveConsent() {
  loading.value = 'consent'
  try {
    const res = await fetch(`${baseUrl}/consent`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
    })
    if (res.ok) {
      consentGiven.value = true
      step.value = 'otp'
    } else {
      setMessage('error', 'Could not record consent. Please try again.')
    }
  } catch {
    setMessage('error', 'Network error. Please try again.')
  } finally {
    loading.value = null
  }
}

async function sendOtp() {
  loading.value = 'otp'
  message.value = null
  try {
    const res = await fetch(`${baseUrl}/otp`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
    })
    const data = await res.json()
    if (res.ok) {
      otpSent.value = true
      setMessage('success', data.message ?? 'OTP sent to your email.')
    } else {
      setMessage('error', data.message ?? 'Failed to send OTP.')
    }
  } catch {
    setMessage('error', 'Network error. Please try again.')
  } finally {
    loading.value = null
  }
}

const signatureData = computed(() =>
  signatureType.value === 'typed' ? typedName.value : drawnData.value
)

/*
 * The document, rendered so it can be read and signed on the page.
 *
 * pdf.js draws it; pdf-lib burns the signature into the file itself before it is sent back. Doing
 * it in the browser means what is stored is the page the signer was actually looking at, rather
 * than the server's later reconstruction of it — the two can differ, and the difference is exactly
 * what a signature is supposed to rule out.
 */
const pdfLoading = ref(false)
const pdfError   = ref<string | null>(null)
const pages      = ref<{ n: number; w: number; h: number; scale: number }[]>([])
const canvasRefs = ref<HTMLCanvasElement[]>([])
const placed     = ref<{ page: number; xPct: number; yPct: number; wPct: number } | null>(null)
let pdfBytes: Uint8Array | null = null

const documentUrl = `${baseUrl}/document`

async function loadDocument() {
  if (!props.envelope.has_document) return
  pdfLoading.value = true
  pdfError.value = null
  try {
    const res = await fetch(documentUrl, { headers: { Accept: 'application/pdf' } })
    if (!res.ok) throw new Error('not ok')
    pdfBytes = new Uint8Array(await res.arrayBuffer())

    const pdf = await pdfjsLib.getDocument({ data: new Uint8Array(pdfBytes) }).promise
    const width = 780
    const viewports: any[] = []
    const list: { n: number; w: number; h: number }[] = []

    for (let n = 1; n <= pdf.numPages; n++) {
      const page = await pdf.getPage(n)
      const base = page.getViewport({ scale: 1 })
      const vp = page.getViewport({ scale: width / base.width })
      /*
         * The scale is kept, not just the pixel size.
         *
         * The stamp is applied in PDF points and the preview is drawn in screen pixels, so without
         * this the preview can only guess at how big 20pt actually is on this page — and a preview
         * that guesses is a preview that lies about where the signature lands.
         */
      list.push({ n, w: vp.width, h: vp.height, scale: width / base.width })
      viewports.push({ page, vp })
    }

    pages.value = list
    await nextTick()

    // v-show rather than v-if on the stack, so the canvases exist by the time this runs. With
    // v-if they do not, every page renders blank, and a blank document is indistinguishable from
    // one with nothing in it.
    const canvases = document.querySelectorAll<HTMLCanvasElement>('.esign-page canvas')

    for (let i = 0; i < viewports.length; i++) {
      const canvas = canvasRefs.value[i] ?? canvases[i]
      if (!canvas) continue
      const { page, vp } = viewports[i]
      canvas.width = vp.width
      canvas.height = vp.height
      await page.render({ canvasContext: canvas.getContext('2d')!, viewport: vp, canvas }).promise
    }
  } catch {
    pdfError.value = 'The document could not be loaded. Refresh the page, or ask the sender to resend it.'
  } finally {
    pdfLoading.value = false
  }
}

/** Where on the page the signature goes — clicked, so it lands where a person would sign. */
function placeSignature(event: MouseEvent, page: { n: number; w: number; h: number }) {
  if (!signatureData.value) return
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  placed.value = {
    page: page.n,
    xPct: (event.clientX - rect.left) / rect.width,
    yPct: (event.clientY - rect.top) / rect.height,
    wPct: 0.22,
  }
}

/**
 * The document with this signature in it.
 *
 * Both kinds are stamped. A typed signature used to be recorded against the signer and never
 * appear on the page, so the executed copy was byte-for-byte the unsigned original — the audit
 * trail said "signed by Francois" and the document showed nothing. For most people TYPE NAME is
 * the obvious choice, so that was the common path, not the edge case.
 *
 * Placement is optional. Somebody who signs without clicking the page gets the signature at the
 * foot of the last page, where a signature block goes, rather than no signature at all.
 */
async function stampedDocument(): Promise<string | null> {
  if (!pdfBytes || !signatureData.value) return null

  const doc = await PDFDocument.load(pdfBytes)
  const pageList = doc.getPages()
  const spot = placed.value ?? { page: pageList.length, xPct: 0.08, yPct: 0.88, wPct: 0.28 }
  const page = pageList[Math.min(spot.page, pageList.length) - 1]
  const { width, height } = page.getSize()

  const w = spot.wPct * width
  const x = spot.xPct * width
  const stamped = new Date().toLocaleString()

  if (signatureType.value === 'drawn' && drawnData.value) {
    const png = await doc.embedPng(drawnData.value)
    const h = w * (png.height / png.width)
    page.drawImage(png, {
      x,
      // pdf-lib measures from the bottom of the page; the click came from the top.
      y: height - spot.yPct * height - h,
      width: w,
      height: h,
    })
  } else {
    // A typed signature is drawn as the name itself, in an italic face so it reads as a signature
    // rather than as body text somebody added to the document.
    const font = await doc.embedFont(StandardFonts.HelveticaOblique)
    page.drawText(typedName.value, {
      x,
      y: height - spot.yPct * height,
      size: 20,
      font,
      color: rgb(0.05, 0.05, 0.3),
    })
  }

  // Who and when, under the mark. A signature with no name beside it is worth arguing about.
  const meta = await doc.embedFont(StandardFonts.Helvetica)
  page.drawText(`${props.signer.name} · ${stamped}`, {
    x,
    y: height - spot.yPct * height - 14,
    size: 8,
    font: meta,
    color: rgb(0.35, 0.35, 0.35),
  })

  const bytes = await doc.save()
  let bin = ''
  const chunk = 0x8000
  for (let i = 0; i < bytes.length; i += chunk) {
    bin += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk) as any)
  }

  return 'data:application/pdf;base64,' + btoa(bin)
}

onMounted(loadDocument)

async function sign() {
  if (!otpCode.value || !signatureData.value) return
  loading.value = 'sign'
  message.value = null
  try {
    const res = await fetch(`${baseUrl}/sign`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        otp:            otpCode.value,
        signature_data: signatureData.value,
        signature_type: signatureType.value,
        document:       await stampedDocument(),
      }),
    })
    const data = await res.json()
    if (res.ok) {
      step.value = 'done'
      setMessage('success', data.message ?? 'Document signed successfully.')
    } else {
      setMessage('error', data.message ?? 'Invalid OTP or signature. Please try again.')
    }
  } catch {
    setMessage('error', 'Network error. Please try again.')
  } finally {
    loading.value = null
  }
}

function decline() {
  confirmDialog.value.open(
    {
      title: t('e_sign.portal.decline_dialog.title'),
      message: t('e_sign.portal.decline_dialog.message'),
      confirmText: t('e_sign.portal.decline_dialog.confirm'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle'
    },
    async () => {
      loading.value = 'decline'
      try {
        const res = await fetch(`${baseUrl}/decline`, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrfToken(), 'Accept': 'application/json' },
        })
        if (res.ok) {
          step.value = 'done'
          setMessage('error', t('e_sign.portal.done.declined'))
        }
      } catch {
        setMessage('error', 'Network error. Please try again.')
      } finally {
        loading.value = null
      }
    },
  )
}
</script>

<template>
  <div
    style="min-height: 100vh; background: #f5f5f5; display: flex; align-items: flex-start; justify-content: center; padding: 40px 24px;"
  >
    <ConfirmDialog ref="confirmDialog" />
    <VCard elevation="0" border style="max-width: 680px; width: 100%;">

      <VCardText class="pa-8 pb-6 text-center">
        <VIcon icon="tabler-file-signature" size="48" color="primary" class="mb-4" />
        <h5 class="text-h5 font-weight-bold mb-2">{{ envelope.title }}</h5>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ envelope.type_label }}</p>
        <p class="text-caption text-medium-emphasis mt-1 mb-0">
          {{ t('e_sign.portal.signing_as', { name: signer.name, role: signer.role_label }) }}
        </p>
        <p v-if="envelope.expires_at" class="text-caption text-medium-emphasis mt-1 mb-0">
          {{ t('e_sign.portal.expires', { date: envelope.expires_at }) }}
        </p>
      </VCardText>

      <VDivider />

      <div v-if="envelope.message" class="pa-5 pb-0">
        <VAlert variant="tonal" color="info" density="comfortable" icon="tabler-message">
          {{ envelope.message }}
        </VAlert>
      </div>

      <div v-if="!isInternal" class="d-flex align-center ga-3 px-5 pt-5">
        <img v-if="company.logo" :src="company.logo" :alt="company.name" style="max-height: 40px">
        <div>
          <div class="text-subtitle-1 font-weight-semibold">{{ company.name }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('e_sign.portal.asks_you_to_sign') }}</div>
        </div>
      </div>

      <!--
        The document, above the signing controls. A signer is being asked to agree to something,
        so the something has to be in front of them — the page previously offered consent, an OTP
        and a signature box with nothing to read.
      -->
      <div v-if="envelope.has_document" class="pa-5 pb-0">
        <div class="d-flex align-center justify-space-between mb-2">
          <span class="text-subtitle-2 font-weight-medium">{{ t('e_sign.portal.document.title') }}</span>
          <VBtn :href="documentUrl" target="_blank" size="small" variant="text" prepend-icon="tabler-download">
            {{ t('e_sign.portal.document.download') }}
          </VBtn>
        </div>

        <VAlert v-if="pdfError" type="error" variant="tonal" density="compact" class="mb-2">{{ pdfError }}</VAlert>
        <div v-if="pdfLoading" class="text-center py-6">
          <VProgressCircular indeterminate color="primary" />
          <div class="text-caption text-medium-emphasis mt-2">{{ t('e_sign.portal.document.loading') }}</div>
        </div>

        <!-- v-show, not v-if: the canvases have to exist before the render loop draws into them. -->
        <div v-show="!pdfLoading && !pdfError" class="esign-stack">
          <div
            v-for="p in pages"
            :key="p.n"
            class="esign-page"
            :style="{ width: p.w + 'px', height: p.h + 'px' }"
            @click="placeSignature($event, p)"
          >
            <canvas :ref="el => { if (el) canvasRefs[p.n - 1] = el as HTMLCanvasElement }" />
            <!--
              What will actually be stamped, where it will actually be stamped.

              Two things were wrong here. A typed signature was placed and never shown — the hint
              stopped saying "click to place" and nothing appeared, so a signer had no way to know
              where their name would land. And the drawn preview was centred on the click while the
              stamp puts the signature's *top* edge there, so it previewed about half a signature
              higher than it printed. A preview that is wrong is worse than none.

              The anchoring below mirrors stampedDocument() exactly: a drawn mark hangs from the
              click point, a typed one sits on it like a line of text on its baseline, and the name
              and date sit underneath both because that is what gets drawn.
            -->
            <div
              v-if="placed && placed.page === p.n && signatureData"
              class="esign-placed"
              :style="{ left: (placed.xPct * 100) + '%', top: (placed.yPct * 100) + '%', width: (placed.wPct * 100) + '%' }"
            >
              <img v-if="signatureType === 'drawn' && drawnData" :src="drawnData" class="esign-placed-mark">
              <div
                v-else
                class="esign-placed-typed"
                :style="{ fontSize: (20 * p.scale) + 'px', lineHeight: 1 }"
              >{{ typedName }}</div>

              <!-- Drawn in the stamp too, and it is what tells somebody how much room this takes. -->
              <div class="esign-placed-meta" :style="{ fontSize: (8 * p.scale) + 'px' }">
                {{ signer.name }}
              </div>
            </div>
          </div>
        </div>

        <p v-if="signatureData && !placed" class="text-caption text-medium-emphasis mt-2 mb-0">
          {{ t('e_sign.portal.document.place_hint') }}
        </p>
        <!-- Once it is placed, say it can still be moved: a first click that landed badly is the
             common case, and nothing on the page said the choice was not final. -->
        <p v-else-if="placed" class="text-caption text-medium-emphasis mt-2 mb-0">
          {{ t('e_sign.portal.document.placed_hint', { page: placed.page }) }}
        </p>
      </div>

      <VAlert v-else-if="step !== 'done'" type="warning" variant="tonal" density="comfortable" class="ma-5">
        {{ t('e_sign.portal.document.missing') }}
      </VAlert>

      <div v-if="step === 'done'" class="pa-6">
        <VAlert
          :type="signer.is_declined ? 'error' : 'success'"
          variant="tonal"
          density="comfortable"
          :icon="signer.is_declined ? 'tabler-x' : 'tabler-circle-check'"
        >
          {{ signer.is_declined ? t('e_sign.portal.done.declined') : t('e_sign.portal.done.signed') }}
        </VAlert>
      </div>

      <template v-else-if="step === 'consent'">
        <VCardText class="pa-6">
          <h6 class="text-h6 font-weight-semibold mb-3">{{ t('e_sign.portal.consent.title') }}</h6>
          <p class="text-body-2 mb-4">{{ t('e_sign.portal.consent.description') }}</p>

          <VAlert v-if="message" :type="message.type" variant="tonal" density="comfortable" class="mb-4">
            {{ message.text }}
          </VAlert>

          <div class="d-flex gap-3">
            <VBtn color="primary" prepend-icon="tabler-check" :loading="loading === 'consent'" @click="giveConsent">
              {{ t('e_sign.portal.consent.agree_button') }}
            </VBtn>
            <VBtn color="error" variant="text" :loading="loading === 'decline'" @click="decline">
              {{ t('e_sign.portal.consent.decline_button') }}
            </VBtn>
          </div>
        </VCardText>
      </template>

      <template v-else-if="step === 'otp'">
        <VCardText class="pa-6">
          <h6 class="text-h6 font-weight-semibold mb-4">{{ t('e_sign.portal.otp.title') }}</h6>

          <VAlert v-if="message" :type="message.type" variant="tonal" density="comfortable" closable class="mb-4"
            @click:close="message = null">
            {{ message.text }}
          </VAlert>

          <VBtn color="primary" variant="tonal" prepend-icon="tabler-shield-lock" :loading="loading === 'otp'" class="mb-5" @click="sendOtp">
            {{ otpSent ? t('e_sign.portal.otp.resend_button') : t('e_sign.portal.otp.send_button') }}
          </VBtn>
          <p v-if="!otpSent" class="text-caption text-medium-emphasis mb-4">{{ t('e_sign.portal.otp.hint') }}</p>

          <template v-if="otpSent">
            <VTextField
              v-model="otpCode"
              :label="t('e_sign.portal.otp.code_label')"
              variant="outlined"
              density="comfortable"
              maxlength="6"
              append-inner-icon="tabler-key"
              :hint="t('e_sign.portal.otp.code_hint')"
              persistent-hint
              class="mb-5"
              :disabled="loading !== null"
            />

            <p class="text-caption text-uppercase text-medium-emphasis font-weight-bold mb-3">
              {{ t('e_sign.portal.otp.signature_label') }}
            </p>

            <VBtnToggle v-model="signatureType" density="comfortable" variant="outlined" class="mb-4">
              <VBtn value="typed" prepend-icon="tabler-keyboard">{{ t('e_sign.portal.otp.type_name') }}</VBtn>
              <VBtn value="drawn" prepend-icon="tabler-pencil">{{ t('e_sign.portal.otp.draw') }}</VBtn>
            </VBtnToggle>

            <div v-if="signatureType === 'typed'">
              <VTextField
                v-model="typedName"
                :label="t('e_sign.portal.otp.type_name')"
                variant="outlined"
                density="comfortable"
                :placeholder="t('e_sign.portal.otp.type_placeholder')"
                class="mb-4"
                :style="{ fontFamily: 'cursive', fontSize: '1.2rem' }"
              />
            </div>

            <div v-else class="mb-4">
              <p class="text-caption text-medium-emphasis mb-2">{{ t('e_sign.portal.otp.draw_hint') }}</p>
              <!--
                A real pad, not the placeholder that used to sit here reading "requires
                signature-pad library". The only way to draw was to paste a base64 data URI into a
                text box, which nobody has ever done, so DRAW stayed disabled and the document
                could not be signed at all.
              -->
              <SignaturePad
                v-model="drawnData"
                :hint="t('e_sign.portal.otp.draw_hint')"
                :clear-label="t('e_sign.portal.otp.clear')"
              />
            </div>

            <VBtn color="success" prepend-icon="tabler-pen" :loading="loading === 'sign'" :disabled="!otpCode || !signatureData" block @click="sign">
              {{ t('e_sign.portal.otp.sign_button') }}
            </VBtn>
          </template>
        </VCardText>
      </template>

      <VDivider />

      <VCardText class="pa-4 text-center">
        <p class="text-caption text-medium-emphasis mb-0">{{ t('e_sign.portal.footer') }}</p>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.esign-stack { display: flex; flex-direction: column; align-items: center; gap: 16px; max-height: 70vh; overflow-y: auto; }
.esign-page { position: relative; box-shadow: 0 1px 6px rgba(0, 0, 0, .18); cursor: crosshair; background: #fff; }
.esign-page canvas { display: block; width: 100%; height: 100%; }
/* Drawn over the page where the signer clicked, so they see the result before committing to it. */
.esign-placed {
  position: absolute;
  pointer-events: none;
  /*
   * No vertical shift. The stamp puts the top of the mark at the click point, so the preview must
   * too — the old `translateY(-50%)` centred it and previewed half a signature higher than it
   * printed.
   */
}

.esign-placed-mark { inline-size: 100%; display: block; }

.esign-placed-typed {
  font-family: Helvetica, Arial, sans-serif;
  font-style: italic;
  color: rgb(13, 13, 77);
  white-space: nowrap;
}

.esign-placed-meta {
  font-family: Helvetica, Arial, sans-serif;
  color: rgb(89, 89, 89);
  white-space: nowrap;
  margin-block-start: 2px;
}
</style>
