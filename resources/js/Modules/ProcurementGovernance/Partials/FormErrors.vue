<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

/**
 * A summary of what a form rejected, announced to assistive technology.
 *
 * Vuetify surfaces per-field messages through `error-messages`, but `VMessages` carries no
 * `role="alert"` — so a screen-reader user submits a twenty-field procurement form, nothing is
 * spoken, and the page appears to have simply done nothing. On forms this long that is not a minor
 * omission: the failed field may be well off-screen.
 *
 * `role="alert"` (which implies aria-live="assertive") means the summary is read the moment it
 * appears. The per-field messages stay where they are — this is in addition to them, not instead,
 * because the summary says *what* is wrong and the field says *where*.
 */
const props = defineProps<{
  /** Inertia's `form.errors` — a map of field name to message. */
  errors: Record<string, string>
}>()

const messages = computed(() => Object.values(props.errors ?? {}).filter(Boolean))
</script>

<template>
  <!-- Always rendered, so assistive technology is already observing the region when it fills.
       A node that appears at the same moment its content does is announced unreliably. -->
  <div role="alert" aria-live="assertive">
    <VAlert
      v-if="messages.length"
      type="error"
      variant="tonal"
      class="mb-4"
      icon="mdi-alert-circle-outline"
    >
      <div class="text-body-2 font-weight-medium mb-1">
        {{ t('procurement_governance.common.form_errors', { n: messages.length }) }}
      </div>
      <ul class="text-body-2 ps-4 mb-0">
        <li v-for="(message, i) in messages" :key="i">{{ message }}</li>
      </ul>
    </VAlert>
  </div>
</template>
