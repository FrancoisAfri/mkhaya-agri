import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ChatMessage = {
  id: number
  conversation_id: number
  sender_type: 'visitor' | 'agent' | 'bot'
  agent_id: number | null
  agent: { id: number; name: string } | null
  message_type: 'text' | 'image' | 'file' | 'event'
  body: string | null
  file_name: string | null
  file_mime: string | null
  file_size: number | null
  file_url: string | null
  delivery_status: string
  seen_at: string | null
  created_at: string
}

export type ConversationStatus = 'pending' | 'active' | 'solved' | 'abandoned'

export const useLiveChatStore = defineStore('livechat', () => {
  const messages    = ref<ChatMessage[]>([])
  const status      = ref<ConversationStatus>('pending')
  const assignedTo  = ref<number | null>(null)
  const resolvedAt  = ref<string | null>(null)

  // Typing state — cleared by the component after a timeout
  const visitorTyping = ref(false)
  const agentTyping   = ref<{ id: number; name: string } | null>(null)

  function init(
    initialMessages: ChatMessage[],
    initialStatus: ConversationStatus,
    initialAssignedTo: number | null,
  ) {
    messages.value   = [...initialMessages]
    status.value     = initialStatus
    assignedTo.value = initialAssignedTo
  }

  function pushMessage(msg: ChatMessage) {
    if (!messages.value.find(m => m.id === msg.id)) {
      messages.value.push(msg)
    }
    // Clear typing indicators when a real message arrives
    visitorTyping.value = false
    agentTyping.value   = null
  }

  function setVisitorTyping(isTyping: boolean) {
    visitorTyping.value = isTyping
  }

  function setAgentTyping(agent: { id: number; name: string } | null) {
    agentTyping.value = agent
  }

  function applyConversationUpdate(payload: {
    status: ConversationStatus
    assigned_to: number | null
    resolved_at: string | null
  }) {
    status.value     = payload.status
    assignedTo.value = payload.assigned_to
    resolvedAt.value = payload.resolved_at
  }

  function reset() {
    messages.value      = []
    status.value        = 'pending'
    assignedTo.value    = null
    resolvedAt.value    = null
    visitorTyping.value = false
    agentTyping.value   = null
  }

  return {
    messages,
    status,
    assignedTo,
    resolvedAt,
    visitorTyping,
    agentTyping,
    init,
    pushMessage,
    setVisitorTyping,
    setAgentTyping,
    applyConversationUpdate,
    reset,
  }
})
