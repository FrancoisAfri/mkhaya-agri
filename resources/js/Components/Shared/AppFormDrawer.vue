<script setup lang="ts">
export interface FieldConfig {
    key: string
    label: string
    type: string
    placeholder?: string
    cols?: number
    defaultValue?: any
    rules?: any[]
}
</script>

<template>
    <div>Form Drawer</div>
</template>
