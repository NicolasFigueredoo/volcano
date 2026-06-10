<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import { onMounted, ref } from 'vue';
import { Plus, Pencil, Trash2, X } from 'lucide-vue-next';

interface Usuario {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'cajero';
    created_at: string;
}

const { get, post, put, loading, error } = useApi();

const usuarios = ref<Usuario[]>([]);
const panel = ref(false);
const editingId = ref<number | null>(null);

const form = ref({
    name: '',
    email: '',
    password: '',
    role: 'cajero' as 'admin' | 'cajero',
});

async function cargar() {
    usuarios.value = await get<Usuario[]>('/api/usuarios') ?? [];
}

function nuevo() {
    editingId.value = null;
    form.value = { name: '', email: '', password: '', role: 'cajero' };
    panel.value = true;
}

function editar(usuario: Usuario) {
    editingId.value = usuario.id;
    form.value = { name: usuario.name, email: usuario.email, password: '', role: usuario.role };
    panel.value = true;
}

function cerrar() {
    panel.value = false;
    editingId.value = null;
}

async function guardar() {
    if (!editingId.value && !form.value.password) {
        alert('La contraseña es obligatoria para crear usuario');
        return;
    }

    const body: any = {
        name: form.value.name,
        email: form.value.email,
        role: form.value.role,
    };
    if (form.value.password) body.password = form.value.password;

    if (editingId.value) {
        await put(`/api/usuarios/${editingId.value}`, body);
    } else {
        await post('/api/usuarios', body);
    }

    cerrar();
    await cargar();
}

async function eliminar(usuario: Usuario) {
    if (!confirm(`¿Eliminar usuario ${usuario.name}?`)) return;

    const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    await fetch(`/api/usuarios/${usuario.id}`, {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': token,
        },
    });

    await cargar();
}

const roleLabel = (role: string) => role === 'admin' ? 'Administrador' : 'Cajero';

onMounted(cargar);
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Usuarios', href: '/usuarios' }]">
        <div class="p-4 flex gap-4">
            <div class="flex-1 flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-semibold">Usuarios</h1>
                        <p class="text-sm text-muted-foreground">Administrá usuarios y roles del sistema.</p>
                    </div>
                    <Button size="sm" @click="nuevo"><Plus class="w-4 h-4 mr-1"/>Nuevo usuario</Button>
                </div>

                <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

                <Card>
                    <CardContent class="p-0">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-xs text-muted-foreground">
                                    <th class="text-left p-3 font-medium">Nombre</th>
                                    <th class="text-left p-3 font-medium">Email</th>
                                    <th class="text-left p-3 font-medium">Rol</th>
                                    <th class="text-right p-3 font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="usuario in usuarios" :key="usuario.id" class="border-b last:border-0 hover:bg-muted/50">
                                    <td class="p-3 font-medium">{{ usuario.name }}</td>
                                    <td class="p-3 text-muted-foreground">{{ usuario.email }}</td>
                                    <td class="p-3">
                                        <span class="text-xs px-2 py-0.5 rounded-full border"
                                            :class="usuario.role === 'admin'
                                                ? 'bg-primary/10 text-primary border-primary/30'
                                                : 'bg-muted text-muted-foreground border-border'">
                                            {{ roleLabel(usuario.role) }}
                                        </span>
                                    </td>
                                    <td class="p-3 text-right">
                                        <Button size="icon" variant="ghost" class="h-7 w-7" @click="editar(usuario)"><Pencil class="w-3 h-3"/></Button>
                                        <Button size="icon" variant="ghost" class="h-7 w-7 text-destructive" @click="eliminar(usuario)"><Trash2 class="w-3 h-3"/></Button>
                                    </td>
                                </tr>
                                <tr v-if="!usuarios.length">
                                    <td colspan="4" class="p-4 text-center text-muted-foreground">Sin usuarios cargados</td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            <div v-if="panel" class="w-80 shrink-0">
                <Card>
                    <CardHeader class="pb-2">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm">{{ editingId ? 'Editar usuario' : 'Nuevo usuario' }}</CardTitle>
                            <button @click="cerrar" class="text-muted-foreground hover:text-foreground"><X class="w-4 h-4"/></button>
                        </div>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <div>
                            <label class="text-xs text-muted-foreground">Nombre</label>
                            <input v-model="form.name" class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"/>
                        </div>
                        <div>
                            <label class="text-xs text-muted-foreground">Email</label>
                            <input v-model="form.email" type="email" class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"/>
                        </div>
                        <div>
                            <label class="text-xs text-muted-foreground">
                                Contraseña {{ editingId ? '(dejar vacío para no cambiar)' : '' }}
                            </label>
                            <input v-model="form.password" type="password" class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"/>
                        </div>
                        <div>
                            <label class="text-xs text-muted-foreground">Rol</label>
                            <select v-model="form.role" class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5">
                                <option value="admin">Administrador</option>
                                <option value="cajero">Cajero</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <Button variant="outline" size="sm" class="flex-1" @click="cerrar">Cancelar</Button>
                            <Button size="sm" class="flex-1" @click="guardar" :disabled="loading">Guardar</Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>