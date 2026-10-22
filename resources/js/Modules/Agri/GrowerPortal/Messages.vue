<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import GrowerShell, { type Brand, type Me } from './GrowerShell.vue'
import { human, statusColor, today } from '../Components/agri'

defineOptions({ layout: null })
const props = defineProps<{
  brand: Brand; me: Me; locale: string
  notices: Array<{ id: string; title: string; body: string; date: string; to_me: boolean }>
  reports: Array<{ id: string; kind: string; date: string; contract: string | null; planted_on: string | null; hectares: number | null; details: string;
    has_photo: boolean; status: string; response: string | null }>
  contracts: Array<{ value: string; title: string }>
}>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})

const form = useForm<{ kind: string; contract_id: string | null; planted_on: string | null; hectares: number | null; details: string; photo: File | null }>({
  kind: 'issue', contract_id: props.contracts[0]?.value ?? null, planted_on: today(), hectares: null, details: '', photo: null,
})
const send = () => form.post(route('grower.portal.reports.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset('details', 'photo', 'hectares') })
const pwd = useForm({ password: '', password_confirmation: '' })
const savePassword = () => pwd.post(route('grower.portal.password'), { preserveScroll: true, onSuccess: () => pwd.reset() })
const language = ref(props.locale)
const saveLanguage = () => router.post(route('grower.portal.locale'), { locale: language.value }, { preserveScroll: true })
const onPhoto = (f: File | File[] | null) => { form.photo = Array.isArray(f) ? (f[0] ?? null) : f }
</script>

<template>
  <GrowerShell :brand="brand" :me="me" :title="k('messages')">
    <VCard :title="k('from_company')" class="mb-4">
      <VList>
        <VListItem v-for="n in notices" :key="n.id">
          <VListItemTitle class="font-weight-medium">{{ n.title }}</VListItemTitle>
          <VListItemSubtitle>{{ n.date }}<template v-if="n.to_me"> · {{ k('to_you') }}</template></VListItemSubtitle>
          <div class="text-body-2 mt-1" style="white-space: pre-line">{{ n.body }}</div>
        </VListItem>
        <VListItem v-if="!notices.length"><VListItemSubtitle>{{ k('no_messages') }}</VListItemSubtitle></VListItem>
      </VList>
    </VCard>

    <VCard :title="k('send_report')" class="mb-4">
      <VCardText>
        <VForm @submit.prevent="send">
          <VBtnToggle v-model="form.kind" mandatory divided density="comfortable" class="mb-4">
            <VBtn value="issue">{{ k('kind_issue') }}</VBtn><VBtn value="planting">{{ k('kind_planting') }}</VBtn>
          </VBtnToggle>
          <VSelect v-if="contracts.length" v-model="form.contract_id" :items="contracts" :label="k('contract')" variant="outlined" class="mb-3" clearable />
          <VRow v-if="form.kind === 'planting'" dense>
            <VCol cols="6"><VTextField v-model="form.planted_on" type="date" :label="k('planted_on')" variant="outlined" :error-messages="form.errors.planted_on" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.hectares" type="number" :label="k('hectares')" variant="outlined" :error-messages="form.errors.hectares" /></VCol>
          </VRow>
          <VTextarea v-model="form.details" :label="form.kind === 'planting' ? k('planting_details') : k('issue_details')" rows="3" auto-grow variant="outlined" class="mb-3"
            :error-messages="form.errors.details" />
          <VFileInput :label="k('photo')" accept="image/*" capture="environment" prepend-icon="tabler-camera" variant="outlined" class="mb-3" :error-messages="form.errors.photo"
            @update:model-value="onPhoto" />
          <VBtn type="submit" color="primary" :loading="form.processing">{{ k('send') }}</VBtn>
        </VForm>
      </VCardText>
    </VCard>

    <VCard :title="k('my_reports')" class="mb-4">
      <VList>
        <VListItem v-for="r in reports" :key="r.id">
          <VListItemTitle class="d-flex align-center ga-2">{{ k('kind_' + r.kind) }} · {{ r.date }}
            <VChip size="x-small" :color="statusColor(r.status === 'answered' ? 'active' : r.status)">{{ human(r.status) }}</VChip></VListItemTitle>
          <div class="text-body-2">{{ r.details }}</div>
          <div v-if="r.response" class="text-body-2 mt-1 text-primary">{{ k('answer') }}: {{ r.response }}</div>
        </VListItem>
        <VListItem v-if="!reports.length"><VListItemSubtitle>{{ k('no_reports') }}</VListItemSubtitle></VListItem>
      </VList>
    </VCard>

    <VCard :title="k('settings')">
      <VCardText>
        <div class="d-flex flex-wrap align-center ga-3 mb-4">
          <VSelect v-model="language" :items="[{ title: 'English', value: 'en' }, { title: 'Afrikaans', value: 'af' }]" :label="k('message_language')" variant="outlined"
            density="compact" hide-details style="max-width: 16rem" />
          <VBtn variant="tonal" @click="saveLanguage">{{ k('save') }}</VBtn>
        </div>
        <p class="text-body-2 mb-2">{{ me.has_password ? k('password_change') : k('password_set') }}</p>
        <VForm class="d-flex flex-wrap ga-3" @submit.prevent="savePassword">
          <VTextField v-model="pwd.password" type="password" :label="k('password')" autocomplete="new-password" variant="outlined" density="compact" style="max-width: 16rem"
            :error-messages="pwd.errors.password" />
          <VTextField v-model="pwd.password_confirmation" type="password" :label="k('password_confirm')" autocomplete="new-password" variant="outlined" density="compact"
            style="max-width: 16rem" />
          <VBtn type="submit" variant="tonal" :loading="pwd.processing">{{ k('save') }}</VBtn>
        </VForm>
      </VCardText>
    </VCard>
  </GrowerShell>
</template>
