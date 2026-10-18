<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Mailbox { id: number; name: string; email: string; host: string; port: number; encryption: string; username: string; folder: string; active: boolean; ai_filter: boolean; last_checked_at: string | null; last_status: string | null; has_password: boolean }

const props = defineProps<{ settings: { respond_hours: number; resolve_hours: number; escalate_after_hours: number; escalate_severities: string[] }; severities: string[]; mailboxes: Mailbox[] }>()

const snack = ref(false); const confirm = ref()
const form = useForm({ respond_hours: props.settings.respond_hours, resolve_hours: props.settings.resolve_hours, escalate_after_hours: props.settings.escalate_after_hours, escalate_severities: [...props.settings.escalate_severities] })
function save() { form.patch(route('crm.complaints.settings.save'), { preserveScroll: true, onSuccess: () => snack.value = true }) }

const mbDrawer = ref(false); const editing = ref<Mailbox | null>(null)
const mbForm = useForm({ purpose: 'complaint', name: '', email: '', host: '', port: 993, encryption: 'ssl', username: '', password: '', folder: 'INBOX', active: true, ai_filter: true })
function newMailbox() { editing.value = null; mbForm.reset(); mbForm.purpose = 'complaint'; mbDrawer.value = true }
function editMailbox(m: Mailbox) { editing.value = m; mbForm.clearErrors(); mbForm.purpose = 'complaint'; mbForm.name = m.name; mbForm.email = m.email; mbForm.host = m.host; mbForm.port = m.port; mbForm.encryption = m.encryption; mbForm.username = m.username; mbForm.password = ''; mbForm.folder = m.folder; mbForm.active = m.active; mbForm.ai_filter = m.ai_filter; mbDrawer.value = true }
function saveMailbox() { const opts = { preserveScroll: true, onSuccess: () => { mbDrawer.value = false } }; if (editing.value) mbForm.patch(route('crm.mailboxes.update', editing.value.id), opts); else mbForm.post(route('crm.mailboxes.store'), opts) }
function testMailbox(m: Mailbox) { router.post(route('crm.mailboxes.test', m.id), {}, { preserveScroll: true }) }
function pollMailbox(m: Mailbox) { router.post(route('crm.mailboxes.poll', m.id), {}, { preserveScroll: true }) }
function removeMailbox(m: Mailbox) { confirm.value.open({ title: t('crm_complaints_settings_index.delete_mailbox.title'), message: t('crm_complaints_settings_index.delete_mailbox.message', { email: m.email }), confirmText: t('crm_complaints_settings_index.delete_mailbox.confirm'), confirmColor: 'error' }, () => router.delete(route('crm.mailboxes.destroy', m.id), { preserveScroll: true })) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center ga-3 mb-4"><VBtn icon="tabler-arrow-left" variant="text" :href="route('crm.complaints')" /><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_complaints_settings_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_complaints_settings_index.description') }}</p></div></div>

    <VRow>
      <VCol cols="12" md="5"><VCard class="h-100"><VCardText>
        <div class="text-overline text-medium-emphasis mb-2">{{ t('crm_complaints_settings_index.sla_title') }}</div>
        <VRow class="mb-2"><VCol cols="12" sm="6"><VTextField v-model.number="form.respond_hours" type="number" min="1" :label="t('crm_complaints_settings_index.respond_hours')" prepend-inner-icon="tabler-clock" /></VCol><VCol cols="12" sm="6"><VTextField v-model.number="form.resolve_hours" type="number" min="1" :label="t('crm_complaints_settings_index.resolve_hours')" prepend-inner-icon="tabler-circle-check" /></VCol></VRow>
        <VDivider class="my-3" />
        <div class="text-overline text-medium-emphasis mb-2">{{ t('crm_complaints_settings_index.escalation_title') }}</div>
        <VSelect v-model="form.escalate_severities" :items="severities" :label="t('crm_complaints_settings_index.escalate_severities')" multiple chips closable-chips class="text-capitalize mb-2" />
        <VTextField v-model.number="form.escalate_after_hours" type="number" min="1" :label="t('crm_complaints_settings_index.escalate_after_hours')" prepend-inner-icon="tabler-alert-triangle" />
        <p class="text-caption text-medium-emphasis mt-1">{{ t('crm_complaints_settings_index.escalate_hint') }}</p>
      </VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn color="primary" :loading="form.processing" prepend-icon="tabler-device-floppy" @click="save">{{ t('crm_complaints_settings_index.save') }}</VBtn></VCardActions></VCard></VCol>

      <VCol cols="12" md="7"><VCard class="h-100"><VCardTitle class="d-flex align-center justify-space-between"><span>{{ t('crm_complaints_settings_index.mailboxes_title') }}</span><VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="newMailbox">{{ t('crm_complaints_settings_index.add_mailbox') }}</VBtn></VCardTitle><VCardText>
        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('crm_complaints_settings_index.mailboxes_description') }}</p>
        <VList v-if="mailboxes.length" lines="two"><template v-for="m in mailboxes" :key="m.id"><VListItem><template #prepend><VAvatar :color="m.active ? 'primary' : 'grey'" variant="tonal"><VIcon icon="tabler-mailbox" /></VAvatar></template><VListItemTitle>{{ m.name }} · {{ m.email }}</VListItemTitle><VListItemSubtitle>{{ m.host }}:{{ m.port }} · {{ m.encryption.toUpperCase() }}<span v-if="m.last_status"> · {{ m.last_checked_at }} — {{ m.last_status }}</span></VListItemSubtitle><template #append><VChip v-if="!m.active" size="x-small" color="warning" label class="me-2">{{ t('crm_complaints_settings_index.paused') }}</VChip><VBtn size="small" variant="text" icon="tabler-plug-connected" :title="t('crm_complaints_settings_index.test_connection')" @click="testMailbox(m)" /><VBtn size="small" variant="text" icon="tabler-refresh" :title="t('crm_complaints_settings_index.poll_now')" @click="pollMailbox(m)" /><VBtn size="small" variant="text" icon="tabler-edit" :title="t('crm_complaints_settings_index.edit')" @click="editMailbox(m)" /><VBtn size="small" variant="text" icon="tabler-trash" color="error" :title="t('crm_complaints_settings_index.remove')" @click="removeMailbox(m)" /></template></VListItem><VDivider /></template></VList>
        <VAlert v-else type="info" variant="tonal" density="compact">{{ t('crm_complaints_settings_index.no_mailboxes') }}</VAlert>
      </VCardText></VCard></VCol>
    </VRow>

    <VNavigationDrawer v-model="mbDrawer" temporary location="right" width="480"><VForm @submit.prevent="saveMailbox" class="h-100 d-flex flex-column">
      <div class="pa-5 border-b d-flex align-center ga-2"><VIcon icon="tabler-mailbox" color="primary" /><h5 class="text-h5">{{ editing ? t('crm_complaints_settings_index.drawer.title_edit') : t('crm_complaints_settings_index.drawer.title_create') }}</h5></div>
      <div class="flex-grow-1 overflow-y-auto pa-5">
        <VTextField v-model="mbForm.name" :label="t('crm_complaints_settings_index.drawer.name')" :placeholder="t('crm_complaints_settings_index.drawer.name_placeholder')" class="mb-2" :error-messages="mbForm.errors.name" />
        <VTextField v-model="mbForm.email" :label="t('crm_complaints_settings_index.drawer.email')" :placeholder="t('crm_complaints_settings_index.drawer.email_placeholder')" class="mb-2" :error-messages="mbForm.errors.email" />
        <VRow><VCol cols="8"><VTextField v-model="mbForm.host" :label="t('crm_complaints_settings_index.drawer.host')" :placeholder="t('crm_complaints_settings_index.drawer.host_placeholder')" :error-messages="mbForm.errors.host" /></VCol><VCol cols="4"><VTextField v-model.number="mbForm.port" type="number" :label="t('crm_complaints_settings_index.drawer.port')" /></VCol></VRow>
        <VRow><VCol cols="6"><VSelect v-model="mbForm.encryption" :items="['ssl','tls','none']" :label="t('crm_complaints_settings_index.drawer.encryption')" /></VCol><VCol cols="6"><VTextField v-model="mbForm.folder" :label="t('crm_complaints_settings_index.drawer.folder')" /></VCol></VRow>
        <VTextField v-model="mbForm.username" :label="t('crm_complaints_settings_index.drawer.username')" :placeholder="t('crm_complaints_settings_index.drawer.username_hint')" class="mb-2" :error-messages="mbForm.errors.username" />
        <VTextField v-model="mbForm.password" :label="t('crm_complaints_settings_index.drawer.password')" type="password" prepend-inner-icon="tabler-lock" class="mb-2" :placeholder="editing && editing.has_password ? t('crm_complaints_settings_index.drawer.password_hint') : ''" :error-messages="mbForm.errors.password" />
        <VSwitch v-model="mbForm.ai_filter" :label="t('crm_complaints_settings_index.drawer.ai_filter')" color="primary" hide-details />
        <VSwitch v-model="mbForm.active" :label="t('crm_complaints_settings_index.drawer.active')" color="primary" hide-details />
      </div>
      <div class="pa-5 border-t d-flex ga-3 justify-end"><VBtn variant="tonal" @click="mbDrawer = false">{{ t('crm_complaints_settings_index.drawer.cancel') }}</VBtn><VBtn type="submit" color="primary" :loading="mbForm.processing">{{ t('crm_complaints_settings_index.drawer.save') }}</VBtn></div>
    </VForm></VNavigationDrawer>

    <ConfirmDialog ref="confirm" />
    <VSnackbar v-model="snack" color="success" location="bottom right" timeout="3000">{{ t('crm_complaints_settings_index.toast_saved') }}</VSnackbar>
  </div>
</template>