<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// A dialog capped at 820px is a usable form on a laptop and a letterbox on a phone. The people
// this module is for are frequently holding one, so every dialog here goes full-screen below the
// `sm` breakpoint rather than shrinking a multi-column layout into a column of clipped fields.
const { smAndDown } = useDisplay()

type Notice = {
  id: string; title: string; body: string; severity: string
  is_pinned: boolean; requires_ack: boolean
  published_at: string | null; expires_at: string | null
  project: { id: string; code: string; name: string } | null
  read: boolean; acknowledged: boolean; ack_count: number; audience: number
}

const props = defineProps<{
  announcements: Notice[]
  severities: string[]
  projects: Array<{ id: string; label: string }>
  filter: { project: string | null }
  can: { post: boolean }
}>()

// What still needs something from the reader, separated out — a list where the one notice awaiting
// acknowledgement sits ninth is a list that gets it ignored.
const needsAck = computed(() => props.announcements.filter(a => a.requires_ack && !a.acknowledged))

const dialog = ref(false)

const form = useForm<{
  project_id: string | null; title: string; body: string; severity: string
  is_pinned: boolean; requires_ack: boolean; expires_at: string
}>({
  project_id: props.filter.project, title: '', body: '', severity: 'info',
  is_pinned: false, requires_ack: false, expires_at: '',
})

function post() {
  form.post(route('project-management.announcements.store'), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

function acknowledge(notice: Notice) {
  router.post(
    route('project-management.announcements.read', notice.id),
    { acknowledge: true },
    { preserveScroll: true },
  )
}

const confirmer = ref()

function remove(notice: Notice) {
  confirmer.value.open({
    title: t('project_management.announcements.confirm_remove_title'),
    message: t('project_management.announcements.confirm_remove_body', { title: notice.title }),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(
    route('project-management.announcements.destroy', notice.id),
    { preserveScroll: true },
  ))
}

function severityColour(severity: string) {
  return severity === 'urgent' ? 'error' : severity === 'important' ? 'warning' : 'info'
}

function severityIcon(severity: string) {
  return severity === 'urgent' ? 'tabler-alert-triangle'
    : severity === 'important' ? 'tabler-bell-ringing' : 'tabler-info-circle'
}

function humanDate(value: string | null) {
  return value ? new Date(value).toLocaleString() : ''
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.announcements.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('project_management.announcements.subtitle') }}
        </p>
      </div>
      <VBtn v-if="can.post" color="primary" prepend-icon="tabler-speakerphone" @click="dialog = true">
        {{ t('project_management.announcements.post') }}
      </VBtn>
    </div>

    <VAlert
      v-if="needsAck.length" type="warning" variant="tonal" class="mb-4"
      :title="t('project_management.announcements.ack_pending_title', { count: needsAck.length })"
    >
      {{ t('project_management.announcements.ack_pending_body') }}
    </VAlert>

    <VCard v-for="notice in announcements" :key="notice.id" border class="mb-3">
      <VCardItem>
        <template #prepend>
          <VAvatar :color="severityColour(notice.severity)" variant="tonal" size="40">
            <VIcon :icon="severityIcon(notice.severity)" />
          </VAvatar>
        </template>
        <VCardTitle class="d-flex align-center gap-2 flex-wrap">
          <span>{{ notice.title }}</span>
          <VIcon v-if="notice.is_pinned" icon="tabler-pin" size="16" class="text-medium-emphasis" />
          <VChip size="x-small" label :color="severityColour(notice.severity)">
            {{ t(`project_management.announcements.severity_${notice.severity}`) }}
          </VChip>
          <VChip v-if="!notice.read" size="x-small" label color="primary">
            {{ t('project_management.announcements.unread') }}
          </VChip>
        </VCardTitle>
        <VCardSubtitle>
          <template v-if="notice.project">{{ notice.project.code }} · {{ notice.project.name }}</template>
          <template v-else>{{ t('project_management.announcements.all_projects') }}</template>
          · {{ humanDate(notice.published_at) }}
        </VCardSubtitle>
        <template #append>
          <VBtn
            v-if="can.post" icon="tabler-trash" variant="text" size="small"
            :aria-label="t('project_management.announcements.remove_named', { title: notice.title })"
            @click="remove(notice)"
          />
        </template>
      </VCardItem>

      <VCardText class="pt-0" style="white-space: pre-line">{{ notice.body }}</VCardText>

      <VDivider v-if="notice.requires_ack" />
      <VCardActions v-if="notice.requires_ack">
        <span class="text-caption text-medium-emphasis ms-2">
          <!-- Who has accepted it, out of who was asked. The number is the point of asking. -->
          {{ t('project_management.announcements.ack_count', { acked: notice.ack_count, total: notice.audience }) }}
        </span>
        <VSpacer />
        <VChip v-if="notice.acknowledged" size="small" color="success" variant="tonal" prepend-icon="tabler-check">
          {{ t('project_management.announcements.acknowledged') }}
        </VChip>
        <VBtn v-else color="primary" variant="tonal" size="small" @click="acknowledge(notice)">
          {{ t('project_management.announcements.acknowledge') }}
        </VBtn>
      </VCardActions>
    </VCard>

    <VCard v-if="!announcements.length" border>
      <VCardText class="text-center text-medium-emphasis py-8">
        {{ t('project_management.announcements.none') }}
      </VCardText>
    </VCard>

    <VDialog :fullscreen="smAndDown" v-model="dialog" max-width="640">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.announcements.post') }}</VCardTitle></VCardItem>
        <VCardText>
          <VAutocomplete
            v-model="form.project_id"
            :items="[{ title: t('project_management.announcements.all_projects'), value: null }, ...projects.map(p => ({ title: p.label, value: p.id }))]"
            :label="t('project_management.announcements.audience')"
            :hint="t('project_management.announcements.audience_hint')" persistent-hint
            :error-messages="form.errors.project_id"
            variant="outlined" density="compact" class="mb-3"
          />
          <VTextField
            v-model="form.title" :label="t('project_management.announcements.title_field')"
            :error-messages="form.errors.title" variant="outlined" density="compact" class="mb-3"
          />
          <VTextarea
            v-model="form.body" :label="t('project_management.announcements.body')"
            :error-messages="form.errors.body" variant="outlined" density="compact" rows="4" class="mb-3"
          />
          <VSelect
            v-model="form.severity"
            :items="severities.map(s => ({ title: t(`project_management.announcements.severity_${s}`), value: s }))"
            :label="t('project_management.announcements.severity')"
            :error-messages="form.errors.severity"
            variant="outlined" density="compact"
          />
          <!-- Said before it is sent, not discovered on the invoice. -->
          <VAlert v-if="form.severity === 'urgent'" type="warning" variant="tonal" density="compact" class="mt-2">
            {{ t('project_management.announcements.urgent_warning') }}
          </VAlert>

          <VTextField
            v-model="form.expires_at" type="datetime-local"
            :label="t('project_management.announcements.expires_at')"
            :hint="t('project_management.announcements.expires_hint')" persistent-hint
            :error-messages="form.errors.expires_at"
            variant="outlined" density="compact" class="mt-3"
          />

          <VSwitch v-model="form.is_pinned" :label="t('project_management.announcements.pin')" color="primary" />
          <VSwitch
            v-model="form.requires_ack" color="primary"
            :label="t('project_management.announcements.require_ack')"
            :hint="t('project_management.announcements.require_ack_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.title || !form.body" @click="post">
            {{ t('project_management.announcements.send') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
