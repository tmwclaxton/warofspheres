<script setup lang="ts">
import AppBottomBar from '@/components/AppBottomBar.vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppToast from '@/components/AppToast.vue';
import AppTopBar from '@/components/AppTopBar.vue';
</script>

<template>
    <AppShell variant="header">
        <div class="flex min-h-svh flex-col bg-[#e8dfc8] text-[#1a1814]">
            <AppTopBar />
            <AppContent
                variant="header"
                class="mx-auto w-full max-w-6xl flex-1 px-6 py-8"
            >
                <slot />
            </AppContent>
            <AppBottomBar />
            <AppToast />
        </div>
    </AppShell>
</template>
