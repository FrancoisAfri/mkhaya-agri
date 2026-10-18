<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useOfflineQueue } from '@/composables/useOfflineQueue'

const {
  isOnline,
  pendingCount,
  syncStatus,
  lastSyncResult,
  storageEstimate,
  refreshStorageEstimate,
} = useOfflineQueue()

// ── Storage info popover ──────────────────────────────────────────────────────
const storageMenu = ref(false)

const storageColor = computed(() => {
  const p = storageEstimate.value?.percentUsed ?? 0
  if (p >= 80) return 'error'
  if (p >= 50) return 'warning'
  return 'success'
})

const storageLabel = computed(() => {
  const s = storageEstimate.value
  if (!s) return 'Checking storage…'
  return `${s.usedMB} MB used of ${s.totalMB} MB (${s.percentUsed}%)`
})

// Refresh storage estimate when popover opens
watch(storageMenu, (open) => {
  if (open) refreshStorageEstimate()
})

// ── Queued toast ──────────────────────────────────────────────────────────────
const queuedToast = ref(false)
const queuedLabel = ref('')

window.addEventListener('offline-queued', (e: Event) => {
  const detail = (e as CustomEvent).detail as { label: string }
  queuedLabel.value = detail.label || 'Request saved for later'
  queuedToast.value = true
})

// ── Sync result toasts ────────────────────────────────────────────────────────
const syncDoneToast   = ref(false)
const syncDoneText    = ref('')
const syncDoneColor   = ref<'success' | 'warning' | 'error'>('success')
const syncFailedToast = ref(false)
const syncFailedItems = ref<string[]>([])

watch(lastSyncResult, (result) => {
  if (!result) return
  if (result.success > 0) {
    syncDoneText.value  = `${result.success} change${result.success > 1 ? 's' : ''} synced successfully.`
    syncDoneColor.value = result.failed > 0 ? 'warning' : 'success'
    syncDoneToast.value = true
  }
  if (result.failed > 0) {
    syncFailedItems.value = result.failedLabels
    syncFailedToast.value = true
  }
})
</script>

<template>
  <!-- ── Offline banner ──────────────────────────────────────────────────────── -->
  <Transition name="slide-down">
    <div
      v-if="!isOnline"
      class="status-banner offline-banner d-flex align-center justify-center gap-3 px-4 py-2 flex-wrap"
    >
      <VIcon icon="mdi-wifi-off" size="18" />
      <span class="text-body-2 font-weight-medium">
        No internet connection —
        <template v-if="pendingCount > 0">
          {{ pendingCount }} change{{ pendingCount > 1 ? 's' : '' }} queued, will sync on reconnect
        </template>
        <template v-else>
          changes will be saved and synced when you reconnect
        </template>
      </span>

      <!-- Storage info trigger -->
      <VMenu v-model="storageMenu" location="bottom center" :close-on-content-click="false">
        <template #activator="{ props: menuProps }">
          <VBtn
            v-bind="menuProps"
            size="x-small"
            variant="tonal"
            color="white"
            rounded="pill"
            prepend-icon="mdi-database-outline"
            class="ms-2"
          >
            Storage
          </VBtn>
        </template>

        <VCard min-width="300" rounded="lg" class="pa-4">
          <div class="d-flex align-center justify-space-between mb-3">
            <span class="text-body-2 font-weight-semibold">Offline Storage</span>
            <VChip
              :color="storageEstimate?.isPersisted ? 'success' : 'warning'"
              size="x-small"
              label
            >
              {{ storageEstimate?.isPersisted ? 'Protected' : 'Unprotected' }}
            </VChip>
          </div>

          <template v-if="storageEstimate">
            <!-- Usage bar -->
            <div class="mb-2">
              <div class="d-flex justify-space-between mb-1">
                <span class="text-caption text-medium-emphasis">Used</span>
                <span class="text-caption font-weight-medium">{{ storageLabel }}</span>
              </div>
              <VProgressLinear
                :model-value="storageEstimate.percentUsed"
                :color="storageColor"
                bg-color="grey-lighten-3"
                rounded
                height="8"
              />
            </div>

            <!-- Detail rows -->
            <div class="mt-3 d-flex flex-column gap-1">
              <div class="d-flex justify-space-between text-caption">
                <span class="text-medium-emphasis">Queued requests</span>
                <span class="font-weight-medium">{{ pendingCount }}</span>
              </div>
              <div class="d-flex justify-space-between text-caption">
                <span class="text-medium-emphasis">Storage used</span>
                <span class="font-weight-medium">{{ storageEstimate.usedMB }} MB</span>
              </div>
              <div class="d-flex justify-space-between text-caption">
                <span class="text-medium-emphasis">Total available</span>
                <span class="font-weight-medium">{{ storageEstimate.totalMB }} MB</span>
              </div>
            </div>

            <VAlert
              v-if="!storageEstimate.isPersisted"
              type="warning"
              variant="tonal"
              density="compact"
              class="mt-3 text-caption"
              icon="mdi-shield-alert-outline"
            >
              Storage is not persisted. The browser may clear offline data if disk space is low.
            </VAlert>

            <VAlert
              v-if="storageEstimate.percentUsed >= 80"
              type="error"
              variant="tonal"
              density="compact"
              class="mt-3 text-caption"
              icon="mdi-database-alert-outline"
            >
              Storage is nearly full ({{ storageEstimate.percentUsed }}%). Older offline data may be discarded.
            </VAlert>
          </template>

          <div v-else class="text-center pa-2 text-medium-emphasis text-caption">
            <VProgressCircular indeterminate size="20" class="mr-2" />
            Checking storage…
          </div>
        </VCard>
      </VMenu>
    </div>
  </Transition>

  <!-- ── Syncing banner ──────────────────────────────────────────────────────── -->
  <Transition name="slide-down">
    <div
      v-if="isOnline && syncStatus === 'syncing'"
      class="status-banner syncing-banner d-flex align-center justify-center gap-3 px-4 py-2"
    >
      <VProgressCircular indeterminate size="16" width="2" color="white" />
      <span class="text-body-2 font-weight-medium">
        Syncing {{ pendingCount }} offline change{{ pendingCount !== 1 ? 's' : '' }} to the server…
      </span>
    </div>
  </Transition>

  <!-- ── Queued toast ───────────────────────────────────────────────────────── -->
  <VSnackbar
    v-model="queuedToast"
    color="info"
    location="bottom right"
    timeout="4000"
    rounded="lg"
  >
    <div class="d-flex align-center gap-2">
      <VIcon icon="mdi-cloud-off-outline" size="18" />
      <span>Saved offline: <em>{{ queuedLabel }}</em></span>
    </div>
  </VSnackbar>

  <!-- ── Sync done toast ────────────────────────────────────────────────────── -->
  <VSnackbar
    v-model="syncDoneToast"
    :color="syncDoneColor"
    location="bottom right"
    timeout="4000"
    rounded="lg"
  >
    <div class="d-flex align-center gap-2">
      <VIcon icon="mdi-cloud-check-outline" size="18" />
      <span>{{ syncDoneText }}</span>
    </div>
  </VSnackbar>

  <!-- ── Sync failed toast ──────────────────────────────────────────────────── -->
  <VSnackbar
    v-model="syncFailedToast"
    color="error"
    location="bottom right"
    timeout="8000"
    rounded="lg"
    multi-line
  >
    <div class="d-flex flex-column gap-1">
      <div class="d-flex align-center gap-2">
        <VIcon icon="mdi-cloud-alert-outline" size="18" />
        <span class="font-weight-medium">
          {{ syncFailedItems.length }} change{{ syncFailedItems.length > 1 ? 's' : '' }} could not be synced and were discarded.
        </span>
      </div>
      <ul v-if="syncFailedItems.length <= 4" class="text-caption ps-5 mt-1">
        <li v-for="item in syncFailedItems" :key="item">{{ item }}</li>
      </ul>
    </div>
  </VSnackbar>
</template>

<style scoped>
.status-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 9999;
  min-height: 40px;
}

.offline-banner {
  background: rgb(var(--v-theme-error));
  color: rgb(var(--v-theme-on-error));
}

.syncing-banner {
  background: rgb(var(--v-theme-info));
  color: rgb(var(--v-theme-on-info));
}

.slide-down-enter-active,
.slide-down-leave-active {
  transition: transform 0.25s ease, opacity 0.25s ease;
}
.slide-down-enter-from,
.slide-down-leave-to {
  transform: translateY(-100%);
  opacity: 0;
}
</style>
