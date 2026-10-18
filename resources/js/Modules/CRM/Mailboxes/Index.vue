<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()

interface Mailbox { id: number; name: string; email: string; host: string; port: number; encryption: string; username: string; folder: string; active: boolean; ai_filter: boolean; notify_roles: string[]; default_owner_id: number | null; owner_name: string | null; last_checked_at: string | null; last_status: string | null; has_password: boolean }

const props = defineProps<{ mailboxes: Mailbox[]; roles: string[]; owners: { id: number; name: string }[] }>()

const drawer = ref(false); const editing = ref<Mailbox | null>(null); const busy = ref<number | null>(null)
const snack = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })
function toast(text: string, color = 'success') { snack.value = { show: true, text, color } }

const form = useForm({ name: '', email: '', host: '', port: 993, encryption: 'ssl', username: '', password: '', folder: 'INBOX', active: true, ai_filter: true, notify_roles: [] as string[], default_owner_id: null as number | null })

function openNew() { editing.value = null; form.reset(); form.clearErrors(); drawer.value = true }
function openEdit(m: Mailbox) { editing.value = m; form.clearErrors(); form.name = m.name; form.email = m.email; form.host = m.host; form.port = m.port; form.encryption = m.encryption; form.username = m.username; form.password = ''; form.folder = m.folder; form.active = m.active; form.ai_filter = m.ai_filter; form.notify_roles = [...m.notify_roles]; form.default_owner_id = m.default_owner_id; drawer.value = true }
function submit() { const opts = { preserveScroll: true, onSuccess: () => { drawer.value = false; toast(t('crm_mailboxes_index.toasts.saved')) }, onError: () => toast(t('crm_mailboxes_index.toasts.error'), 'error') }; if (editing.value) form.patch(ziggyRoute('crm.mailboxes.update', editing.value.id), opts); else form.post(ziggyRoute('crm.mailboxes.store'), opts) }
function toggle(m: Mailbox) { router.post(ziggyRoute('crm.mailboxes.toggle', m.id), {}, { preserveScroll: true }) }
function test(m: Mailbox) { busy.value = m.id; router.post(ziggyRoute('crm.mailboxes.test', m.id), {}, { preserveScroll: true, onFinish: () => busy.value = null }) }
function pollNow(m: Mailbox) { busy.value = m.id; router.post(ziggyRoute('crm.mailboxes.poll', m.id), {}, { preserveScroll: true, onFinish: () => busy.value = null }) }
function remove(m: Mailbox) { router.delete(ziggyRoute('crm.mailboxes.destroy', m.id), { preserveScroll: true, onSuccess: () => toast(t('crm_mailboxes_index.toasts.removed')) }) }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6"><VRow justify="center"><VCol cols="12" lg="10">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3"><div><div class="text-overline text-medium-emphasis">CRM</div><h4 class="text-h4">{{ t('crm_mailboxes_index.page_title') }}</h4><p class="text-medium-emphasis mb-0" style="max-width: 640px">{{ t('crm_mailboxes_index.description') }}</p></div><VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('crm_mailboxes_index.add_mailbox') }}</VBtn></div>
    <VAlert v-if="!mailboxes.length" type="info" variant="tonal" class="mb-4">{{ t('crm_mailboxes_index.no_mailboxes') }}</VAlert>

    <VRow><VCol v-for="m in mailboxes" :key="m.id" cols="12" md="6">
      <VCard :style="m.active ? 'border: 1px solid rgb(var(--v-theme-primary))' : ''" class="h-100">
        <VCardTitle class="pa-4 pb-2 d-flex align-center ga-2"><VIcon icon="tabler-mailbox" color="primary" /><span class="text-body-1 font-weight-bold">{{ m.name }}</span><VSpacer /><VChip size="x-small" :color="m.active ? 'success' : 'default'" :label="m.active ? t('crm_mailboxes_index.active') : t('crm_mailboxes_index.paused')" /></VCardTitle>
        <VCardText class="pa-4 pt-1"><div class="text-body-2 mb-2"><VIcon size="14" class="me-1">tabler-at</VIcon>{{ m.email }}</div><div class="text-caption text-medium-emphasis mb-1">{{ m.host }}:{{ m.port }} · {{ m.encryption.toUpperCase() }} · {{ m.folder }}</div>
          <div class="d-flex flex-wrap ga-1 my-2"><VChip v-if="m.ai_filter" size="x-small" color="info" variant="tonal" prepend-icon="tabler-sparkles">{{ t('crm_mailboxes_index.ai_filtering') }}</VChip><VChip v-if="m.owner_name" size="x-small" variant="tonal" prepend-icon="tabler-user">{{ m.owner_name }}</VChip><VChip v-for="r in m.notify_roles" :key="r" size="x-small" variant="tonal" prepend-icon="tabler-users-group">{{ r }}</VChip></div>
          <div v-if="m.last_status" class="text-caption text-medium-emphasis"><VIcon size="14" class="me-1">tabler-clock</VIcon>{{ m.last_checked_at }} — {{ m.last_status }}</div>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3 d-flex ga-2 flex-wrap"><VBtn size="small" variant="tonal" prepend-icon="tabler-pencil" @click="openEdit(m)">{{ t('crm_mailboxes_index.edit') }}</VBtn><VBtn size="small" variant="tonal" prepend-icon="tabler-plug-connected" :loading="busy === m.id" @click="test(m)">{{ t('crm_mailboxes_index.test') }}</VBtn><VBtn size="small" variant="tonal" prepend-icon="tabler-refresh" :loading="busy === m.id" @click="pollNow(m)">{{ t('crm_mailboxes_index.poll_now') }}</VBtn><VSpacer /><VSwitch :model-value="m.active" color="primary" density="compact" hide-details @change="toggle(m)" /></VCardActions>
      </VCard>
    </VCol></VRow>
  </VCol></VRow></VContainer>

  <VNavigationDrawer v-model="drawer" temporary location="right" width="520"><VForm @submit.prevent="submit" class="h-100 d-flex flex-column">
    <div class="pa-5 border-b d-flex align-center ga-2"><VIcon icon="tabler-mailbox" color="primary" /><h5 class="text-h5">{{ editing ? t('crm_mailboxes_index.drawer.title_edit') : t('crm_mailboxes_index.drawer.title_create') }}</h5></div>
    <div class="flex-grow-1 overflow-y-auto pa-5"><VRow>
      <VCol cols="12"><VTextField v-model="form.name" :label="t('crm_mailboxes_index.drawer.name')" :placeholder="t('crm_mailboxes_index.drawer.name_placeholder')" variant="outlined" density="comfortable" :error-messages="form.errors.name" /></VCol>
      <VCol cols="12"><VTextField v-model="form.email" :label="t('crm_mailboxes_index.drawer.email')" :placeholder="t('crm_mailboxes_index.drawer.email_placeholder')" variant="outlined" density="comfortable" :error-messages="form.errors.email" /></VCol>
      <VCol cols="8"><VTextField v-model="form.host" :label="t('crm_mailboxes_index.drawer.host')" :placeholder="t('crm_mailboxes_index.drawer.host_placeholder')" variant="outlined" density="comfortable" :error-messages="form.errors.host" /></VCol>
      <VCol cols="4"><VTextField v-model.number="form.port" :label="t('crm_mailboxes_index.drawer.port')" type="number" variant="outlined" density="comfortable" :error-messages="form.errors.port" /></VCol>
      <VCol cols="6"><VSelect v-model="form.encryption" :items="['ssl','tls','none']" :label="t('crm_mailboxes_index.drawer.encryption')" variant="outlined" density="comfortable" /></VCol>
      <VCol cols="6"><VTextField v-model="form.folder" :label="t('crm_mailboxes_index.drawer.folder')" placeholder="INBOX" variant="outlined" density="comfortable" /></VCol>
      <VCol cols="12"><VTextField v-model="form.username" :label="t('crm_mailboxes_index.drawer.username')" :placeholder="t('crm_mailboxes_index.drawer.username_hint')" variant="outlined" density="comfortable" :error-messages="form.errors.username" /></VCol>
      <VCol cols="12"><VTextField v-model="form.password" :label="t('crm_mailboxes_index.drawer.password')" type="password" :placeholder="editing && editing.has_password ? t('crm_mailboxes_index.drawer.password_hint') : ''" variant="outlined" density="comfortable" prepend-inner-icon="tabler-lock" :error-messages="form.errors.password" /></VCol>
      <VCol cols="12"><VDivider class="mb-1" /></VCol>
      <VCol cols="12"><VSelect v-model="form.default_owner_id" :items="owners" item-title="name" item-value="id" :label="t('crm_mailboxes_index.drawer.assign_to')" variant="outlined" density="comfortable" clearable /></VCol>
      <VCol cols="12"><VSelect v-model="form.notify_roles" :items="roles" :label="t('crm_mailboxes_index.drawer.notify_roles')" variant="outlined" density="comfortable" multiple chips closable-chips /></VCol>
      <VCol cols="12"><VSwitch v-model="form.ai_filter" :label="t('crm_mailboxes_index.drawer.ai_filter')" color="primary" density="compact" hide-details /></VCol>
      <VCol cols="12"><VSwitch v-model="form.active" :label="t('crm_mailboxes_index.drawer.active_switch')" color="primary" density="compact" hide-details /></VCol>
      <VCol cols="12"><VAlert type="info" variant="tonal" density="compact" class="text-caption"><VIcon start size="14">tabler-shield-lock</VIcon>{{ t('crm_mailboxes_index.drawer.encryption_notice') }}</VAlert></VCol>
    </VRow></div>
    <div class="pa-5 border-t d-flex ga-3 justify-space-between"><VBtn v-if="editing" variant="text" color="error" icon="tabler-trash" :title="t('crm_mailboxes_index.toasts.removed')" @click="remove(editing); drawer = false" /><div class="d-flex ga-3 ms-auto"><VBtn variant="tonal" @click="drawer = false">{{ t('crm_mailboxes_index.drawer.cancel') }}</VBtn><VBtn type="submit" color="primary" :loading="form.processing" prepend-icon="tabler-device-floppy">{{ t('crm_mailboxes_index.drawer.save') }}</VBtn></div></div>
  </VForm></VNavigationDrawer>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="4000">{{ snack.text }}</VSnackbar>
</template>