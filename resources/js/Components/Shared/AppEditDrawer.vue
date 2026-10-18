<script setup lang="ts">
defineProps({
    isDrawerOpen: Boolean,
    title: String,
    fields: Array,
    record: Object,
    submitLabel: String
})

defineEmits(['update:isDrawerOpen', 'submitted'])
</script>

<template>
    <div>Edit Drawer</div>
</template>
