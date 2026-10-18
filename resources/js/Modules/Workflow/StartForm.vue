<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import DynamicForm from './components/DynamicForm.vue'

const route = ziggyRoute
const props = defineProps<{
  workflow: { uuid: string; name: string; description: string | null; confidential: boolean }
  fields: any[]; draft: Record<string, any> | null
  people: Array<{ id: number; name: string }>; departments: Array<{ id: number; name: string }>; suppliers: Array<{ id: number; name: string }>
}>()
const values = ref<Record<string, any>>({ ...(props.draft ?? {}) })
const form = useForm({ values: {} as Record<string, any> })
// One key per form opened: pressing Submit twice cannot start two requests.
const idem = crypto.randomUUID ? crypto.randomUUID() : String(Date.now())
function submit() {
  form.transform(() => ({ values: values.value })).post(route('workflow.start.store', props.workflow.uuid), { headers: { 'Idempotency-Key': idem } })
}
function saveDraft() {
  router.post(route('workflow.start.draft', props.workflow.uuid), { values: values.value }, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('workflow.start'))">Start a request</VBtn>
    <VRow>
      <VCol cols="12" md="8" lg="7">
        <VCard border>
          <VCardItem>
            <VCardTitle class="text-h5">{{ workflow.name }}</VCardTitle>
            <VCardSubtitle v-if="workflow.description" class="text-wrap">{{ workflow.description }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VAlert v-if="workflow.confidential" type="warning" variant="tonal" density="compact" class="mb-4">
              This is a confidential process. Only the people named for it, and those who handle your request, can see it. Notifications carry a link only.
            </VAlert>
            <VAlert v-if="draft" type="info" variant="tonal" density="compact" class="mb-4">Your saved draft has been restored.</VAlert>
            <DynamicForm v-model="values" :fields="fields" :errors="form.errors" :people="people" :departments="departments" :suppliers="suppliers" />
            <div v-if="!fields.length" class="text-body-2 text-medium-emphasis">This request needs no details; submit it to start.</div>
          </VCardText>
          <VCardActions class="px-4 pb-4">
            <VBtn variant="text" @click="saveDraft">Save draft</VBtn>
            <VSpacer />
            <VBtn color="primary" size="large" :loading="form.processing" prepend-icon="tabler-send" @click="submit">Submit</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
