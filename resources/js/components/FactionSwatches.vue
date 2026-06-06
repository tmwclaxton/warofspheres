<script setup lang="ts">
const swatchClasses = {
    red: 'bg-wod-faction-red',
    blue: 'bg-wod-faction-blue',
    orange: 'bg-wod-faction-orange',
    purple: 'bg-wod-faction-purple',
    green: 'bg-wod-faction-green',
    cyan: 'bg-wod-faction-cyan',
} as const;

const factionOrder = [
    'red',
    'blue',
    'orange',
    'purple',
    'green',
    'cyan',
] as const satisfies Array<keyof typeof swatchClasses>;
</script>

<template>
    <div
        class="wod-faction-strip"
        aria-hidden="true"
        title="Six faction colours"
    >
        <span
            v-for="color in factionOrder"
            :key="color"
            :class="['wod-swatch', swatchClasses[color]]"
        />
    </div>
</template>
