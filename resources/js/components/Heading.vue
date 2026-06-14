<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : 'mb-6 space-y-1 sm:mb-8'">
        <h2
            :class="
                variant === 'small'
                    ? 'font-display mb-0.5 text-base font-bold'
                    : 'font-display text-xl font-bold tracking-tight sm:text-2xl md:text-3xl'
            "
        >
            {{ title }}
        </h2>
        <p
            v-if="description"
            class="text-sm leading-relaxed text-muted-foreground"
        >
            {{ description }}
        </p>
    </header>
</template>
