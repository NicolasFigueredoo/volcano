<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ShoppingCart,
    ClipboardList,
    Package,
    Settings,
    Users,
} from 'lucide-vue-next';
import type { Component } from 'vue';

interface NavItem {
    title: string;
    url: string;
    icon: Component;
}

const page = usePage();

const user = page.props.auth.user as {
    role: string;
};

const mainNavItems: NavItem[] = [
    { title: 'POS', url: '/pos', icon: ShoppingCart },
    { title: 'Caja', url: '/caja', icon: ClipboardList },
    { title: 'Inventario', url: '/inventario', icon: Package },
];

const adminNavItems: NavItem[] = [
    { title: 'Admin', url: '/admin', icon: Settings },
    { title: 'Usuarios', url: '/usuarios', icon: Users },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('pos')" class="flex items-center gap-2">
                            <div
                                class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary text-primary-foreground font-black text-lg leading-none"
                                style="font-family: 'Nunito', sans-serif"
                            >
                                V
                            </div>

                            <div class="flex flex-col leading-tight">
                                <span
                                    class="font-black text-sidebar-foreground tracking-tight"
                                    style="font-family: 'Nunito', sans-serif; font-size: 15px"
                                >
                                    VOLCANO
                                </span>

                                <span class="text-[10px] text-sidebar-foreground/50 font-medium tracking-widest uppercase">
                                    Burger
                                </span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain v-if="user.role === 'admin'" :items="adminNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>

    <slot />
</template>