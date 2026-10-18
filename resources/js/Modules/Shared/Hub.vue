<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { hubCardIcon } from '@/utils/faToMdi'

const { t } = useI18n()

interface Card { title: string; desc: string; icon: string; path: string; color: string }
defineProps<{ module: string; group: string; title: string; subtitle: string; cards: Card[] }>()

function open(path: string) { router.visit(path) }
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t(title) }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t(subtitle) }}</p>
    </div>

    <VRow>
      <VCol v-for="c in cards" :key="c.path" cols="12" sm="6" md="4" lg="3">
        <VCard elevation="0" border rounded="lg" class="hub-card h-100 cursor-pointer" @click="open(c.path)">
          <VCardText class="d-flex flex-column h-100">
            <VAvatar :color="c.color" variant="tonal" size="48" class="mb-3">
              <VIcon size="26">{{ hubCardIcon(c.icon) }}</VIcon>
            </VAvatar>
            <div class="text-body-1 font-weight-semibold mb-1">{{ t(c.title) }}</div>
            <p class="text-caption text-medium-emphasis mb-3 flex-grow-1" style="white-space:normal">{{ t(c.desc) }}</p>
            <div class="d-flex align-center text-primary text-body-2 font-weight-medium">
              {{ t('hub_ui.open') }}<VIcon size="18" class="ms-1">mdi-arrow-right</VIcon>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard v-if="!cards.length" elevation="0" border rounded="lg">
      <VCardText class="text-center py-10 text-medium-emphasis">{{ t('hub_ui.no_access') }}</VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.hub-card { transition: box-shadow .15s, transform .15s; }
.hub-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.10) !important; transform: translateY(-2px); }
</style>
