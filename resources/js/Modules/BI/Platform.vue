<script setup lang="ts">
// The platform-wide Business Intelligence switch, for platform administrators.
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps<{ included: boolean; updatedAt: string | null; updatedBy: string | null; companiesWithAddon: number; whereToSwitch: string }>()
const included = ref(props.included)
const saving = ref(false)
function save() {
  saving.value = true
  router.post('/bi/platform', { included: included.value }, { preserveScroll: true, onFinish: () => { saving.value = false } })
}
</script>

<template>
  <div class="mkbi-plat">
    <p class="mkbi-plat__eyebrow">Platform setting</p>
    <h1 class="mkbi-plat__title">Business Intelligence</h1>
    <p class="mkbi-plat__sub">Decide whether every company gets the Analytics dashboards, or only the companies you sell them to.</p>

    <VCard class="mkbi-plat__card" rounded="xl" elevation="0">
      <VCardText class="d-flex flex-column gap-4">
        <VSwitch v-model="included" color="primary" inset hide-details
          :label="included ? 'Included for every company' : 'Paid add-on — only companies that have it'" />
        <div v-if="included" class="mkbi-plat__note">
          Every company sees <strong>Analytics → Dashboards</strong>. People still need access to it (the access screens decide who), and each chart only shows data from the modules the company has.
        </div>
        <div v-else class="mkbi-plat__note">
          Only companies given Business Intelligence see it — {{ companiesWithAddon }} at the moment. Switch it on for a company here: <strong>{{ whereToSwitch }}</strong>.
        </div>
        <p v-if="updatedAt" class="text-caption text-medium-emphasis mb-0">Last changed {{ new Date(updatedAt).toLocaleString('en-ZA') }}<template v-if="updatedBy"> by {{ updatedBy }}</template>.</p>
      </VCardText>
      <VCardActions class="px-4 pb-4">
        <VSpacer />
        <VBtn color="primary" :loading="saving" :disabled="included === props.included" prepend-icon="tabler-device-floppy" @click="save">Save</VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>

<style scoped>
.mkbi-plat { padding: 8px 16px 48px; max-width: 760px; }
.mkbi-plat__eyebrow { margin: 0 0 4px; font-size: 12px; color: #6A0DAD; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; }
.mkbi-plat__title { margin: 0; font-size: 28px; font-weight: 700; letter-spacing: -.6px; color: #16161F; }
.mkbi-plat__sub { margin: 4px 0 20px; font-size: 14px; color: #77778A; }
.mkbi-plat__card { border: 1px solid #EEEEF3; }
.mkbi-plat__note { background: #F7F3FC; border-radius: 12px; padding: 12px 14px; font-size: 14px; color: #3B3B4A; line-height: 1.5; }
</style>
