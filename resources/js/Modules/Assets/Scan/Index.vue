<script setup lang="ts">
import { ref, nextTick, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type ResolvedAsset = {
  id: number; uuid: string; asset_number: string | null
  name: string; asset_status: string | null; location: string | null
}

const code = ref('')
const loading = ref(false)
const notFound = ref(false)
const lastMissed = ref('')
const inputRef = ref<HTMLInputElement | null>(null)

onMounted(() => inputRef.value?.focus())

async function submit() {
  const value = code.value.trim()
  if (!value || loading.value) return

  loading.value = true
  notFound.value = false

  try {
    const res = await fetch(route('assets.scan.resolve'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({ code: value }),
    })
    const data: { found: boolean; asset: ResolvedAsset | null } = await res.json()

    if (data.found && data.asset) {
      router.visit(`/assets/${data.asset.id}`)
      return
    }

    notFound.value = true
    lastMissed.value = value
    code.value = ''
    await nextTick()
    inputRef.value?.focus()
  } catch (e) {
    notFound.value = true
    lastMissed.value = value
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="pa-4">
    <VContainer style="max-width: 640px">
      <VCard>
        <VCardTitle>{{ t('Scan Asset') }}</VCardTitle>
        <VCardSubtitle>
          {{ t('Scan a barcode/QR tag or type the asset number, then press Enter.') }}
        </VCardSubtitle>

        <VCardText>
          <form @submit.prevent="submit">
            <VTextField
              ref="inputRef"
              v-model="code"
              :label="t('Barcode / Asset number')"
              :loading="loading"
              :disabled="loading"
              autofocus
              clearable
              prepend-inner-icon="mdi-barcode-scan"
              hint="A hardware scanner types into this field and hits Enter automatically."
              persistent-hint
              @keyup.enter="submit"
            />

            <VBtn
              class="mt-4"
              color="primary"
              block
              :loading="loading"
              type="submit"
            >
              {{ t('Find asset') }}
            </VBtn>
          </form>

          <VAlert
            v-if="notFound"
            class="mt-4"
            type="warning"
            variant="tonal"
            density="comfortable"
          >
            {{ t('No asset found for code') }}: <strong>{{ lastMissed }}</strong>
          </VAlert>
        </VCardText>
      </VCard>
    </VContainer>
  </div>
</template>
