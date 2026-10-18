<script lang="ts" setup>
import { computed, ref, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  ceoMessage: any | null
  isOpen:     boolean
}>()

const emit = defineEmits<{ close: [] }>()

const imageUrl = computed<string | null>(() => {
  const m = props.ceoMessage
  if (!m) return null
  if (m.image_url) return m.image_url
  const img = m.assetsFile?.image ?? m.asset?.image
  if (!img) return null
  if (typeof img === 'string') return img
  if (Array.isArray(img) && img[0]?.data) return `data:${img[0].mime};base64,${img[0].data}`
  return null
})

const videoUrl = computed<string | null>(() => {
  const m = props.ceoMessage
  if (!m) return null
  if (m.video_url) return m.video_url
  const vid = m.assetsFile?.video ?? m.asset?.video
  if (!vid) return null
  try { const p = typeof vid === 'string' ? JSON.parse(vid) : vid; return p.path ? `/storage/${p.path}` : null }
  catch { return null }
})

const hasImage = computed(() => !!imageUrl.value)
const hasVideo = computed(() => !!videoUrl.value)
const hasSlider = computed(() => hasImage.value && hasVideo.value)
const hasMedia = computed(() => hasImage.value || hasVideo.value)

const activeSlide = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

function startAuto() { if (!hasSlider.value) return; timer = setInterval(() => { activeSlide.value = activeSlide.value === 0 ? 1 : 0 }, 5000) }
function stopAuto() { if (timer) { clearInterval(timer); timer = null } }
function goTo(idx: number) { stopAuto(); activeSlide.value = idx }

watch(() => props.isOpen, open => { activeSlide.value = 0; stopAuto(); if (open && hasSlider.value) startAuto() })
onUnmounted(stopAuto)

const messageHtml = computed(() => {
  const m = props.ceoMessage
  if (!m) return null
  return m.message ?? m.summary ?? m.description ?? null
})

function formatDate(d: string): string {
  return new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="isOpen" class="modal-backdrop" @click.self="emit('close')">
        <div class="modal-panel ceo-modal" role="dialog" aria-modal="true" aria-labelledby="ceo-modal-title">

          <div class="modal-header">
            <div class="header-left">
              <div class="header-icon"><i class="fa-solid fa-bullhorn"></i></div>
              <div>
                <p class="eyebrow">{{ t('home_page.ceo_modal.featured') }}</p>
                <h2 id="ceo-modal-title" class="modal-title">{{ ceoMessage?.title ?? t('home_page.hero.ceo_title') }}</h2>
                <p v-if="ceoMessage?.updated_at" class="modal-meta"><i class="fa-regular fa-clock mr-1"></i>{{ formatDate(ceoMessage.updated_at) }}</p>
              </div>
            </div>
            <button class="close-btn" @click="emit('close')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
          </div>

          <div class="modal-body">
            <div v-if="hasMedia" class="media-wrap">
              <template v-if="hasSlider">
                <div class="slide" :class="{ active: activeSlide === 0 }"><img :src="imageUrl!" alt="CEO visual" class="media-img" /></div>
                <div class="slide" :class="{ active: activeSlide === 1 }"><video :src="videoUrl!" controls playsinline preload="metadata" class="media-video"></video></div>
                <div class="dots"><button :class="['dot', { active: activeSlide === 0 }]" @click="goTo(0)" aria-label="Image"><i class="fa-solid fa-image"></i></button><button :class="['dot', { active: activeSlide === 1 }]" @click="goTo(1)" aria-label="Video"><i class="fa-solid fa-play"></i></button></div>
                <div class="progress-track"><div class="progress-fill" :key="activeSlide"></div></div>
              </template>
              <template v-else-if="hasImage"><img :src="imageUrl!" alt="CEO visual" class="media-img solo" /></template>
              <template v-else-if="hasVideo"><video :src="videoUrl!" controls playsinline preload="metadata" class="media-video solo"></video></template>
            </div>
            <div v-else class="media-placeholder"><i class="fa-solid fa-quote-left"></i></div>

            <div class="body-content">
              <div v-if="messageHtml" class="rich-text" v-html="messageHtml"></div>
              <div v-else class="empty-state"><i class="fa-regular fa-file-lines"></i><p>{{ t('home_page.ceo_modal.no_message') }}</p></div>
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn-close" @click="emit('close')"><i class="fa-solid fa-xmark mr-2"></i>{{ t('home_page.ceo_modal.close') }}</button>
          </div>

        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.55); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 9999; padding: 1rem; }
.modal-panel { background: #fff; border-radius: 20px; box-shadow: 0 32px 80px rgba(0,0,0,.22); max-height: 90vh; overflow: hidden; display: flex; flex-direction: column; width: 100%; animation: slideUp .28s cubic-bezier(.34,1.56,.64,1); }
.ceo-modal { max-width: 680px; }
@keyframes slideUp { from { opacity:0; transform:translateY(24px) scale(.97); } to { opacity:1; transform:none; } }
.modal-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 1.4rem 1.5rem 1.2rem; background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); flex-shrink: 0; }
.header-left { display: flex; align-items: flex-start; gap: 1rem; }
.header-icon { width: 46px; height: 46px; border-radius: 13px; background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #fff; flex-shrink: 0; }
.eyebrow { font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,.5); margin: 0 0 3px; }
.modal-title { font-size: 1.15rem; font-weight: 700; color: #fff; margin: 0 0 3px; line-height: 1.3; }
.modal-meta { font-size: 11px; color: rgba(255,255,255,.45); margin: 0; }
.close-btn { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,.12); border: none; color: rgba(255,255,255,.8); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: .95rem; flex-shrink: 0; transition: background .2s; }
.close-btn:hover { background: rgba(255,255,255,.24); }
.modal-body { overflow-y: auto; flex: 1; }
.body-content { padding: 1.5rem; }
.media-wrap { position: relative; background: #000; overflow: hidden; }
.slide { display: none; }
.slide.active { display: block; }
.media-img { width: 100%; height: 280px; object-fit: cover; display: block; }
.media-img.solo { height: 280px; }
.media-video { width: 100%; max-height: 340px; display: block; background: #000; }
.media-video.solo { max-height: 340px; }
.dots { position: absolute; bottom: .65rem; right: .7rem; display: flex; gap: .35rem; z-index: 3; }
.dot { width: 30px; height: 30px; border-radius: 50%; background: rgba(0,0,0,.5); border: 1.5px solid rgba(255,255,255,.3); color: rgba(255,255,255,.75); font-size: .6rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .2s; backdrop-filter: blur(4px); }
.dot.active { background: rgba(255,255,255,.92); color: #0f172a; border-color: transparent; }
.progress-track { position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: rgba(255,255,255,.18); z-index: 2; }
.progress-fill { height: 100%; width: 0; background: rgba(255,255,255,.8); animation: prog 5s linear forwards; }
@keyframes prog { from { width:0; } to { width:100%; } }
.media-placeholder { height: 80px; display: flex; align-items: center; justify-content: center; font-size: 2.8rem; color: #e2e8f0; padding: 1.5rem; }
.rich-text { font-size: .94rem; line-height: 1.82; color: #334155; }
.rich-text :deep(p) { margin-bottom: 1rem; }
.rich-text :deep(h2), .rich-text :deep(h3) { font-weight: 700; margin: 1.5rem 0 .5rem; color: #0f172a; }
.rich-text :deep(ul), .rich-text :deep(ol) { padding-left: 1.5rem; margin-bottom: 1rem; }
.rich-text :deep(li) { margin-bottom: .4rem; }
.rich-text :deep(strong) { color: #0f172a; }
.rich-text :deep(img) { max-width: 100%; border-radius: 10px; margin: 1rem 0; }
.rich-text :deep(a) { color: #3b82f6; text-decoration: underline; }
.rich-text :deep(blockquote) { border-left: 3px solid #e2e8f0; padding-left: 1rem; color: #64748b; margin: 1rem 0; font-style: italic; }
.empty-state { text-align: center; padding: 2.5rem 0; color: #94a3b8; font-size: 1rem; }
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; }
.modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; flex-shrink: 0; }
.btn-close { padding: .55rem 1.25rem; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #fff; color: #475569; font-size: .88rem; font-weight: 600; cursor: pointer; transition: all .2s; display: flex; align-items: center; }
.btn-close:hover { background: #f8fafc; border-color: #cbd5e1; }
.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity .22s ease; }
.modal-fade-enter-from, .modal-fade-leave-to { opacity: 0; }
.modal-fade-enter-active .modal-panel, .modal-fade-leave-active .modal-panel { transition: transform .22s cubic-bezier(.4,0,.2,1), opacity .22s; }
.modal-fade-enter-from .modal-panel { transform: translateY(20px) scale(.97); opacity: 0; }
.modal-fade-leave-to .modal-panel { transform: translateY(10px) scale(.98); opacity: 0; }
</style>
