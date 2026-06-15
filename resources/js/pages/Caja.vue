<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import { computed, onMounted, ref } from 'vue';
import { Download, Eye, Lock, RefreshCw, Unlock, X } from 'lucide-vue-next';

interface Pago {
    metodo: string;
    monto: number;
}

interface Detalle {
    nombre_snapshot: string;
    cantidad: number;
    precio_snapshot: number;
    costo_snapshot: number;
    subtotal: number;
}

interface Venta {
    id: number;
    created_at: string;
    mesa: string | null;
    notas?: string | null;
    estado: string;
    numero_orden: number;
    total: number;
    detalles: Detalle[];
    pagos: Pago[];
}

interface Caja {
    id: number;
    fecha_operativa: string;
    estado: string;
    abierta_at: string | null;
    cerrada_at: string | null;
    total_ventas: number;
    total_efectivo: number;
    total_transferencia: number;
    costo_insumos: number;
    ganancia_bruta: number;
    gastos_fijos: number;
    ganancia_neta: number;
    cantidad_ventas: number;
    abierta_por?: { id: number; name: string } | null;
    cerrada_por?: { id: number; name: string } | null;
}

interface Stats {
    cantidad_ventas: number;
    total_monto: number;
    total_efectivo: number;
    total_transferencia: number;
    costo_insumos?: number;
    ganancia_bruta?: number;
    gastos_fijos?: number;
    ganancia_neta?: number;
    separacion?: {
        reponer_insumos: number;
        ahorro: number;
        retiro: number;
        negocio: number;
    };
}

interface CajaData {
    caja: Caja | null;
    stats: Stats;
    ventas: Venta[];
    es_admin: boolean;
    puede_operar_caja: boolean;
}

interface ResumenSemanal {
    desde: string;
    hasta: string;
    total_ventas: number;
    total_efectivo: number;
    total_transferencia: number;
    costo_insumos: number;
    ganancia_bruta: number;
    gastos_fijos: number;
    ganancia_neta: number;
    cantidad_ventas: number;
    cajas: Caja[];
}

interface ProductoTop {
    nombre: string;
    cantidad: number;
    monto: number;
}

interface CajaDetalle {
    caja: Caja;
    ventas: Venta[];
    stats_guardadas: {
        cantidad_ventas: number;
        total_monto: number;
        total_efectivo: number;
        total_transferencia: number;
        costo_insumos: number;
        ganancia_bruta: number;
        gastos_fijos: number;
        ganancia_neta: number;
    };
    resumen: {
        productos_top?: ProductoTop[];
    };
}

const { get, post, loading } = useApi();

const data = ref<CajaData | null>(null);
const pedidosActivos = ref<Venta[]>([]);
const alertasCompra = ref<any[]>([]);
const historial = ref<Caja[]>([]);
const resumenSemanal = ref<ResumenSemanal | null>(null);

const cajaDetalle = ref<CajaDetalle | null>(null);
const cargandoCajaDetalle = ref(false);

// Filtro por rango
const filtroDesde = ref('');
const filtroHasta = ref('');
const resumenRango = ref<ResumenSemanal | null>(null);
const cargandoRango = ref(false);

const esAdmin = computed(() => data.value?.es_admin === true);
const puedeOperarCaja = computed(() => data.value?.puede_operar_caja === true);

const fmt = (n: any) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');

const estadoLabel: Record<string, string> = {
    pendiente: 'Pendiente',
    preparacion: 'En prep.',
    pagado: 'Pagado',
    entregado: 'Entregado',
};

const estadoColor: Record<string, string> = {
    pendiente: 'bg-yellow-500/10 text-yellow-600 border-yellow-500/30',
    preparacion: 'bg-blue-500/10 text-blue-600 border-blue-500/30',
    pagado: 'bg-green-500/10 text-green-600 border-green-500/30',
    entregado: 'bg-muted text-muted-foreground border-border',
};

function horaVenta(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleTimeString('es-AR', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function fecha(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('es-AR');
}

async function cargar() {
    data.value = await get<CajaData>('/api/caja/hoy');
    pedidosActivos.value = await get<Venta[]>('/api/caja/pedidos-activos') ?? [];

    if (data.value?.es_admin) {
        historial.value = await get<Caja[]>('/api/caja/historial') ?? [];
        resumenSemanal.value = await get<ResumenSemanal>('/api/caja/resumen-semanal');
        alertasCompra.value = await get<any[]>('/api/caja/alertas-compra') ?? [];
    } else {
        historial.value = [];
        resumenSemanal.value = null;
        alertasCompra.value = [];
    }
}

async function abrirCaja() {
    await post('/api/caja/abrir', {});
    await cargar();
}

async function cerrarCaja() {
    if (!confirm('¿Confirmar cierre de caja?')) return;
    await post('/api/caja/cerrar', {});
    await cargar();
}

async function verCajaGuardada(caja: Caja) {
    cargandoCajaDetalle.value = true;
    const res = await get<CajaDetalle>(`/api/caja/historial/${caja.id}`);
    if (res) {
        cajaDetalle.value = res;
    }
    cargandoCajaDetalle.value = false;
}

function cerrarDetalleCaja() {
    cajaDetalle.value = null;
}

async function cerrarManual(caja: Caja) {
    if (!confirm(`¿Cerrar manualmente la caja del ${fecha(caja.fecha_operativa)}?\nSe calcularán los totales desde las ventas registradas.`)) return;
    await post(`/api/caja/${caja.id}/cerrar-manual`, {});
    cajaDetalle.value = null;
    await cargar();
}

async function buscarRango() {
    if (!filtroDesde.value || !filtroHasta.value) return;
    cargandoRango.value = true;
    resumenRango.value = await get<ResumenSemanal>(
        `/api/caja/resumen-rango?desde=${filtroDesde.value}&hasta=${filtroHasta.value}`
    );
    cargandoRango.value = false;
}

function exportar() {
    if (!data.value || !esAdmin.value) return;

    const rows = [
        ['#', 'Hora', 'Mesa', 'Descripción', 'Estado', 'Efectivo', 'Transferencia', 'Total'],
        ...data.value.ventas.map(v => [
            v.numero_orden,
            horaVenta(v.created_at),
            v.mesa ?? '-',
            v.notas ?? '-',
            estadoLabel[v.estado] ?? v.estado,
            v.pagos.find(p => p.metodo === 'efectivo')?.monto ?? 0,
            v.pagos.find(p => p.metodo === 'transferencia')?.monto ?? 0,
            v.total,
        ]),
    ];

    const a = document.createElement('a');
    a.href = URL.createObjectURL(
        new Blob([rows.map(r => r.join(',')).join('\n')], { type: 'text/csv' })
    );
    a.download = `caja_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
}

onMounted(cargar);
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Caja', href: '/caja' }]">
        <div class="p-4 flex flex-col gap-4">

            <!-- Estado de caja -->
            <Card>
                <CardContent class="p-4 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs text-muted-foreground">Estado de caja</p>

                        <div class="flex items-center gap-2 mt-1">
                            <span
                                class="text-sm font-semibold px-2 py-1 rounded-full border"
                                :class="data?.caja?.estado === 'abierta'
                                    ? 'bg-green-500/10 text-green-600 border-green-500/30'
                                    : 'bg-muted text-muted-foreground border-border'"
                            >
                                {{ data?.caja?.estado === 'abierta' ? 'Abierta' : 'Cerrada' }}
                            </span>

                            <span class="text-xs text-muted-foreground">
                                Fecha operativa:
                                {{ data?.caja?.fecha_operativa ? fecha(data.caja.fecha_operativa) : '—' }}
                            </span>
                        </div>

                        <p v-if="data?.caja?.abierta_at" class="text-xs text-muted-foreground mt-1">
                            Abierta {{ horaVenta(data.caja.abierta_at) }}
                            <span v-if="data.caja.abierta_por">por {{ data.caja.abierta_por.name }}</span>
                        </p>

                        <p v-if="data?.caja?.cerrada_at" class="text-xs text-muted-foreground mt-1">
                            Cerrada {{ horaVenta(data.caja.cerrada_at) }}
                            <span v-if="data.caja.cerrada_por">por {{ data.caja.cerrada_por.name }}</span>
                        </p>
                    </div>

                    <div v-if="puedeOperarCaja" class="flex gap-2">
                        <Button
                            v-if="!data?.caja || data.caja.estado === 'cerrada'"
                            size="sm"
                            @click="abrirCaja"
                            :disabled="loading"
                        >
                            <Unlock class="w-4 h-4 mr-1" />
                            Abrir caja
                        </Button>

                        <Button
                            v-if="data?.caja?.estado === 'abierta'"
                            variant="destructive"
                            size="sm"
                            @click="cerrarCaja"
                            :disabled="loading"
                        >
                            <Lock class="w-4 h-4 mr-1" />
                            Cerrar caja
                        </Button>
                    </div>

                    <div v-else-if="esAdmin" class="text-xs text-muted-foreground">
                        Modo administrador: solo visualización
                    </div>
                </CardContent>
            </Card>

            <!-- Stats del día -->
            <div v-if="data" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <Card>
                    <CardContent class="p-4">
                        <p class="text-xs text-muted-foreground mb-1">Ventas</p>
                        <p class="text-2xl font-semibold">{{ data.stats.cantidad_ventas }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="p-4">
                        <p class="text-xs text-muted-foreground mb-1">Total vendido</p>
                        <p class="text-xl font-semibold">{{ fmt(data.stats.total_monto) }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="p-4">
                        <p class="text-xs text-muted-foreground mb-1">Efectivo</p>
                        <p class="text-xl font-semibold">{{ fmt(data.stats.total_efectivo) }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="p-4">
                        <p class="text-xs text-muted-foreground mb-1">Transferencia</p>
                        <p class="text-xl font-semibold">{{ fmt(data.stats.total_transferencia) }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Stats admin del día -->
            <div
                v-if="esAdmin && data?.stats.ganancia_neta !== undefined"
                class="grid grid-cols-2 sm:grid-cols-4 gap-3"
            >
                <Card>
                    <CardContent class="p-4">
                        <p class="text-xs text-muted-foreground mb-1">Costo insumos</p>
                        <p class="text-xl font-semibold text-destructive">{{ fmt(data.stats.costo_insumos) }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="p-4">
                        <p class="text-xs text-muted-foreground mb-1">Ganancia bruta</p>
                        <p class="text-xl font-semibold">{{ fmt(data.stats.ganancia_bruta) }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="p-4">
                        <p class="text-xs text-muted-foreground mb-1">Gastos fijos</p>
                        <p class="text-xl font-semibold text-destructive">{{ fmt(data.stats.gastos_fijos) }}</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent class="p-4">
                        <p class="text-xs text-muted-foreground mb-1">Ganancia neta</p>
                        <p
                            class="text-xl font-semibold"
                            :class="Number(data.stats.ganancia_neta) >= 0 ? 'text-green-600' : 'text-destructive'"
                        >
                            {{ fmt(data.stats.ganancia_neta) }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Separación sugerida -->
            <Card v-if="esAdmin && data?.stats.separacion">
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm">Separación sugerida</CardTitle>
                </CardHeader>

                <CardContent class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                    <div>
                        <p class="text-xs text-muted-foreground">Reponer insumos</p>
                        <p class="font-semibold">{{ fmt(data.stats.separacion.reponer_insumos) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Ahorro 10%</p>
                        <p class="font-semibold">{{ fmt(data.stats.separacion.ahorro) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Retiro 40%</p>
                        <p class="font-semibold">{{ fmt(data.stats.separacion.retiro) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Negocio</p>
                        <p class="font-semibold">{{ fmt(data.stats.separacion.negocio) }}</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Resumen semanal -->
            <Card v-if="esAdmin && resumenSemanal">
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm">
                        Resumen semanal {{ fecha(resumenSemanal.desde) }} - {{ fecha(resumenSemanal.hasta) }}
                    </CardTitle>
                </CardHeader>

                <CardContent class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                    <div>
                        <p class="text-xs text-muted-foreground">Total vendido</p>
                        <p class="font-semibold">{{ fmt(resumenSemanal.total_ventas) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Ventas</p>
                        <p class="font-semibold">{{ resumenSemanal.cantidad_ventas }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Ganancia bruta</p>
                        <p class="font-semibold">{{ fmt(resumenSemanal.ganancia_bruta) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Ganancia neta</p>
                        <p class="font-semibold text-green-600">{{ fmt(resumenSemanal.ganancia_neta) }}</p>
                    </div>
                </CardContent>
            </Card>

            <!-- Consulta por rango de fechas -->
            <Card v-if="esAdmin">
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm">Consulta por rango de fechas</CardTitle>
                </CardHeader>

                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-col sm:flex-row gap-3 items-end">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-muted-foreground">Desde</label>
                            <input
                                type="date"
                                v-model="filtroDesde"
                                class="border rounded px-3 py-1.5 text-sm bg-background"
                            />
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-muted-foreground">Hasta</label>
                            <input
                                type="date"
                                v-model="filtroHasta"
                                class="border rounded px-3 py-1.5 text-sm bg-background"
                            />
                        </div>

                        <Button
                            size="sm"
                            @click="buscarRango"
                            :disabled="cargandoRango || !filtroDesde || !filtroHasta"
                        >
                            <RefreshCw class="w-4 h-4 mr-1" :class="cargandoRango ? 'animate-spin' : ''" />
                            Consultar
                        </Button>
                    </div>

                    <div v-if="resumenRango" class="flex flex-col gap-3">
                        <p class="text-xs text-muted-foreground">
                            {{ fecha(resumenRango.desde) }} → {{ fecha(resumenRango.hasta) }}
                            · {{ resumenRango.cantidad_ventas }} ventas
                        </p>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <p class="text-xs text-muted-foreground">Total vendido</p>
                                <p class="font-semibold text-lg">{{ fmt(resumenRango.total_ventas) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">Efectivo</p>
                                <p class="font-semibold text-lg">{{ fmt(resumenRango.total_efectivo) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">Transferencia</p>
                                <p class="font-semibold text-lg">{{ fmt(resumenRango.total_transferencia) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">Costo insumos</p>
                                <p class="font-semibold text-lg text-destructive">{{ fmt(resumenRango.costo_insumos) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">Ganancia bruta</p>
                                <p class="font-semibold text-lg">{{ fmt(resumenRango.ganancia_bruta) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">Gastos fijos</p>
                                <p class="font-semibold text-lg text-destructive">{{ fmt(resumenRango.gastos_fijos) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-muted-foreground">Ganancia neta</p>
                                <p
                                    class="font-semibold text-lg"
                                    :class="Number(resumenRango.ganancia_neta) >= 0 ? 'text-green-600' : 'text-destructive'"
                                >
                                    {{ fmt(resumenRango.ganancia_neta) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <p v-else-if="!cargandoRango" class="text-xs text-muted-foreground">
                        Seleccioná un rango y hacé clic en Consultar.
                    </p>
                </CardContent>
            </Card>

            <!-- Pedidos activos -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="font-semibold text-sm">Pedidos activos</h3>

                    <button
                        @click="cargar"
                        class="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1"
                    >
                        <RefreshCw class="w-3 h-3" />
                        Actualizar
                    </button>
                </div>

                <div v-if="!pedidosActivos.length" class="text-xs text-muted-foreground">
                    Sin pedidos activos
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <Card
                        v-for="pedido in pedidosActivos"
                        :key="pedido.id"
                        class="border"
                        :class="estadoColor[pedido.estado]"
                    >
                        <CardContent class="p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-black text-lg">#{{ pedido.numero_orden }}</span>
                                <span
                                    class="text-xs font-medium px-2 py-0.5 rounded-full border"
                                    :class="estadoColor[pedido.estado]"
                                >
                                    {{ estadoLabel[pedido.estado] }}
                                </span>
                            </div>

                            <p v-if="pedido.mesa" class="text-xs text-muted-foreground mb-1">
                                {{ pedido.mesa }}
                            </p>

                            <p v-if="pedido.notas" class="text-xs italic mb-2">
                                {{ pedido.notas }}
                            </p>

                            <div class="text-xs space-y-0.5 mb-2">
                                <p v-for="d in pedido.detalles" :key="d.nombre_snapshot">
                                    {{ d.cantidad }}x {{ d.nombre_snapshot }}
                                </p>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold">{{ fmt(pedido.total) }}</span>
                                <span class="text-xs text-muted-foreground">{{ horaVenta(pedido.created_at) }}</span>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Alertas de compra -->
            <Card v-if="esAdmin && alertasCompra.length">
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm text-destructive">⚠ Insumos a comprar</CardTitle>
                </CardHeader>

                <CardContent class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <div
                        v-for="a in alertasCompra"
                        :key="a.nombre"
                        class="text-xs flex justify-between border rounded px-2 py-1"
                    >
                        <span>{{ a.nombre }}</span>
                        <span
                            class="font-medium"
                            :class="a.estado === 'falta' ? 'text-destructive' : 'text-yellow-600'"
                        >
                            {{ a.stock_actual }} / {{ a.stock_minimo }} {{ a.unidad }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- Exportar CSV -->
            <div v-if="esAdmin" class="flex gap-2">
                <Button variant="outline" size="sm" @click="exportar" :disabled="!data?.ventas.length">
                    <Download class="w-4 h-4 mr-1" />
                    Exportar CSV
                </Button>
            </div>

            <!-- Ventas de la caja actual -->
            <Card v-if="esAdmin">
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Ventas de la caja actual</CardTitle>
                </CardHeader>

                <CardContent class="p-0">
                    <div v-if="loading" class="p-4 text-sm text-muted-foreground">
                        Cargando...
                    </div>

                    <div v-else-if="!data?.ventas.length" class="p-4 text-sm text-muted-foreground text-center">
                        Sin ventas
                    </div>

                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-xs text-muted-foreground">
                                <th class="text-left p-3 font-medium">#</th>
                                <th class="text-left p-3 font-medium">Hora</th>
                                <th class="text-left p-3 font-medium">Mesa</th>
                                <th class="text-left p-3 font-medium">Descripción</th>
                                <th class="text-left p-3 font-medium">Estado</th>
                                <th class="text-right p-3 font-medium">Efectivo</th>
                                <th class="text-right p-3 font-medium">Transfer.</th>
                                <th class="text-right p-3 font-medium">Total</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="venta in data.ventas"
                                :key="venta.id"
                                class="border-b last:border-0 hover:bg-muted/50"
                            >
                                <td class="p-3 font-bold">#{{ venta.numero_orden }}</td>
                                <td class="p-3 text-muted-foreground">{{ horaVenta(venta.created_at) }}</td>
                                <td class="p-3">{{ venta.mesa ?? '—' }}</td>
                                <td class="p-3">{{ venta.notas ?? '—' }}</td>
                                <td class="p-3">
                                    <span
                                        class="text-xs px-2 py-0.5 rounded-full border"
                                        :class="estadoColor[venta.estado]"
                                    >
                                        {{ estadoLabel[venta.estado] }}
                                    </span>
                                </td>
                                <td class="p-3 text-right">
                                    {{ fmt(venta.pagos.find(p => p.metodo === 'efectivo')?.monto ?? 0) }}
                                </td>
                                <td class="p-3 text-right">
                                    {{ fmt(venta.pagos.find(p => p.metodo === 'transferencia')?.monto ?? 0) }}
                                </td>
                                <td class="p-3 text-right font-semibold">
                                    {{ fmt(venta.total) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- Historial de cajas -->
            <Card v-if="esAdmin">
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Historial de cajas</CardTitle>
                </CardHeader>

                <CardContent class="p-0">
                    <div v-if="!historial.length" class="p-4 text-sm text-muted-foreground text-center">
                        Sin historial
                    </div>

                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-xs text-muted-foreground">
                                <th class="text-left p-3 font-medium">Fecha</th>
                                <th class="text-left p-3 font-medium">Estado</th>
                                <th class="text-left p-3 font-medium">Apertura</th>
                                <th class="text-left p-3 font-medium">Cierre</th>
                                <th class="text-right p-3 font-medium">Ventas</th>
                                <th class="text-right p-3 font-medium">Efectivo</th>
                                <th class="text-right p-3 font-medium">Transfer.</th>
                                <th class="text-right p-3 font-medium">Ganancia neta</th>
                                <th class="text-right p-3 font-medium">Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="caja in historial"
                                :key="caja.id"
                                class="border-b last:border-0 hover:bg-muted/50"
                            >
                                <td class="p-3">{{ fecha(caja.fecha_operativa) }}</td>
                                <td class="p-3 capitalize">{{ caja.estado }}</td>
                                <td class="p-3 text-muted-foreground">{{ horaVenta(caja.abierta_at) }}</td>
                                <td class="p-3 text-muted-foreground">{{ horaVenta(caja.cerrada_at) }}</td>
                                <td class="p-3 text-right">{{ caja.cantidad_ventas }}</td>
                                <td class="p-3 text-right">{{ fmt(caja.total_efectivo) }}</td>
                                <td class="p-3 text-right">{{ fmt(caja.total_transferencia) }}</td>
                                <td class="p-3 text-right font-semibold">{{ fmt(caja.ganancia_neta) }}</td>
                                <td class="p-3 text-right">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="verCajaGuardada(caja)"
                                        :disabled="cargandoCajaDetalle"
                                    >
                                        <Eye class="w-4 h-4 mr-1" />
                                        Ver caja
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- Modal detalle de caja -->
            <div
                v-if="cajaDetalle"
                class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4"
            >
                <div class="bg-background border rounded-xl w-full max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
                    <div class="p-4 border-b flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold">
                                Caja del {{ fecha(cajaDetalle.caja.fecha_operativa) }}
                            </h2>

                            <p class="text-xs text-muted-foreground mt-1">
                                Estado: {{ cajaDetalle.caja.estado }}
                                · Apertura: {{ horaVenta(cajaDetalle.caja.abierta_at) }}
                                · Cierre: {{ horaVenta(cajaDetalle.caja.cerrada_at) }}
                            </p>

                            <p class="text-xs text-muted-foreground mt-1">
                                Abierta por: {{ cajaDetalle.caja.abierta_por?.name ?? '—' }}
                                · Cerrada por: {{ cajaDetalle.caja.cerrada_por?.name ?? '—' }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <Button
                                v-if="cajaDetalle.caja.estado === 'abierta'"
                                variant="destructive"
                                size="sm"
                                @click="cerrarManual(cajaDetalle.caja)"
                                :disabled="loading"
                            >
                                <Lock class="w-4 h-4 mr-1" />
                                Cerrar manualmente
                            </Button>

                            <button
                                class="w-8 h-8 rounded-full border flex items-center justify-center hover:bg-muted"
                                @click="cerrarDetalleCaja"
                            >
                                <X class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <div class="p-4 overflow-y-auto flex flex-col gap-4">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <Card>
                                <CardContent class="p-4">
                                    <p class="text-xs text-muted-foreground mb-1">Ventas</p>
                                    <p class="text-xl font-semibold">{{ cajaDetalle.stats_guardadas.cantidad_ventas }}</p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent class="p-4">
                                    <p class="text-xs text-muted-foreground mb-1">Total vendido</p>
                                    <p class="text-xl font-semibold">{{ fmt(cajaDetalle.stats_guardadas.total_monto) }}</p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent class="p-4">
                                    <p class="text-xs text-muted-foreground mb-1">Efectivo</p>
                                    <p class="text-xl font-semibold">{{ fmt(cajaDetalle.stats_guardadas.total_efectivo) }}</p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent class="p-4">
                                    <p class="text-xs text-muted-foreground mb-1">Transferencia</p>
                                    <p class="text-xl font-semibold">{{ fmt(cajaDetalle.stats_guardadas.total_transferencia) }}</p>
                                </CardContent>
                            </Card>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <Card>
                                <CardContent class="p-4">
                                    <p class="text-xs text-muted-foreground mb-1">Costo insumos</p>
                                    <p class="text-xl font-semibold text-destructive">{{ fmt(cajaDetalle.stats_guardadas.costo_insumos) }}</p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent class="p-4">
                                    <p class="text-xs text-muted-foreground mb-1">Ganancia bruta</p>
                                    <p class="text-xl font-semibold">{{ fmt(cajaDetalle.stats_guardadas.ganancia_bruta) }}</p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent class="p-4">
                                    <p class="text-xs text-muted-foreground mb-1">Gastos fijos</p>
                                    <p class="text-xl font-semibold text-destructive">{{ fmt(cajaDetalle.stats_guardadas.gastos_fijos) }}</p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent class="p-4">
                                    <p class="text-xs text-muted-foreground mb-1">Ganancia neta</p>
                                    <p
                                        class="text-xl font-semibold"
                                        :class="Number(cajaDetalle.stats_guardadas.ganancia_neta) >= 0
                                            ? 'text-green-600'
                                            : 'text-destructive'"
                                    >
                                        {{ fmt(cajaDetalle.stats_guardadas.ganancia_neta) }}
                                    </p>
                                </CardContent>
                            </Card>
                        </div>

                        <Card v-if="cajaDetalle.resumen?.productos_top?.length">
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm">Productos más vendidos</CardTitle>
                            </CardHeader>

                            <CardContent class="p-0">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-xs text-muted-foreground">
                                            <th class="text-left p-3 font-medium">Producto</th>
                                            <th class="text-right p-3 font-medium">Cantidad</th>
                                            <th class="text-right p-3 font-medium">Monto</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <tr
                                            v-for="p in cajaDetalle.resumen.productos_top"
                                            :key="p.nombre"
                                            class="border-b last:border-0"
                                        >
                                            <td class="p-3">{{ p.nombre }}</td>
                                            <td class="p-3 text-right">{{ p.cantidad }}</td>
                                            <td class="p-3 text-right font-semibold">{{ fmt(p.monto) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm">Ventas de esta caja</CardTitle>
                            </CardHeader>

                            <CardContent class="p-0">
                                <div
                                    v-if="!cajaDetalle.ventas.length"
                                    class="p-4 text-sm text-muted-foreground text-center"
                                >
                                    Esta caja no tiene ventas asociadas.
                                </div>

                                <table v-else class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-xs text-muted-foreground">
                                            <th class="text-left p-3 font-medium">#</th>
                                            <th class="text-left p-3 font-medium">Hora</th>
                                            <th class="text-left p-3 font-medium">Mesa</th>
                                            <th class="text-left p-3 font-medium">Detalle</th>
                                            <th class="text-left p-3 font-medium">Pago</th>
                                            <th class="text-right p-3 font-medium">Total</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <tr
                                            v-for="venta in cajaDetalle.ventas"
                                            :key="venta.id"
                                            class="border-b last:border-0 hover:bg-muted/50"
                                        >
                                            <td class="p-3 font-bold">#{{ venta.numero_orden }}</td>

                                            <td class="p-3 text-muted-foreground">
                                                {{ horaVenta(venta.created_at) }}
                                            </td>

                                            <td class="p-3">{{ venta.mesa ?? '—' }}</td>

                                            <td class="p-3">
                                                <div class="flex flex-col gap-0.5">
                                                    <span
                                                        v-for="d in venta.detalles"
                                                        :key="d.nombre_snapshot"
                                                        class="text-xs"
                                                    >
                                                        {{ d.cantidad }}x {{ d.nombre_snapshot }}
                                                    </span>
                                                </div>
                                            </td>

                                            <td class="p-3">
                                                <div class="flex flex-col gap-0.5">
                                                    <span
                                                        v-for="p in venta.pagos"
                                                        :key="p.metodo"
                                                        class="text-xs capitalize"
                                                    >
                                                        {{ p.metodo }}: {{ fmt(p.monto) }}
                                                    </span>
                                                </div>
                                            </td>

                                            <td class="p-3 text-right font-semibold">
                                                {{ fmt(venta.total) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>