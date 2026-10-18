<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardTitle class="d-flex align-center pa-4">
          <span class="text-h6">{{ t('fleet_notification_preferences.page_title') }}</span>
          <VSpacer />
          <VBtn color="primary" :loading="saving" @click="save">{{ t('fleet_notification_preferences.save') }}</VBtn>
        </VCardTitle>

        <VDivider />

        <!-- Push notification banner -->
        <VCardText v-if="vapidKey">
          <VAlert
            :type="pushEnabled ? 'success' : 'info'"
            density="compact"
            class="mb-0"
          >
            <template v-if="pushEnabled">
              {{ t('fleet_notification_preferences.push_active') }}
              <VBtn variant="text" size="small" color="inherit" @click="disablePush">{{ t('fleet_notification_preferences.disable') }}</VBtn>
            </template>
            <template v-else>
              {{ t('fleet_notification_preferences.push_inactive') }}
              <VBtn variant="text" size="small" color="inherit" @click="enablePush">{{ t('fleet_notification_preferences.enable') }}</VBtn>
            </template>
          </VAlert>
        </VCardText>

        <!-- Preferences matrix -->
        <VCardText>
          <VTable density="compact" class="preference-table">
            <thead>
              <tr>
                <th class="text-left" style="width:220px;">{{ t('fleet_notification_preferences.event') }}</th>
                <th v-for="ch in channels" :key="ch" class="text-center text-capitalize" style="width:90px;">
                  <VIcon size="18" :icon="channelIcon(ch)" class="me-1" />
                  {{ ch }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(label, eventKey) in events" :key="eventKey">
                <td class="text-body-2">{{ label }}</td>
                <td v-for="ch in channels" :key="ch" class="text-center">
                  <VCheckbox
                    v-model="local[ch][eventKey]"
                    hide-details
                    density="compact"
                    class="d-inline-flex"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Calendar + Map views - FR-5.3 -->
    <VCol cols="12">
      <FleetCalendarView />
    </VCol>
  </VRow>
</template>

<script setup lang="ts">
import { reactive, ref, onMounted } from 'vue'
import axios from 'axios'
import FleetCalendarView from '../Bookings/CalendarView.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  matrix: Record<string, Record<string, { label: string; enabled: boolean }>>
  channels: string[]
  events: Record<string, string>
  vapidKey: string
}>()

function channelIcon(ch: string): string {
  return {
    email: 'ri-mail-line',
    sms: 'ri-message-2-line',
    whatsapp: 'ri-whatsapp-line',
    push: 'ri-notification-3-line',
    in_app: 'ri-apps-line',
  }[ch] ?? 'ri-bell-line'
}

// Build local reactive copy from matrix prop
const local = reactive<Record<string, Record<string, boolean>>>(
  Object.fromEntries(
    props.channels.map(ch => [
      ch,
      Object.fromEntries(
        Object.keys(props.events).map(ev => [ev, props.matrix[ch]?.[ev]?.enabled ?? true])
      ),
    ])
  )
)

const saving = ref(false)
const pushEnabled = ref(false)

async function save() {
  saving.value = true
  const preferences: { channel: string; event: string; enabled: boolean }[] = []
  for (const ch of props.channels) {
    for (const ev of Object.keys(props.events)) {
      preferences.push({ channel: ch, event: ev, enabled: local[ch][ev] })
    }
  }
  try {
    await axios.post('/fleet/notifications/preferences', { preferences })
  } finally {
    saving.value = false
  }
}

// Web Push helpers
async function enablePush() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return

  try {
    const reg = await navigator.serviceWorker.ready
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(props.vapidKey),
    })

    const json = sub.toJSON()
    await axios.post('/fleet/push/subscribe', {
      endpoint:     json.endpoint,
      p256dh_key:   json.keys?.p256dh,
      auth_key:     json.keys?.auth,
      device_label: navigator.userAgent.slice(0, 100),
    })

    pushEnabled.value = true
  } catch (err) {
    console.error('Failed to enable push notifications:', err)
  }
}

async function disablePush() {
  try {
    const reg = await navigator.serviceWorker.ready
    const sub = await reg.pushManager.getSubscription()
    if (sub) {
      await axios.post('/fleet/push/unsubscribe', { endpoint: sub.endpoint })
      await sub.unsubscribe()
    }
    pushEnabled.value = false
  } catch (err) {
    console.error('Failed to disable push notifications:', err)
  }
}

function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(b64)
  const outputArray = new Uint8Array(raw.length)
  for (let i = 0; i < raw.length; ++i) {
    outputArray[i] = raw.charCodeAt(i)
  }
  return outputArray
}

onMounted(async () => {
  if ('serviceWorker' in navigator) {
    try {
      const reg = await navigator.serviceWorker.ready
      const sub = await reg.pushManager.getSubscription()
      pushEnabled.value = !!sub
    } catch {
      pushEnabled.value = false
    }
  }
})
</script>

<style scoped>
.preference-table th, .preference-table td { padding: 6px 8px; }
</style>
