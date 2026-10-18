<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Entry = {
  event_type: string; title: string; message: string; url: string | null; reference: string | null; recipient: string | null; reason: string
  level: number; occurrences: number; in_digest: boolean; channels: string[]; first_seen_at: string | null; last_seen_at: string | null
  sent_at: string | null; resolved_at: string | null
}

const props = defineProps<{ entries: Entry[]; events: string[]; filters: { event: string | null; state: string }; can: { run: boolean } }>()

const f = ref({ ...props.filters })
watch(f, v => router.get(route('manufacturing.notifications.log'), v, { preserveState: true, replace: true }), { deep: true })
const when = (s: string | null) => s ? s.slice(0, 16).replace('T', ' ') : '—'
const running = ref(false)
const run = () => router.post(route('manufacturing.notifications.run'), {}, { preserveScroll: true, onStart: () => { running.value = true }, onFinish: () => { running.value = false } })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.alerts.log_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.alerts.log_subtitle') }}</p>
      </div>
      <VBtn v-if="can.run" color="primary" prepend-icon="tabler-bell-ringing" :loading="running" @click="run">{{ t('manufacturing.alerts.run_now') }}</VBtn>
    </div>
    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap">
        <VSelect v-model="f.event" :items="events.map(e => ({ value: e, title: t(`manufacturing.alerts.event.${e}`) }))" clearable hide-details style="max-width: 320px" :label="t('manufacturing.alerts.event_label')" />
        <VBtnToggle v-model="f.state" density="compact" variant="outlined" mandatory>
          <VBtn value="open">{{ t('manufacturing.alerts.standing') }}</VBtn>
          <VBtn value="all">{{ t('manufacturing.alerts.all') }}</VBtn>
        </VBtnToggle>
      </VCardText>
    </VCard>
    <VCard border>
      <VTable density="compact">
        <thead><tr>
          <th>{{ t('manufacturing.alerts.alert') }}</th><th>{{ t('manufacturing.alerts.recipient') }}</th><th>{{ t('manufacturing.alerts.why') }}</th>
          <th class="text-end">{{ t('manufacturing.alerts.seen') }}</th><th>{{ t('manufacturing.alerts.first_last') }}</th><th>{{ t('manufacturing.alerts.state') }}</th>
        </tr></thead>
        <tbody>
          <tr v-for="(e, i) in entries" :key="i">
            <td>
              <div class="font-weight-medium"><a v-if="e.url" :href="e.url">{{ e.title }}</a><span v-else>{{ e.title }}</span></div>
              <div class="text-caption text-medium-emphasis">{{ t(`manufacturing.alerts.event.${e.event_type}`) }} · {{ e.message }}</div>
            </td>
            <td>{{ e.recipient ?? '—' }}</td>
            <td>
              <VChip size="x-small" label :color="e.level ? 'error' : undefined">{{ t(`manufacturing.alerts.reason.${e.reason}`) }}</VChip>
              <VChip v-if="e.in_digest" size="x-small" label class="ms-1">{{ t('manufacturing.alerts.digest') }}</VChip>
            </td>
            <td class="text-end">{{ e.occurrences }}</td>
            <td class="text-caption">{{ when(e.first_seen_at) }}<br>{{ when(e.last_seen_at) }}</td>
            <td><VChip size="x-small" label :color="e.resolved_at ? 'success' : 'warning'">{{ e.resolved_at ? t('manufacturing.alerts.resolved') : t('manufacturing.alerts.standing') }}</VChip></td>
          </tr>
          <tr v-if="!entries.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.alerts.no_entries') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
