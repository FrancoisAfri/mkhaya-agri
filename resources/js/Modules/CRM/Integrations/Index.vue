<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()

interface Field { key: string; label: string; type: string; placeholder: string; default?: string; secret?: boolean; options?: string[] }
interface Integration { id: number | null; category: string; provider: string; label: string; logo: string; description: string; fields: Field[]; values: Record<string, string>; secrets_set: Record<string, boolean>; active: boolean; status: string; status_message: string | null; last_tested_at: string | null; has_credentials: boolean }
interface Group { category: string; label: string; icon: string; items: Integration[] }

const props = defineProps<{ groups: Group[] }>()

const drawer = ref(false); const testing = ref<number | null>(null)
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })
const active = ref<Integration | null>(null)
function toast(text: string, color = 'success') { snack.value = { show: true, text, color } }

const form = useForm({ provider: '', values: {} as Record<string, string>, active: false })

function openEdit(intg: Integration) { active.value = intg; form.provider = intg.provider; form.active = intg.active; const vals: Record<string, string> = {}; for (const f of intg.fields) { if (f.secret) vals[f.key] = ''; else vals[f.key] = intg.values[f.key] ?? f.default ?? '' } form.values = vals; form.clearErrors(); drawer.value = true }
function submitForm() { form.post(ziggyRoute('crm.integrations.store'), { preserveScroll: true, onSuccess: () => { drawer.value = false; toast(t('crm_integrations_index.toasts.saved')) }, onError: () => toast(t('crm_integrations_index.toasts.error'), 'error') }) }
function toggleActive(intg: Integration) { if (!intg.id) { toast(t('crm_integrations_index.toasts.save_first'), 'warning'); return }; router.post(ziggyRoute('crm.integrations.toggle', intg.id), {}, { preserveScroll: true, onSuccess: () => toast(intg.active ? t('crm_integrations_index.toasts.disabled') : t('crm_integrations_index.toasts.enabled')) }) }
function testConnection(intg: Integration) { if (!intg.id) { toast(t('crm_integrations_index.toasts.save_first'), 'warning'); return }; testing.value = intg.id; router.post(ziggyRoute('crm.integrations.test', intg.id), {}, { preserveScroll: true, onFinish: () => { testing.value = null } }) }
function removeIntegration(intg: Integration) { if (!intg.id) return; router.delete(ziggyRoute('crm.integrations.destroy', intg.id), { preserveScroll: true, onSuccess: () => { drawer.value = false; toast(t('crm_integrations_index.toasts.removed')) } }) }

const secretPlaceholder = (intg: Integration | null, f: Field) => intg?.secrets_set?.[f.key] ? '•••••••• (saved — leave blank to keep)' : f.placeholder
const statusColor = (s: string) => ({ connected: 'success', error: 'error', configured: 'info' } as Record<string, string>)[s] ?? 'grey'
const statusIcon = (s: string) => ({ connected: 'tabler-circle-check', error: 'tabler-alert-circle', configured: 'tabler-circle-dot' } as Record<string, string>)[s] ?? 'tabler-help-circle'
const connectedCount = computed(() => props.groups.reduce((n, g) => n + g.items.filter(i => i.active).length, 0))
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6"><VRow justify="center"><VCol cols="12" lg="11">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3"><div><div class="text-overline text-medium-emphasis">CRM</div><h4 class="text-h4">{{ t('crm_integrations_index.page_title') }}</h4><p class="text-medium-emphasis mb-0">{{ t('crm_integrations_index.description', { count: connectedCount }) }}</p></div><VBtn variant="tonal" prepend-icon="tabler-mailbox" :href="ziggyRoute('crm.mailboxes')">{{ t('crm_integrations_index.lead_capture_mailboxes') }}</VBtn></div>

    <div v-for="group in groups" :key="group.category" class="mb-8"><div class="d-flex align-center ga-2 mb-3"><VIcon :icon="group.icon" color="primary" /><h6 class="text-h6 font-weight-bold">{{ group.label }}</h6></div>
      <VRow><VCol v-for="intg in group.items" :key="intg.provider" cols="12" md="6" lg="4">
        <VCard :class="['h-100', intg.active ? 'border-primary' : '']" :style="intg.active ? 'border: 1px solid rgb(var(--v-theme-primary))' : ''">
          <VCardTitle class="pa-4 pb-2 d-flex align-center ga-2"><VIcon :icon="intg.logo" color="primary" size="22" /><span class="text-body-1 font-weight-bold">{{ intg.label }}</span><VSpacer /><VChip size="x-small" :color="intg.active ? 'success' : 'default'" :label="intg.active ? t('crm_integrations_index.active') : t('crm_integrations_index.inactive')" /></VCardTitle>
          <VCardText class="pa-4 pt-1"><p class="text-body-2 text-medium-emphasis mb-3" style="min-height: 40px">{{ intg.description }}</p>
            <div class="d-flex align-center ga-1 mb-2"><VIcon :icon="intg.has_credentials ? 'tabler-lock-check' : 'tabler-lock-open'" :color="intg.has_credentials ? 'success' : 'grey'" size="16" /><span class="text-caption" :class="intg.has_credentials ? 'text-success' : 'text-medium-emphasis'">{{ intg.has_credentials ? t('crm_integrations_index.credentials_saved') : t('crm_integrations_index.no_credentials') }}</span></div>
            <div v-if="intg.last_tested_at" class="d-flex align-center ga-1 mb-1"><VIcon :icon="statusIcon(intg.status)" :color="statusColor(intg.status)" size="16" /><span class="text-caption">{{ intg.status_message }}</span></div>
            <div v-if="intg.last_tested_at" class="text-caption text-medium-emphasis">{{ t('crm_integrations_index.last_tested') }} {{ intg.last_tested_at }}</div>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-3 d-flex ga-2 flex-wrap"><VBtn size="small" variant="tonal" prepend-icon="tabler-pencil" @click="openEdit(intg)">{{ intg.has_credentials ? t('crm_integrations_index.configure') : t('crm_integrations_index.connect') }}</VBtn><VBtn size="small" variant="tonal" prepend-icon="tabler-plug-connected" :loading="testing === intg.id" :disabled="!intg.has_credentials" @click="testConnection(intg)">{{ t('crm_integrations_index.test') }}</VBtn><VSpacer /><VSwitch :model-value="intg.active" color="primary" density="compact" hide-details :disabled="!intg.has_credentials" @change="toggleActive(intg)" /></VCardActions>
        </VCard>
      </VCol></VRow>
    </div>
  </VCol></VRow></VContainer>

  <VNavigationDrawer v-model="drawer" temporary location="right" width="500"><VForm v-if="active" @submit.prevent="submitForm" class="h-100 d-flex flex-column">
    <div class="pa-5 border-b d-flex align-center ga-2"><VIcon :icon="active.logo" color="primary" /><h5 class="text-h5">{{ active.label }}</h5></div>
    <div class="flex-grow-1 overflow-y-auto pa-5"><p class="text-body-2 text-medium-emphasis mb-4">{{ active.description }}</p><VDivider class="mb-4" />
      <VRow><VCol v-for="field in active.fields" :key="field.key" cols="12"><VSelect v-if="field.type === 'select'" v-model="form.values[field.key]" :items="field.options" :label="field.label" variant="outlined" density="comfortable" /><VTextField v-else v-model="form.values[field.key]" :label="field.label" :placeholder="field.secret ? secretPlaceholder(active, field) : field.placeholder" :type="field.type === 'password' ? 'password' : 'text'" :error-messages="(form.errors as any)[`values.${field.key}`]" variant="outlined" density="comfortable" :prepend-inner-icon="field.secret ? 'tabler-lock' : undefined" /></VCol>
        <VCol cols="12"><VDivider class="mb-3" /><VSwitch v-model="form.active" :label="t('crm_integrations_index.enable_integration')" color="primary" density="compact" hide-details /></VCol>
        <VCol cols="12"><VAlert type="info" variant="tonal" density="compact" class="text-caption"><VIcon start size="14">tabler-shield-lock</VIcon>{{ t('crm_integrations_index.encryption_notice') }}</VAlert></VCol>
      </VRow>
    </div>
    <div class="pa-5 border-t d-flex ga-3 justify-space-between"><VBtn v-if="active.id" variant="text" color="error" icon="tabler-trash" :title="t('crm_integrations_index.remove_integration')" @click="removeIntegration(active)" /><div class="d-flex ga-3 ms-auto"><VBtn variant="tonal" @click="drawer = false">{{ t('crm_integrations_index.cancel') }}</VBtn><VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="tabler-device-floppy">{{ t('crm_integrations_index.save_credentials') }}</VBtn></div></div>
  </VForm></VNavigationDrawer>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="4000">{{ snack.text }}</VSnackbar>
</template>