<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import { onMounted, ref, computed } from 'vue';
import { Pencil, Plus, X } from 'lucide-vue-next';

interface Insumo {
    id: number; nombre: string; unidad: string;
    stock_actual: number; stock_minimo: number;
    costo_unitario: number; estado_stock: 'ok' | 'poco' | 'falta';
}

const { get, put, post, loading } = useApi();
const insumos = ref<Insumo[]>([]);
const panel = ref<{ insumo: Insumo; modo: 'ajuste' | 'entrada' } | null>(null);
const nuevoStock = ref(0);
const cantidadEntrada = ref(0);
const motivo = ref('');

const alertas = computed(() => insumos.value.filter(i => i.estado_stock !== 'ok').length);

const badgeClass = (estado: string) => {
    if (estado === 'falta') return 'bg-destructive/10 text-destructive border-destructive/30';
    if (estado === 'poco') return 'bg-yellow-500/10 text-yellow-600 border-yellow-500/30';
    return 'bg-green-500/10 text-green-600 border-green-500/30';
};
const badgeLabel = (estado: string) => estado === 'falta' ? 'Sin stock' : estado === 'poco' ? 'Poco' : 'OK';

async function cargar() {
    const data = await get<Insumo[]>('/api/inventario');
    if (data) insumos.value = data;
}

function abrirPanel(insumo: Insumo, modo: 'ajuste' | 'entrada') {
    panel.value = { insumo, modo };
    nuevoStock.value = insumo.stock_actual;
    cantidadEntrada.value = 0;
    motivo.value = '';
}

async function guardar() {
    if (!panel.value) return;
    const { insumo, modo } = panel.value;
    if (modo === 'entrada') {
        await post(`/api/inventario/${insumo.id}/entrada`, { cantidad: cantidadEntrada.value, motivo: motivo.value || 'compra' });
    } else {
        await put(`/api/inventario/${insumo.id}/ajustar`, { stock_actual: nuevoStock.value, motivo: motivo.value || 'ajuste manual' });
    }
    panel.value = null;
    await cargar();
}

onMounted(cargar);
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Inventario', href: '/inventario' }]">
        <div class="p-4 flex gap-4">

            <!-- Tabla -->
            <div class="flex-1 flex flex-col gap-4 min-w-0">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-semibold">Stock actual</h2>
                        <span v-if="alertas > 0" class="text-xs px-2 py-0.5 rounded-full border bg-destructive/10 text-destructive border-destructive/30">
                            {{ alertas }} alerta{{ alertas > 1 ? 's' : '' }}
                        </span>
                    </div>
                    <Button size="sm" variant="outline" @click="cargar" :disabled="loading">Actualizar</Button>
                </div>

                <Card>
                    <CardContent class="p-0">
                        <div v-if="loading" class="p-4 text-sm text-muted-foreground">Cargando...</div>
                        <table v-else class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-xs text-muted-foreground">
                                    <th class="text-left p-3 font-medium">Insumo</th>
                                    <th class="text-left p-3 font-medium">Unidad</th>
                                    <th class="text-right p-3 font-medium">Stock</th>
                                    <th class="text-right p-3 font-medium">Mín.</th>
                                    <th class="text-center p-3 font-medium">Estado</th>
                                    <th class="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="insumo in insumos" :key="insumo.id"
                                    class="border-b last:border-0 hover:bg-muted/50"
                                    :class="{ 'bg-destructive/5': insumo.estado_stock === 'falta' }"
                                >
                                    <td class="p-3 font-medium">{{ insumo.nombre }}</td>
                                    <td class="p-3 text-muted-foreground">{{ insumo.unidad }}</td>
                                    <td class="p-3 text-right font-mono">{{ insumo.stock_actual }}</td>
                                    <td class="p-3 text-right text-muted-foreground font-mono">{{ insumo.stock_minimo }}</td>
                                    <td class="p-3 text-center">
                                        <span class="text-xs px-2 py-0.5 rounded-full border" :class="badgeClass(insumo.estado_stock)">
                                            {{ badgeLabel(insumo.estado_stock) }}
                                        </span>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex gap-1 justify-end">
                                            <Button size="icon" variant="ghost" class="h-7 w-7" @click="abrirPanel(insumo, 'entrada')" title="Entrada"><Plus class="w-3 h-3" /></Button>
                                            <Button size="icon" variant="ghost" class="h-7 w-7" @click="abrirPanel(insumo, 'ajuste')" title="Ajustar"><Pencil class="w-3 h-3" /></Button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            <!-- Panel lateral (sin Dialog/radix) -->
            <div v-if="panel" class="w-64 shrink-0">
                <Card>
                    <CardHeader class="pb-2">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm">
                                {{ panel.modo === 'entrada' ? 'Registrar entrada' : 'Ajustar stock' }}
                            </CardTitle>
                            <button @click="panel = null" class="text-muted-foreground hover:text-foreground"><X class="w-4 h-4" /></button>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ panel.insumo.nombre }}</p>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <div v-if="panel.modo === 'entrada'">
                            <label class="text-xs text-muted-foreground">Cantidad a agregar</label>
                            <input type="number" v-model="cantidadEntrada" min="0" step="0.1"
                                class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5" />
                        </div>
                        <div v-else>
                            <label class="text-xs text-muted-foreground">Nuevo stock</label>
                            <input type="number" v-model="nuevoStock" min="0" step="0.1"
                                class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5" />
                        </div>
                        <div>
                            <label class="text-xs text-muted-foreground">Motivo (opcional)</label>
                            <input v-model="motivo" :placeholder="panel.modo === 'entrada' ? 'compra' : 'ajuste manual'"
                                class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5" />
                        </div>
                        <div class="flex gap-2">
                            <Button variant="outline" size="sm" class="flex-1" @click="panel = null">Cancelar</Button>
                            <Button size="sm" class="flex-1" @click="guardar" :disabled="loading">Guardar</Button>
                        </div>
                    </CardContent>
                </Card>
            </div>

        </div>
    </AppLayout>
</template>