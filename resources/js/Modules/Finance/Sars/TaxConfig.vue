<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()
const page = usePage()

interface Config { vat_number: string | null; vat_registered: boolean; vat_frequency: string; paye_reference: string | null; uif_reference: string | null; sdl_reference: string | null; sdl_liable: boolean; income_tax_number: string | null; sic_code: string | null; contact_person: string | null; contact_email: string | null; efiling_username: string | null }
interface VErr { entity: string; id: any; field: string; message: string }
const props = defineProps<{ config: Config; frequencies: string[]; validation: { validated: number; errors: VErr[] } }>()

const form = useForm<any>({ ...props.config, vat_registered: !!props.config.vat_registered, sdl_liable: !!props.config.sdl_liable })
function save() { form.post(route('finance.accounting.sars.config.save'), { preserveScroll: true }) }

// The validation prop refreshes after each save.
const validation = computed(() => (page.props.validation as any) ?? props.validation)
const freqLabel = (f: string) => t('sars.freq_' + f)
</script>

<template>
  <div class="pa-4" style="max-width: 1100px; margin: 0 auto">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('sars.config_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sars.config_subtitle') }}</p>
      </div>
      <Link :href="route('finance.accounting.sars.submissions')"><VBtn variant="tonal" prepend-icon="tabler-history">{{ t('sars.submissions') }}</VBtn></Link>
    </div>

    <!-- Validation summary (the "N validated — X errors" panel) -->
    <VCard border rounded="lg" class="mb-4" :color="validation.errors.length ? undefined : 'success'" :variant="validation.errors.length ? 'outlined' : 'tonal'">
      <VCardText>
        <div class="d-flex align-center gap-3 flex-wrap">
          <VIcon :color="validation.errors.length ? 'warning' : 'success'" size="28">{{ validation.errors.length ? 'tabler-alert-triangle' : 'tabler-circle-check' }}</VIcon>
          <div>
            <span class="text-body-1 font-weight-semibold">{{ t('sars.n_validated', { n: validation.validated }) }}</span>
            <span v-if="validation.errors.length" class="text-error font-weight-semibold ms-2">· {{ t('sars.n_errors', { n: validation.errors.length }) }}</span>
            <span v-else class="text-success ms-2">· {{ t('sars.all_valid') }}</span>
          </div>
        </div>
        <VList v-if="validation.errors.length" density="compact" class="mt-2 bg-transparent">
          <VListItem v-for="(e, i) in validation.errors" :key="i" class="px-0">
            <template #prepend><VIcon size="16" color="error" class="me-2">tabler-point-filled</VIcon></template>
            <VListItemTitle class="text-body-2"><strong>{{ e.entity }}</strong> — {{ e.field }}: {{ e.message }}</VListItemTitle>
          </VListItem>
        </VList>
      </VCardText>
    </VCard>

    <VForm @submit.prevent="save">
      <VCard border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('sars.vat_section') }}</VCardTitle>
        <VCardText>
          <VSwitch v-model="form.vat_registered" :label="t('sars.vat_registered')" color="primary" hide-details class="mb-2" />
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="form.vat_number" :label="t('sars.vat_number')" placeholder="4XXXXXXXXX" variant="outlined" density="comfortable" :disabled="!form.vat_registered" :error-messages="(form.errors as any).vat_number" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="form.vat_frequency" :items="frequencies.map(f => ({ title: freqLabel(f), value: f }))" :label="t('sars.vat_frequency')" variant="outlined" density="comfortable" :disabled="!form.vat_registered" /></VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('sars.payroll_section') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="form.paye_reference" :label="t('sars.paye_reference')" placeholder="7XXXXXXXXX" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.uif_reference" :label="t('sars.uif_reference')" placeholder="UXXXXXXXXX" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.sdl_reference" :label="t('sars.sdl_reference')" placeholder="LXXXXXXXXX" variant="outlined" density="comfortable" :disabled="!form.sdl_liable" /></VCol>
            <VCol cols="12" sm="6" class="d-flex align-center"><VSwitch v-model="form.sdl_liable" :label="t('sars.sdl_liable')" color="primary" hide-details /></VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-semibold">{{ t('sars.company_section') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="form.income_tax_number" :label="t('sars.income_tax_number')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.sic_code" :label="t('sars.sic_code')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.contact_person" :label="t('sars.contact_person')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.contact_email" type="email" :label="t('sars.contact_email')" variant="outlined" density="comfortable" :error-messages="(form.errors as any).contact_email" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.efiling_username" :label="t('sars.efiling_username')" variant="outlined" density="comfortable" /></VCol>
          </VRow>
        </VCardText>
      </VCard>

      <div class="d-flex justify-end">
        <VBtn color="primary" type="submit" :loading="form.processing" prepend-icon="tabler-device-floppy">{{ t('sars.save_validate') }}</VBtn>
      </div>
    </VForm>
  </div>
</template>
