<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import { computed, onMounted, ref } from 'vue';
import { Download, Lock, RefreshCw, Unlock, X, Eye, Pencil, Trash2, Plus, Minus } from 'lucide-vue-next';

// ── Types ────────────────────────────────────────────────────────────────────

interface Pago {
    metodo: string;
    monto: number;
}

interface Detalle {
    id?: number;
    variante_id?: number;
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
    user?: { id: number; name: string } | null;
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

interface MenuItem {
    id: number;
    nombre: string;
    precio: number;
    costo: number;
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
        productos_top?: { nombre: string; cantidad: number; monto: number }[];
    };
}

// ── Composable & state ───────────────────────────────────────────────────────

const { get, post, put, patch, del, loading } = useApi();

const data            = ref<CajaData | null>(null);
const ventasHoy       = ref<Venta[]>([]);
const menuItems       = ref<MenuItem[]>([]);
const alertasCompra   = ref<any[]>([]);
const historial       = ref<Caja[]>([]);
const resumenSemanal  = ref<ResumenSemanal | null>(null);
const cajaDetalle     = ref<CajaDetalle | null>(null);
const cargandoDetalle = ref(false);

// Modal edición
const modalVenta      = ref<Venta | null>(null);
const editNotas       = ref('');
const editItems       = ref<{ variante_id: number; nombre: string; precio: number; costo: number; cantidad: number }[]>([]);
const editEstado      = ref('');
const guardando       = ref(false);
const busquedaMenu    = ref('');

// ── Computed ─────────────────────────────────────────────────────────────────

const esAdmin        = computed(() => data.value?.es_admin === true);
const puedeOperar    = computed(() => data.value?.puede_operar_caja === true);

const ventasOrdenadas = computed(() =>
    [...ventasHoy.value].sort((a, b) => {
        // activos primero, luego por hora desc
        const prioridad: Record<string, number> = { pendiente: 0, preparacion: 1, pagado: 2, entregado: 3, anulado: 4 };
        const diff = (prioridad[a.estado] ?? 9) - (prioridad[b.estado] ?? 9);
        if (diff !== 0) return diff;
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
    })
);

const menuFiltrado = computed(() => {
    const q = busquedaMenu.value.toLowerCase();
    return q ? menuItems.value.filter(m => m.nombre.toLowerCase().includes(q)) : menuItems.value;
});

const editTotal = computed(() =>
    editItems.value.reduce((s, i) => s + i.precio * i.cantidad, 0)
);

// ── Helpers ──────────────────────────────────────────────────────────────────

const fmt = (n: any) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');

const ESTADOS = ['pendiente', 'preparacion', 'pagado', 'entregado', 'anulado'] as const;

const estadoLabel: Record<string, string> = {
    pendiente:   'Pendiente',
    preparacion: 'En prep.',
    pagado:      'Pagado',
    entregado:   'Entregado',
    anulado:     'Anulado',
};

const estadoColor: Record<string, string> = {
    pendiente:   'bg-yellow-500/10 text-yellow-600 border-yellow-500/30',
    preparacion: 'bg-blue-500/10 text-blue-600 border-blue-500/30',
    pagado:      'bg-green-500/10 text-green-600 border-green-500/30',
    entregado:   'bg-muted text-muted-foreground border-border',
    anulado:     'bg-red-500/10 text-red-500 border-red-500/30 opacity-60',
};

function horaVenta(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
}

function fecha(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('es-AR');
}

// ── Carga ────────────────────────────────────────────────────────────────────

async function cargar() {
    data.value = await get<CajaData>('/api/caja/hoy');

    // Ventas del día unificadas
    const vhRes = await get<{ ventas: Venta[]; menu: MenuItem[] }>('/api/caja/ventas-hoy');
    if (vhRes) {
        ventasHoy.value  = vhRes.ventas;
        menuItems.value  = vhRes.menu;
    }

    if (data.value?.es_admin) {
        historial.value      = await get<Caja[]>('/api/caja/historial') ?? [];
        resumenSemanal.value = await get<ResumenSemanal>('/api/caja/resumen-semanal');
        alertasCompra.value  = await get<any[]>('/api/caja/alertas-compra') ?? [];
    }
}

// ── Operaciones de caja ──────────────────────────────────────────────────────

async function abrirCaja() {
    await post('/api/caja/abrir', {});
    await cargar();
}

async function cerrarCaja() {
    if (!confirm('¿Confirmar cierre de caja?')) return;
    await post('/api/caja/cerrar', {});
    await cargar();
}

// ── Cambio rápido de estado (sin abrir modal) ────────────────────────────────

async function cambiarEstadoRapido(venta: Venta, estado: string) {
    await patch(`/api/caja/ventas/${venta.id}/estado`, { estado });
    await cargar();
}

// ── Modal de edición ─────────────────────────────────────────────────────────

function abrirModal(venta: Venta) {
    modalVenta.value = venta;
    editEstado.value = venta.estado;
    editNotas.value  = venta.notas ?? '';
    editItems.value  = venta.detalles.map(d => ({
        variante_id: d.variante_id ?? 0,
        nombre:      d.nombre_snapshot,
        precio:      Number(d.precio_snapshot),
        costo:       Number(d.costo_snapshot),
        cantidad:    d.cantidad,
    }));
    busquedaMenu.value = '';
}

function cerrarModal() {
    modalVenta.value = null;
}

function agregarItem(item: MenuItem) {
    const existente = editItems.value.find(i => i.variante_id === item.id);
    if (existente) {
        existente.cantidad++;
    } else {
        editItems.value.push({
            variante_id: item.id,
            nombre:      item.nombre,
            precio:      item.precio,
            costo:       item.costo,
            cantidad:    1,
        });
    }
}

function quitarItem(idx: number) {
    editItems.value.splice(idx, 1);
}

function cambiarCantidad(idx: number, delta: number) {
    const item = editItems.value[idx];
    if (!item) return;
    item.cantidad += delta;
    if (item.cantidad <= 0) editItems.value.splice(idx, 1);
}

async function guardarEdicion() {
    if (!modalVenta.value) return;
    if (editItems.value.length === 0) {
        alert('La venta debe tener al menos un ítem.');
        return;
    }

    guardando.value = true;

    await put(`/api/caja/ventas/${modalVenta.value.id}`, {
        notas: editNotas.value || null,
        items: editItems.value.map(i => ({
            variante_id: i.variante_id,
            cantidad:    i.cantidad,
        })),
    });

    // Si el estado también cambió, actualizarlo por separado
    if (editEstado.value !== modalVenta.value.estado) {
        await patch(`/api/caja/ventas/${modalVenta.value.id}/estado`, { estado: editEstado.value });
    }

    guardando.value = false;
    cerrarModal();
    await cargar();
}

async function anularVenta(venta: Venta) {
    if (!confirm(`¿Anular la venta #${venta.numero_orden}? Esta acción no se puede deshacer.`)) return;
    await del(`/api/caja/ventas/${venta.id}`);
    await cargar();
}

// ── Ver caja guardada (historial) ────────────────────────────────────────────

async function verCajaGuardada(caja: Caja) {
    cargandoDetalle.value = true;
    const res = await get<CajaDetalle>(`/api/caja/historial/${caja.id}`);
    if (res) cajaDetalle.value = res;
    cargandoDetalle.value = false;
}

function cerrarDetalleCaja() {
    cajaDetalle.value = null;
}

// ── Exportar CSV ─────────────────────────────────────────────────────────────

function exportar() {
    if (!data.value || !esAdmin.value) return;

    const rows = [
        ['#', 'Hora', 'Mesa', 'Descripción', 'Estado', 'Efectivo', 'Transferencia', 'Total'],
        ...ventasHoy.value
            .filter(v => v.estado !== 'anulado')
            .map(v => [
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

            <!-- ── Estado de caja ── -->
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
                                Fecha operativa: {{ data?.caja?.fecha_operativa ? fecha(data.caja.fecha_operativa) : '—' }}
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

                    <div v-if="puedeOperar" class="flex gap-2">
                        <Button
                            v-if="!data?.caja || data.caja.estado === 'cerrada'"
                            size="sm" @click="abrirCaja" :disabled="loading"
                        >
                            <Unlock class="w-4 h-4 mr-1" /> Abrir caja
                        </Button>
                        <Button
                            v-if="data?.caja?.estado === 'abierta'"
                            variant="destructive" size="sm" @click="cerrarCaja" :disabled="loading"
                        >
                            <Lock class="w-4 h-4 mr-1" /> Cerrar caja
                        </Button>
                    </div>
                    <div v-else-if="esAdmin" class="text-xs text-muted-foreground">
                        Modo administrador: solo visualización
                    </div>
                </CardContent>
            </Card>

            <!-- ── Stats básicas ── -->
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

            <!-- ── Stats admin (costos/ganancias) ── -->
            <div v-if="esAdmin && data?.stats.ganancia_neta !== undefined" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
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
                        <p class="text-xl font-semibold"
                            :class="Number(data.stats.ganancia_neta) >= 0 ? 'text-green-600' : 'text-destructive'">
                            {{ fmt(data.stats.ganancia_neta) }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- ── Separación sugerida ── -->
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

            <!-- ── Resumen semanal ── -->
            <Card v-if="esAdmin && resumenSemanal">
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm">
                        Resumen semanal {{ fecha(resumenSemanal.desde) }} — {{ fecha(resumenSemanal.hasta) }}
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

            <!-- ── PEDIDOS DEL DÍA (unificado) ── -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-sm">
                        Pedidos del día
                        <span class="text-muted-foreground font-normal ml-1">({{ ventasHoy.length }})</span>
                    </h3>
                    <button
                        @click="cargar"
                        class="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1"
                    >
                        <RefreshCw class="w-3 h-3" /> Actualizar
                    </button>
                </div>

                <div v-if="!ventasHoy.length" class="text-xs text-muted-foreground">
                    Sin pedidos hoy
                </div>

                <!-- Vista cards (cajero) / tabla (admin) -->

                <!-- CARDS — visible para todos en mobile, para cajero en desktop -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3" :class="{ 'lg:hidden': esAdmin }">
                    <Card
                        v-for="venta in ventasOrdenadas"
                        :key="venta.id"
                        class="border transition-opacity"
                        :class="[estadoColor[venta.estado], venta.estado === 'anulado' ? 'opacity-50' : '']"
                    >
                        <CardContent class="p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-black text-lg">#{{ venta.numero_orden }}</span>
                                <span
                                    class="text-xs font-medium px-2 py-0.5 rounded-full border"
                                    :class="estadoColor[venta.estado]"
                                >
                                    {{ estadoLabel[venta.estado] }}
                                </span>
                            </div>

                            <p v-if="venta.mesa" class="text-xs text-muted-foreground mb-1">{{ venta.mesa }}</p>
                            <p v-if="venta.notas" class="text-xs italic mb-2">{{ venta.notas }}</p>

                            <div class="text-xs space-y-0.5 mb-2">
                                <p v-for="d in venta.detalles" :key="d.nombre_snapshot">
                                    {{ d.cantidad }}× {{ d.nombre_snapshot }}
                                </p>
                            </div>

                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs font-semibold">{{ fmt(venta.total) }}</span>
                                <span class="text-xs text-muted-foreground">{{ horaVenta(venta.created_at) }}</span>
                            </div>

                            <!-- Acciones rápidas de estado (cajero) -->
                            <div v-if="puedeOperar && venta.estado !== 'anulado'" class="flex flex-wrap gap-1 mt-1">
                                <button
                                    v-for="e in (['pendiente', 'preparacion', 'pagado', 'entregado'] as const)"
                                    :key="e"
                                    @click="cambiarEstadoRapido(venta, e)"
                                    :disabled="venta.estado === e"
                                    class="text-[10px] px-1.5 py-0.5 rounded border transition-opacity"
                                    :class="venta.estado === e
                                        ? estadoColor[e] + ' opacity-100'
                                        : 'border-border text-muted-foreground hover:bg-muted disabled:cursor-default'"
                                >
                                    {{ estadoLabel[e] }}
                                </button>
                            </div>

                            <!-- Acciones admin en card -->
                            <div v-if="esAdmin && venta.estado !== 'anulado'" class="flex gap-1 mt-2">
                                <Button variant="outline" size="sm" class="flex-1 text-xs h-7" @click="abrirModal(venta)">
                                    <Pencil class="w-3 h-3 mr-1" /> Editar
                                </Button>
                                <Button variant="outline" size="sm" class="h-7 px-2 text-destructive border-destructive/30 hover:bg-destructive/10" @click="anularVenta(venta)">
                                    <Trash2 class="w-3 h-3" />
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- TABLA — solo admin en desktop -->
                <Card v-if="esAdmin" class="hidden lg:block">
                    <CardContent class="p-0">
                        <div v-if="!ventasOrdenadas.length" class="p-4 text-sm text-muted-foreground text-center">
                            Sin ventas
                        </div>
                        <table v-else class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-xs text-muted-foreground">
                                    <th class="text-left p-3 font-medium">#</th>
                                    <th class="text-left p-3 font-medium">Hora</th>
                                    <th class="text-left p-3 font-medium">Mesa</th>
                                    <th class="text-left p-3 font-medium">Ítems</th>
                                    <th class="text-left p-3 font-medium">Nota</th>
                                    <th class="text-left p-3 font-medium">Estado</th>
                                    <th class="text-right p-3 font-medium">Efectivo</th>
                                    <th class="text-right p-3 font-medium">Transfer.</th>
                                    <th class="text-right p-3 font-medium">Total</th>
                                    <th class="text-right p-3 font-medium">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="venta in ventasOrdenadas"
                                    :key="venta.id"
                                    class="border-b last:border-0 hover:bg-muted/50 transition-opacity"
                                    :class="{ 'opacity-40': venta.estado === 'anulado' }"
                                >
                                    <td class="p-3 font-bold">#{{ venta.numero_orden }}</td>
                                    <td class="p-3 text-muted-foreground">{{ horaVenta(venta.created_at) }}</td>
                                    <td class="p-3">{{ venta.mesa ?? '—' }}</td>
                                    <td class="p-3">
                                        <div class="flex flex-col gap-0.5">
                                            <span v-for="d in venta.detalles" :key="d.nombre_snapshot" class="text-xs">
                                                {{ d.cantidad }}× {{ d.nombre_snapshot }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="p-3 text-xs text-muted-foreground italic">{{ venta.notas ?? '—' }}</td>
                                    <td class="p-3">
                                        <!-- Selector de estado inline para admin -->
                                        <select
                                            v-if="venta.estado !== 'anulado'"
                                            :value="venta.estado"
                                            @change="cambiarEstadoRapido(venta, ($event.target as HTMLSelectElement).value)"
                                            class="text-xs border rounded px-1.5 py-0.5 bg-background"
                                        >
                                            <option v-for="e in ESTADOS.filter(s => s !== 'anulado')" :key="e" :value="e">
                                                {{ estadoLabel[e] }}
                                            </option>
                                        </select>
                                        <span v-else class="text-xs px-2 py-0.5 rounded-full border" :class="estadoColor.anulado">
                                            Anulado
                                        </span>
                                    </td>
                                    <td class="p-3 text-right">
                                        {{ fmt(venta.pagos.find(p => p.metodo === 'efectivo')?.monto ?? 0) }}
                                    </td>
                                    <td class="p-3 text-right">
                                        {{ fmt(venta.pagos.find(p => p.metodo === 'transferencia')?.monto ?? 0) }}
                                    </td>
                                    <td class="p-3 text-right font-semibold">{{ fmt(venta.total) }}</td>
                                    <td class="p-3 text-right">
                                        <div v-if="venta.estado !== 'anulado'" class="flex items-center justify-end gap-1">
                                            <Button variant="outline" size="sm" @click="abrirModal(venta)">
                                                <Pencil class="w-3 h-3 mr-1" /> Editar
                                            </Button>
                                            <Button
                                                variant="outline" size="sm"
                                                class="text-destructive border-destructive/30 hover:bg-destructive/10"
                                                @click="anularVenta(venta)"
                                            >
                                                <Trash2 class="w-3 h-3" />
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>

            <!-- ── Alertas de compra ── -->
            <Card v-if="esAdmin && alertasCompra.length">
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm text-destructive">⚠ Insumos a comprar</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <div
                        v-for="a in alertasCompra" :key="a.nombre"
                        class="text-xs flex justify-between border rounded px-2 py-1"
                    >
                        <span>{{ a.nombre }}</span>
                        <span class="font-medium" :class="a.estado === 'falta' ? 'text-destructive' : 'text-yellow-600'">
                            {{ a.stock_actual }} / {{ a.stock_minimo }} {{ a.unidad }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Acciones admin ── -->
            <div v-if="esAdmin" class="flex gap-2">
                <Button variant="outline" size="sm" @click="exportar" :disabled="!ventasHoy.length">
                    <Download class="w-4 h-4 mr-1" /> Exportar CSV
                </Button>
            </div>

            <!-- ── Historial de cajas ── -->
            <Card v-if="esAdmin">
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Historial de cajas</CardTitle>
                </CardHeader>
                <CardContent class="p-0">
                    <div v-if="!historial.length" class="p-4 text-sm text-muted-foreground text-center">Sin historial</div>
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
                            <tr v-for="caja in historial" :key="caja.id" class="border-b last:border-0 hover:bg-muted/50">
                                <td class="p-3">{{ fecha(caja.fecha_operativa) }}</td>
                                <td class="p-3 capitalize">{{ caja.estado }}</td>
                                <td class="p-3 text-muted-foreground">{{ horaVenta(caja.abierta_at) }}</td>
                                <td class="p-3 text-muted-foreground">{{ horaVenta(caja.cerrada_at) }}</td>
                                <td class="p-3 text-right">{{ caja.cantidad_ventas }}</td>
                                <td class="p-3 text-right">{{ fmt(caja.total_efectivo) }}</td>
                                <td class="p-3 text-right">{{ fmt(caja.total_transferencia) }}</td>
                                <td class="p-3 text-right font-semibold">{{ fmt(caja.ganancia_neta) }}</td>
                                <td class="p-3 text-right">
                                    <Button variant="outline" size="sm" @click="verCajaGuardada(caja)" :disabled="cargandoDetalle">
                                        <Eye class="w-4 h-4 mr-1" /> Ver caja
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

        </div>

        <!-- ════════════════════════════════════════════════════════════════════
             MODAL — Editar venta
             ════════════════════════════════════════════════════════════════════ -->
        <div
            v-if="modalVenta"
            class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4"
            @mousedown.self="cerrarModal"
        >
            <div class="bg-background border rounded-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col">

                <!-- Header -->
                <div class="p-4 border-b flex items-center justify-between">
                    <h2 class="text-base font-semibold">
                        Editar pedido #{{ modalVenta.numero_orden }}
                    </h2>
                    <button
                        class="w-8 h-8 rounded-full border flex items-center justify-center hover:bg-muted"
                        @click="cerrarModal"
                    >
                        <X class="w-4 h-4" />
                    </button>
                </div>

                <div class="p-4 overflow-y-auto flex flex-col gap-4">

                    <!-- Estado -->
                    <div>
                        <p class="text-xs font-medium text-muted-foreground mb-2">Estado</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="e in ESTADOS"
                                :key="e"
                                @click="editEstado = e"
                                class="text-xs px-3 py-1.5 rounded-full border font-medium transition-all"
                                :class="editEstado === e
                                    ? estadoColor[e]
                                    : 'border-border text-muted-foreground hover:bg-muted'"
                            >
                                {{ estadoLabel[e] }}
                            </button>
                        </div>
                    </div>

                    <!-- Nota -->
                    <div>
                        <p class="text-xs font-medium text-muted-foreground mb-2">Nota</p>
                        <input
                            v-model="editNotas"
                            type="text"
                            placeholder="Sin cebolla, bien cocida…"
                            class="w-full text-sm border rounded-lg px-3 py-2 bg-background focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                    </div>

                    <!-- Ítems actuales -->
                    <div>
                        <p class="text-xs font-medium text-muted-foreground mb-2">Ítems del pedido</p>

                        <div v-if="!editItems.length" class="text-xs text-muted-foreground text-center py-3 border rounded-lg">
                            Sin ítems — agregá al menos uno
                        </div>

                        <div class="flex flex-col gap-1">
                            <div
                                v-for="(item, idx) in editItems"
                                :key="idx"
                                class="flex items-center gap-2 border rounded-lg px-3 py-2"
                            >
                                <span class="flex-1 text-sm">{{ item.nombre }}</span>
                                <span class="text-xs text-muted-foreground mr-1">{{ fmt(item.precio * item.cantidad) }}</span>

                                <!-- Cantidad -->
                                <div class="flex items-center gap-1">
                                    <button
                                        @click="cambiarCantidad(idx, -1)"
                                        class="w-6 h-6 rounded border flex items-center justify-center hover:bg-muted"
                                    >
                                        <Minus class="w-3 h-3" />
                                    </button>
                                    <span class="w-6 text-center text-sm font-semibold">{{ item.cantidad }}</span>
                                    <button
                                        @click="cambiarCantidad(idx, +1)"
                                        class="w-6 h-6 rounded border flex items-center justify-center hover:bg-muted"
                                    >
                                        <Plus class="w-3 h-3" />
                                    </button>
                                </div>

                                <button @click="quitarItem(idx)" class="text-destructive hover:text-destructive/80 ml-1">
                                    <X class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        <!-- Total -->
                        <div class="flex justify-end mt-2 text-sm font-semibold">
                            Total: {{ fmt(editTotal) }}
                        </div>
                    </div>

                    <!-- Agregar ítem del menú -->
                    <div>
                        <p class="text-xs font-medium text-muted-foreground mb-2">Agregar ítem del menú</p>
                        <input
                            v-model="busquedaMenu"
                            type="text"
                            placeholder="Buscar producto…"
                            class="w-full text-sm border rounded-lg px-3 py-2 bg-background focus:outline-none focus:ring-2 focus:ring-ring mb-2"
                        />
                        <div class="max-h-40 overflow-y-auto border rounded-lg divide-y">
                            <div v-if="!menuFiltrado.length" class="text-xs text-muted-foreground text-center py-3">
                                Sin resultados
                            </div>
                            <button
                                v-for="item in menuFiltrado"
                                :key="item.id"
                                @click="agregarItem(item)"
                                class="w-full flex items-center justify-between px-3 py-2 text-sm hover:bg-muted text-left"
                            >
                                <span>{{ item.nombre }}</span>
                                <span class="text-xs text-muted-foreground ml-2">{{ fmt(item.precio) }}</span>
                            </button>
                        </div>
                    </div>

                </div>

                <!-- Footer -->
                <div class="p-4 border-t flex justify-end gap-2">
                    <Button variant="outline" @click="cerrarModal" :disabled="guardando">Cancelar</Button>
                    <Button @click="guardarEdicion" :disabled="guardando || editItems.length === 0">
                        {{ guardando ? 'Guardando…' : 'Guardar cambios' }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════════
             MODAL — Ver caja guardada (historial)
             ════════════════════════════════════════════════════════════════════ -->
        <div
            v-if="cajaDetalle"
            class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4"
            @mousedown.self="cerrarDetalleCaja"
        >
            <div class="bg-background border rounded-xl w-full max-w-6xl max-h-[90vh] overflow-hidden flex flex-col">
                <div class="p-4 border-b flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">Caja del {{ fecha(cajaDetalle.caja.fecha_operativa) }}</h2>
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
                    <button class="w-8 h-8 rounded-full border flex items-center justify-center hover:bg-muted" @click="cerrarDetalleCaja">
                        <X class="w-4 h-4" />
                    </button>
                </div>

                <div class="p-4 overflow-y-auto flex flex-col gap-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <Card><CardContent class="p-4"><p class="text-xs text-muted-foreground mb-1">Ventas</p><p class="text-xl font-semibold">{{ cajaDetalle.stats_guardadas.cantidad_ventas }}</p></CardContent></Card>
                        <Card><CardContent class="p-4"><p class="text-xs text-muted-foreground mb-1">Total vendido</p><p class="text-xl font-semibold">{{ fmt(cajaDetalle.stats_guardadas.total_monto) }}</p></CardContent></Card>
                        <Card><CardContent class="p-4"><p class="text-xs text-muted-foreground mb-1">Efectivo</p><p class="text-xl font-semibold">{{ fmt(cajaDetalle.stats_guardadas.total_efectivo) }}</p></CardContent></Card>
                        <Card><CardContent class="p-4"><p class="text-xs text-muted-foreground mb-1">Transferencia</p><p class="text-xl font-semibold">{{ fmt(cajaDetalle.stats_guardadas.total_transferencia) }}</p></CardContent></Card>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <Card><CardContent class="p-4"><p class="text-xs text-muted-foreground mb-1">Costo insumos</p><p class="text-xl font-semibold text-destructive">{{ fmt(cajaDetalle.stats_guardadas.costo_insumos) }}</p></CardContent></Card>
                        <Card><CardContent class="p-4"><p class="text-xs text-muted-foreground mb-1">Ganancia bruta</p><p class="text-xl font-semibold">{{ fmt(cajaDetalle.stats_guardadas.ganancia_bruta) }}</p></CardContent></Card>
                        <Card><CardContent class="p-4"><p class="text-xs text-muted-foreground mb-1">Gastos fijos</p><p class="text-xl font-semibold text-destructive">{{ fmt(cajaDetalle.stats_guardadas.gastos_fijos) }}</p></CardContent></Card>
                        <Card><CardContent class="p-4"><p class="text-xs text-muted-foreground mb-1">Ganancia neta</p><p class="text-xl font-semibold" :class="Number(cajaDetalle.stats_guardadas.ganancia_neta) >= 0 ? 'text-green-600' : 'text-destructive'">{{ fmt(cajaDetalle.stats_guardadas.ganancia_neta) }}</p></CardContent></Card>
                    </div>

                    <Card v-if="cajaDetalle.resumen?.productos_top?.length">
                        <CardHeader class="pb-2"><CardTitle class="text-sm">Productos más vendidos</CardTitle></CardHeader>
                        <CardContent class="p-0">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b text-xs text-muted-foreground"><th class="text-left p-3 font-medium">Producto</th><th class="text-right p-3 font-medium">Cantidad</th><th class="text-right p-3 font-medium">Monto</th></tr></thead>
                                <tbody>
                                    <tr v-for="p in cajaDetalle.resumen.productos_top" :key="p.nombre" class="border-b last:border-0">
                                        <td class="p-3">{{ p.nombre }}</td>
                                        <td class="p-3 text-right">{{ p.cantidad }}</td>
                                        <td class="p-3 text-right font-semibold">{{ fmt(p.monto) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="pb-2"><CardTitle class="text-sm">Ventas de esta caja</CardTitle></CardHeader>
                        <CardContent class="p-0">
                            <div v-if="!cajaDetalle.ventas.length" class="p-4 text-sm text-muted-foreground text-center">
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
                                    <tr v-for="venta in cajaDetalle.ventas" :key="venta.id" class="border-b last:border-0 hover:bg-muted/50">
                                        <td class="p-3 font-bold">#{{ venta.numero_orden }}</td>
                                        <td class="p-3 text-muted-foreground">{{ horaVenta(venta.created_at) }}</td>
                                        <td class="p-3">{{ venta.mesa ?? '—' }}</td>
                                        <td class="p-3">
                                            <div class="flex flex-col gap-0.5">
                                                <span v-for="d in venta.detalles" :key="d.nombre_snapshot" class="text-xs">
                                                    {{ d.cantidad }}× {{ d.nombre_snapshot }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="p-3">
                                            <div class="flex flex-col gap-0.5">
                                                <span v-for="p in venta.pagos" :key="p.metodo" class="text-xs capitalize">
                                                    {{ p.metodo }}: {{ fmt(p.monto) }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="p-3 text-right font-semibold">{{ fmt(venta.total) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>

    </AppLayout>
</template>