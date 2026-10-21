<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute
const props = defineProps<{ templates: Array<{ key: string; name: string; description: string; category: string; module: string; steps: number; forms: number; installed: boolean; requires: string[] }>; modules: Array<{ key: string; label: string }> }>()
const moduleLabel = (k: string) => props.modules.find(m => m.key === k)?.label ?? k

const category = ref<string | null>(null)
const search = ref('')
const categories = computed(() => [...new Set(props.templates.map(t => t.category))].sort())
const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  return props.templates.filter(t => (!category.value || t.category === category.value)
    && (!q || `${t.name} ${t.description}`.toLowerCase().includes(q)))
})
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('workflow.definitions.index'))">Workflows</VBtn>
    <h4 class="text-h4">Templates</h4>
    <div class="text-body-2 text-medium-emphasis mb-4">Ready-to-configure processes. Installing one creates an editable draft (with its forms) that your company owns; check who each step goes to, then publish.</div>
    <VRow class="mb-2">
      <VCol cols="12" md="4"><VTextField v-model="search" label="Search templates" prepend-inner-icon="tabler-search" clearable density="compact" /></VCol>
      <VCol cols="12" md="8">
        <VChipGroup v-model="category" selected-class="text-primary" column>
          <VChip :value="null" filter variant="outlined">All ({{ templates.length }})</VChip>
          <VChip v-for="c in categories" :key="c" :value="c" filter variant="outlined">{{ c }} ({{ templates.filter(t => t.category === c).length }})</VChip>
        </VChipGroup>
      </VCol>
    </VRow>
    <VRow>
      <VCol v-for="t in shown" :key="t.key" cols="12" md="6" lg="4">
        <VCard border class="h-100 d-flex flex-column">
          <VCardItem><VCardTitle class="text-body-1 text-wrap">{{ t.name }}</VCardTitle><VCardSubtitle>{{ t.category }} · {{ moduleLabel(t.module) }}</VCardSubtitle></VCardItem>
          <VCardText class="text-body-2 flex-grow-1">
            {{ t.description }}
            <div v-if="t.requires?.length" class="text-caption text-medium-emphasis mt-2">Also installs: {{ t.requires.join(', ') }}</div>
          </VCardText>
          <VCardActions>
            <span class="text-caption text-medium-emphasis ms-2">{{ t.steps }} steps{{ t.forms ? ` · ${t.forms} form` : '' }}</span>
            <VSpacer />
            <VChip v-if="t.installed" size="small" label class="me-2">Installed</VChip>
            <VBtn color="primary" variant="tonal" @click="router.post(route('workflow.templates.install', t.key))">{{ t.installed ? 'Install again' : 'Install' }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!shown.length" cols="12"><div class="text-body-2 text-medium-emphasis">No templates match.</div></VCol>
    </VRow>
  </div>
</template>
