<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Person = { id: number; name: string }
type Comment = { id: number; name: string; body: string; at: string }
type FeedItem = {
  id: number; giver_name: string; receiver_id: number; receiver_name: string
  badge: string | null; badge_label: string | null; value_tag: string | null
  message: string; created_at: string
  reactions: Record<string, number>; my_reactions: string[]
  comments: Comment[]; can_delete: boolean
}

const props = defineProps<{
  feed: FeedItem[]
  badges: Record<string, string>
  leaderboard: { employee_id: number; name: string; total: number }[]
  people: Person[]
}>()

const confirmDialog = ref()
const composeOpen = ref(false)
const form = ref({ receiver_id: null as number | null, badge: null as string | null, message: '', is_public: true })
const submitting = ref(false)
const commentDraft = ref<Record<number, string>>({})
const EMOJIS = ['👏', '🎉', '🙌', '❤️', '🔥', '💯']

function post() {
  submitting.value = true
  router.post(route('performance.recognition.store'), form.value, {
    preserveScroll: true,
    onSuccess: () => { composeOpen.value = false; form.value = { receiver_id: null, badge: null, message: '', is_public: true } },
    onFinish: () => (submitting.value = false),
  })
}

function react(item: FeedItem, emoji: string) {
  router.post(route('performance.recognition.react', item.id), { emoji }, { preserveScroll: true })
}

function comment(item: FeedItem) {
  const body = (commentDraft.value[item.id] || '').trim()
  if (!body) return
  router.post(route('performance.recognition.comment', item.id), { body }, {
    preserveScroll: true,
    onSuccess: () => (commentDraft.value[item.id] = ''),
  })
}

function remove(item: FeedItem) {
  confirmDialog.value.open(
    {
      title: t('pa_recognition.confirm.title'),
      message: t('pa_recognition.confirm.message'),
      confirmText: t('pa_recognition.confirm.confirm_text'),
      confirmColor: 'error',
      icon: t('pa_recognition.confirm.icon'),
    },
    () => { router.delete(route('performance.recognition.destroy', item.id), { preserveScroll: true }) },
  )
}

const badgeItems = Object.entries(props.badges).map(([value, title]) => ({ value, title }))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="warning" variant="tonal" size="48" rounded><VIcon icon="tabler-award" size="26" /></VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('pa_recognition.title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('pa_recognition.subtitle') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="composeOpen = true">{{ t('pa_recognition.give_recognition') }}</VBtn>
    </div>

    <VRow>
      <!-- Feed -->
      <VCol cols="12" md="8">
        <div v-if="!feed.length" class="text-center py-12 text-medium-emphasis">
          {{ t('pa_recognition.empty') }}
        </div>

        <VCard v-for="item in feed" :key="item.id" border class="mb-3">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="d-flex align-center gap-2">
                <VAvatar color="primary" variant="tonal" size="36"><VIcon icon="tabler-user" /></VAvatar>
                <div>
                  <div class="text-body-2">
                    <strong>{{ item.giver_name }}</strong> recognised <strong>{{ item.receiver_name }}</strong>
                  </div>
                  <div class="text-caption text-medium-emphasis">{{ item.created_at }}</div>
                </div>
              </div>
              <div class="d-flex align-center gap-1">
                <VChip v-if="item.badge_label" size="small" color="warning" variant="tonal" prepend-icon="tabler-medal">{{ item.badge_label }}</VChip>
                <VBtn v-if="item.can_delete" icon="tabler-trash" size="x-small" variant="text" @click="remove(item)" />
              </div>
            </div>

            <p class="text-body-1 mb-3">{{ item.message }}</p>

            <!-- Reactions -->
            <div class="d-flex align-center flex-wrap gap-1 mb-2">
              <VBtn
                v-for="emoji in EMOJIS" :key="emoji"
                size="x-small" variant="tonal"
                :color="item.my_reactions.includes(emoji) ? 'primary' : undefined"
                @click="react(item, emoji)"
              >
                {{ emoji }} <span v-if="item.reactions[emoji]" class="ms-1">{{ item.reactions[emoji] }}</span>
              </VBtn>
            </div>

            <!-- Comments -->
            <div v-if="item.comments.length" class="mt-2">
              <div v-for="c in item.comments" :key="c.id" class="text-body-2 mb-1">
                <strong>{{ c.name }}</strong>: {{ c.body }}
                <span class="text-caption text-medium-emphasis ms-1">{{ c.at }}</span>
              </div>
            </div>
            <div class="d-flex align-center gap-2 mt-2">
              <VTextField v-model="commentDraft[item.id]" density="compact" variant="outlined" hide-details
                :placeholder="t('pa_recognition.comment_placeholder')" @keyup.enter="comment(item)" />
              <VBtn icon="tabler-send" size="small" variant="tonal" @click="comment(item)" />
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Leaderboard -->
      <VCol cols="12" md="4">
        <VCard border>
          <VCardTitle class="text-subtitle-1 d-flex align-center gap-2">
            <VIcon icon="tabler-trophy" color="warning" /> {{ t('pa_recognition.leaderboard_title') }}
          </VCardTitle>
          <VCardText>
            <div v-if="!leaderboard.length" class="text-caption text-medium-emphasis">{{ t('pa_recognition.leaderboard_empty') }}</div>
            <div v-for="(l, i) in leaderboard" :key="l.employee_id" class="d-flex align-center justify-space-between py-2 border-b">
              <div class="d-flex align-center gap-2">
                <VChip size="small" :color="i === 0 ? 'warning' : 'default'" variant="tonal">#{{ i + 1 }}</VChip>
                <span class="text-body-2">{{ l.name }}</span>
              </div>
              <VChip size="small" variant="tonal" color="primary">{{ l.total }}</VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Compose dialog -->
    <VDialog v-model="composeOpen" max-width="560">
      <VCard>
        <VCardTitle>{{ t('pa_recognition.compose.title') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="form.receiver_id" :items="people" item-title="name" item-value="id"
            :label="t('pa_recognition.compose.recipient_label')" variant="outlined" density="comfortable" class="mb-3" />
          <VSelect v-model="form.badge" :items="badgeItems" :label="t('pa_recognition.compose.badge_label')" variant="outlined"
            density="comfortable" clearable class="mb-3" />
          <VTextarea v-model="form.message" :label="t('pa_recognition.compose.message_label')" variant="outlined" rows="3" auto-grow class="mb-2" />
          <VSwitch v-model="form.is_public" :label="t('pa_recognition.compose.public_label')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="composeOpen = false">{{ t('pa_recognition.compose.cancel') }}</VBtn>
          <VBtn color="primary" :loading="submitting" :disabled="!form.receiver_id || !form.message" @click="post">{{ t('pa_recognition.compose.post') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
