<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Clock3,
    History,
    Map,
    Swords,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import FactionSwatches from '@/components/FactionSwatches.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { home, mapBuilder, wiki } from '@/routes';
import { index as lobbies } from '@/routes/lobbies';
import { ongoing, past } from '@/routes/matches';
import type { NavItem } from '@/types';

const page = usePage();
const auth = computed(() => page.props.auth);
const { isCurrentOrParentUrl } = useCurrentUrl();

const navItems = computed<NavItem[]>(() => [
    { title: 'Wiki', href: wiki().url, icon: BookOpen },
    { title: 'Map Builder', href: mapBuilder().url, icon: Map },
    { title: 'Lobbies', href: lobbies().url, icon: Users },
    { title: 'Ongoing', href: ongoing().url, icon: Clock3 },
    { title: 'Past Matches', href: past().url, icon: History },
]);
</script>

<template>
    <header class="wod-bar-top relative">
        <div
            class="relative flex w-full flex-wrap items-center justify-between gap-4 px-6 py-3"
        >
            <div class="flex items-center gap-5">
                <Link :href="home().url" class="flex items-center gap-2.5">
                    <div class="wod-logo-terrain size-9">
                        <Swords class="size-4" />
                    </div>
                    <div class="hidden sm:block">
                        <p class="font-display text-base font-bold leading-tight">
                            War of Spheres
                        </p>
                        <p class="wod-tagline">Plan first, fight second</p>
                    </div>
                </Link>

                <FactionSwatches class="hidden sm:grid" />

                <nav class="hidden items-center gap-1 md:flex">
                    <Button
                        v-for="item in navItems"
                        :key="item.title"
                        variant="ghost"
                        size="sm"
                        as-child
                        :class="[
                            'wod-nav-ghost',
                            isCurrentOrParentUrl(item.href)
                                ? 'wod-nav-active'
                                : '',
                        ]"
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="size-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </div>

            <div class="flex items-center gap-2">
                <DropdownMenu v-if="auth.user">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="wod-nav-ghost rounded-md"
                        >
                            <Avatar
                                class="size-8 overflow-hidden rounded-md border-2 border-foreground"
                            >
                                <AvatarImage
                                    v-if="auth.user.avatar"
                                    :src="auth.user.avatar"
                                    :alt="auth.user.name"
                                />
                                <AvatarFallback
                                    class="rounded-md bg-card text-xs font-bold"
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
            class="flex w-full gap-1 overflow-x-auto border-t-2 border-foreground/20 px-6 py-2 md:hidden"
        >
            <Button
                v-for="item in navItems"
                :key="`mobile-${item.title}`"
                variant="ghost"
                size="sm"
                as-child
                :class="[
                    'wod-nav-ghost shrink-0 gap-2',
                    isCurrentOrParentUrl(item.href) ? 'wod-nav-active' : '',
                ]"
            >
                <Link :href="item.href">
                    <component :is="item.icon" class="size-4" />
                    {{ item.title }}
                </Link>
            </Button>
        </nav>
    </header>
</template>
