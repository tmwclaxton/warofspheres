<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Clock3,
    Github,
    Globe2,
    History,
    Map,
    Trophy,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import FactionSwatches from '@/components/FactionSwatches.vue';
import GameLogoMark from '@/components/GameLogoMark.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { avatarUrl } from '@/composables/useAvatar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { GITHUB_REPOSITORY_URL } from '@/lib/site';
import { home, login, mapBuilder, wiki } from '@/routes';
import { index as lobbies } from '@/routes/lobbies';
import { explore as mapsExplore } from '@/routes/maps';
import { ongoing, past } from '@/routes/matches';
import { index as leaderboard } from '@/routes/leaderboard';
import type { NavItem } from '@/types';

const page = usePage();
/** Logged-in WorkOS user only (guest play uses session, not this object). */
const user = computed(() => page.props.auth.user);
const { isCurrentOrParentUrl } = useCurrentUrl();

const navItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Explore', href: mapsExplore().url, icon: Globe2 },
        { title: 'Lobbies', href: lobbies().url, icon: Users },
        { title: 'Wiki', href: wiki().url, icon: BookOpen },
        { title: 'Map Builder', href: mapBuilder().url, icon: Map },
        { title: 'Leaderboard', href: leaderboard().url, icon: Trophy },
        { title: 'Ongoing', href: ongoing().url, icon: Clock3 },
    ];

    if (user.value !== null) {
        items.push({ title: 'Past Matches', href: past().url, icon: History });
    }

    return items;
});
</script>

<template>
    <header class="wod-bar-top relative">
        <div
            class="relative flex w-full flex-wrap items-center justify-between gap-3 px-4 py-2.5 sm:gap-4 sm:px-6 sm:py-3"
        >
            <div class="flex min-w-0 flex-1 items-center gap-3 sm:gap-5">
                <Link
                    :href="home().url"
                    class="flex shrink-0 items-center gap-2.5"
                >
                    <GameLogoMark />
                    <div class="hidden sm:block">
                        <p class="font-display text-base font-bold leading-tight">
                            Clash of Dots
                        </p>
                        <p class="wod-tagline">Plan first, fight second</p>
                    </div>
                </Link>

                <FactionSwatches class="hidden shrink-0 sm:grid" />

                <nav
                    class="hidden min-w-0 flex-1 overflow-x-auto [-ms-overflow-style:none] [scrollbar-width:none] sm:flex sm:[&::-webkit-scrollbar]:hidden"
                    aria-label="Main"
                >
                    <div class="flex w-max items-center gap-1 pr-1">
                        <Button
                            v-for="item in navItems"
                            :key="item.title"
                            variant="ghost"
                            size="sm"
                            as-child
                            :class="[
                                'wod-nav-ghost shrink-0',
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
                    </div>
                </nav>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <ThemeToggle />
                <Button
                    variant="ghost"
                    size="icon"
                    as-child
                    class="wod-nav-ghost rounded-md"
                >
                    <a
                        :href="GITHUB_REPOSITORY_URL"
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label="View source on GitHub"
                    >
                        <Github class="size-4" />
                    </a>
                </Button>
                <DropdownMenu v-if="user">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="wod-nav-ghost rounded-md"
                        >
                            <Avatar
                                class="size-8 overflow-hidden rounded-md border-2 border-foreground bg-black"
                            >
                                <AvatarImage
                                    v-if="user.profile_uuid"
                                    :src="avatarUrl(user.profile_uuid)"
                                    :alt="user.game_display_name ?? user.name"
                                />
                                <AvatarFallback
                                    class="rounded-md bg-card text-xs font-bold"
                                >
                                    {{ getInitials(user.game_display_name ?? user.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button
                    v-else
                    variant="default"
                    size="sm"
                    as-child
                >
                    <Link :href="login().url">Sign in</Link>
                </Button>
            </div>
        </div>

        <nav
            class="flex w-full gap-1 overflow-x-auto border-t border-foreground/25 px-3 py-2 sm:hidden [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            aria-label="Main"
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
