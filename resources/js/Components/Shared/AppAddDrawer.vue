<script setup lang="ts">
defineProps({
    isDrawerOpen: Boolean,
    title: String,
    fields: Array,
    submitLabel: String
})

defineEmits(['update:isDrawerOpen', 'submitted'])
</script>

<template>
    <div>Add Drawer</div>
</template>
