<script setup lang="ts">
import { useSidebar } from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import { ClipboardList, Menu, Package, Settings, ShoppingCart } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

// Barra de abajo tipo app, solo en celular. "Más" abre el menú lateral
// con el resto de las pantallas (Separación, Usuarios, perfil, salir).
const page = usePage();
const { toggleSidebar } = useSidebar();

const esAdmin = computed(() => (page.props.auth.user as { role: string }).role === 'admin');

const items = computed<{ title: string; url: string; icon: Component }[]>(() => [
    { title: 'POS', url: '/pos', icon: ShoppingCart },
    { title: 'Caja', url: '/caja', icon: ClipboardList },
    { title: 'Stock', url: '/inventario', icon: Package },
    ...(esAdmin.value ? [{ title: 'Admin', url: '/admin', icon: Settings }] : []),
]);

const activo = (url: string) => page.url === url || page.url.startsWith(url + '?') || page.url.startsWith(url + '/');
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 border-t border-sidebar-border/70 bg-background/95 backdrop-blur md:hidden"
        style="padding-bottom: env(safe-area-inset-bottom)"
    >
        <div class="flex h-16">
            <Link
                v-for="item in items"
                :key="item.url"
                :href="item.url"
                class="flex flex-1 flex-col items-center justify-center gap-1 text-[11px] font-medium transition-colors"
                :class="activo(item.url) ? 'text-primary' : 'text-muted-foreground'"
            >
                <span
                    class="flex h-7 w-12 items-center justify-center rounded-full transition-colors"
                    :class="activo(item.url) ? 'bg-primary/15' : ''"
                >
                    <component :is="item.icon" class="h-5 w-5" />
                </span>
                {{ item.title }}
            </Link>
            <button
                type="button"
                class="flex flex-1 flex-col items-center justify-center gap-1 text-[11px] font-medium text-muted-foreground"
                @click="toggleSidebar"
            >
                <span class="flex h-7 w-12 items-center justify-center rounded-full">
                    <Menu class="h-5 w-5" />
                </span>
                Más
            </button>
        </div>
    </nav>
</template>
