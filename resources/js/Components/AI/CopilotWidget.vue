<template>
    <!-- ═══════════════════════════════════════════
         FLOATING TRIGGER — BOTTOM LEFT
    ═══════════════════════════════════════════ -->
    <button @click="toggleOpen" class="copilot-trigger" :class="{ open: isOpen }" aria-label="Toggle Mkhaya AI">
        <Transition name="icon-swap" mode="out-in">
            <span v-if="!isOpen" key="bot" class="trigger-bot-face">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="30" height="30">
                    <!-- antenna -->
                    <line x1="12" y1="1.5" x2="12" y2="5" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="12" cy="1.5" r="1.2" fill="white"/>
                    <!-- ears -->
                    <rect x="2" y="9" width="2.5" height="5" rx="1.2" fill="rgba(255,255,255,0.7)"/>
                    <rect x="19.5" y="9" width="2.5" height="5" rx="1.2" fill="rgba(255,255,255,0.7)"/>
                    <!-- head -->
                    <rect x="4" y="5" width="16" height="14" rx="4" fill="white"/>
                    <!-- eyes -->
                    <circle cx="9" cy="11" r="2.3" fill="#2d9e8e"/>
                    <circle cx="15" cy="11" r="2.3" fill="#2d9e8e"/>
                    <circle cx="9.7" cy="10.2" r="0.7" fill="white"/>
                    <circle cx="15.7" cy="10.2" r="0.7" fill="white"/>
                    <!-- mouth -->
                    <rect x="8" y="15.5" width="8" height="1.5" rx="0.75" fill="rgba(45,158,142,0.55)"/>
                </svg>
            </span>
            <span v-else key="close" class="trigger-close-icon">
                <svg viewBox="0 0 24 24" fill="none" width="16" height="16">
                    <path d="M5 5L19 19M5 19L19 5" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </span>
        </Transition>
        <span v-if="!isOpen" class="trigger-ripple"></span>
        <span v-if="!isOpen" class="trigger-ring"></span>
    </button>

    <!-- ═══════════════════════════════════════════
         BACKDROP
    ═══════════════════════════════════════════ -->
    <Transition name="fade">
        <div v-if="isOpen" class="copilot-backdrop" @click="isOpen = false"></div>
    </Transition>

    <!-- ═══════════════════════════════════════════
         CHAT PANEL — LEFT SIDE
    ═══════════════════════════════════════════ -->
    <Transition name="panel-slide">
        <div v-if="isOpen" class="copilot-panel">

            <!-- ── HEADER ── -->
            <div class="cp-header">
                <div class="cp-header-brand">
                    <div class="cp-logo">
                        <img src="/images/logo_default.png" alt="Mkhaya" class="cp-logo-img" />
                        <span class="cp-online-dot"></span>
                    </div>
                    <div class="cp-brand-text">
                        <p class="cp-name">Mkhaya AI</p>
                        <p class="cp-status">
                            <span v-if="isLoading" class="status-thinking">
                                <span class="thinking-dots"><span></span><span></span><span></span></span>
                                Thinking…
                            </span>
                            <span v-else>
                                <span class="status-live-dot"></span>
                                Live ERP Data
                            </span>
                        </p>
                    </div>
                </div>
                <div class="cp-header-actions">
                    <button @click="showSuggestions = !showSuggestions" class="cp-icon-btn" :class="{ active: showSuggestions }" title="Suggested Questions">
                        <i class="fa-solid fa-lightbulb"></i>
                    </button>
                    <button @click="showSessions = !showSessions" class="cp-icon-btn" :class="{ active: showSessions }" title="History">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </button>
                    <button @click="startNewSession" class="cp-icon-btn" title="New chat">
                        <i class="fa-solid fa-square-pen"></i>
                    </button>
                    <button @click="isOpen = false" class="cp-icon-btn cp-close-btn" title="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- ── SUGGESTIONS DRAWER ── -->
            <Transition name="drawer-slide">
                <div v-if="showSuggestions" class="cp-suggestions-drawer">
                    <div class="cp-suggestions-header">
                        <i class="fa-solid fa-lightbulb"></i>
                        <span>Suggested Questions</span>
                    </div>

                    <!-- Employee Section -->
                    <div class="suggestion-category">
                        <div class="category-title">
                            <i class="fa-solid fa-users"></i>
                            <span>Employees</span>
                        </div>
                        <div class="suggestion-list">
                            <button
                                v-for="q in suggestedQuestions.employee"
                                :key="q.text"
                                @click="sendMessage(q.text)"
                                class="suggestion-item"
                            >
                                <i :class="`fa-solid ${q.icon}`"></i>
                                <span>{{ q.text }}</span>
                                <i class="fa-solid fa-arrow-right suggestion-arrow"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Leave Section -->
                    <div class="suggestion-category">
                        <div class="category-title">
                            <i class="fa-solid fa-calendar-alt"></i>
                            <span>Leave & Attendance</span>
                        </div>
                        <div class="suggestion-list">
                            <button
                                v-for="q in suggestedQuestions.leave"
                                :key="q.text"
                                @click="sendMessage(q.text)"
                                class="suggestion-item"
                            >
                                <i :class="`fa-solid ${q.icon}`"></i>
                                <span>{{ q.text }}</span>
                                <i class="fa-solid fa-arrow-right suggestion-arrow"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Asset Section -->
                    <div class="suggestion-category">
                        <div class="category-title">
                            <i class="fa-solid fa-laptop"></i>
                            <span>Assets</span>
                        </div>
                        <div class="suggestion-list">
                            <button
                                v-for="q in suggestedQuestions.asset"
                                :key="q.text"
                                @click="sendMessage(q.text)"
                                class="suggestion-item"
                            >
                                <i :class="`fa-solid ${q.icon}`"></i>
                                <span>{{ q.text }}</span>
                                <i class="fa-solid fa-arrow-right suggestion-arrow"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- ── SESSIONS DRAWER ── -->
            <Transition name="drawer-slide">
                <div v-if="showSessions" class="cp-sessions">
                    <p class="cp-sessions-label">Recent conversations</p>
                    <div class="cp-sessions-list">
                        <div
                            v-for="s in sessions"
                            :key="s.session_id"
                            @click="loadSession(s.session_id)"
                            class="cp-session-row"
                            :class="{ active: s.session_id === sessionId }"
                            role="button"
                            tabindex="0"
                            @keydown.enter="loadSession(s.session_id)"
                            @keydown.space.prevent="loadSession(s.session_id)"
                        >
                            <i class="fa-regular fa-message"></i>
                            <span class="cp-session-title">{{ s.title || 'Untitled' }}</span>
                            <button class="cp-session-del" @click.stop="deleteSession(s.session_id)" title="Delete">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                        <p v-if="sessions.length === 0" class="cp-sessions-empty">
                            No previous conversations
                        </p>
                    </div>
                </div>
            </Transition>

            <!-- ── ERROR BANNER ── -->
            <Transition name="drawer-slide">
                <div v-if="errorMessage" class="cp-error">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>{{ errorMessage }}</span>
                    <button @click="errorMessage = null"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </Transition>

            <!-- ── MESSAGES ── -->
            <div class="cp-messages" ref="chatContainer">
                <!-- WELCOME -->
                <div v-if="!hasMessages" class="cp-welcome">
                    <div class="cp-welcome-avatar">
                        <img src="/images/logo_default.png" alt="Mkhaya" class="cp-logo-img" />
                    </div>
                    <h4 class="cp-welcome-title">Hi, I'm Mkhaya</h4>
                    <p class="cp-welcome-desc">
                        Your intelligent ERP assistant. Ask me anything about employees, leave, assets, and more.
                    </p>

                    <!-- Quick Stats Overview -->
                    <div class="cp-quick-stats" v-if="quickStats">
                        <div class="stat-card">
                            <i class="fa-solid fa-users"></i>
                            <div class="stat-info">
                                <span class="stat-value">{{ quickStats.employees || '...' }}</span>
                                <span class="stat-label">Active Employees</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <i class="fa-solid fa-calendar-day"></i>
                            <div class="stat-info">
                                <span class="stat-value">{{ quickStats.onLeave || '...' }}</span>
                                <span class="stat-label">On Leave Today</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <i class="fa-solid fa-laptop"></i>
                            <div class="stat-info">
                                <span class="stat-value">{{ quickStats.assets || '...' }}</span>
                                <span class="stat-label">Total Assets</span>
                            </div>
                        </div>
                    </div>

                    <div class="cp-suggestions-toggle" @click="showSuggestions = true">
                        <i class="fa-regular fa-lightbulb"></i>
                        <span>View suggested questions</span>
                        <i class="fa-solid fa-chevron-right"></i>
                    </div>

                    <p class="cp-shortcut"><kbd>⌘K</kbd> to toggle · <kbd>Esc</kbd> to close</p>
                </div>

                <!-- MESSAGE BUBBLES -->
                <TransitionGroup name="msg-in">
                    <template v-for="(msg, i) in messages" :key="`m-${i}`">
                        <!-- User Message -->
                        <div v-if="msg.role === 'user'" class="cp-msg cp-msg-user">
                            <div class="cp-bubble cp-bubble-user">{{ msg.content }}</div>
                            <span class="cp-ts">{{ formatTime(msg.timestamp) }}</span>
                        </div>

                        <!-- Assistant Message -->
                        <div v-else class="cp-msg cp-msg-ai">
                            <div class="cp-ai-avatar"><img src="/images/logo_default.png" alt="Mkhaya" class="cp-logo-img" /></div>
                            <div class="cp-ai-body">
                                <div v-if="msg.loading" class="cp-bubble cp-bubble-ai cp-bubble-loading">
                                    <span class="cp-typing">
                                        <span></span><span></span><span></span>
                                    </span>
                                </div>
                                <div v-else class="cp-bubble cp-bubble-ai" :class="{ 'cp-bubble-error': msg.metadata?.error }">
                                    <div class="cp-markdown" v-html="renderMarkdown(msg.content)"></div>
                                </div>

                                <div v-if="!msg.loading" class="cp-msg-meta">
                                    <div v-if="msg.metadata?.data_sources?.length" class="cp-sources">
                                        <span v-for="src in msg.metadata.data_sources" :key="src" class="cp-source-tag">
                                            <i class="fa-solid fa-database"></i> {{ src }}
                                        </span>
                                    </div>
                                    <div class="cp-msg-actions">
                                        <span class="cp-ts">{{ formatTime(msg.timestamp) }}</span>
                                        <button class="cp-action-btn" @click="copyMessage(msg.content)" title="Copy">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                        <button class="cp-action-btn" @click="regenerateResponse(i)" title="Regenerate" v-if="!msg.metadata?.error">
                                            <i class="fa-solid fa-rotate-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </TransitionGroup>
            </div>

            <!-- ── INPUT AREA ── -->
            <div class="cp-input-area">
                <div class="cp-input-box">
                    <textarea
                        ref="textareaRef"
                        v-model="input"
                        @keydown="handleKeydown"
                        @input="autoResizeTextarea"
                        placeholder="Ask me anything about your ERP data…"
                        rows="1"
                        class="cp-textarea"
                        :disabled="isLoading"
                    ></textarea>
                    <button @click="sendMessage()" :disabled="!canSend" class="cp-send-btn" :class="{ ready: canSend }">
                        <i v-if="!isLoading" class="fa-solid fa-paper-plane"></i>
                        <i v-else class="fa-solid fa-circle-notch fa-spin"></i>
                    </button>
                </div>
                <p class="cp-footer-note">
                    <i class="fa-solid fa-shield-halved"></i>
                    Based on live ERP data — AI may make mistakes
                </p>
            </div>
        </div>
    </Transition>
</template>

<script setup lang="ts">
import { ref, nextTick, onMounted, onUnmounted, computed } from 'vue'
import axios from 'axios'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

// ── Types ──
interface Message {
    role: 'user' | 'assistant'
    content: string
    metadata?: Record<string, any>
    timestamp?: string
    loading?: boolean
}

interface Session {
    session_id: string
    title: string
    created_at: string
    message_count?: number
}

interface QuickStats {
    employees: number
    onLeave: number
    assets: number
}

// ── State ──
const isOpen = ref(false)
const showSessions = ref(false)
const showSuggestions = ref(false)
const input = ref('')
const messages = ref<Message[]>([])
const sessions = ref<Session[]>([])
const sessionId = ref<string | null>(null)
const isLoading = ref(false)
const chatContainer = ref<HTMLElement | null>(null)
const textareaRef = ref<HTMLTextAreaElement | null>(null)
const errorMessage = ref<string | null>(null)
const quickStats = ref<QuickStats | null>(null)

// ── Suggested Questions by Category ──
const suggestedQuestions = {
    employee: [
        { icon: 'fa-users', text: 'How many active employees do we have?' },
        { icon: 'fa-user-plus', text: 'Who was hired in the last 30 days?' },
        { icon: 'fa-user-minus', text: 'How many employees have left recently?' },
        { icon: 'fa-venus-mars', text: 'Show me the gender breakdown of employees' },
        { icon: 'fa-building', text: 'List employees by department' },
        { icon: 'fa-id-card', text: 'Find employee John Smith' },
        { icon: 'fa-chart-pie', text: 'What is the employee distribution across departments?' },
        { icon: 'fa-envelope', text: 'Get email addresses for IT department' },
        { icon: 'fa-trophy', text: 'Who has been with the company the longest?' },
    ],
    leave: [
        { icon: 'fa-calendar-day', text: 'Who is on leave today?' },
        { icon: 'fa-clock', text: 'Show me pending leave requests' },
        { icon: 'fa-chart-line', text: 'How many leaves were approved this month?' },
        { icon: 'fa-calendar-week', text: 'Who is off next week?' },
        { icon: 'fa-chart-pie', text: 'Show me leave breakdown by type this year' },
        { icon: 'fa-trophy', text: 'Who took the most leave this year?' },
        { icon: 'fa-user-clock', text: 'What is my leave balance?' },
        { icon: 'fa-balance-scale', text: 'Show leave balance for John Smith' },
        { icon: 'fa-chart-bar', text: 'Which leave type is most popular?' },
        { icon: 'fa-exclamation-triangle', text: 'Show pending leave approvals needed' },
    ],
    asset: [
        { icon: 'fa-laptop', text: 'How many total assets are registered?' },
        { icon: 'fa-user-check', text: 'Show me all assigned assets' },
        { icon: 'fa-box-open', text: 'Which assets are available?' },
        { icon: 'fa-tools', text: 'List assets under maintenance' },
        { icon: 'fa-chart-pie', text: 'Show asset breakdown by type' },
        { icon: 'fa-tag', text: 'Find asset with tag LPT-001' },
        { icon: 'fa-dollar-sign', text: 'What is the total value of all assets?' },
        { icon: 'fa-chart-line', text: 'Show me high-value assets over $5000' },
        { icon: 'fa-user', text: 'Which assets are assigned to users?' },
        { icon: 'fa-clipboard-list', text: 'Show me unassigned assets' },
        { icon: 'fa-calendar-plus', text: 'What assets were added recently?' },
        { icon: 'fa-history', text: 'Show me recent asset activity' },
        { icon: 'fa-microchip', text: 'List all assets with components' },
    ],
}

// ── Computed ──
const hasMessages = computed(() => messages.value.length > 0)
const canSend = computed(() => input.value.trim().length > 0 && !isLoading.value)

// ── Markdown Rendering ──
marked.setOptions({ breaks: true, gfm: true })

function renderMarkdown(content: string): string {
    if (!content) return ''
    try {
        const raw = marked.parse(content) as string
        return DOMPurify.sanitize(raw, {
            ALLOWED_TAGS: [
                'p', 'br', 'strong', 'em', 'b', 'i', 'u', 'code', 'pre',
                'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'table', 'thead', 'tbody', 'tr', 'th', 'td',
                'blockquote', 'a', 'span', 'div', 'hr',
            ],
            ALLOWED_ATTR: ['href', 'target', 'rel', 'class'],
        })
    } catch (e) {
        return content
    }
}

// ── Session Management ──
async function toggleOpen() {
    isOpen.value = !isOpen.value
    if (isOpen.value) {
        if (!sessionId.value) await startNewSession()
        await loadSessions()
        await loadQuickStats()
        await nextTick()
        textareaRef.value?.focus()
    }
}

async function startNewSession() {
    try {
        errorMessage.value = null
        const { data } = await axios.post('/ai-copilot/new-session')
        sessionId.value = data.session_id
        messages.value = []
        showSessions.value = false
        showSuggestions.value = false
    } catch (e: any) {
        errorMessage.value = 'Failed to start a new session. Please try again.'
    }
}

async function loadSessions() {
    try {
        const { data } = await axios.get('/ai-copilot/sessions')
        sessions.value = data.sessions || []
    } catch (e) {}
}

async function loadQuickStats() {
    try {
        const { data } = await axios.get('/ai-copilot/quick-stats')
        quickStats.value = data
    } catch (e) {}
}

async function loadSession(id: string) {
    sessionId.value = id
    showSessions.value = false
    errorMessage.value = null
    try {
        const { data } = await axios.get(`/ai-copilot/history/${id}`)
        messages.value = data.messages || []
        await scrollToBottom()
    } catch (e) {
        errorMessage.value = 'Failed to load conversation history.'
    }
}

async function deleteSession(id: string) {
    try {
        await axios.delete(`/ai-copilot/sessions/${id}`)
        sessions.value = sessions.value.filter((s) => s.session_id !== id)
        if (sessionId.value === id) await startNewSession()
    } catch (e) {}
}

// ── Messaging ──
async function sendMessage(text?: string) {
    const msg = text || input.value.trim()
    if (!msg || isLoading.value) return

    input.value = ''
    errorMessage.value = null
    resetTextareaHeight()
    showSuggestions.value = false

    messages.value.push({ role: 'user', content: msg, timestamp: new Date().toISOString() })
    messages.value.push({ role: 'assistant', content: '', loading: true })

    await scrollToBottom()
    isLoading.value = true

    try {
        const { data } = await axios.post('/ai-copilot/chat', {
            message: msg,
            session_id: sessionId.value,
        })

        const loadingIdx = messages.value.findIndex((m) => m.loading)
        if (loadingIdx !== -1) {
            messages.value[loadingIdx] = {
                ...data.message,
                loading: false,
                timestamp: data.message.timestamp || new Date().toISOString()
            }
        }
        if (data.session_id) sessionId.value = data.session_id
        // Surface AI token-quota warnings (low balance / paused) in the banner.
        if (data.quota_warning) errorMessage.value = data.quota_warning
        await loadSessions()
    } catch (error: any) {
        const loadingIdx = messages.value.findIndex((m) => m.loading)
        let errContent = 'Sorry, I encountered an error. Please try again.'
        if (error.response?.status === 429) {
            errContent = "You've reached your usage limit. Resets at midnight."
        } else if (error.response?.status === 401) {
            errContent = 'Session expired. Please refresh the page.'
        } else if (error.response?.data?.message?.content) {
            errContent = error.response.data.message.content
        }
        if (loadingIdx !== -1) {
            messages.value[loadingIdx] = {
                role: 'assistant',
                content: errContent,
                loading: false,
                metadata: { error: true },
                timestamp: new Date().toISOString()
            }
        }
    } finally {
        isLoading.value = false
        await scrollToBottom()
        textareaRef.value?.focus()
    }
}

async function regenerateResponse(messageIndex: number) {
    // Find the user message that preceded this assistant message
    let userMessage = null
    for (let i = messageIndex - 1; i >= 0; i--) {
        if (messages.value[i].role === 'user') {
            userMessage = messages.value[i].content
            break
        }
    }

    if (userMessage) {
        // Remove the current assistant message
        messages.value.splice(messageIndex, 1)
        // Resend the user message
        await sendMessage(userMessage)
    }
}

// ── UI Helpers ──
async function scrollToBottom() {
    await nextTick()
    if (chatContainer.value) {
        chatContainer.value.scrollTo({ top: chatContainer.value.scrollHeight, behavior: 'smooth' })
    }
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault()
        sendMessage()
    }
}

function autoResizeTextarea() {
    const el = textareaRef.value
    if (!el) return
    el.style.height = 'auto'
    el.style.height = Math.min(el.scrollHeight, 120) + 'px'
}

function resetTextareaHeight() {
    if (textareaRef.value) textareaRef.value.style.height = 'auto'
}

function formatTime(ts?: string): string {
    if (!ts) return ''
    try {
        return new Date(ts).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
    } catch { return '' }
}

function copyMessage(content: string) {
    navigator.clipboard.writeText(content).catch(() => {
        const ta = document.createElement('textarea')
        ta.value = content
        document.body.appendChild(ta)
        ta.select()
        document.execCommand('copy')
        document.body.removeChild(ta)
    })
}

// ── Keyboard Shortcuts ──
function handleGlobalKeydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        toggleOpen()
    }
    if (e.key === 'Escape' && isOpen.value) {
        if (showSessions.value) {
            showSessions.value = false
        } else if (showSuggestions.value) {
            showSuggestions.value = false
        } else {
            isOpen.value = false
        }
    }
}

async function handleMkhayaOpen(e: Event) {
    const prompt = (e as CustomEvent).detail?.prompt as string | undefined
    if (!isOpen.value) await toggleOpen()
    if (prompt) {
        await nextTick()
        input.value = prompt
        autoResizeTextarea()
        textareaRef.value?.focus()
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown)
    window.addEventListener('mkhaya:open', handleMkhayaOpen)
})

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown)
    window.removeEventListener('mkhaya:open', handleMkhayaOpen)
})
</script>

<style scoped>
/* ═══════════════════════════════════════════════════
   TOKENS
═══════════════════════════════════════════════════ */
* {
    --teal: #2d9e8e;
    --teal-lt: #5bc2b3;
    --teal-pale: #e8f8f5;
    --dark: #0f172a;
    --dark-mid: #1e293b;
    --panel-w: 420px;
    --header-h: 62px;
}

/* ═══════════════════════════════════════════════════
   TRIGGER — BOTTOM LEFT
═══════════════════════════════════════════════════ */
.copilot-trigger {
    position: fixed;
    bottom: 1.75rem;
    left: 1.75rem;
    z-index: 10000;
    width: 52px;
    height: 52px;
    border-radius: 16px;
    background: linear-gradient(135deg, #1a7a6e 0%, var(--teal) 50%, var(--teal-lt) 100%);
    color: #fff;
    border: none;
    font-size: 1.15rem;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 4px 20px rgba(45,158,142,0.45), 0 1px 4px rgba(0,0,0,0.18);
    transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
    overflow: visible;
}
.copilot-trigger:hover {
    transform: translateY(-2px) scale(1.06);
    box-shadow: 0 10px 32px rgba(45,158,142,0.5), 0 2px 6px rgba(0,0,0,0.18);
}
.copilot-trigger.open {
    background: #ef4444;
    border-radius: 12px;
    width: 44px;
    height: 44px;
}
.trigger-bot-face {
    display: flex;
    align-items: center;
    justify-content: center;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.18));
}
.trigger-close-icon {
    display: flex;
    align-items: center;
    justify-content: center;
}
.trigger-ripple {
    position: absolute;
    inset: -6px;
    border-radius: 22px;
    border: 1.5px solid rgba(91,194,179,0.55);
    animation: ripple-out 2.8s ease-out infinite;
    pointer-events: none;
}
.trigger-ring {
    position: absolute;
    inset: -12px;
    border-radius: 28px;
    border: 1px solid rgba(91,194,179,0.28);
    animation: ripple-out 2.8s ease-out 0.9s infinite;
    pointer-events: none;
}
@keyframes ripple-out {
    0%   { transform: scale(1); opacity: 0.7; }
    100% { transform: scale(1.5); opacity: 0; }
}
.icon-swap-enter-active, .icon-swap-leave-active { transition: all 0.18s ease; }
.icon-swap-enter-from { transform: scale(0) rotate(-90deg); opacity: 0; }
.icon-swap-leave-to  { transform: scale(0) rotate(90deg); opacity: 0; }

/* ═══════════════════════════════════════════════════
   BACKDROP
═══════════════════════════════════════════════════ */
.copilot-backdrop {
    display: none;
}
@media (max-width: 500px) {
    .copilot-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 9997;
        background: rgba(0,0,0,0.45);
        backdrop-filter: blur(4px);
    }
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

/* ═══════════════════════════════════════════════════
   PANEL
═══════════════════════════════════════════════════ */
.copilot-panel {
    position: fixed;
    bottom: 1.75rem;
    left: 1.75rem;
    z-index: 9999;
    width: var(--panel-w);
    max-height: 85vh;
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 0 0 1px rgba(0,0,0,0.05), 0 24px 60px rgba(0,0,0,0.13);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
@media (max-width: 500px) {
    .copilot-panel {
        bottom: 0;
        left: 0;
        right: 0;
        width: 100%;
        max-height: 90vh;
        border-radius: 20px 20px 0 0;
    }
}
.panel-slide-enter-active {
    transition: all 0.38s cubic-bezier(0.16,1,0.3,1);
}
.panel-slide-leave-active {
    transition: all 0.2s ease-in;
}
.panel-slide-enter-from {
    opacity: 0;
    transform: translateY(20px) scale(0.96);
    transform-origin: bottom left;
}
.panel-slide-leave-to {
    opacity: 0;
    transform: translateY(12px) scale(0.98);
    transform-origin: bottom left;
}

/* ═══════════════════════════════════════════════════
   HEADER
═══════════════════════════════════════════════════ */
.cp-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 1rem;
    height: var(--header-h);
    background: var(--dark);
    flex-shrink: 0;
    border-radius: 20px 20px 0 0;
}
.cp-header-brand {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.cp-logo {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--teal), var(--teal-lt));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 0.85rem;
    position: relative;
    flex-shrink: 0;
    overflow: hidden;
}
.cp-logo-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}
.cp-online-dot {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #22c55e;
    border: 2px solid var(--dark);
}
.cp-brand-text { line-height: 1; }
.cp-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: #fff;
    margin: 0 0 2px;
}
.cp-status {
    font-size: 0.68rem;
    color: rgba(255,255,255,0.4);
    display: flex;
    align-items: center;
    gap: 4px;
    margin: 0;
}
.status-live-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #22c55e;
    display: inline-block;
}
.status-thinking {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--teal-lt);
}
.thinking-dots {
    display: flex;
    gap: 3px;
    align-items: center;
}
.thinking-dots span {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: var(--teal-lt);
    animation: tbeat 1.1s ease-in-out infinite;
}
.thinking-dots span:nth-child(2) { animation-delay: 0.18s; }
.thinking-dots span:nth-child(3) { animation-delay: 0.36s; }
@keyframes tbeat {
    0%,100% { transform: scale(0.7); opacity: 0.4; }
    50%      { transform: scale(1.2); opacity: 1; }
}
.cp-header-actions {
    display: flex;
    gap: 2px;
}
.cp-icon-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.45);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    transition: all 0.15s;
}
.cp-icon-btn:hover { background: rgba(255,255,255,0.12); color: #fff; }
.cp-icon-btn.active { background: rgba(91,194,179,0.2); color: var(--teal-lt); }
.cp-close-btn:hover { background: rgba(239,68,68,0.22); color: #fca5a5; }

/* ═══════════════════════════════════════════════════
   SUGGESTIONS DRAWER
═══════════════════════════════════════════════════ */
.cp-suggestions-drawer {
    background: #f8fafc;
    border-bottom: 1px solid #f1f5f9;
    max-height: 60vh;
    overflow-y: auto;
    flex-shrink: 0;
}
.cp-suggestions-header {
    padding: 0.85rem 1rem;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #64748b;
    border-bottom: 1px solid #e2e8f0;
    background: #fff;
}
.cp-suggestions-header i {
    margin-right: 0.5rem;
    color: var(--teal);
}
.suggestion-category {
    padding: 0.75rem 0.75rem 0.25rem;
    border-bottom: 1px solid #e2e8f0;
}
.suggestion-category:last-child {
    border-bottom: none;
}
.category-title {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--teal);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.category-title i {
    font-size: 0.65rem;
}
.suggestion-list {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.suggestion-item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.5rem 0.75rem;
    border: none;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    text-align: left;
    transition: all 0.18s ease;
    font-size: 0.78rem;
    color: #334155;
}
.suggestion-item:hover {
    background: var(--teal-pale);
    transform: translateX(3px);
}
.suggestion-item i:first-child {
    color: var(--teal);
    font-size: 0.75rem;
    width: 16px;
    flex-shrink: 0;
}
.suggestion-item span {
    flex: 1;
}
.suggestion-arrow {
    color: var(--teal-lt);
    font-size: 0.6rem;
    opacity: 0;
    transition: opacity 0.15s;
}
.suggestion-item:hover .suggestion-arrow {
    opacity: 1;
}

/* ═══════════════════════════════════════════════════
   SESSIONS DRAWER
═══════════════════════════════════════════════════ */
.cp-sessions {
    background: #f8fafc;
    border-bottom: 1px solid #f1f5f9;
    max-height: 220px;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    overflow: hidden;
}
.cp-sessions-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #94a3b8;
    padding: 0.65rem 1rem 0.35rem;
}
.cp-sessions-list {
    overflow-y: auto;
    padding: 0 0.4rem 0.4rem;
    flex: 1;
}
.cp-session-row {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    width: 100%;
    text-align: left;
    padding: 0.5rem 0.6rem;
    border-radius: 8px;
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 0.78rem;
    color: #334155;
    transition: background 0.15s;
}
.cp-session-row:hover { background: #e2e8f0; }
.cp-session-row.active { background: #dbeafe; color: #1d4ed8; }
.cp-session-row > i:first-child { font-size: 0.65rem; color: #94a3b8; }
.cp-session-title { flex: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.cp-session-del {
    opacity: 0;
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 4px;
    font-size: 0.62rem;
    transition: all 0.15s;
}
.cp-session-row:hover .cp-session-del { opacity: 1; }
.cp-session-del:hover { color: #ef4444; background: rgba(239,68,68,0.1); }
.cp-sessions-empty { font-size: 0.78rem; color: #94a3b8; text-align: center; padding: 1.2rem; }

.drawer-slide-enter-active, .drawer-slide-leave-active {
    transition: all 0.22s ease;
    overflow: hidden;
}
.drawer-slide-enter-from, .drawer-slide-leave-to { max-height: 0; opacity: 0; }
.drawer-slide-enter-to, .drawer-slide-leave-from { max-height: 60vh; opacity: 1; }

/* ═══════════════════════════════════════════════════
   ERROR BANNER
═══════════════════════════════════════════════════ */
.cp-error {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.55rem 1rem;
    background: #fef2f2;
    border-bottom: 1px solid #fecaca;
    color: #b91c1c;
    font-size: 0.75rem;
    flex-shrink: 0;
}
.cp-error span { flex: 1; }
.cp-error button { background: none; border: none; cursor: pointer; color: #b91c1c; font-size: 0.7rem; padding: 2px 4px; }

/* ═══════════════════════════════════════════════════
   MESSAGES
═══════════════════════════════════════════════════ */
.cp-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    scrollbar-width: thin;
    scrollbar-color: #e2e8f0 transparent;
}
.cp-messages::-webkit-scrollbar { width: 3px; }
.cp-messages::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 2px; }
.msg-in-enter-active { transition: all 0.28s cubic-bezier(0.16,1,0.3,1); }
.msg-in-enter-from { opacity: 0; transform: translateY(8px); }

/* WELCOME */
.cp-welcome {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 1rem 0.75rem 0.5rem;
    gap: 0.6rem;
}
.cp-welcome-avatar {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    background: linear-gradient(135deg, var(--teal), var(--teal-lt));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    color: #fff;
    margin-bottom: 0.25rem;
    box-shadow: 0 6px 20px rgba(45,158,142,0.25);
}
.cp-welcome-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--dark);
    margin: 0;
}
.cp-welcome-desc {
    font-size: 0.8rem;
    color: #64748b;
    line-height: 1.55;
    max-width: 280px;
    margin: 0;
}
.cp-quick-stats {
    display: flex;
    gap: 0.75rem;
    margin-top: 0.5rem;
    width: 100%;
}
.stat-card {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    background: #f8fafc;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
}
.stat-card i {
    font-size: 1rem;
    color: var(--teal);
}
.stat-info {
    display: flex;
    flex-direction: column;
    text-align: left;
}
.stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--dark);
}
.stat-label {
    font-size: 0.6rem;
    color: #64748b;
}
.cp-suggestions-toggle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--teal-pale);
    border-radius: 10px;
    cursor: pointer;
    font-size: 0.78rem;
    color: var(--teal);
    font-weight: 500;
    margin-top: 0.5rem;
    transition: all 0.15s;
}
.cp-suggestions-toggle:hover {
    background: #d4f4ee;
    transform: translateX(3px);
}
.cp-suggestions-toggle i:last-child {
    margin-left: auto;
    font-size: 0.7rem;
}
.cp-shortcut {
    font-size: 0.65rem;
    color: #94a3b8;
    margin: 0.25rem 0 0;
}
.cp-shortcut kbd {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    padding: 0.1rem 0.3rem;
    border-radius: 4px;
    font-size: 0.6rem;
    font-family: inherit;
    font-weight: 600;
}

/* MESSAGE BUBBLES */
.cp-msg { display: flex; flex-direction: column; }
.cp-msg-user { align-items: flex-end; }
.cp-msg-ai { flex-direction: row; align-items: flex-start; gap: 0.45rem; }
.cp-ai-avatar {
    width: 26px;
    height: 26px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--teal), var(--teal-lt));
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 0.58rem;
    flex-shrink: 0;
    margin-top: 2px;
}
.cp-ai-body { flex: 1; min-width: 0; }
.cp-bubble {
    padding: 0.65rem 0.85rem;
    border-radius: 14px;
    font-size: 0.82rem;
    line-height: 1.55;
    word-break: break-word;
    max-width: 100%;
}
.cp-bubble-user {
    background: var(--dark);
    color: #fff;
    border-bottom-right-radius: 4px;
    max-width: 84%;
}
.cp-bubble-ai {
    background: #f1f5f9;
    color: #1e293b;
    border-bottom-left-radius: 4px;
}
.cp-bubble-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}
.cp-bubble-loading { min-width: 60px; }
.cp-typing {
    display: flex;
    gap: 4px;
    align-items: center;
    height: 18px;
}
.cp-typing span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--teal);
    animation: typing-bounce 1s ease-in-out infinite;
}
.cp-typing span:nth-child(2) { animation-delay: 0.15s; }
.cp-typing span:nth-child(3) { animation-delay: 0.3s; }
@keyframes typing-bounce {
    0%,100% { transform: translateY(0); opacity: 0.4; }
    50%      { transform: translateY(-5px); opacity: 1; }
}
.cp-msg-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 4px;
    padding: 0 2px;
}
.cp-sources { display: flex; flex-wrap: wrap; gap: 3px; }
.cp-source-tag {
    font-size: 0.6rem;
    font-weight: 600;
    color: var(--teal);
    background: rgba(45,158,142,0.08);
    padding: 2px 7px;
    border-radius: 20px;
    text-transform: capitalize;
}
.cp-msg-actions { display: flex; align-items: center; gap: 4px; margin-left: auto; }
.cp-ts { font-size: 0.6rem; color: #94a3b8; }
.cp-action-btn {
    background: none;
    border: none;
    color: #cbd5e1;
    cursor: pointer;
    padding: 3px 5px;
    border-radius: 4px;
    font-size: 0.65rem;
    transition: all 0.15s;
    opacity: 0;
}
.cp-msg-ai:hover .cp-action-btn { opacity: 1; }
.cp-action-btn:hover { color: var(--teal); background: rgba(45,158,142,0.08); }
.cp-msg-user .cp-ts { margin-top: 3px; padding: 0 2px; }

/* MARKDOWN */
.cp-markdown :deep(p) { margin: 0 0 0.45rem; }
.cp-markdown :deep(p:last-child) { margin-bottom: 0; }
.cp-markdown :deep(strong) { font-weight: 700; color: var(--dark); }
.cp-markdown :deep(ul), .cp-markdown :deep(ol) { margin: 0.25rem 0 0.45rem; padding-left: 1.2rem; }
.cp-markdown :deep(li) { margin-bottom: 0.18rem; font-size: 0.82rem; }
.cp-markdown :deep(code) { background: #e2e8f0; padding: 0.1rem 0.3rem; border-radius: 4px; font-size: 0.76rem; font-family: monospace; }
.cp-markdown :deep(pre) { background: var(--dark); color: #e2e8f0; padding: 0.7rem 0.9rem; border-radius: 8px; overflow-x: auto; margin: 0.45rem 0; font-size: 0.73rem; }
.cp-markdown :deep(pre code) { background: none; padding: 0; color: inherit; }
.cp-markdown :deep(table) { width: 100%; border-collapse: collapse; margin: 0.45rem 0; font-size: 0.76rem; }
.cp-markdown :deep(th) { background: #e2e8f0; font-weight: 600; text-align: left; padding: 0.35rem 0.55rem; border: 1px solid #cbd5e1; }
.cp-markdown :deep(td) { padding: 0.3rem 0.55rem; border: 1px solid #e2e8f0; }
.cp-markdown :deep(tr:nth-child(even)) { background: #f8fafc; }
.cp-markdown :deep(blockquote) { border-left: 3px solid var(--teal-lt); padding: 0.25rem 0 0.25rem 0.7rem; margin: 0.35rem 0; color: #475569; font-style: italic; }
.cp-markdown :deep(h1), .cp-markdown :deep(h2), .cp-markdown :deep(h3), .cp-markdown :deep(h4) { font-weight: 700; color: var(--dark); margin: 0.5rem 0 0.25rem; line-height: 1.3; }
.cp-markdown :deep(h1) { font-size: 1rem; }
.cp-markdown :deep(h2) { font-size: 0.92rem; }
.cp-markdown :deep(h3) { font-size: 0.86rem; }
.cp-markdown :deep(hr) { border: none; border-top: 1px solid #e2e8f0; margin: 0.5rem 0; }
.cp-markdown :deep(a) { color: var(--teal); text-decoration: underline; text-underline-offset: 2px; }

/* INPUT AREA */
.cp-input-area {
    padding: 0.75rem;
    border-top: 1px solid #f1f5f9;
    background: #fff;
    flex-shrink: 0;
    border-radius: 0 0 20px 20px;
}
.cp-input-box {
    display: flex;
    align-items: flex-end;
    gap: 0.45rem;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.45rem 0.45rem 0.45rem 0.75rem;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
}
.cp-input-box:focus-within {
    border-color: var(--teal-lt);
    box-shadow: 0 0 0 3px rgba(91,194,179,0.1);
    background: #fff;
}
.cp-textarea {
    flex: 1;
    border: none;
    outline: none;
    background: transparent;
    font-size: 0.84rem;
    color: #1e293b;
    resize: none;
    min-height: 1.35rem;
    max-height: 120px;
    line-height: 1.4;
    font-family: inherit;
}
.cp-textarea::placeholder { color: #94a3b8; }
.cp-textarea:disabled { opacity: 0.5; }
.cp-send-btn {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    border: none;
    background: #e2e8f0;
    color: #94a3b8;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    flex-shrink: 0;
    transition: all 0.2s;
}
.cp-send-btn.ready {
    background: linear-gradient(135deg, var(--teal), var(--teal-lt));
    color: #fff;
    box-shadow: 0 2px 10px rgba(45,158,142,0.3);
}
.cp-send-btn.ready:hover { transform: scale(1.06); box-shadow: 0 4px 14px rgba(45,158,142,0.38); }
.cp-send-btn:disabled { cursor: not-allowed; transform: none !important; }
.cp-footer-note {
    font-size: 0.6rem;
    color: #94a3b8;
    text-align: center;
    padding-top: 0.4rem;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}
.cp-footer-note i { font-size: 0.55rem; }
</style>
