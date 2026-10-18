<script setup lang="ts">
/**
 * Asking before something cannot be undone.
 *
 * The activator is a slot rather than a prop, so the button and the question it raises are written
 * in one place. Ten destructive actions in this module fired straight from a click because the
 * confirmation was a separate thing somebody had to remember to add — this makes forgetting it the
 * harder path rather than the default one.
 *
 * What it says matters as much as that it asks. "Are you sure?" tells nobody anything; the body
 * names the thing and states the consequence, because the two questions somebody actually has are
 * "which one is this?" and "what happens to what is inside it?".
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

withDefaults(defineProps<{
  title: string
  body?: string
  /** What is lost. Shown apart from the body, because it is the part worth reading twice. */
  consequence?: string
  confirmLabel?: string
  /** Destructive by default — everything routed through here is. */
  danger?: boolean
  disabled?: boolean
}>(), {
  danger: true,
  disabled: false,
})

const emit = defineEmits<{ confirm: [] }>()

const open = ref(false)

function go() {
  open.value = false
  emit('confirm')
}
</script>

<template>
  <slot name="activator" :open="() => { if (!disabled) open = true }" />

  <VDialog v-model="open" max-width="440">
    <VCard>
      <VCardTitle class="text-subtitle-1">{{ title }}</VCardTitle>
      <VCardText>
        <p v-if="body" class="text-body-2 mb-2">{{ body }}</p>
        <VAlert v-if="consequence" :type="danger ? 'warning' : 'info'" variant="tonal" density="compact">
          {{ consequence }}
        </VAlert>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="open = false">{{ d('cancel') }}</VBtn>
        <VBtn :color="danger ? 'error' : 'primary'" variant="tonal" @click="go">
          {{ confirmLabel ?? d('confirm_yes') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
