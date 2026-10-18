// Store/ReactionStore.ts
import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'

interface ReactionState {
    like_count: number
    user_liked: boolean
    reaction_id: number | null
    comment_count: number
}

interface Comment {
    id: number
    comment: string
    created_at: string
    is_mine: boolean
    user: {
        id: number
        name: string
        email: string
        initial: string
    } | null
}

type ReactionMap  = Record<string, ReactionState>
type CommentsMap  = Record<string, Comment[]>

export const useReactionStore = defineStore('reactions', {  // ← ID simple sans slash
    state: () => ({
        reactions:       {} as ReactionMap,
        comments:        {} as CommentsMap,
        loadingKeys:     {} as Record<string, boolean>,
        loadingComments: {} as Record<string, boolean>,
        snackbar:        false,
        snackMsg:        '',
        snackColor:      'success' as 'success' | 'error',
    }),

    getters: {
        isLoading: (state) => (key: string): boolean =>
            !!state.loadingKeys[key],

        getState: (state) => (key: string): ReactionState =>
            state.reactions[key] ?? { like_count: 0, user_liked: false, reaction_id: null, comment_count: 0 },

        getComments: (state) => (key: string): Comment[] =>
            state.comments[key] ?? [],

        isLoadingComments: (state) => (key: string): boolean =>
            !!state.loadingComments[key],
    },

    actions: {
        hydrate(summary: Record<string, {
            like_count:     number
            user_liked:     boolean
            reaction_id:    number | null
            comment_count?: number
        }>) {
            Object.entries(summary).forEach(([key, val]) => {
                this.reactions[key] = {
                    like_count:    val.like_count,
                    user_liked:    val.user_liked,
                    reaction_id:   val.reaction_id,
                    comment_count: val.comment_count ?? 0,
                }
            })
        },

        getKey(contentId?: number | null, spotlightId?: number | null): string {
            if (contentId)   return `content_${contentId}`
            if (spotlightId) return `spotlight_${spotlightId}`
            throw new Error('Provide contentId or spotlightId')
        },

        // ── Like / Unlike ──────────────────────────────────────────────────
        toggleLike(contentId?: number | null, spotlightId?: number | null) {
            const key = this.getKey(contentId, spotlightId)
            if (this.loadingKeys[key]) return

            this.loadingKeys[key] = true

            const current: ReactionState = this.reactions[key]
                ?? { like_count: 0, user_liked: false, reaction_id: null, comment_count: 0 }

            // Optimistic update
            this.reactions[key] = {
                ...current,
                like_count:  current.user_liked ? current.like_count - 1 : current.like_count + 1,
                user_liked:  !current.user_liked,
                reaction_id: current.user_liked ? null : current.reaction_id,
            }

            if (current.user_liked && current.reaction_id) {
                // ── Unlike ─────────────────────────────────────────────────
                router.delete(`/reactions/${current.reaction_id}`, {
                    preserveState:  true,
                    preserveScroll: true,
                    onSuccess: () => {
                        this.showNotification('Like removed', 'success')
                    },
                    onError:  () => { this.reactions[key] = current },
                    onFinish: () => { this.loadingKeys[key] = false },
                })
            } else {
                // ── Like ───────────────────────────────────────────────────
                const payload: Record<string, any> = { like: true }
                if (contentId)   payload.content_id = contentId
                if (spotlightId) payload.employee_spotlight_id = spotlightId

                router.post('/reactions', payload, {   // ← /reactions pas /store/reaction
                    preserveState:  true,
                    preserveScroll: true,
                    onSuccess: (page) => {
                        const flash = (page.props as any).flash
                        if (flash?.reaction_id) {
                            this.reactions[key].reaction_id = flash.reaction_id
                        }
                        this.showNotification('You liked this! 💙', 'success')
                    },
                    onError:  () => { this.reactions[key] = current },
                    onFinish: () => { this.loadingKeys[key] = false },
                })
            }
        },

        addComment(
            text: string,
            contentId?: number | null,
            spotlightId?: number | null
        ): Promise<boolean> {
            const key = this.getKey(contentId, spotlightId)
            const payload: Record<string, any> = { like: false, comment: text }
            if (contentId)   payload.content_id = contentId
            if (spotlightId) payload.employee_spotlight_id = spotlightId

            return new Promise<boolean>((resolve) => {
                router.post('/reactions', payload, {   // ← /reactions
                    preserveState:  true,
                    preserveScroll: true,
                    onSuccess: () => {
                        // Incrémenter localement
                        const current = this.reactions[key]
                        this.reactions[key] = {
                            ...(current ?? { like_count: 0, user_liked: false, reaction_id: null, comment_count: 0 }),
                            comment_count: (current?.comment_count ?? 0) + 1,
                        }
                        // Recharger la liste des commentaires
                        this.fetchComments(contentId, spotlightId)
                        this.showNotification('Comment posted! 🎉', 'success')
                        resolve(true)
                    },
                    onError: () => resolve(false),
                })
            })
        },

        // ── Charger les commentaires ───────────────────────────────────────
        async fetchComments(contentId?: number | null, spotlightId?: number | null) {
            const key = this.getKey(contentId, spotlightId)
            if (this.loadingComments[key]) return

            this.loadingComments[key] = true

            try {
                const params = new URLSearchParams()
                if (contentId)   params.set('content_id',   String(contentId))
                if (spotlightId) params.set('spotlight_id', String(spotlightId))

                const res = await fetch(`/reactions/comments?${params}`, {
                    headers: {
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                const data = await res.json()
                if (data.success) {
                    this.comments[key] = data.data
                }
            } catch (e) {
                console.error('fetchComments error', e)
            } finally {
                this.loadingComments[key] = false
            }
        },

        deleteComment(
            reactionId: number,
            contentId?: number | null,
            spotlightId?: number | null
        ): Promise<boolean> {
            const key = this.getKey(contentId, spotlightId)

            return new Promise<boolean>((resolve) => {
                router.delete(`/reactions/comment/${reactionId}`, {
                    preserveState:  true,
                    preserveScroll: true,
                    onSuccess: () => {
                        if (this.comments[key]) {
                            this.comments[key] = this.comments[key].filter(c => c.id !== reactionId)
                        }
                        const current = this.reactions[key]
                        if (current) {
                            this.reactions[key] = {
                                ...current,
                                comment_count: Math.max(0, current.comment_count - 1),
                            }
                        }
                        this.showNotification('Comment deleted', 'success')
                        resolve(true)
                    },
                    onError: () => resolve(false),
                })
            })
        },

        showNotification(msg: string, color: 'success' | 'error' = 'success') {
            this.snackMsg   = msg
            this.snackColor = color
            this.snackbar   = true
            setTimeout(() => { this.snackbar = false }, 3500)
        },
    },
})
