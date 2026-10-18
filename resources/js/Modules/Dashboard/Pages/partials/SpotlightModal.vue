<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import type { EmployeeSpotlight } from '../Store/type'
import { useReactionStore } from '../Store/ReactionStore'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{ spotlight: EmployeeSpotlight | null; isOpen: boolean }>()
const emit = defineEmits<{ close: [] }>()

const reactionStore = useReactionStore()
const reactionKey = computed(() => props.spotlight ? `spotlight_${props.spotlight.id}` : '')
const reactionState = computed(() => { const state = reactionStore.getState(reactionKey.value); return state || { like_count: 0, user_liked: false, reaction_id: null, comment_count: 0 } })
const isLoading = computed(() => reactionStore.isLoading(reactionKey.value))
const comments = computed(() => reactionStore.getComments(reactionKey.value))
const commentsLoading = computed(() => reactionStore.isLoadingComments(reactionKey.value))

watch(() => props.isOpen, (open) => { if (open && props.spotlight) { reactionStore.fetchComments(null, props.spotlight.id) } })

const congratAnimating = ref(false)
function handleCongrat() { if (!props.spotlight || isLoading.value) return; congratAnimating.value = true; setTimeout(() => { congratAnimating.value = false }, 600); reactionStore.toggleLike(null, props.spotlight.id) }

const showCommentBox = ref(false)
const commentText = ref('')
const commentSending = ref(false)
const commentSuccess = ref(false)

async function submitComment() { if (!props.spotlight || !commentText.value.trim()) return; commentSending.value = true; const ok = await reactionStore.addComment(commentText.value.trim(), null, props.spotlight.id); commentSending.value = false; if (ok) { commentText.value = ''; commentSuccess.value = true; setTimeout(() => { commentSuccess.value = false; showCommentBox.value = false }, 2000) } }
async function deleteComment(commentId: number) { if (!props.spotlight) return; await reactionStore.deleteComment(commentId, null, props.spotlight.id) }

const spotlightTypeConfig: Record<number, { label: string; icon: string; color: string; bg: string }> = {
    1: { label: t('home_page.spotlights.award'), icon: 'fa-trophy', color: '#FF9F43', bg: '#fff8ef' },
    2: { label: t('home_page.spotlights.anniversary'), icon: 'fa-cake-candles', color: '#00CFE8', bg: '#edfafe' },
    3: { label: t('home_page.spotlights.competition'), icon: 'fa-medal', color: '#28C76F', bg: '#edfaf3' },
}
function getCfg(type: number) { return spotlightTypeConfig[type] ?? { label: 'Spotlight', icon: 'fa-star', color: '#8b5cf6', bg: '#f5f3ff' } }
function getImageUrl(s: EmployeeSpotlight): string | null { const img = s.asset?.image; if (!img) return null; if (typeof img === 'string') return img; const arr = img as any; if (Array.isArray(arr) && arr[0]?.data) return `data:${arr[0].mime};base64,${arr[0].data}`; return null }
function employeeName(s: EmployeeSpotlight): string { const p = s.employee?.person; if (p) return [p.first_name, p.surname].filter(Boolean).join(' ') || s.employee?.email || '—'; return s.employee?.email || '—' }
function employeeInitial(s: EmployeeSpotlight): string { const p = s.employee?.person; return (p?.first_name?.[0] ?? s.employee?.email?.[0] ?? '?').toUpperCase() }
function formatMonth(val: string) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-US', { day: '2-digit', month: 'long', year: 'numeric' }) }
</script>

<template>
  <Teleport to="body">
    <Transition name="modal-fade">
      <div v-if="isOpen && spotlight" class="modal-backdrop" @click.self="emit('close')">
        <div class="modal-panel spot-modal" role="dialog" aria-modal="true">
          <div class="spot-hero">
            <img v-if="getImageUrl(spotlight)" :src="getImageUrl(spotlight)!" :alt="spotlight.title" class="spot-hero-img" />
            <div v-else class="spot-hero-placeholder" :style="{ background: `linear-gradient(135deg, ${getCfg(spotlight.type).color}33, ${getCfg(spotlight.type).color}88)` }"><i :class="`fa-solid ${getCfg(spotlight.type).icon}`" :style="{ color: getCfg(spotlight.type).color, fontSize: '4rem', opacity: .7 }"></i></div>
            <button class="hero-close-btn" @click="emit('close')" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            <div class="hero-gradient"></div>
          </div>
          <div class="spot-body">
            <div class="spot-type-badge" :style="{ background: getCfg(spotlight.type).bg, color: getCfg(spotlight.type).color, border: `1.5px solid ${getCfg(spotlight.type).color}33` }"><i :class="`fa-solid ${getCfg(spotlight.type).icon} mr-1`"></i>{{ getCfg(spotlight.type).label }}</div>
            <h2 class="spot-title">{{ spotlight.title }}</h2>
            <div class="spot-employee-card">
                <div class="spot-emp-avatar" :style="{ background: `linear-gradient(135deg, ${getCfg(spotlight.type).color}44, ${getCfg(spotlight.type).color}99)`, color: getCfg(spotlight.type).color }">{{ employeeInitial(spotlight) }}</div>
                <div class="spot-emp-info"><p class="spot-emp-name">{{ employeeName(spotlight) }}</p><p v-if="spotlight.companyUnit" class="spot-emp-dept"><i class="fa-solid fa-building mr-1 opacity-60"></i>{{ spotlight.companyUnit.name }}</p><p v-if="spotlight.month" class="spot-emp-month"><i class="fa-regular fa-calendar mr-1 opacity-60"></i>{{ formatMonth(spotlight.month) }}</p></div>
            </div>
            <div v-if="spotlight.description" class="spot-desc" v-html="spotlight.description"></div>
            <div v-else class="spot-empty"><i class="fa-regular fa-star"></i><p>{{ t('home_page.spotlights.celebrating') }}</p></div>

            <div class="comments-section">
                <div class="comments-header">
                    <h4 class="comments-title"><i class="fa-regular fa-comments mr-2"></i>{{ t('home_page.spotlight_modal.comments') }}<span v-if="reactionState.comment_count > 0" class="comments-count-badge">{{ reactionState.comment_count }}</span></h4>
                    <button class="btn-add-comment" @click="showCommentBox = !showCommentBox"><i :class="showCommentBox ? 'fa-solid fa-xmark' : 'fa-solid fa-plus'" class="mr-1"></i>{{ showCommentBox ? t('home_page.spotlight_modal.cancel') : t('home_page.spotlight_modal.add_comment') }}</button>
                </div>
                <Transition name="slide-down">
                    <div v-if="showCommentBox" class="comment-box">
                        <textarea v-model="commentText" :placeholder="t('home_page.content_modal.write_comment')" class="comment-textarea" rows="3" autofocus></textarea>
                        <div class="comment-actions-row">
                            <button class="btn-send-comment" :disabled="!commentText.trim() || commentSending" @click="submitComment">
                                <template v-if="commentSuccess"><i class="fa-solid fa-check mr-1"></i>{{ t('home_page.spotlight_modal.sent') }}</template>
                                <template v-else-if="commentSending"><i class="fa-solid fa-spinner fa-spin mr-1"></i>{{ t('home_page.spotlight_modal.sending') }}</template>
                                <template v-else><i class="fa-solid fa-paper-plane mr-1"></i>{{ t('home_page.spotlight_modal.send') }}</template>
                            </button>
                        </div>
                    </div>
                </Transition>
                <div v-if="commentsLoading" class="comments-loading"><i class="fa-solid fa-spinner fa-spin mr-2"></i>{{ t('home_page.spotlight_modal.loading_comments') }}</div>
                <template v-else-if="comments.length > 0">
                    <div class="comment-item" v-for="c in comments" :key="c.id">
                        <div class="comment-avatar">{{ c.user?.initial ?? '?' }}</div>
                        <div class="comment-content">
                            <div class="comment-header-row"><span class="comment-author">{{ c.user?.name ?? t('home_page.spotlight_modal.unknown') }}</span><span class="comment-date">{{ c.created_at }}</span><button v-if="c.is_mine" class="comment-delete-btn" @click="deleteComment(c.id)" :title="t('home_page.spotlight_modal.delete_comment')"><i class="fa-solid fa-trash-can"></i></button></div>
                            <p class="comment-text">{{ c.comment }}</p>
                        </div>
                    </div>
                </template>
                <div v-else class="comments-empty"><i class="fa-regular fa-comment-dots"></i><p>{{ t('home_page.spotlight_modal.no_comments') }}</p></div>
            </div>
          </div>
          <div class="spot-footer">
            <div class="spot-actions">
                <button class="action-btn congrat-btn" :class="{ liked: reactionState.user_liked, animating: congratAnimating }" :disabled="isLoading" @click="handleCongrat">
                    <span class="like-icon-wrap"><i :class="reactionState.user_liked ? 'fa-solid fa-trophy' : 'fa-regular fa-thumbs-up'"></i><span v-if="congratAnimating && !reactionState.user_liked" class="like-burst"><span v-for="n in 6" :key="n" :class="`burst-particle p${n}`"></span></span></span>
                    <Transition name="count-flip"><span :key="reactionState.like_count">{{ reactionState.like_count > 0 ? reactionState.like_count + ' ' : '' }}{{ t('home_page.spotlight_modal.congratulate') }}</span></Transition>
                </button>
                <button class="action-btn" :class="{ active: showCommentBox }" @click="showCommentBox = !showCommentBox"><i class="fa-regular fa-comment mr-1"></i>{{ t('home_page.spotlight_modal.comment') }}<span v-if="reactionState.comment_count > 0" class="comment-count-pill">{{ reactionState.comment_count }}</span></button>
            </div>
            <button class="btn-secondary" @click="emit('close')"><i class="fa-solid fa-xmark mr-2"></i>{{ t('home_page.spotlight_modal.close') }}</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:9999;padding:1rem; }
.modal-panel { background:#fff;border-radius:20px;box-shadow:0 32px 80px rgba(0,0,0,.22);max-height:90vh;overflow:hidden;display:flex;flex-direction:column;width:100%;animation:slideUp .28s cubic-bezier(.34,1.56,.64,1); }
.spot-modal { max-width:580px; }
@keyframes slideUp { from{opacity:0;transform:translateY(24px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
.spot-hero { position:relative;height:240px;flex-shrink:0;overflow:hidden; }
.spot-hero-img { width:100%;height:100%;object-fit:cover;display:block; }
.spot-hero-placeholder { width:100%;height:100%;display:flex;align-items:center;justify-content:center; }
.hero-gradient { position:absolute;bottom:0;left:0;right:0;height:100px;background:linear-gradient(to top,rgba(0,0,0,.4),transparent);pointer-events:none; }
.hero-close-btn { position:absolute;top:.75rem;right:.75rem;width:34px;height:34px;border-radius:50%;background:rgba(0,0,0,.45);border:none;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1rem;backdrop-filter:blur(4px);transition:background .2s;z-index:2; }
.hero-close-btn:hover { background:rgba(0,0,0,.65); }
.spot-body { padding:1.5rem;overflow-y:auto;flex:1; }
.spot-type-badge { display:inline-flex;align-items:center;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:4px 12px;border-radius:20px;margin-bottom:1rem; }
.spot-title { font-size:1.3rem;font-weight:800;color:#0f172a;line-height:1.35;margin:0 0 1.25rem; }
.spot-employee-card { display:flex;align-items:center;gap:1rem;padding:1rem;background:#f8fafc;border-radius:14px;border:1px solid #e2e8f0;margin-bottom:1.5rem; }
.spot-emp-avatar { width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:800;flex-shrink:0; }
.spot-emp-name { font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 3px; }
.spot-emp-dept,.spot-emp-month { font-size:.78rem;color:#64748b;margin:0 0 2px; }
.spot-desc { font-size:.93rem;line-height:1.85;color:#334155; }
.spot-desc :deep(p) { margin-bottom:.85rem; }
.spot-desc :deep(strong) { color:#0f172a; }
.spot-empty { text-align:center;padding:1.5rem 0;color:#94a3b8;font-size:1rem; }
.spot-empty i { font-size:2rem;display:block;margin-bottom:.5rem; }
.comments-section { margin-top:1.5rem;border-top:1px solid #f1f5f9;padding-top:1.25rem; }
.comments-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem; }
.comments-title { font-size:.95rem;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:.5rem; }
.comments-count-badge { background:#3b82f6;color:#fff;font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px; }
.btn-add-comment { padding:.35rem .8rem;border-radius:8px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center; }
.btn-add-comment:hover { background:#f8fafc;border-color:#94a3b8; }
.comment-box { margin-bottom:1rem;padding:1rem;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0; }
.comment-textarea { width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:.6rem .8rem;font-size:.88rem;color:#334155;resize:none;outline:none;font-family:inherit;background:#fff;transition:border-color .2s;box-sizing:border-box; }
.comment-textarea:focus { border-color:#3b82f6; }
.comment-actions-row { display:flex;justify-content:flex-end;margin-top:.5rem; }
.btn-send-comment { padding:.4rem .9rem;border-radius:8px;border:none;background:#3b82f6;color:#fff;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center; }
.btn-send-comment:hover:not(:disabled) { background:#2563eb; }
.btn-send-comment:disabled { opacity:.6;cursor:default; }
.comments-loading { text-align:center;padding:1rem 0;color:#94a3b8;font-size:.88rem; }
.comment-item { display:flex;gap:.75rem;padding:.75rem 0;border-bottom:1px solid #f8fafc; }
.comment-item:last-child { border-bottom:none; }
.comment-avatar { width:34px;height:34px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:.8rem;font-weight:700;display:flex;align-items:center;justify-content:center; }
.comment-content { flex:1; }
.comment-header-row { display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem;flex-wrap:wrap; }
.comment-author { font-size:.83rem;font-weight:700;color:#0f172a; }
.comment-date { font-size:.75rem;color:#94a3b8; }
.comment-delete-btn { margin-left:auto;background:none;border:none;color:#cbd5e1;cursor:pointer;font-size:.75rem;padding:2px 4px;border-radius:4px;transition:color .2s; }
.comment-delete-btn:hover { color:#ef4444; }
.comment-text { font-size:.88rem;color:#334155;line-height:1.5;margin:0; }
.comments-empty { text-align:center;padding:1.5rem 0;color:#94a3b8;font-size:.88rem; }
.comments-empty i { font-size:1.8rem;display:block;margin-bottom:.5rem; }
.spot-footer { padding:1rem 1.5rem;border-top:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0; }
.spot-actions { display:flex;gap:.5rem;flex-wrap:wrap; }
.action-btn { padding:.45rem .9rem;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:.35rem; }
.action-btn:hover { background:#f8fafc;border-color:#94a3b8;color:#1e293b; }
.action-btn.active { background:#f0f9ff;border-color:#3b82f6;color:#1d4ed8; }
.btn-secondary { padding:.55rem 1.25rem;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:.88rem;font-weight:600;cursor:pointer;transition:all .2s;display:flex;align-items:center; }
.btn-secondary:hover { background:#f8fafc;border-color:#cbd5e1; }
.like-icon-wrap { position:relative;display:inline-flex; }
.congrat-btn.liked { background:#fff8ef;border-color:#fbbf24;color:#d97706; }
.congrat-btn.liked:hover { background:#fef3c7; }
.congrat-btn:disabled { opacity:.7;cursor:default; }
.congrat-btn.animating i { animation:likeBounce .5s cubic-bezier(.36,.07,.19,.97) both; }
@keyframes likeBounce { 0%{transform:scale(1)} 25%{transform:scale(1.5)} 50%{transform:scale(.9)} 75%{transform:scale(1.2)} 100%{transform:scale(1)} }
.like-burst { position:absolute;top:50%;left:50%;pointer-events:none; }
.burst-particle { position:absolute;width:6px;height:6px;border-radius:50%;animation:burst .5s ease-out forwards; }
.p1{background:#f87171;--tx:16px;--ty:-14px}.p2{background:#fb923c;--tx:-16px;--ty:-14px}.p3{background:#facc15;--tx:20px;--ty:0px}.p4{background:#a78bfa;--tx:-20px;--ty:0px}.p5{background:#34d399;--tx:12px;--ty:16px}.p6{background:#60a5fa;--tx:-12px;--ty:16px}
@keyframes burst { 0%{transform:translate(-50%,-50%) scale(1);opacity:1} 100%{transform:translate(calc(-50% + var(--tx,0px)),calc(-50% + var(--ty,0px))) scale(0);opacity:0} }
.count-flip-enter-active { animation:countUp .25s ease; }
.count-flip-leave-active { animation:countDown .25s ease;position:absolute; }
@keyframes countUp { from{transform:translateY(8px);opacity:0} to{transform:translateY(0);opacity:1} }
@keyframes countDown { from{transform:translateY(0);opacity:1} to{transform:translateY(-8px);opacity:0} }
.comment-count-pill { background:#e2e8f0;color:#475569;font-size:11px;font-weight:700;padding:1px 6px;border-radius:20px; }
.action-btn.active .comment-count-pill { background:#bfdbfe;color:#1d4ed8; }
.slide-down-enter-active { transition:all .25s ease; }
.slide-down-leave-active { transition:all .2s ease; }
.slide-down-enter-from,.slide-down-leave-to { opacity:0;transform:translateY(-8px); }
.modal-fade-enter-active,.modal-fade-leave-active { transition:opacity .22s ease; }
.modal-fade-enter-from,.modal-fade-leave-to { opacity:0; }
.modal-fade-enter-active .modal-panel,.modal-fade-leave-active .modal-panel { transition:transform .22s cubic-bezier(.4,0,.2,1),opacity .22s; }
.modal-fade-enter-from .modal-panel { transform:translateY(20px) scale(.97);opacity:0; }
.modal-fade-leave-to .modal-panel { transform:translateY(10px) scale(.98);opacity:0; }
</style>
