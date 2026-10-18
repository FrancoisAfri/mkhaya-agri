<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

/**
 * Draw a signature with a finger, a stylus or a mouse.
 *
 * Pointer events rather than separate mouse and touch handlers: one code path covers all three,
 * and it is the only way a stylus reports pressure-free strokes correctly on a tablet — which is
 * what a site manager signing on a phone is actually holding.
 *
 * The canvas is sized to its rendered box and scaled by devicePixelRatio, otherwise a retina
 * screen produces a signature that looks like it was drawn through frosted glass.
 */
const props = withDefaults(defineProps<{
  /** Shown on the empty canvas, and on the clear button. Passed in so each module keeps its own
   *  translations rather than borrowing another's. */
  hint?: string
  clearLabel?: string
}>(), { hint: '', clearLabel: '' })

const emit = defineEmits<{ (e: 'update:modelValue', value: string | null): void }>()

const canvas = ref<HTMLCanvasElement | null>(null)
const hasInk = ref(false)

let ctx: CanvasRenderingContext2D | null = null
let drawing = false

function resize() {
  const el = canvas.value
  if (!el) return

  const ratio = window.devicePixelRatio || 1
  const box = el.getBoundingClientRect()

  // Preserve anything already drawn across a resize (an orientation change mid-signature).
  const previous = hasInk.value ? el.toDataURL('image/png') : null

  el.width = Math.max(1, Math.round(box.width * ratio))
  el.height = Math.max(1, Math.round(box.height * ratio))

  ctx = el.getContext('2d')
  if (!ctx) return

  ctx.scale(ratio, ratio)
  ctx.lineWidth = 2
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.strokeStyle = '#1a1a1a'

  if (previous) {
    const img = new Image()
    img.onload = () => ctx?.drawImage(img, 0, 0, box.width, box.height)
    img.src = previous
  }
}

function pointFrom(event: PointerEvent) {
  const box = canvas.value!.getBoundingClientRect()

  return { x: event.clientX - box.left, y: event.clientY - box.top }
}

function start(event: PointerEvent) {
  if (!ctx) return
  drawing = true
  canvas.value?.setPointerCapture(event.pointerId)
  const p = pointFrom(event)
  ctx.beginPath()
  ctx.moveTo(p.x, p.y)
}

function move(event: PointerEvent) {
  if (!drawing || !ctx) return
  const p = pointFrom(event)
  ctx.lineTo(p.x, p.y)
  ctx.stroke()
  hasInk.value = true
}

function end(event: PointerEvent) {
  if (!drawing) return
  drawing = false
  canvas.value?.releasePointerCapture(event.pointerId)
  emit('update:modelValue', hasInk.value ? canvas.value!.toDataURL('image/png') : null)
}

function clear() {
  const el = canvas.value
  if (!el || !ctx) return
  const box = el.getBoundingClientRect()
  ctx.clearRect(0, 0, box.width, box.height)
  hasInk.value = false
  emit('update:modelValue', null)
}

defineExpose({ clear })

onMounted(() => {
  resize()
  window.addEventListener('resize', resize)
})

onBeforeUnmount(() => window.removeEventListener('resize', resize))
</script>

<template>
  <div>
    <div class="sig-wrap">
      <canvas
        ref="canvas"
        class="sig-canvas"
        @pointerdown.prevent="start"
        @pointermove.prevent="move"
        @pointerup.prevent="end"
        @pointercancel.prevent="end"
        @pointerleave="end"
      />
      <!-- A ruled line, so the box reads as somewhere to sign rather than somewhere to scribble. -->
      <div class="sig-rule" />
      <span v-if="!hasInk" class="sig-hint text-medium-emphasis">
        {{ props.hint || t('project_management.contracts.draw_here') }}
      </span>
    </div>

    <div class="d-flex justify-end mt-1">
      <VBtn size="small" variant="text" :disabled="!hasInk" @click="clear">
        {{ props.clearLabel || t('project_management.contracts.clear_signature') }}
      </VBtn>
    </div>
  </div>
</template>

<style scoped>
.sig-wrap {
  position: relative;
  block-size: 160px;
  border: 1px solid rgba(var(--v-border-color), 0.4);
  border-radius: 6px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
}
/* touch-action none, or dragging a finger scrolls the page instead of drawing. */
.sig-canvas { inline-size: 100%; block-size: 100%; display: block; touch-action: none; cursor: crosshair; }
.sig-rule {
  position: absolute; inset-inline: 1.5rem; inset-block-end: 2.5rem;
  border-block-end: 1px dashed rgba(var(--v-border-color), 0.6);
  pointer-events: none;
}
.sig-hint {
  position: absolute; inset-block-end: 1rem; inset-inline-start: 1.5rem;
  font-size: 0.75rem; pointer-events: none;
}
</style>
