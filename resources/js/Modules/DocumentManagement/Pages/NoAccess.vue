<script setup lang="ts">
/**
 * "You cannot open this — would you like to ask?"
 *
 * The page somebody lands on when a colleague sends them a link to a document they have no access
 * to. A bare 403 sends them to ask in a corridor and whatever grant follows is recorded nowhere;
 * this makes asking the obvious next step and writes it down.
 *
 * It shows the title and nothing else. Everything that made the document confidential stays
 * withheld — and the title is already disclosed by the fact that somebody sent them the link.
 */
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

const props = defineProps<{
  document: { uuid: string; title: string; folder: string | null }
  already_requested: boolean
}>()

const form = useForm({ document: props.document.uuid, reason: '' })

function ask() {
  form.post(route('document-management.access-requests.store'), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4 d-flex justify-center">
    <VCard elevation="0" border style="max-width:34rem;inline-size:100%">
      <VCardText class="text-center pt-8">
        <VIcon size="42" icon="mdi-lock-outline" class="mb-3" style="opacity:.45" />
        <h1 class="text-h6 font-weight-bold mb-1">{{ d('access_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ d('access_hint') }}</p>

        <VCard elevation="0" color="surface-variant" class="text-start mb-4">
          <VCardText class="py-3">
            <div class="text-body-2 font-weight-medium">{{ document.title }}</div>
            <div v-if="document.folder" class="text-caption text-medium-emphasis">{{ document.folder }}</div>
          </VCardText>
        </VCard>
      </VCardText>

      <VDivider />

      <!-- Asked already: saying so stops somebody clicking again and wondering whether it worked. -->
      <VCardText v-if="already_requested || form.recentlySuccessful" class="text-center">
        <VIcon size="20" icon="mdi-check-circle-outline" color="success" class="mb-1" />
        <div class="text-body-2">{{ d('access_already') }}</div>
      </VCardText>

      <template v-else>
        <VCardText>
          <VTextarea
            v-model="form.reason" :label="d('access_reason')" rows="2" density="comfortable"
            :hint="d('access_reason_hint')" persistent-hint
            :error-messages="form.errors.reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" :href="route('document-management.index')">{{ d('access_back') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="ask">{{ d('access_ask') }}</VBtn>
        </VCardActions>
      </template>
    </VCard>
  </div>
</template>
