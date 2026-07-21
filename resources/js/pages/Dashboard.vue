<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { AlertTriangle, CheckCircle2, RefreshCw, Target, TrendingDown, TrendingUp } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

// ── Tipos ─────────────────────────────────────────────────────────────────────

interface GastoDesglose {
    nombre: string;
    monto_mensual: number;
    costo_diario: number;
}

interface ProductoMargen {
    id: number;
    nombre: string;
    precio: number;
    costo: number;
    ganancia: number;
    margen: number;
}

interface Rentabilidad {
    gastos_fijos_dia: number;
    desglose_gastos: GastoDesglose[];
    margen_pct: number;
    origen_margen: 'ventas' | 'menu';
    ventas_equilibrio: number;
    ticket_promedio: number;
    pedidos_equilibrio: number;
    hoy: {
        tiene_caja: boolean;
        estado_caja: string | null;
        vendido: number;
        ganancia_neta: number;
        falta_equilibrio: number;
        progreso_pct: number;
        en_rojo: boolean;
        cantidad_ventas: number;
    };
    productos: ProductoMargen[];
    dias_con_datos: number;
}

// ── Estado ────────────────────────────────────────────────────────────────────

const { get, loading, error } = useApi();
const data = ref<Rentabilidad | null>(null);
const accessDenied = ref(false);

const fmt = (n: unknown) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');

// Umbral de margen "sano" para gastronomía (food cost ~35% → margen 65%).
const MARGEN_OBJETIVO = 60;

function margenColor(m: number): string {
    if (m >= MARGEN_OBJETIVO) return 'text-green-600';
    if (m >= 45) return 'text-yellow-600';
    return 'text-destructive';
}

function margenBarColor(m: number): string {
    if (m >= MARGEN_OBJETIVO) return 'bg-green-500';
    if (m >= 45) return 'bg-yellow-500';
    return 'bg-destructive';
}

// Los N productos con peor margen (los que te comen la ganancia).
const peores = computed(() => data.value?.productos.slice(0, 5) ?? []);
const totalMensualFijo = computed(() =>
    (data.value?.desglose_gastos ?? []).reduce((a: number, g: GastoDesglose) => a + g.monto_mensual, 0),
);

async function cargar() {
    accessDenied.value = false;
    const res = await get<Rentabilidad>('/api/caja/rentabilidad');
    if (res) {
        data.value = res;
    } else if (error.value?.includes('403') || error.value?.toLowerCase().includes('administrador')) {
        accessDenied.value = true;
    }
}

onMounted(cargar);
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-4">
            <!-- Sin acceso (cajero) -->
            <Card v-if="accessDenied">
                <CardContent class="p-6 text-center text-sm text-muted-foreground">
                    El panel de rentabilidad es solo para administradores.
                </CardContent>
            </Card>

            <template v-else-if="data">
                <!-- Encabezado -->
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-lg font-semibold">Punto de equilibrio y rentabilidad</h1>
                        <p class="text-xs text-muted-foreground">
                            Margen y equilibrio calculados sobre
                            {{ data.origen_margen === 'ventas' ? `${data.dias_con_datos} día(s) de ventas reales` : 'los precios del menú' }}.
                        </p>
                    </div>
                    <button
                        class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                        @click="cargar"
                    >
                        <RefreshCw class="h-3.5 w-3.5" :class="loading ? 'animate-spin' : ''" /> Actualizar
                    </button>
                </div>

                <!-- Tarjeta principal: cuánto vender para no perder -->
                <Card class="border-2" :class="data.hoy.en_rojo ? 'border-destructive/40' : 'border-green-500/40'">
                    <CardContent class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <Target class="mt-0.5 h-8 w-8 shrink-0 text-muted-foreground" />
                            <div>
                                <p class="text-xs text-muted-foreground">Necesitás vender por día para no perder plata</p>
                                <p class="text-3xl font-black">{{ fmt(data.ventas_equilibrio) }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Cubre {{ fmt(data.gastos_fijos_dia) }} de gastos fijos con un margen del {{ data.margen_pct }}%.
                                </p>
                            </div>
                        </div>

                        <!-- Estado de hoy -->
                        <div class="min-w-[220px] rounded-lg border bg-muted/30 p-3">
                            <div class="mb-1 flex items-center justify-between">
                                <span class="text-xs text-muted-foreground">Hoy vas</span>
                                <span
                                    class="flex items-center gap-1 text-xs font-semibold"
                                    :class="data.hoy.en_rojo ? 'text-destructive' : 'text-green-600'"
                                >
                                    <component :is="data.hoy.en_rojo ? TrendingDown : TrendingUp" class="h-3.5 w-3.5" />
                                    {{ data.hoy.en_rojo ? 'En rojo' : 'En ganancia' }}
                                </span>
                            </div>

                            <p class="text-xl font-bold">{{ fmt(data.hoy.vendido) }}</p>
                            <p class="text-xs text-muted-foreground">{{ data.hoy.cantidad_ventas }} pedido(s)</p>

                            <!-- Barra de progreso al equilibrio -->
                            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="data.hoy.progreso_pct >= 100 ? 'bg-green-500' : 'bg-yellow-500'"
                                    :style="{ width: Math.max(2, data.hoy.progreso_pct) + '%' }"
                                />
                            </div>
                            <p class="mt-1 text-xs" :class="data.hoy.falta_equilibrio > 0 ? 'text-destructive' : 'text-green-600'">
                                <template v-if="data.hoy.falta_equilibrio > 0">
                                    Faltan {{ fmt(data.hoy.falta_equilibrio) }} para el equilibrio
                                </template>
                                <template v-else>✓ Equilibrio cubierto</template>
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Alerta día en rojo -->
                <Card v-if="data.hoy.tiene_caja && data.hoy.en_rojo" class="border-destructive/40 bg-destructive/5">
                    <CardContent class="flex items-center gap-3 p-4 text-sm">
                        <AlertTriangle class="h-5 w-5 shrink-0 text-destructive" />
                        <span>
                            El día está cerrando <strong class="text-destructive">en rojo</strong>:
                            ganancia neta {{ fmt(data.hoy.ganancia_neta) }}. Necesitás
                            {{ fmt(data.hoy.falta_equilibrio) }} más en ventas para dar vuelta el día.
                        </span>
                    </CardContent>
                </Card>
                <Card v-else-if="data.hoy.tiene_caja" class="border-green-500/40 bg-green-500/5">
                    <CardContent class="flex items-center gap-3 p-4 text-sm">
                        <CheckCircle2 class="h-5 w-5 shrink-0 text-green-600" />
                        <span>Vas <strong class="text-green-600">en ganancia</strong>: ganancia neta de hoy {{ fmt(data.hoy.ganancia_neta) }}.</span>
                    </CardContent>
                </Card>

                <!-- KPIs -->
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Card>
                        <CardContent class="p-4">
                            <p class="mb-1 text-xs text-muted-foreground">Gastos fijos / día</p>
                            <p class="text-xl font-semibold text-destructive">{{ fmt(data.gastos_fijos_dia) }}</p>
                            <p class="text-xs text-muted-foreground">{{ fmt(totalMensualFijo) }}/mes</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="p-4">
                            <p class="mb-1 text-xs text-muted-foreground">Margen bruto promedio</p>
                            <p class="text-xl font-semibold" :class="margenColor(data.margen_pct)">{{ data.margen_pct }}%</p>
                            <p class="text-xs text-muted-foreground">objetivo {{ MARGEN_OBJETIVO }}%</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="p-4">
                            <p class="mb-1 text-xs text-muted-foreground">Ticket promedio</p>
                            <p class="text-xl font-semibold">{{ fmt(data.ticket_promedio) }}</p>
                            <p class="text-xs text-muted-foreground">por pedido</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent class="p-4">
                            <p class="mb-1 text-xs text-muted-foreground">Pedidos p/ equilibrio</p>
                            <p class="text-xl font-semibold">{{ data.pedidos_equilibrio || '—' }}</p>
                            <p class="text-xs text-muted-foreground">al ticket promedio</p>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- Desglose de gastos fijos -->
                    <Card>
                        <CardHeader class="pb-2"><CardTitle class="text-sm">¿En qué se te va el gasto fijo?</CardTitle></CardHeader>
                        <CardContent class="flex flex-col gap-2">
                            <div v-for="g in data.desglose_gastos" :key="g.nombre" class="text-sm">
                                <div class="mb-1 flex items-center justify-between">
                                    <span>{{ g.nombre }}</span>
                                    <span class="font-medium">{{ fmt(g.costo_diario) }}/día</span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-foreground/70"
                                        :style="{ width: (data.gastos_fijos_dia > 0 ? (g.costo_diario / data.gastos_fijos_dia) * 100 : 0) + '%' }"
                                    />
                                </div>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ fmt(g.monto_mensual) }}/mes ·
                                    {{ data.gastos_fijos_dia > 0 ? Math.round((g.costo_diario / data.gastos_fijos_dia) * 100) : 0 }}% del fijo
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Productos que menos dejan -->
                    <Card>
                        <CardHeader class="pb-2">
                            <CardTitle class="text-sm">Productos que menos margen dejan</CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-2">
                            <div v-for="p in peores" :key="p.id" class="text-sm">
                                <div class="mb-1 flex items-center justify-between">
                                    <span class="truncate pr-2">{{ p.nombre }}</span>
                                    <span class="shrink-0 font-semibold" :class="margenColor(p.margen)">{{ p.margen }}%</span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                    <div class="h-full rounded-full" :class="margenBarColor(p.margen)" :style="{ width: Math.max(2, p.margen) + '%' }" />
                                </div>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    Vende {{ fmt(p.precio) }} · cuesta {{ fmt(p.costo) }} · deja {{ fmt(p.ganancia) }}
                                </p>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Subir el precio o bajar el costo de estos primero es lo que más mueve la aguja.
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <!-- Tabla completa de rentabilidad por producto -->
                <Card>
                    <CardHeader class="pb-2"><CardTitle class="text-sm">Rentabilidad por producto</CardTitle></CardHeader>
                    <CardContent class="p-0">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-xs text-muted-foreground">
                                        <th class="p-3 text-left font-medium">Producto</th>
                                        <th class="p-3 text-right font-medium">Precio</th>
                                        <th class="p-3 text-right font-medium">Costo</th>
                                        <th class="p-3 text-right font-medium">Ganancia</th>
                                        <th class="p-3 text-right font-medium">Margen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in data.productos" :key="p.id" class="border-b last:border-0 hover:bg-muted/50">
                                        <td class="p-3">{{ p.nombre }}</td>
                                        <td class="p-3 text-right">{{ fmt(p.precio) }}</td>
                                        <td class="p-3 text-right text-muted-foreground">{{ fmt(p.costo) }}</td>
                                        <td class="p-3 text-right font-medium">{{ fmt(p.ganancia) }}</td>
                                        <td class="p-3 text-right font-semibold" :class="margenColor(p.margen)">{{ p.margen }}%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>

                <p class="text-xs text-muted-foreground">
                    Nota: el margen y el ticket promedio se afinan a medida que cerrás más cajas.
                    Con pocos días cargados los promedios pueden quedar sesgados por un pedido grande.
                </p>
            </template>

            <!-- Cargando -->
            <Card v-else>
                <CardContent class="p-6 text-center text-sm text-muted-foreground">
                    <RefreshCw class="mx-auto mb-2 h-5 w-5 animate-spin" />
                    Calculando rentabilidad...
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
