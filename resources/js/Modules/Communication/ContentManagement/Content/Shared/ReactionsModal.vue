<script lang="ts" setup>
import { computed, ref, watch } from 'vue'
import type { Content } from '../utils/type'
import DOMPurify from 'dompurify'
import { useReactionStore } from '@/Modules/Dashboard/Pages/Store/ReactionStore'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
    item:   Content | null
    isOpen: boolean
}>()
const emit = defineEmits<{ close: [] }>()

const reactionStore = useReactionStore()

const reactionKey    = computed(() => props.item ? `content_${props.item.id}` : '')
const reactionState  = computed(() => {
    const state = reactionStore.getState(reactionKey.value)
    return state || { like_count: 0, user_liked: false, reaction_id: null, comment_count: 0 }
})
const isLikeLoading  = computed(() => reactionStore.isLoading(reactionKey.value))
const comments       = computed(() => reactionStore.getComments(reactionKey.value))
const commentsLoading = computed(() => reactionStore.isLoadingComments(reactionKey.value))

watch(() => props.isOpen, (open) => {
    if (open && props.item) {
        reactionStore.fetchComments(props.item.id, null)
    }
})

const likeAnimating = ref(false)
function handleLike() {
    if (!props.item || isLikeLoading.value) return
    likeAnimating.value = true
    setTimeout(() => { likeAnimating.value = false }, 600)
    reactionStore.toggleLike(props.item.id, null)
}

const showCommentBox  = ref(false)
const commentText     = ref('')
const commentSending  = ref(false)
const commentSuccess  = ref(false)

async function submitComment() {
    if (!props.item || !commentText.value.trim()) return
    commentSending.value = true
    const ok = await reactionStore.addComment(commentText.value.trim(), props.item.id, null)
    commentSending.value = false
    if (ok) {
        commentText.value = ''
        commentSuccess.value = true
        setTimeout(() => { commentSuccess.value = false; showCommentBox.value = false }, 2000)
    }
}

async function deleteComment(commentId: number) {
    if (!props.item) return
    await reactionStore.deleteComment(commentId, props.item.id, null)
}

function resolveImageUrl(item: Content): string | null {
    const img = item.asset?.image
    if (!img) return null
    if (typeof img === 'string') return img
    const arr = img as any
    if (Array.isArray(arr) && arr[0]?.data) return `data:${arr[0].mime};base64,${arr[0].data}`
    return null
}

function resolveVideoUrl(item: Content): string | null {
    const vid = (item.asset as any)?.video
    if (!vid) return null
    try {
        const p = typeof vid === 'string' ? JSON.parse(vid) : vid
        return p.path ? `/storage/${p.path}` : null
    } catch { return null }
}

const mediaType = computed<'video' | 'image' | null>(() => {
    if (!props.item) return null
    return resolveVideoUrl(props.item) ? 'video' : resolveImageUrl(props.item) ? 'image' : null
})

const mediaUrl = computed<string | null>(() => {
    if (!props.item) return null
    if (mediaType.value === 'video') return resolveVideoUrl(props.item!)
    if (mediaType.value === 'image') return resolveImageUrl(props.item!)
    return null
})

function sanitize(html: string | null) {
    if (!html) return ''
    return DOMPurify.sanitize(html)
}

function authorName(item: Content): string {
    const u = item.author
    if (!u) return t('content_reactions.unknown_author')
    const p = (u as any).person
    if (p) return [p.first_name, p.surname].filter(Boolean).join(' ') || (u as any).email
    return (u as any).email || t('content_reactions.unknown_author')
}

function authorInitial(item: Content): string {
    const u = item.author
    const p = (u as any)?.person
    return (p?.first_name?.[0] ?? (u as any)?.email?.[0] ?? '?').toUpperCase()
}

function formatDate(d: string | null): string {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })
}

function formatTime(s: string | null, e: string | null): string {
    if (!s) return ''
    return e ? `${s.slice(0, 5)} – ${e.slice(0, 5)}` : s.slice(0, 5)
}

const kindLabels: Record<number, string> = {
  1: 'announcement',
  2: 'news',
  3: 'event',
  4: 'internal_notice',
}

function kindLabel(item: Content): string {
  return t(`content_reactions.kind.${kindLabels[item.type] ?? 'news'}`)
}

function kindColor(item: Content): string {
    return { 1: '#f59e0b', 2: '#3b82f6', 3: '#10b981', 4: '#8b5cf6' }[item.type] ?? '#64748b'
}
</script>

<template>
    <Teleport to="body">
        <Transition name="modal-fade">
            <div v-if="isOpen && item" class="modal-backdrop" @click.self="emit('close')">
                <div class="modal-panel content-modal" role="dialog" aria-modal="true">

                    <div class="content-hero">
                        <video v-if="mediaType === 'video'" :src="mediaUrl!" class="content-hero-media" controls playsinline />
                        <img  v-else-if="mediaType === 'image'" :src="mediaUrl!" :alt="item.title" class="content-hero-media" />
                        <div  v-else class="content-hero-placeholder">
                            <i class="fa-solid fa-newspaper"></i>
                        </div>
                        <div v-if="mediaType !== 'video'" class="hero-gradient" />
                        <button class="hero-close-btn" @click="emit('close')" :aria-label="t('content_reactions.close')">
                            <i class="fa-solid fa-xmark" />
                        </button>
                        <span class="hero-kind-badge" :style="{ background: kindColor(item) }">
                            {{ kindLabel(item) }}
                        </span>
                    </div>

                    <div class="modal-body content-body">
                        <div class="content-meta-row">
                            <div class="content-avatar">{{ authorInitial(item) }}</div>
                            <div class="content-meta-text">
                                <p class="content-author">{{ authorName(item) }}</p>
                                <p class="content-date">
                                    <i class="fa-regular fa-calendar mr-1" />
                                    {{ formatDate(item.created_at) }}
                                </p>
                            </div>
                            <div v-if="item.companyUnit" class="content-unit-badge">
                                <i class="fa-solid fa-building mr-1" />
                                {{ item.companyUnit.name }}
                            </div>
                        </div>

                        <h2 class="content-title">{{ item.title }}</h2>

                        <div v-if="item.type === 3 && (item.event_date || item.event_start)" class="event-detail-row">
                            <div v-if="item.event_date" class="event-detail-chip">
                                <i class="fa-regular fa-calendar" />
                                <span>{{ formatDate(item.event_date) }}</span>
                            </div>
                            <div v-if="item.event_start" class="event-detail-chip">
                                <i class="fa-regular fa-clock" />
                                <span>{{ formatTime(item.event_start, item.event_end) }}</span>
                            </div>
                        </div>

                        <div class="content-divider" />

                        <div v-if="item.description || item.summary" class="content-desc"
                            v-html="sanitize(item.description ?? item.summary)" />
                        <div v-else class="content-empty">
                            <i class="fa-regular fa-file-lines" />
                            <p>{{ t('content_reactions.no_content') }}</p>
                        </div>

                        <div class="comments-section">
                            <div class="comments-header">
                                <h4 class="comments-title">
                                    <i class="fa-regular fa-comments mr-2"></i>
                                    {{ t('content_reactions.comments') }}
                                    <span v-if="reactionState.comment_count > 0" class="comments-count-badge">
                                        {{ reactionState.comment_count }}
                                    </span>
                                </h4>
                                <button class="btn-add-comment" @click="showCommentBox = !showCommentBox">
                                    <i :class="showCommentBox ? 'fa-solid fa-xmark' : 'fa-solid fa-plus'" class="mr-1"></i>
                                    {{ showCommentBox ? t('content_reactions.cancel') : t('content_reactions.add_comment') }}
                                </button>
                            </div>

                            <Transition name="slide-down">
                                <div v-if="showCommentBox" class="comment-box">
                                    <textarea
                                        v-model="commentText"
                                        :placeholder="t('content_reactions.write_comment')"
                                        class="comment-textarea"
                                        rows="3"
                                        autofocus
                                    ></textarea>
                                    <div class="comment-actions-row">
                                        <button class="btn-send-comment"
                                            :disabled="!commentText.trim() || commentSending"
                                            @click="submitComment"
                                        >
                                            <template v-if="commentSuccess">
                                                <i class="fa-solid fa-check mr-1"></i>{{ t('content_reactions.sent') }}
                                            </template>
                                            <template v-else-if="commentSending">
                                                <i class="fa-solid fa-spinner fa-spin mr-1"></i>{{ t('content_reactions.sending') }}
                                            </template>
                                            <template v-else>
                                                <i class="fa-solid fa-paper-plane mr-1"></i>{{ t('content_reactions.send') }}
                                            </template>
                                        </button>
                                    </div>
                                </div>
                            </Transition>

                            <div v-if="commentsLoading" class="comments-loading">
                                <i class="fa-solid fa-spinner fa-spin mr-2"></i>{{ t('content_reactions.loading_comments') }}
                            </div>

                            <template v-else-if="comments.length > 0">
                                <div class="comment-item" v-for="c in comments" :key="c.id">
                                    <div class="comment-avatar">{{ c.user?.initial ?? '?' }}</div>
                                    <div class="comment-content">
                                        <div class="comment-header-row">
                                            <span class="comment-author">{{ c.user?.name ?? t('content_reactions.unknown_author') }}</span>
                                            <span class="comment-date">{{ c.created_at }}</span>
                                            <button
                                                v-if="c.is_mine"
                                                class="comment-delete-btn"
                                                @click="deleteComment(c.id)"
                                                :title="t('content_reactions.delete_comment')"
                                            >
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </div>
                                        <p class="comment-text">{{ c.comment }}</p>
                                    </div>
                                </div>
                            </template>

                            <div v-else-if="!commentsLoading" class="comments-empty">
                                <i class="fa-regular fa-comment-dots"></i>
                                <p>{{ t('content_reactions.no_comments') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <div class="footer-actions">
                            <button
                                class="action-btn like-btn"
                                :class="{ liked: reactionState.user_liked, animating: likeAnimating }"
                                @click="handleLike"
                                :disabled="isLikeLoading"
                            >
                                <span class="like-icon-wrap">
                                    <i :class="reactionState.user_liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart'"></i>
                                    <span v-if="likeAnimating && !reactionState.user_liked" class="like-burst">
                                        <span v-for="n in 6" :key="n" :class="`burst-particle p${n}`"></span>
                                    </span>
                                </span>
                                <Transition name="count-flip">
                                    <span :key="reactionState.like_count" class="like-count">
                                        {{ reactionState.like_count > 0 ? reactionState.like_count + ' ' : '' }}{{ reactionState.like_count !== 1 ? t('content_reactions.likes') : t('content_reactions.like') }}
                                    </span>
                                </Transition>
                            </button>

                            <button class="action-btn" :class="{ active: showCommentBox }" @click="showCommentBox = !showCommentBox">
                                <i class="fa-regular fa-comment mr-1"></i>
                                {{ t('content_reactions.comment') }}
                                <span v-if="reactionState.comment_count > 0" class="comment-count-pill">
                                    {{ reactionState.comment_count }}
                                </span>
                            </button>
                        </div>
                        <button class="btn-close-modal" @click="emit('close')">
                            <i class="fa-solid fa-xmark mr-2" />{{ t('content_reactions.close') }}
                        </button>
                    </div>

                </div>
            </div>
        </Transition>
    </Teleport>

    <Teleport to="body">
        <VSnackbar v-model="reactionStore.snackbar" :timeout="3500" :color="reactionStore.snackColor" location="bottom right" rounded="lg">
            <div class="d-flex align-center gap-2">
                <i :class="reactionStore.snackColor === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'"
                    style="font-size:16px" />
                {{ reactionStore.snackMsg }}
            </div>
        </VSnackbar>
    </Teleport>
</template>

<style scoped>
.modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999; padding: 1rem;
}
.modal-panel {
    background: #fff; border-radius: 20px;
    box-shadow: 0 32px 80px rgba(0,0,0,.22);
    max-height: 90vh; overflow: hidden;
    display: flex; flex-direction: column;
    width: 100%; max-width: 720px;
}
.content-hero { position: relative; flex-shrink: 0; overflow: hidden; background: #000; }
.content-hero-media { width: 100%; display: block; max-height: 340px; object-fit: cover; }
video.content-hero-media { object-fit: contain; background: #000; }
.content-hero-placeholder {
    height: 200px;
    background: linear-gradient(135deg, #1e3a5f, #0f4c81);
    display: flex; align-items: center; justify-content: center;
    font-size: 4rem; color: rgba(255,255,255,.2);
}
.hero-gradient {
    position: absolute; bottom: 0; left: 0; right: 0; height: 80px;
    background: linear-gradient(to top, rgba(0,0,0,.35), transparent);
    pointer-events: none;
}
.hero-close-btn {
    position: absolute; top: .75rem; right: .75rem;
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(0,0,0,.45); border: none; color: #fff; cursor: pointer;
    display: flex; align-items: center; justify-content: center; font-size: 1rem;
    backdrop-filter: blur(4px); transition: background .2s; z-index: 2;
}
.hero-close-btn:hover { background: rgba(0,0,0,.65); }
.hero-kind-badge {
    position: absolute; bottom: .75rem; left: .75rem;
    color: #fff; font-size: 11px; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
    padding: 4px 10px; border-radius: 20px;
}
.modal-body { overflow-y: auto; flex: 1; }
.content-body { padding: 1.5rem; }
.content-meta-row { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; flex-wrap: wrap; }
.content-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, #1e40af, #3b82f6);
    color: #fff; font-size: .85rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.content-meta-text { flex: 1; }
.content-author { font-size: .88rem; font-weight: 600; color: #0f172a; margin: 0; }
.content-date   { font-size: .78rem; color: #94a3b8; margin: 0; }
.content-unit-badge {
    margin-left: auto; font-size: 11px; font-weight: 600; color: #1d4ed8;
    background: #eff6ff; border: 1px solid #bfdbfe; padding: 3px 10px; border-radius: 20px;
}
.content-title { font-size: 1.35rem; font-weight: 800; color: #0f172a; margin: 0 0 1rem; line-height: 1.35; }
.event-detail-row { display: flex; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem; }
.event-detail-chip {
    display: flex; align-items: center; gap: .4rem;
    font-size: .83rem; font-weight: 600; color: #059669;
    background: #ecfdf5; border: 1px solid #a7f3d0; padding: 4px 12px; border-radius: 20px;
}
.content-divider { height: 1px; background: #f1f5f9; margin-bottom: 1.25rem; }
.content-desc { font-size: .93rem; line-height: 1.85; color: #334155; }
.content-desc :deep(p)      { margin-bottom: 1rem; }
.content-desc :deep(h2),
.content-desc :deep(h3)     { font-weight: 700; margin: 1.5rem 0 .5rem; color: #0f172a; }
.content-desc :deep(ul),
.content-desc :deep(ol)     { padding-left: 1.5rem; margin-bottom: 1rem; }
.content-desc :deep(li)     { margin-bottom: .4rem; }
.content-desc :deep(strong) { color: #0f172a; }
.content-desc :deep(img)    { max-width: 100%; border-radius: 10px; margin: 1rem 0; }
.content-empty { text-align: center; padding: 2rem 0; color: #94a3b8; }
.content-empty i { font-size: 2.5rem; display: block; margin-bottom: .75rem; }

.comments-section { margin-top: 1.5rem; border-top: 1px solid #f1f5f9; padding-top: 1.25rem; }
.comments-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.comments-title {
    font-size: .95rem; font-weight: 700; color: #0f172a; margin: 0;
    display: flex; align-items: center; gap: .5rem;
}
.comments-count-badge {
    background: #3b82f6; color: #fff;
    font-size: 11px; font-weight: 700;
    padding: 2px 7px; border-radius: 20px;
}
.btn-add-comment {
    padding: .35rem .8rem; border-radius: 8px;
    border: 1.5px solid #e2e8f0; background: #fff;
    color: #475569; font-size: .8rem; font-weight: 600;
    cursor: pointer; transition: all .2s;
    display: flex; align-items: center;
}
.btn-add-comment:hover { background: #f8fafc; border-color: #94a3b8; }

.comment-box {
    margin-bottom: 1rem; padding: 1rem;
    background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;
}
.comment-textarea {
    width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: .6rem .8rem; font-size: .88rem; color: #334155;
    resize: none; outline: none; font-family: inherit; background: #fff;
    transition: border-color .2s; box-sizing: border-box;
}
.comment-textarea:focus { border-color: #3b82f6; }
.comment-actions-row { display: flex; justify-content: flex-end; margin-top: .5rem; }
.btn-send-comment {
    padding: .4rem .9rem; border-radius: 8px; border: none;
    background: #3b82f6; color: #fff; font-size: .82rem; font-weight: 600;
    cursor: pointer; transition: all .2s; display: flex; align-items: center;
}
.btn-send-comment:hover:not(:disabled) { background: #2563eb; }
.btn-send-comment:disabled { opacity: .6; cursor: default; }

.comments-loading { text-align: center; padding: 1rem 0; color: #94a3b8; font-size: .88rem; }

.comment-item {
    display: flex; gap: .75rem;
    padding: .75rem 0; border-bottom: 1px solid #f8fafc;
}
.comment-item:last-child { border-bottom: none; }
.comment-avatar {
    width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff; font-size: .8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
.comment-content { flex: 1; }
.comment-header-row {
    display: flex; align-items: center; gap: .5rem;
    margin-bottom: .25rem; flex-wrap: wrap;
}
.comment-author { font-size: .83rem; font-weight: 700; color: #0f172a; }
.comment-date   { font-size: .75rem; color: #94a3b8; }
.comment-delete-btn {
    margin-left: auto; background: none; border: none;
    color: #cbd5e1; cursor: pointer; font-size: .75rem;
    padding: 2px 4px; border-radius: 4px; transition: color .2s;
}
.comment-delete-btn:hover { color: #ef4444; }
.comment-text { font-size: .88rem; color: #334155; line-height: 1.5; margin: 0; }

.comments-empty { text-align: center; padding: 1.5rem 0; color: #94a3b8; font-size: .88rem; }
.comments-empty i { font-size: 1.8rem; display: block; margin-bottom: .5rem; }

.modal-footer {
    padding: 1rem 1.5rem; border-top: 1px solid #f0f0f0;
    display: flex; align-items: center; justify-content: space-between;
    flex-shrink: 0; flex-wrap: wrap; gap: .5rem;
}
.footer-actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
.action-btn {
    padding: .45rem .9rem; border-radius: 10px;
    border: 1.5px solid #e2e8f0; background: #fff;
    color: #64748b; font-size: .82rem; font-weight: 600;
    cursor: pointer; transition: all .2s;
    display: flex; align-items: center; gap: .35rem;
}
.action-btn:hover  { background: #f8fafc; border-color: #94a3b8; color: #1e293b; }
.action-btn.active { background: #f0f9ff; border-color: #3b82f6; color: #1d4ed8; }

.like-btn { position: relative; overflow: visible; }
.like-btn.liked { background: #fff0f0; border-color: #fca5a5; color: #ef4444; }
.like-btn.liked:hover { background: #fee2e2; }
.like-btn:disabled { opacity: .7; cursor: default; }
.like-icon-wrap { position: relative; display: inline-flex; }
.like-btn.animating .fa-heart { animation: likeBounce .5s cubic-bezier(.36,.07,.19,.97) both; }
@keyframes likeBounce {
    0%   { transform: scale(1); }
    25%  { transform: scale(1.5); }
    50%  { transform: scale(.9); }
    75%  { transform: scale(1.2); }
    100% { transform: scale(1); }
}

.like-burst { position: absolute; top: 50%; left: 50%; pointer-events: none; }
.burst-particle { position: absolute; width: 6px; height: 6px; border-radius: 50%; animation: burst .5s ease-out forwards; }
.p1{background:#f87171;--tx:16px;--ty:-14px}.p2{background:#fb923c;--tx:-16px;--ty:-14px}
.p3{background:#facc15;--tx:20px;--ty:0px}.p4{background:#a78bfa;--tx:-20px;--ty:0px}
.p5{background:#34d399;--tx:12px;--ty:16px}.p6{background:#60a5fa;--tx:-12px;--ty:16px}
@keyframes burst {
    0%   { transform: translate(-50%,-50%) scale(1); opacity: 1; }
    100% { transform: translate(calc(-50% + var(--tx,0px)),calc(-50% + var(--ty,0px))) scale(0); opacity: 0; }
}

.like-count { display: inline-block; }
.count-flip-enter-active { animation: countUp .25s ease; }
.count-flip-leave-active { animation: countDown .25s ease; position: absolute; }
@keyframes countUp   { from { transform: translateY(8px);  opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@keyframes countDown { from { transform: translateY(0);    opacity: 1; } to { transform: translateY(-8px); opacity: 0; } }

.comment-count-pill {
    background: #e2e8f0; color: #475569;
    font-size: 11px; font-weight: 700;
    padding: 1px 6px; border-radius: 20px;
}
.action-btn.active .comment-count-pill { background: #bfdbfe; color: #1d4ed8; }

.slide-down-enter-active { transition: all .25s ease; }
.slide-down-leave-active { transition: all .2s ease; }
.slide-down-enter-from   { opacity: 0; transform: translateY(-8px); }
.slide-down-leave-to     { opacity: 0; transform: translateY(-8px); }

.btn-close-modal {
    padding: .55rem 1.25rem; border-radius: 10px;
    border: 1.5px solid #e2e8f0; background: #fff;
    color: #475569; font-size: .88rem; font-weight: 600;
    cursor: pointer; transition: all .2s; display: flex; align-items: center;
}
.btn-close-modal:hover { background: #f8fafc; border-color: #cbd5e1; }

.modal-fade-enter-active, .modal-fade-leave-active { transition: opacity .22s ease; }
.modal-fade-enter-from,   .modal-fade-leave-to     { opacity: 0; }
.modal-fade-enter-active .modal-panel,
.modal-fade-leave-active .modal-panel { transition: transform .22s cubic-bezier(.4,0,.2,1), opacity .22s; }
.modal-fade-enter-from .modal-panel { transform: translateY(20px) scale(.97); opacity: 0; }
.modal-fade-leave-to .modal-panel   { transform: translateY(10px) scale(.98); opacity: 0; }
</style>
