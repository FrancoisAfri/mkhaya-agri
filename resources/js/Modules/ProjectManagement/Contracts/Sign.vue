<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import * as pdfjsLib from 'pdfjs-dist'
import PdfjsWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?worker'
import { PDFDocument } from 'pdf-lib'
import { useI18n } from 'vue-i18n'
import SignaturePad from '@/Core/Components/SignaturePad.vue'
import MainLayout from '@/Core/Layouts/MainLayout.vue'
import PortalShell from '../Portal/PortalShell.vue'
import { h } from 'vue'

const route = ziggyRoute
const { t } = useI18n()

pdfjsLib.GlobalWorkerOptions.workerPort = new PdfjsWorker()

/**
 * Sign the contract on the contract.
 *
 * Modelled on Tender Pursuit's DocumentFiller: pdfjs renders the pages, the signature is placed
 * where the signer puts it, and pdf-lib burns it into the file in the browser. The flattened bytes
 * are what get lodged as the executed copy — so the signature is *in* the document, not recorded
 * beside it.
 *
 * A subcontractor reaches this from their portal, so the frame follows the same rule as the
 * contract page: outside parties never get the business's own application shell.
 */
defineOptions({
  layout: (_h: unknown, page: any) => page.props.portal
    ? h(PortalShell, { company: page.props.project?.name, me: page.props.me }, () => page)
    : h(MainLayout, () => page),
})

const page = usePage()

/**
 * Whatever the server refused with.
 *
 * Signing posts back to this same page, so a refusal returns a 302 and the screen simply redraws.
 * Without this the button appeared to do nothing at all — the most common report on this feature,
 * and every time the server had said exactly why.
 */
const serverError = computed(() => (page.props as any).errors?.contract as string | undefined)

const props = defineProps<{
  contract: { id: string; number: string; title: string; documentUrl: string }
  project: { name: string } | null
  party: string
  signerName: string | null
  savedSignatures: Array<{ id: string; name: string; kind: string; image: string }>
  portal: boolean
  me?: { name: string; role: string } | null
}>()

type Placed = { page: number; xPct: number; yPct: number; wPct: number; image: string }

const loading = ref(true)
const pages = ref<{ n: number; w: number; h: number }[]>([])
const canvasRefs = ref<HTMLCanvasElement[]>([])
const placed = ref<Placed | null>(null)
const typedName = ref(props.signerName ?? '')
const saved = ref([...props.savedSignatures])

// The signature about to be placed: picked from the saved ones, or drawn now.
const drawing = ref<string | null>(null)
const chosen = ref<string | null>(null)
const saveForReuse = ref(false)
const saving = ref(false)
const errorMessage = ref('')

/** Pristine bytes, kept so pdf-lib exports from the original rather than a re-encode. */
let pdfBytes: Uint8Array | null = null

const signature = computed(() => chosen.value ?? drawing.value)
const canSign = computed(() => !!signature.value && !!placed.value && typedName.value.trim().length > 0)

const blockedBecause = computed(() => {
  if (canSign.value) return null
  if (!signature.value) return t('project_management.contracts.need_a_signature')
  if (!placed.value) return t('project_management.contracts.place_it_on_the_page')
  if (!typedName.value.trim()) return t('project_management.contracts.need_name')

  return null
})

function bytesFrom(buffer: ArrayBuffer): Uint8Array {
  return new Uint8Array(buffer)
}

async function load() {
  loading.value = true
  try {
    const res = await axios.get(props.contract.documentUrl, { responseType: 'arraybuffer' })
    pdfBytes = bytesFrom(res.data)

    const pdf = await pdfjsLib.getDocument({ data: new Uint8Array(pdfBytes) }).promise
    const width = 820
    const list: { n: number; w: number; h: number }[] = []
    const viewports: any[] = []

    for (let n = 1; n <= pdf.numPages; n++) {
      const page = await pdf.getPage(n)
      const base = page.getViewport({ scale: 1 })
      const vp = page.getViewport({ scale: width / base.width })
      list.push({ n, w: vp.width, h: vp.height })
      viewports.push({ page, vp })
    }

    pages.value = list
    canvasRefs.value = []
    await nextTick()

    const canvases = document.querySelectorAll<HTMLCanvasElement>('.pdf-page canvas')

    for (let i = 0; i < viewports.length; i++) {
      // The ref is the intended path; the DOM query is the safety net. A page that renders blank
      // is indistinguishable from a contract with no wording, which is the worst way to fail here.
      const c = canvasRefs.value[i] ?? canvases[i]
      if (!c) continue
      c.width = viewports[i].vp.width
      c.height = viewports[i].vp.height
      await viewports[i].page.render({
        canvasContext: c.getContext('2d'),
        viewport: viewports[i].vp,
      }).promise
    }
  } catch {
    errorMessage.value = t('project_management.contracts.document_load_failed')
  } finally {
    loading.value = false
  }
}

/** Drop the signature where they clicked. Percentages, so it survives any zoom. */
function placeOn(event: MouseEvent, page: { n: number; w: number; h: number }) {
  if (!signature.value) return

  const box = (event.currentTarget as HTMLElement).getBoundingClientRect()

  placed.value = {
    page: page.n,
    xPct: (event.clientX - box.left) / box.width,
    yPct: (event.clientY - box.top) / box.height,
    wPct: 0.22,
    image: signature.value,
  }
}

/** Burn the signature into the PDF itself. */
async function flatten(): Promise<Uint8Array> {
  const doc = await PDFDocument.load(pdfBytes!)
  const p = doc.getPages()[placed.value!.page - 1]
  const { width, height } = p.getSize()

  const png = await doc.embedPng(placed.value!.image)
  const w = placed.value!.wPct * width
  const hgt = w * (png.height / png.width)

  p.drawImage(png, {
    x: placed.value!.xPct * width,
    // pdf-lib measures from the bottom; the click was from the top.
    y: height - placed.value!.yPct * height - hgt,
    width: w,
    height: hgt,
  })

  return doc.save()
}

function toBase64(bytes: Uint8Array): string {
  let bin = ''
  const chunk = 0x8000
  for (let i = 0; i < bytes.length; i += chunk) {
    bin += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk) as any)
  }

  return btoa(bin)
}

async function sign() {
  if (!canSign.value) return
  saving.value = true

  try {
    if (saveForReuse.value && drawing.value) {
      await axios.post(route('project-management.contracts.signature.store'), {
        image: drawing.value, kind: 'signature',
      })
    }

    const bytes = await flatten()

    // One request: the executed PDF and the signature record move together, so a saved document
    // can never exist without the signature that produced it.
    router.post(route('project-management.contracts.sign', props.contract.id), {
      typed_name: typedName.value,
      drawing: signature.value,
      pdf: toBase64(bytes),
    }, {
      onFinish: () => (saving.value = false),
    })
  } catch {
    errorMessage.value = t('project_management.contracts.signing_failed')
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center gap-3 mb-4">
      <VBtn
        icon="tabler-arrow-left" variant="text"
        @click="router.visit(route('project-management.contracts.show', props.contract.id))"
      />
      <div class="flex-grow-1">
        <h4 class="text-h6 mb-0">{{ props.contract.number }}</h4>
        <div class="text-caption text-medium-emphasis">
          {{ props.contract.title }}
          <template v-if="props.project"> · {{ props.project.name }}</template>
        </div>
      </div>
      <VChip color="warning" variant="tonal" size="small">
        {{ t('project_management.contracts.signing_as_' + props.party) }}
      </VChip>
    </div>

    <VAlert v-if="serverError" type="error" variant="tonal" class="mb-4">{{ serverError }}</VAlert>
    <VAlert v-if="errorMessage" type="error" variant="tonal" class="mb-4">{{ errorMessage }}</VAlert>

    <VRow>
      <!-- ── The document ──────────────────────────────────────────────────── -->
      <VCol cols="12" md="8">
        <VCard border>
          <VCardText>
            <!--
              The spinner sits beside the page stack, never around it. Gating the pages on
              `loading` meant the canvases did not exist while the render loop was looking for
              them, so every page came out blank at the browser's default 300x150 — which reads
              exactly like a contract with no wording.
            -->
            <div v-if="loading" class="text-center py-6">
              <VProgressCircular indeterminate color="primary" />
              <div class="text-caption text-medium-emphasis mt-3">
                {{ t('project_management.contracts.loading_document') }}
              </div>
            </div>

            <div v-show="!loading" class="pdf-stack">
              <div
                v-for="p in pages" :key="p.n"
                class="pdf-page" :class="{ armed: !!signature }"
                :style="{ width: p.w + 'px', height: p.h + 'px' }"
                @click="placeOn($event, p)"
              >
                <!--
                  The `if (el)` guard is load-bearing. Vue calls an inline ref function with null
                  when it tears the old binding down on re-render; assigning that null blindly
                  leaves canvasRefs empty, so the sizing and render steps silently skip and every
                  page comes out blank at the browser's default 300x150.
                -->
                <canvas :ref="el => { if (el) canvasRefs[p.n - 1] = el as HTMLCanvasElement }" />

                <!-- Where it will land. Dragging is not offered: click again to move it. -->
                <img
                  v-if="placed && placed.page === p.n"
                  :src="placed.image" alt=""
                  class="placed"
                  :style="{
                    left: (placed.xPct * 100) + '%',
                    top: (placed.yPct * 100) + '%',
                    width: (placed.wPct * 100) + '%',
                  }"
                >
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Signing ───────────────────────────────────────────────────────── -->
      <VCol cols="12" md="4">
        <VCard border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">
              {{ t('project_management.contracts.your_signature') }}
            </VCardTitle>
            <VCardSubtitle>{{ t('project_management.contracts.sign_steps') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <!-- Saved signatures first: most people sign the same way every time. -->
            <div v-if="saved.length" class="mb-4">
              <div class="text-caption text-medium-emphasis mb-2">
                {{ t('project_management.contracts.saved_signatures') }}
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button
                  v-for="s in saved" :key="s.id"
                  type="button" class="saved-sig"
                  :class="{ active: chosen === s.image }"
                  @click="chosen = chosen === s.image ? null : s.image; drawing = null"
                >
                  <img :src="s.image" :alt="s.name">
                </button>
              </div>
            </div>

            <div class="text-caption text-medium-emphasis mb-1">
              {{ t('project_management.contracts.or_draw_one') }}
            </div>
            <SignaturePad v-model="drawing" @update:model-value="chosen = null" />

            <VCheckbox
              v-if="drawing" v-model="saveForReuse" density="compact" hide-details
              :label="t('project_management.contracts.save_for_reuse')"
            />

            <VTextField
              v-model="typedName" class="mt-3"
              :label="t('project_management.contracts.type_your_name')"
              :hint="t('project_management.contracts.name_alongside_drawing')" persistent-hint
              variant="outlined" density="compact"
            />

            <VBtn
              block color="success" class="mt-4" prepend-icon="tabler-signature"
              :loading="saving" :disabled="!canSign" @click="sign"
            >
              {{ t('project_management.contracts.sign_document') }}
            </VBtn>
            <div v-if="blockedBecause" class="text-caption text-medium-emphasis mt-2 text-center">
              {{ blockedBecause }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.pdf-stack { display: flex; flex-direction: column; align-items: center; gap: 1rem; }
.pdf-page {
  position: relative;
  box-shadow: 0 1px 6px rgba(0, 0, 0, 0.18);
  background: #fff;
  max-inline-size: 100%;
}
.pdf-page.armed { cursor: crosshair; }
.pdf-page canvas { display: block; inline-size: 100%; block-size: 100%; }
.placed { position: absolute; pointer-events: none; transform: translate(0, 0); }

.saved-sig {
  border: 1px solid rgba(var(--v-border-color), 0.4);
  border-radius: 6px; padding: 4px; background: rgb(var(--v-theme-surface));
  cursor: pointer; transition: border-color 150ms ease;
}
.saved-sig.active { border-color: rgb(var(--v-theme-primary)); border-width: 2px; }
.saved-sig img { display: block; block-size: 40px; inline-size: auto; max-inline-size: 130px; }
</style>
