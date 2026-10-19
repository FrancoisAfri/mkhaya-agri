<script setup lang="ts">
/** The ⋮ actions menu used on every Mkhaya Agri row and header instead of a row of buttons. */
export type RowAction = { title: string; icon?: string; color?: string; show?: boolean; onClick: () => void }
defineProps<{ actions: RowAction[]; size?: string }>()
</script>

<template>
  <VMenu v-if="actions.some(a => a.show !== false)">
    <template #activator="{ props: p }">
      <VBtn v-bind="p" icon variant="text" :size="size ?? 'small'" aria-label="Actions"><VIcon icon="tabler-dots-vertical" /></VBtn>
    </template>
    <VList density="compact">
      <template v-for="a in actions" :key="a.title">
        <VListItem v-if="a.show !== false" :prepend-icon="a.icon" :base-color="a.color" :title="a.title" @click="a.onClick" />
      </template>
    </VList>
  </VMenu>
</template>
