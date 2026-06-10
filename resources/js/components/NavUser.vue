<script setup lang="ts">
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData, type User } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { LogOut } from 'lucide-vue-next';
import UserInfo from '@/components/UserInfo.vue';

const page = usePage<SharedData>();
const user = page.props.auth.user as User;
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <UserInfo :user="user" class="px-2 py-1" />
        </SidebarMenuItem>
        <SidebarMenuItem>
            <SidebarMenuButton as-child>
                <Link :href="route('logout')" method="post" as="button" class="flex items-center gap-2 w-full">
                    <LogOut class="w-4 h-4" />
                    <span>Cerrar sesión</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>