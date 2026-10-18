<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

// Evidence on a tax record: listed with a link to Document Management's own preview (its access
// rules decide who may open it), and an upload that files the document there and links it here.
const props = defineProps<{
  type: 'registrations' | 'liabilities' | 'reconciliations' | 'returns' | 'computations'
  uuid: string
  items: Array<{ id: number; title: string; added?: string | null; url?: string }>
  canAdd: boolean
  hint?: string
}>()

const open = ref(false)
const form = useForm({ file: null as File | null, title: '' })
function upload() {
  form.post(route('tax.evidence.store', [props.type, props.uuid]), {
    forceFormData: true, preserveScroll: true, onSuccess: () => { open.value = false; form.reset() },
  })
}
</script>

<template>
  <div>
    <div class="d-flex align-center">
      <span class="text-overline">Evidence</span>
      <VBtn v-if="canAdd" size="x-small" variant="text" prepend-icon="tabler-upload" class="ms-2" @click="open = true">Attach</VBtn>
    </div>
    <div v-if="items.length" class="d-flex flex-wrap gap-1">
      <VChip v-for="d in items" :key="d.id" size="small" prepend-icon="tabler-file" :href="d.url" target="_blank">{{ d.title }}</VChip>
    </div>
    <p v-else class="text-caption text-medium-emphasis mb-0">{{ hint ?? 'Nothing attached.' }}</p>

    <VDialog v-model="open" max-width="480">
      <VCard title="Attach evidence">
        <VCardText>
          <p class="text-caption text-medium-emphasis">Filed in Document Management (file checks and malware scanning apply) and linked to this record. Evidence is added, never replaced.</p>
          <VFileInput label="File" density="compact" :error-messages="form.errors.file" @update:model-value="(f: any) => form.file = Array.isArray(f) ? f[0] : f" />
          <VTextField v-model="form.title" label="What it is" density="compact" :error-messages="form.errors.title" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="open = false">Cancel</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.file || !form.title" @click="upload">Upload</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
