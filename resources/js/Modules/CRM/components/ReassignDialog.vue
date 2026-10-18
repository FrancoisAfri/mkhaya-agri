<script lang="ts" setup>
/**
 * Hands the selected records to another sales person.
 *
 * Shared by Leads, Deals, Accounts and Contacts — the wording changes with the record type but the
 * decision the user is making is identical, and a manager should not have to relearn it per screen.
 * The owner list is whatever the server said this user may assign to, so a manager only ever sees
 * their own team in the dropdown.
 */
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

interface Owner { id: number; name: string }

const props = defineProps<{
  modelValue: boolean
  type: 'lead' | 'deal' | 'account' | 'contact'
  owners: Owner[]
  ids: number[]
}>()

const emit = defineEmits<{ 'update:modelValue': [boolean]; done: [] }>()

const form = useForm({ type: props.type, ids: [] as number[], owner_id: null as number | null, move_open_activities: true })
const search = ref('')

const open = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

// The picker is a plain filtered list rather than an autocomplete so it stays usable for a manager
// with four reports, which is the common case.
const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  return q ? props.owners.filter(o => o.name.toLowerCase().includes(q)) : props.owners
})

const count = computed(() => props.ids.length)

const label = computed(() => t(`crm_reassign.records.${props.type}`, count.value))

watch(open, isOpen => {
  if (isOpen) {
    form.reset()
    form.type = props.type
    form.owner_id = null
    search.value = ''
  }
})

function submit() {
  form.ids = [...props.ids]
  form.post(route('crm.reassign'), {
    preserveScroll: true,
    onSuccess: () => { open.value = false; emit('done') },
  })
}
</script>

<template>
  <VDialog v-model="open" max-width="480" persistent>
    <VCard>
      <VCardTitle>{{ t('crm_reassign.title') }}</VCardTitle>

      <VCardText>
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ t('crm_reassign.description', { count, records: label }) }}
        </p>

        <VTextField
          v-if="owners.length > 8"
          v-model="search"
          :label="t('crm_reassign.search')"
          prepend-inner-icon="tabler-search"
          density="compact"
          variant="outlined"
          clearable
          hide-details
          class="mb-3"
        />

        <VSelect
          v-model="form.owner_id"
          :items="shown.map(o => ({ title: o.name, value: o.id }))"
          :label="t('crm_reassign.new_owner')"
          :error-messages="form.errors.owner_id"
          variant="outlined"
          :no-data-text="t('crm_reassign.no_owners')"
        />

        <VCheckbox
          v-model="form.move_open_activities"
          :label="t('crm_reassign.move_activities')"
          :hint="t('crm_reassign.move_activities_hint')"
          persistent-hint
          density="compact"
        />

        <VAlert v-if="form.errors.ids" type="error" variant="tonal" density="compact" class="mt-3">
          {{ form.errors.ids }}
        </VAlert>
      </VCardText>

      <VDivider />

      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="tonal" @click="open = false">{{ t('crm_reassign.cancel') }}</VBtn>
        <VBtn
          color="primary"
          :loading="form.processing"
          :disabled="!form.owner_id || !count"
          @click="submit"
        >
          {{ t('crm_reassign.confirm') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
