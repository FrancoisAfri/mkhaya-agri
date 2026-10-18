<script lang="ts" setup>
import { computed, ref, watch } from 'vue'
import type { Content } from '../Store/type'
import DOMPurify from 'dompurify'
import { useReactionStore } from '../Store/ReactionStore'
import { useI18n } from 'vue-i18n'
import "../css/content.css"

const { t } = useI18n()

const props = defineProps<{
    item: Content | null; isOpen: boolean
    getAnniversaryImageUrl?: () => string; getLeaveImageUrl?: () => string
}>()
const emit = defineEmits<{ close: [] }>()

const reactionStore = useReactionStore()
const reactionKey = computed(() => props.item ? `content_${props.item.id}` : '')
const reactionState = computed(() => { const state = reactionStore.getState(reactionKey.value); return state || { like_count: 0, user_liked: false, reaction_id: null, comment_count: 0 } })
const isLikeLoading = computed(() => reactionStore.isLoading(reactionKey.value))
const comments = computed(() => reactionStore.getComments(reactionKey.value))
const commentsLoading = computed(() => reactionStore.isLoadingComments(reactionKey.value))

const isSpecialItem = computed(() => { const kind = (props.item as any)?._kind; return kind === 'Staff Anniversary' || kind === 'Staff Leave' })

watch(() => props.isOpen, (open) => { if (open && props.item && !isSpecialItem.value) { reactionStore.fetchComments(props.item.id, null) } })

const likeAnimating = ref(false)
function handleLike() { if (!props.item || isLikeLoading.value) return; likeAnimating.value = true; setTimeout(() => { likeAnimating.value = false }, 600); reactionStore.toggleLike(props.item.id, null) }

const showCommentBox = ref(false)
const commentText = ref('')
const commentSending = ref(false)
const commentSuccess = ref(false)

async function submitComment() { if (!props.item || !commentText.value.trim()) return; commentSending.value = true; const ok = await reactionStore.addComment(commentText.value.trim(), props.item.id, null); commentSending.value = false; if (ok) { commentText.value = ''; commentSuccess.value = true; setTimeout(() => { commentSuccess.value = false; showCommentBox.value = false }, 2000) } }
async function deleteComment(commentId: number) { if (!props.item) return; await reactionStore.deleteComment(commentId, props.item.id, null) }

const mediaType = computed<'video' | 'image' | null>(() => { if (!props.item) return null; return resolveVideoUrl(props.item) ? 'video' : resolveImageUrl(props.item) ? 'image' : null })
const mediaUrl = computed<string | null>(() => { if (!props.item) return null; if (mediaType.value === 'video') return resolveVideoUrl(props.item!); if (mediaType.value === 'image') return resolveImageUrl(props.item!); return null })

function getPersonPhotoUrl(person: any): string | null { if (!person?.profile_pic) return null; const raw = person.profile_pic; if (typeof raw === 'string') { if (raw.startsWith('data:')) return raw; try { const obj = JSON.parse(raw); if (obj?.data && obj?.mime) return `data:${obj.mime};base64,${obj.data}`; if (obj?.path) return obj.path.startsWith('/') ? obj.path : `/storage/${obj.path}` } catch { if (raw.startsWith('/')) return raw; if (raw.includes('http')) return raw; return `/storage/${raw}` } } if (typeof raw === 'object' && raw !== null) { if (raw.data && raw.mime) return `data:${raw.mime};base64,${raw.data}`; if (raw.path) return raw.path.startsWith('/') ? raw.path : `/storage/${raw.path}` } return null }

function resolveImageUrl(item: Content | any): string | null { const itemType = (item as any)._kind; const personPhoto = getPersonPhotoUrl((item as any).person); if (personPhoto) return personPhoto; if (itemType === 'Staff Anniversary' && props.getAnniversaryImageUrl) return props.getAnniversaryImageUrl(); if (itemType === 'Staff Leave' && props.getLeaveImageUrl) return props.getLeaveImageUrl(); const img = (item as any).asset?.image; const DEFAULT = 'https://plus.unsplash.com/premium_photo-1661777211390-125ba8631767?q=80&w=687&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D'; if (!img) return DEFAULT; if (typeof img === 'string') return img; const arr = img as any; if (Array.isArray(arr) && arr[0]?.data) return `data:${arr[0].mime};base64,${arr[0].data}`; return DEFAULT }
function resolveVideoUrl(item: Content): string | null { const vid = (item.asset as any)?.video; if (!vid) return null; try { const p = typeof vid === 'string' ? JSON.parse(vid) : vid; return p.path ? `/storage/${p.path}` : null } catch { return null } }

function sanitize(html: string | null) { if (!html) return ''; return DOMPurify.sanitize(html) }
function authorName(item: any): string { const kind = (item as any)._kind; if ((kind === 'Staff Anniversary' || kind === 'Staff Leave') && item.person) { return [item.person.first_name, item.person.surname].filter(Boolean).join(' ') || t('home_page.content_modal.unknown') } const u = item.author; if (!u) return t('home_page.content_modal.unknown'); const p = u.person; if (p) return [p.first_name, p.surname].filter(Boolean).join(' ') || u.email; return u.email || t('home_page.content_modal.unknown') }
function authorInitial(item: any): string { const kind = (item as any)._kind; if ((kind === 'Staff Anniversary' || kind === 'Staff Leave') && item.person) { return ((item.person.first_name?.[0] ?? item.person.surname?.[0] ?? '?')).toUpperCase() } const u = item.author; const p = u?.person; return (p?.first_name?.[0] ?? u?.email?.[0] ?? '?').toUpperCase() }
function formatDate(d: string | null): string { if (!d) return '—'; return new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) }
function formatTime(s: string | null, e: string | null): string { if (!s) return ''; return e ? `${s.slice(0, 5)} – ${e.slice(0, 5)}` : s.slice(0, 5) }
function kindLabel(item: Content): string { const kind = (item as any)._kind; if (kind) return kind; return { 1: 'Announcement', 2: 'News', 3: 'Event', 4: 'Internal Notice' }[item.type] ?? 'Update' }
function kindColor(item: Content): string { const kind = (item as any)._kind; if (kind === 'Staff Anniversary') return '#fb7185'; if (kind === 'Staff Leave') return '#38bdf8'; return { 1: '#f59e0b', 2: '#3b82f6', 3: '#10b981', 4: '#8b5cf6' }[item.type] ?? '#64748b' }
</script>

<template>
    <Teleport to="body">
        <Transition name="modal-fade">
            <div v-if="isOpen && item" class="modal-backdrop" @click.self="emit('close')">
                <div class="modal-panel content-modal" role="dialog" aria-modal="true">
                    <div class="content-hero">
                        <video v-if="mediaType === 'video'" :src="mediaUrl!" class="content-hero-media" controls playsinline />
                        <img v-else-if="mediaType === 'image'" :src="mediaUrl!" :alt="item.title" class="content-hero-media" />
                        <div v-else class="content-hero-placeholder"><i class="fa-solid fa-newspaper"></i></div>
                        <div v-if="mediaType !== 'video'" class="hero-gradient" />
                        <button class="hero-close-btn" @click="emit('close')" aria-label="Close"><i class="fa-solid fa-xmark" /></button>
                        <span class="hero-kind-badge" :style="{ background: kindColor(item) }">{{ kindLabel(item) }}</span>
                    </div>
                    <div class="modal-body content-body">
                        <div class="content-meta-row">
                            <div class="content-avatar">{{ authorInitial(item) }}</div>
                            <div class="content-meta-text"><p class="content-author">{{ authorName(item) }}</p><p class="content-date"><i class="fa-regular fa-calendar mr-1" />{{ formatDate(item.created_at) }}</p></div>
                            <div v-if="item.companyUnit" class="content-unit-badge"><i class="fa-solid fa-building mr-1" />{{ item.companyUnit.name }}</div>
                        </div>
                        <h2 class="content-title">{{ item.title }}</h2>
                        <div v-if="item.type === 3 && (item.event_date || item.event_start)" class="event-detail-row">
                            <div v-if="item.event_date" class="event-detail-chip"><i class="fa-regular fa-calendar" /><span>{{ formatDate(item.event_date) }}</span></div>
                            <div v-if="item.event_start" class="event-detail-chip"><i class="fa-regular fa-clock" /><span>{{ formatTime(item.event_start, item.event_end) }}</span></div>
                        </div>
                        <div class="content-divider" />
                        <div v-if="item.description || item.summary" class="content-desc" v-html="sanitize(item.description ?? item.summary)" />
                        <div v-else class="content-empty"><i class="fa-regular fa-file-lines" /><p>{{ t('home_page.content_modal.no_content') }}</p></div>

                        <div v-if="!isSpecialItem" class="comments-section">
                            <div class="comments-header">
                                <h4 class="comments-title"><i class="fa-regular fa-comments mr-2"></i>{{ t('home_page.content_modal.comments') }}<span v-if="reactionState.comment_count > 0" class="comments-count-badge">{{ reactionState.comment_count }}</span></h4>
                                <button class="btn-add-comment" @click="showCommentBox = !showCommentBox"><i :class="showCommentBox ? 'fa-solid fa-xmark' : 'fa-solid fa-plus'" class="mr-1"></i>{{ showCommentBox ? t('home_page.content_modal.cancel') : t('home_page.content_modal.add_comment') }}</button>
                            </div>
                            <Transition name="slide-down">
                                <div v-if="showCommentBox" class="comment-box">
                                    <textarea v-model="commentText" :placeholder="t('home_page.content_modal.write_comment')" class="comment-textarea" rows="3" autofocus></textarea>
                                    <div class="comment-actions-row">
                                        <button class="btn-send-comment" :disabled="!commentText.trim() || commentSending" @click="submitComment">
                                            <template v-if="commentSuccess"><i class="fa-solid fa-check mr-1"></i>{{ t('home_page.content_modal.sent') }}</template>
                                            <template v-else-if="commentSending"><i class="fa-solid fa-spinner fa-spin mr-1"></i>{{ t('home_page.content_modal.sending') }}</template>
                                            <template v-else><i class="fa-solid fa-paper-plane mr-1"></i>{{ t('home_page.content_modal.send') }}</template>
                                        </button>
                                    </div>
                                </div>
                            </Transition>
                            <div v-if="commentsLoading" class="comments-loading"><i class="fa-solid fa-spinner fa-spin mr-2"></i>{{ t('home_page.content_modal.loading_comments') }}</div>
                            <template v-else-if="comments.length > 0">
                                <div class="comment-item" v-for="c in comments" :key="c.id">
                                    <div class="comment-avatar">{{ c.user?.initial ?? '?' }}</div>
                                    <div class="comment-content">
                                        <div class="comment-header-row"><span class="comment-author">{{ c.user?.name ?? t('home_page.content_modal.unknown') }}</span><span class="comment-date">{{ c.created_at }}</span><button v-if="c.is_mine" class="comment-delete-btn" @click="deleteComment(c.id)" :title="t('home_page.content_modal.delete_comment')"><i class="fa-solid fa-trash-can"></i></button></div>
                                        <p class="comment-text">{{ c.comment }}</p>
                                    </div>
                                </div>
                            </template>
                            <div v-else-if="!commentsLoading" class="comments-empty"><i class="fa-regular fa-comment-dots"></i><p>{{ t('home_page.content_modal.no_comments') }}</p></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div v-if="!isSpecialItem" class="footer-actions">
                            <button class="action-btn like-btn" :class="{ liked: reactionState.user_liked, animating: likeAnimating }" @click="handleLike" :disabled="isLikeLoading">
                                <span class="like-icon-wrap"><i :class="reactionState.user_liked ? 'fa-solid fa-heart' : 'fa-regular fa-heart'"></i><span v-if="likeAnimating && !reactionState.user_liked" class="like-burst"><span v-for="n in 6" :key="n" :class="`burst-particle p${n}`"></span></span></span>
                                <Transition name="count-flip"><span :key="reactionState.like_count" class="like-count">{{ reactionState.like_count > 0 ? reactionState.like_count + ' ' : '' }}{{ reactionState.like_count !== 1 ? t('home_page.content_modal.likes') : t('home_page.content_modal.like') }}</span></Transition>
                            </button>
                            <button class="action-btn" :class="{ active: showCommentBox }" @click="showCommentBox = !showCommentBox"><i class="fa-regular fa-comment mr-1"></i>{{ t('home_page.content_modal.comment') }}<span v-if="reactionState.comment_count > 0" class="comment-count-pill">{{ reactionState.comment_count }}</span></button>
                        </div>
                        <button class="btn-close-modal" @click="emit('close')"><i class="fa-solid fa-xmark mr-2" />{{ t('home_page.content_modal.close') }}</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
