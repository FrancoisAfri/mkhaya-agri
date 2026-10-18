<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const page = usePage()
const year = new Date().getFullYear()

// White-label: the tenant's footer carries THEIR logo and name only — the Mkhaya
// copyright and the "Powered by" mark are both hidden. resolveBranding() only
// populates these when the feature is active, so falsy = default Mkhaya footer.
const whiteLabel = computed(() => Boolean(page.props.branding?.white_label))
const systemLogo = computed(() => page.props.branding?.system_logo || '/images/logo_default.png')
const systemName = computed(() => page.props.branding?.system_name || 'MKHAYA HUB')
</script>
<template>
  <footer class="bg-[#020d31] text-white py-5 px-4 sm:px-8">
    <div class="max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <img :src="systemLogo" :alt="systemName" class="w-10 h-10 object-contain" />
        <span class="font-bold tracking-wide text-sm sm:text-base">{{ systemName }}</span>
      </div>
      <p v-if="!whiteLabel" class="text-gray-400 text-xs sm:text-sm text-center">
        <i class="fa-regular fa-copyright mr-1"></i> {{ year }} Mkhaya Hub. {{ t('layout.footer.all_rights') }}
      </p>
      <p v-if="!whiteLabel" class="text-gray-500 text-xs flex items-center gap-1">
        {{ t('layout.footer.powered_by') }}
        <span class="text-teal-400 font-semibold ml-1">
          <img src="/images/logo_default.png" alt="Mkhaya Hub Logo" class="w-10 h-10 object-contain" />
        </span>
      </p>
    </div>
  </footer>
</template>
