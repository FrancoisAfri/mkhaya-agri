<script setup lang="ts">
import { watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useOfflineGuard, startGuard, stopGuard } from '@/composables/useOfflineGuard'
import { useOfflineQueue } from '@/composables/useOfflineQueue'

const page    = usePage()
const { isOnline } = useOfflineQueue()
const {
  isLockedOut,
  showWarning,
  minutesRemaining,
  elapsedFormatted,
  limitFormatted,
  policy,
} = useOfflineGuard()

function currentUserId(): number | null {
  return (page.props as any)?.auth?.user?.id ?? null
}

// Start / stop the guard when connectivity changes
watch(isOnline, (online) => {
  if (online) {
    stopGuard(currentUserId())
  } else {
    startGuard(currentUserId())
  }
}, { immediate: true })

// Guard-unlocked custom event fires when connectivity poll detects a real connection
window.addEventListener('offline-guard-unlocked', () => {
  stopGuard(currentUserId())
})

// Dismiss warning manually (guard still runs in background)
function dismissWarning() {
  // showWarning is readonly; we dispatch a signal to the composable
  // The composable already set warningSeen = true so it won't show again
  ;(showWarning as any).value = false
}
</script>

<template>
  <!-- ── Full-screen lockout overlay ──────────────────────────────────────────── -->
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="isLockedOut" class="offline-lockout-overlay">
        <VCard max-width="480" rounded="xl" class="mx-4 text-center">

          <!-- Icon -->
          <div class="pt-8 pb-2">
            <div class="lockout-icon-ring mx-auto mb-4">
              <VIcon icon="mdi-wifi-off" size="40" color="error" />
            </div>
            <h2 class="text-h5 font-weight-bold mb-1">Offline Limit Reached</h2>
            <p class="text-body-2 text-medium-emphasis px-6">
              {{ policy?.lockout_message }}
            </p>
          </div>

          <VDivider class="mx-6" />

          <!-- Stats -->
          <div class="pa-6">
            <VRow dense class="mb-4">
              <VCol cols="6">
                <div class="stat-box rounded-lg pa-3">
                  <p class="text-caption text-medium-emphasis mb-1">Offline for</p>
                  <p class="text-h6 font-weight-bold text-error mb-0">{{ elapsedFormatted }}</p>
                </div>
              </VCol>
              <VCol cols="6">
                <div class="stat-box rounded-lg pa-3">
                  <p class="text-caption text-medium-emphasis mb-1">Allowed limit</p>
                  <p class="text-h6 font-weight-bold mb-0">{{ limitFormatted }}</p>
                </div>
              </VCol>
            </VRow>

            <!-- Connectivity check indicator -->
            <div class="d-flex align-center justify-center gap-2 text-caption text-medium-emphasis mb-5">
              <VProgressCircular indeterminate size="14" width="2" color="primary" />
              Checking for connection every 15 seconds…
            </div>

            <VAlert type="info" variant="tonal" density="compact" class="text-left text-body-2">
              <strong>Your work is safe.</strong>
              All {{ $page.props.offline_policy ? '' : '' }}changes you made offline are saved on this device
              and will sync automatically when you reconnect.
            </VAlert>
          </div>

        </VCard>
      </div>
    </Transition>
  </Teleport>

  <!-- ── Pre-lockout warning snackbar ─────────────────────────────────────────── -->
  <VSnackbar
    :model-value="showWarning && !isLockedOut"
    color="warning"
    location="top center"
    :timeout="-1"
    rounded="lg"
    multi-line
  >
    <div class="d-flex align-center gap-3">
      <VIcon icon="mdi-clock-alert-outline" size="22" />
      <div>
        <p class="font-weight-semibold mb-0">Offline time limit approaching</p>
        <p class="text-caption mb-0">
          You have been offline for {{ elapsedFormatted }}.
          The system will lock in <strong>{{ minutesRemaining }} minute{{ minutesRemaining !== 1 ? 's' : '' }}</strong>.
          Please reconnect to continue working.
        </p>
      </div>
    </div>
    <template #actions>
      <VBtn variant="text" size="small" @click="dismissWarning">Dismiss</VBtn>
    </template>
  </VSnackbar>
</template>

<style scoped>
.offline-lockout-overlay {
  position: fixed;
  inset: 0;
  z-index: 99999;
  background: rgba(0, 0, 0, 0.72);
  backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
}

.lockout-icon-ring {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: rgba(var(--v-theme-error), 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
}

.stat-box {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
