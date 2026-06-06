<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Clock3,
    History,
    Map,
    Swords,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { mapBuilder } from '@/routes';
import { index as lobbies } from '@/routes/lobbies';
import { ongoing, past } from '@/routes/matches';
import type { NavItem } from '@/types';

const page = usePage();
const auth = computed(() => page.props.auth);
const { isCurrentOrParentUrl } = useCurrentUrl();

const navItems = computed<NavItem[]>(() => [
    {
        title: 'Map Builder',
        href: mapBuilder().url,
        icon: Map,
    },
    {
        title: 'Lobbies',
        href: lobbies().url,
        icon: Users,
    },
    {
        title: 'Ongoing',
        href: ongoing().url,
        icon: Clock3,
    },
    {
        title: 'Past Matches',
        href: past().url,
        icon: History,
    },
]);
</script>

<template>
    <header
        class="sticky top-0 z-40 border-b border-[#1a1814]/10 bg-[#f7f1e3]/95 backdrop-blur-sm"
    >
        <div
            class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-4"
        >
            <div class="flex items-center gap-6">
                <Link :href="lobbies().url" class="flex items-center gap-3">
                    <div
                        class="flex size-9 items-center justify-center rounded-full border-2 border-[#1a1814]/15 bg-[#e8dfc8] shadow-[2px_2px_0_#1a1814]/10"
                    >
                        <Swords class="size-4" />
                    </div>
                    <div class="hidden sm:block">
                        <p class="text-sm font-bold tracking-[0.12em] uppercase">
                            War of Spheres
                        </p>
                    </div>
                </Link>

                <nav class="hidden items-center gap-1 md:flex">
                    <Link
                        v-for="item in navItems"
                        :key="item.title"
                        :href="item.href"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            :class="[
                                'gap-2 text-[#3d362b] hover:bg-[#e8dfc8]',
                                isCurrentOrParentUrl(item.href)
                                    ? 'bg-[#e8dfc8] font-bold text-[#1a1814]'
                                    : '',
                            ]"
                        >
                            <component :is="item.icon" class="size-4" />
                            {{ item.title }}
                        </Button>
                    </Link>
                </nav>
            </div>

            <div class="flex items-center gap-3">
                <TeamSwitcher in-header />

                <DropdownMenu v-if="auth.user">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="rounded-full border border-[#1a1814]/10"
                        >
                            <Avatar class="size-8 overflow-hidden rounded-full">
                                <AvatarImage
                                    v-if="auth.user.avatar"
                                    :src="auth.user.avatar"
                                    :alt="auth.user.name"
                                />
                                <AvatarFallback
                                    class="rounded-full bg-[#e8dfc8] text-[#1a1814]"
                                >
                                    {{ getInitials(auth.user.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="auth.user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <nav
            class="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-6 pb-3 md:hidden"
        >
            <Link
                v-for="item in navItems"
                :key="`mobile-${item.title}`"
                :href="item.href"
            >
                <Button
                    variant="ghost"
                    size="sm"
                    :class="[
                        'shrink-0 gap-2 text-[#3d362b]',
                        isCurrentOrParentUrl(item.href)
                            ? 'bg-[#e8dfc8] font-bold'
                            : '',
                    ]"
                >
                    <component :is="item.icon" class="size-4" />
                    {{ item.title }}
                </Button>
            </Link>
        </nav>
    </header>
</template>
