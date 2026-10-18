<script setup lang="ts">
/**
 * Choosing where a signature goes on a document.
 *
 * Bulk signing stamped every document at a fixed spot — last page, eight per cent from the left,
 * twelve per cent up — because it had nowhere to ask. That is fine until it lands on top of a
 * clause, and on a batch of twenty it lands on top of a clause twenty times before anybody sees
 * it. The signer picks now, on the page, against the document they are actually signing.
 *
 * ── Ratios, not points ──────────────────────────────────────────────────────
 *
 * What comes out is a fraction of the page: 0.08 of the width, 0.12 up from the bottom. The
 * preview is rendered at whatever size the screen allows and the stamp is applied at the PDF's own
 * dimensions, so anything measured in pixels here would be wrong there. A4 and Letter differ by
 * enough to put a signature off the paper.
 *
 * ── The origin ──────────────────────────────────────────────────────────────
 *
 * A canvas measures down from the top; a PDF measures up from the bottom. The conversion happens
 * once, here, at the point the drag ends — rather than in the caller, where getting it backwards
 * silently flips every signature to the wrong half of the page.
 */
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  /** Where to fetch the PDF from. */
  url: string
  /** A preview of what will be stamped: a data URI for a drawn signature, or the typed name. */
  preview: string
  previewType: 'typed' | 'drawn'
  /** The placement so far, so reopening shows what was chosen last time. */
  modelValue: Placement | null
}>()

export type Placement = {
  /** 1-based, as a person counts pages. */
  page: number
  /** Fractions of the page: x from the left, y up from the bottom, width across. */
  x: number
  y: number
  width: number
}

const emit = defineEmits<{ (e: 'update:modelValue', value: Placement): void }>()

const canvas = ref<HTMLCanvasElement | null>(null)
const wrapper = ref<HTMLElement | null>(null)
const pageCount = ref(1)
const page = ref(props.modelValue?.page ?? 1)
const loading = ref(true)
const failed = ref(false)

/** The box, in canvas pixels. Converted to page fractions only when it is emitted. */
const box = ref({ left: 0, top: 0, width: 160 })

let pdf: any = null

async function render() {
  loading.value = true

  try {
    /*
     * Imported here rather than at the top of the file.
     *
     * pdfjs pulls in a worker and several hundred kilobytes, and most visits to this page never
     * open the placer at all — a signer with one document usually accepts the default. Loading it
     * on demand keeps that cost off everybody who does not.
     */
    const pdfjs = await import('pdfjs-dist')
    pdfjs.GlobalWorkerOptions.workerSrc = (
      await import('pdfjs-dist/build/pdf.worker.min.mjs?url')
    ).default

    if (!pdf) {
      pdf = await pdfjs.getDocument({ url: props.url, withCredentials: true }).promise
      pageCount.value = pdf.numPages

      // A placement made against page 9 of a document that now has 4 would draw nothing.
      if (page.value > pageCount.value) page.value = pageCount.value
    }

    const rendered = await pdf.getPage(page.value)
    const target = wrapper.value?.clientWidth ?? 520
    const base = rendered.getViewport({ scale: 1 })
    const viewport = rendered.getViewport({ scale: target / base.width })

    const element = canvas.value
    if (!element) return

    element.width = viewport.width
    element.height = viewport.height

    await rendered.render({
      canvas: element,
      canvasContext: element.getContext('2d')!,
      viewport,
    }).promise

    placeInitialBox(viewport.width, viewport.height)
  } catch {
    // A preview that cannot be drawn must not stop somebody signing: the caller keeps its default
    // placement and the document is still signed.
    failed.value = true
  } finally {
    loading.value = false
  }
}

function placeInitialBox(width: number, height: number) {
  const existing = props.modelValue

  box.value = {
    left: (existing?.x ?? 0.08) * width,
    // Back from the PDF's bottom-up origin to the canvas's top-down one.
    top: height - (existing?.y ?? 0.12) * height - 48,
    width: (existing?.width ?? 0.28) * width,
  }
}

// ── Dragging ─────────────────────────────────────────────────────────────────

const dragging = ref(false)
let offsetX = 0
let offsetY = 0

function startDrag(event: PointerEvent) {
  const element = canvas.value
  if (!element) return

  dragging.value = true
  offsetX = event.clientX - box.value.left
  offsetY = event.clientY - box.value.top
  ;(event.target as HTMLElement).setPointerCapture(event.pointerId)
}

function onDrag(event: PointerEvent) {
  const element = canvas.value
  if (!dragging.value || !element) return

  // Clamped to the page: a signature dragged off the paper is not stamped anywhere useful, and the
  // person doing it has no way to see that it went nowhere.
  box.value.left = Math.max(0, Math.min(event.clientX - offsetX, element.width - box.value.width))
  box.value.top = Math.max(0, Math.min(event.clientY - offsetY, element.height - 48))
}

function endDrag() {
  dragging.value = false
  commit()
}

function commit() {
  const element = canvas.value
  if (!element) return

  emit('update:modelValue', {
    page: page.value,
    x: box.value.left / element.width,
    // Canvas top-down to PDF bottom-up, once, here.
    y: (element.height - box.value.top - 48) / element.height,
    width: box.value.width / element.width,
  })
}

watch(page, () => render())
watch(() => box.value.width, commit)

onMounted(render)
</script>

<template>
  <div ref="wrapper">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-2">
      <span class="text-caption text-medium-emphasis">{{ t('e_sign.place.hint') }}</span>

      <div v-if="pageCount > 1" class="d-flex align-center ga-1">
        <VBtn
          icon="tabler-chevron-left" size="x-small" variant="text"
          :disabled="page <= 1" :aria-label="t('e_sign.place.previous_page')"
          @click="page--"
        />
        <span class="text-caption">{{ t('e_sign.place.page_of', { page, total: pageCount }) }}</span>
        <VBtn
          icon="tabler-chevron-right" size="x-small" variant="text"
          :disabled="page >= pageCount" :aria-label="t('e_sign.place.next_page')"
          @click="page++"
        />
      </div>
    </div>

    <div class="placer">
      <VProgressLinear v-if="loading" indeterminate color="primary" class="mb-2" />

      <!-- A preview that will not draw must not block signing; the default placement still applies. -->
      <VAlert v-if="failed" type="info" variant="tonal" density="compact">
        {{ t('e_sign.place.no_preview') }}
      </VAlert>

      <div v-show="!failed" class="placer-stage">
        <canvas ref="canvas" class="placer-canvas" />

        <div
          class="placer-box"
          :style="{ left: `${box.left}px`, top: `${box.top}px`, width: `${box.width}px` }"
          role="button"
          tabindex="0"
          :aria-label="t('e_sign.place.drag_hint')"
          @pointerdown.prevent="startDrag"
          @pointermove="onDrag"
          @pointerup="endDrag"
          @pointercancel="endDrag"
        >
          <img v-if="previewType === 'drawn' && preview" :src="preview" alt="" class="placer-signature">
          <span v-else class="placer-typed">{{ preview || t('e_sign.place.your_signature') }}</span>
        </div>
      </div>

      <!-- Size, because a signature that fits an A4 contract is too wide for a delivery note. -->
      <div v-if="!failed" class="d-flex align-center ga-3 mt-2">
        <span class="text-caption text-medium-emphasis">{{ t('e_sign.place.size') }}</span>
        <VSlider
          v-model="box.width" :min="80" :max="380" :step="10"
          density="compact" hide-details color="primary" class="flex-grow-1"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.placer-stage {
  position: relative;
  display: inline-block;
  line-height: 0;
}

.placer-canvas {
  max-inline-size: 100%;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 4px;
}

.placer-box {
  position: absolute;
  min-block-size: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: grab;
  /* Visible against a printed page of any density, without hiding what is under it. */
  border: 2px dashed rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.08);
  border-radius: 4px;
  touch-action: none;
}

.placer-box:active { cursor: grabbing; }

.placer-signature {
  max-inline-size: 100%;
  max-block-size: 44px;
}

.placer-typed {
  font-family: cursive;
  font-size: 18px;
  line-height: 1.2;
  color: rgb(13, 13, 77);
  padding: 2px 6px;
  text-align: center;
  overflow-wrap: anywhere;
}
</style>
