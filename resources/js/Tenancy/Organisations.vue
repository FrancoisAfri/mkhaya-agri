<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

/**
 * Organisations & members: the organisations you belong to (switch between them), and — for an
 * organisation's administrators — people from other organisations brought in to work here.
 */
const props = defineProps<{
  current: { id: number; name: string }
  mine: Array<{ id: number; name: string; roles: string[]; current: boolean; last_used_at: string | null }>
  members: Array<{ id: number; name: string; email: string; roles: string[]; status: string; home: boolean; working_in: string | null; is_me: boolean }>
  roles: string[]
  can: { manage: boolean }
}>()

const page = usePage()
const errors = () => (page.props as any).errors ?? {}
const switchTo = (id: number) => router.post(route('organisations.switch', id))

const invite = useForm({ email: '', roles: [] as string[] })
const inviteOpen = ref(false)
function sendInvite() { invite.post(route('organisations.members.store'), { preserveScroll: true, onSuccess: () => { inviteOpen.value = false; invite.reset() } }) }

const editing = ref<null | { id: number; name: string; roles: string[] }>(null)
function saveRoles() {
  if (!editing.value) return
  router.patch(route('organisations.members.update', editing.value.id), { roles: editing.value.roles }, { preserveScroll: true, onSuccess: () => { editing.value = null } })
}
function setStatus(m: any, status: string) {
  if (status === 'suspended' && !confirm(`Remove ${m.name}'s access to ${props.current.name}? Their own organisation is not affected.`)) return
  router.patch(route('organisations.members.update', m.id), { status }, { preserveScroll: true })
}
</script>

<template>
  <div>
    <div class="mb-6">
      <h4 class="text-h4 mb-1">Organisations &amp; members</h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        One login, several farming businesses. You work in one at a time — <strong>{{ current.name }}</strong> now — with the roles you hold there.
      </p>
    </div>

    <VAlert v-if="Object.keys(errors()).length" type="error" variant="tonal" class="mb-4">
      <div v-for="(e, k) in errors()" :key="k">{{ e }}</div>
    </VAlert>

    <VCard title="Your organisations" class="mb-6">
      <VList lines="two">
        <VListItem v-for="o in mine" :key="o.id" :title="o.name" :subtitle="(o.roles.length ? o.roles.join(', ') : 'No role') + (o.last_used_at && !o.current ? ` · last used ${o.last_used_at}` : '')">
          <template #prepend><VAvatar :color="o.current ? 'primary' : 'secondary'" variant="tonal"><VIcon icon="tabler-building-community" /></VAvatar></template>
          <template #append>
            <VChip v-if="o.current" color="primary" size="small" variant="tonal">Working here</VChip>
            <VBtn v-else color="primary" variant="tonal" size="small" prepend-icon="tabler-switch-horizontal" @click="switchTo(o.id)">Switch</VBtn>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <VCard v-if="can.manage">
      <VCardItem>
        <VCardTitle>Members of {{ current.name }}</VCardTitle>
        <VCardSubtitle>Add somebody who already has a login in another organisation — an agronomist, a contractor, a co-owner.</VCardSubtitle>
        <template #append><VBtn color="primary" prepend-icon="tabler-user-plus" @click="inviteOpen = true">Add member</VBtn></template>
      </VCardItem>
      <VTable density="comfortable">
        <thead><tr><th>Person</th><th>Roles here</th><th>Belongs to</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="m in members" :key="m.id">
            <td><strong>{{ m.name }}</strong><div class="text-caption">{{ m.email }}</div></td>
            <td><VChip v-for="r in m.roles" :key="r" size="x-small" class="me-1 mb-1">{{ r }}</VChip><span v-if="!m.roles.length" class="text-disabled">—</span></td>
            <td>
              <VChip v-if="m.home" size="small" variant="tonal">This organisation</VChip>
              <span v-else class="text-body-2">Member from elsewhere<div class="text-caption">working in {{ m.working_in ?? '—' }}</div></span>
            </td>
            <td><VChip :color="m.status === 'active' ? 'success' : 'secondary'" size="small" variant="tonal">{{ m.status === 'active' ? 'Active' : 'Removed' }}</VChip></td>
            <td class="text-end">
              <VMenu v-if="!m.is_me">
                <template #activator="{ props: p }"><VBtn v-bind="p" icon variant="text" size="small" aria-label="Actions"><VIcon icon="tabler-dots-vertical" /></VBtn></template>
                <VList density="compact">
                  <VListItem prepend-icon="tabler-shield" title="Change roles here" @click="editing = { id: m.id, name: m.name, roles: [...m.roles] }" />
                  <VListItem v-if="m.status === 'active'" prepend-icon="tabler-user-off" base-color="error" title="Remove from this organisation" @click="setStatus(m, 'suspended')" />
                  <VListItem v-else prepend-icon="tabler-user-check" title="Restore access" @click="setStatus(m, 'active')" />
                </VList>
              </VMenu>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="inviteOpen" max-width="520">
      <VCard title="Add a member from another organisation">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">They keep their own organisation and switch into {{ current.name }} from the menu under their name. They are emailed to let them know.</p>
          <VTextField v-model="invite.email" label="Their login email *" type="email" class="mb-3" :error-messages="invite.errors.email" />
          <VSelect v-model="invite.roles" :items="roles" label="Roles in this organisation *" multiple chips :error-messages="invite.errors.roles || invite.errors['roles.0']"
            hint="Farm access is still given farm by farm, on each farm's People & access tab." persistent-hint />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="inviteOpen = false">Cancel</VBtn><VBtn color="primary" :loading="invite.processing" @click="sendInvite">Add member</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!editing" max-width="520" @update:model-value="v => !v && (editing = null)">
      <VCard v-if="editing" :title="`Roles for ${editing.name} in ${current.name}`">
        <VCardText><VSelect v-model="editing.roles" :items="roles" label="Roles" multiple chips /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">Cancel</VBtn><VBtn color="primary" @click="saveRoles">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
