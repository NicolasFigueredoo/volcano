<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import { computed, onMounted, reactive, ref } from 'vue';
import { Download, Eye, Lock, Minus, Pencil, Plus, RefreshCw, Trash2, Unlock, X } from 'lucide-vue-next';

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

interface ProductoTop {
    nombre: string;
    cantidad: number;
    monto: number;
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
        productos_top?: ProductoTop[];
    };
}

// ── Composable & estado ──────────────────────────────────────────────────────

const { get, post, put, patch, del, loading } = useApi();

const data = ref<CajaData | null>(null);
const ventasHoy = ref<Venta[]>([]);
const menuItems = ref<MenuItem[]>([]);
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

// Edición de caja
const editandoCaja = ref<Caja | null>(null);
const formCaja = reactive({
    fecha_operativa: '',
    estado: 'abierta',
    total_ventas: 0,
    total_efectivo: 0,
    total_transferencia: 0,
    costo_insumos: 0,
    ganancia_bruta: 0,
    gastos_fijos: 0,
    ganancia_neta: 0,
    cantidad_ventas: 0,
});

// Edición de venta (ítems)
const editandoVenta = ref<Venta | null>(null);
const formVentaNotas = ref('');
const formVentaItems = ref<{ variante_id: number; nombre: string; cantidad: number; precio: number }[]>([]);
const menuParaAgregar = ref<number | ''>('');

// Nuevo pedido en una caja puntual (abierta o cerrada)
const agregandoVentaA = ref<Caja | null>(null);
const formNuevaVenta = reactive({
    mesa: '',
    notas: '',
    estado: 'pagado',
});
const formNuevaVentaItems = ref<{ variante_id: number; nombre: string; cantidad: number; precio: number }[]>([]);
const nuevoMenuParaAgregar = ref<number | ''>('');
const formNuevaVentaEfectivo = ref(0);
const formNuevaVentaTransferencia = ref(0);

const esAdmin = computed(() => data.value?.es_admin === true);
const puedeOperarCaja = computed(() => data.value?.puede_operar_caja === true);

const ventasOrdenadas = computed(() =>
    [...ventasHoy.value].sort((a, b) => (a.estado === 'anulado' ? 1 : 0) - (b.estado === 'anulado' ? 1 : 0))
);

const formVentaTotal = computed(() =>
    formVentaItems.value.reduce((acc, i) => acc + i.precio * i.cantidad, 0)
);

const formNuevaVentaTotal = computed(() =>
    formNuevaVentaItems.value.reduce((acc, i) => acc + i.precio * i.cantidad, 0)
);

const fmt = (n: any) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');

const estadoLabel: Record<string, string> = {
    pendiente: 'Pendiente',
    preparacion: 'En prep.',
    pagado: 'Pagado',
    entregado: 'Entregado',
    anulado: 'Anulado',
};

const estadoColor: Record<string, string> = {
    pendiente: 'bg-yellow-500/10 text-yellow-600 border-yellow-500/30',
    preparacion: 'bg-blue-500/10 text-blue-600 border-blue-500/30',
    pagado: 'bg-green-500/10 text-green-600 border-green-500/30',
    entregado: 'bg-muted text-muted-foreground border-border',
    anulado: 'bg-red-500/10 text-red-500 border-red-500/30',
};

function horaVenta(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
}

function fecha(d: string | null) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('es-AR');
}

function fechaInput(d: string | null) {
    if (!d) return '';
    return new Date(d).toISOString().slice(0, 10);
}

// ── Carga ────────────────────────────────────────────────────────────────────

async function cargar() {
    data.value = await get<CajaData>('/api/caja/hoy');

    const vhRes = await get<{ ventas: Venta[]; menu: MenuItem[] }>('/api/caja/ventas-hoy');
    if (vhRes) {
        ventasHoy.value = vhRes.ventas ?? [];
        menuItems.value = vhRes.menu ?? [];
    }

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

// ── Caja: abrir / cerrar ─────────────────────────────────────────────────────

async function abrirCaja() {
    await post('/api/caja/abrir', {});
    await cargar();
}

async function cerrarCaja() {
    if (!confirm('¿Confirmar cierre de caja?')) return;
    await post('/api/caja/cerrar', {});
    await cargar();
}

async function cerrarManual(caja: Caja) {
    if (!confirm(`¿Cerrar manualmente la caja del ${fecha(caja.fecha_operativa)}?\nSe calcularán los totales desde las ventas registradas.`)) return;
    await post(`/api/caja/${caja.id}/cerrar-manual`, {});
    cajaDetalle.value = null;
    await cargar();
}

// ── Caja: ver detalle guardado ───────────────────────────────────────────────

async function verCajaGuardada(caja: Caja) {
    cargandoCajaDetalle.value = true;
    const res = await get<CajaDetalle>(`/api/caja/historial/${caja.id}`);
    if (res) cajaDetalle.value = res;
    cargandoCajaDetalle.value = false;
}

function cerrarDetalleCaja() {
    cajaDetalle.value = null;
}

// ── Caja: editar / eliminar ──────────────────────────────────────────────────

function abrirEdicionCaja(caja: Caja) {
    editandoCaja.value = caja;
    formCaja.fecha_operativa = fechaInput(caja.fecha_operativa);
    formCaja.estado = caja.estado;
    formCaja.total_ventas = caja.total_ventas;
    formCaja.total_efectivo = caja.total_efectivo;
    formCaja.total_transferencia = caja.total_transferencia;
    formCaja.costo_insumos = caja.costo_insumos;
    formCaja.ganancia_bruta = caja.ganancia_bruta;
    formCaja.gastos_fijos = caja.gastos_fijos;
    formCaja.ganancia_neta = caja.ganancia_neta;
    formCaja.cantidad_ventas = caja.cantidad_ventas;
}

function cerrarEdicionCaja() {
    editandoCaja.value = null;
}

async function guardarEdicionCaja() {
    if (!editandoCaja.value) return;
    await put(`/api/caja/${editandoCaja.value.id}`, { ...formCaja });
    editandoCaja.value = null;
    if (cajaDetalle.value) await verCajaGuardada(cajaDetalle.value.caja);
    await cargar();
}

async function eliminarCaja(caja: Caja) {
    if (!confirm(`¿Eliminar la caja del ${fecha(caja.fecha_operativa)}?\nLas ventas asociadas quedarán sin caja (no se borran).`)) return;
    await del(`/api/caja/${caja.id}`);
    if (cajaDetalle.value?.caja.id === caja.id) cajaDetalle.value = null;
    await cargar();
}

// ── Venta: cambiar estado rápido ─────────────────────────────────────────────

async function cambiarEstadoVenta(venta: Venta, estado: string) {
    await patch(`/api/caja/ventas/${venta.id}/estado`, { estado });
    if (cajaDetalle.value) await verCajaGuardada(cajaDetalle.value.caja);
    await cargar();
}

// ── Venta: editar ítems ───────────────────────────────────────────────────────

function abrirEdicionVenta(venta: Venta) {
    editandoVenta.value = venta;
    formVentaNotas.value = venta.notas ?? '';
    formVentaItems.value = venta.detalles.map(d => ({
        variante_id: d.variante_id ?? 0,
        nombre: d.nombre_snapshot,
        cantidad: d.cantidad,
        precio: d.precio_snapshot,
    }));
    menuParaAgregar.value = '';
}

function cerrarEdicionVenta() {
    editandoVenta.value = null;
}

function agregarItem() {
    if (!menuParaAgregar.value) return;
    const m = menuItems.value.find(mi => mi.id === menuParaAgregar.value);
    if (!m) return;

    const existente = formVentaItems.value.find(i => i.variante_id === m.id);
    if (existente) {
        existente.cantidad++;
    } else {
        formVentaItems.value.push({ variante_id: m.id, nombre: m.nombre, cantidad: 1, precio: m.precio });
    }
    menuParaAgregar.value = '';
}

function sumarCantidad(item: { cantidad: number }) {
    item.cantidad++;
}

function restarCantidad(item: { cantidad: number }, index: number) {
    item.cantidad--;
    if (item.cantidad <= 0) formVentaItems.value.splice(index, 1);
}

async function guardarEdicionVenta() {
    if (!editandoVenta.value) return;
    if (!formVentaItems.value.length) {
        alert('El pedido debe tener al menos un ítem. Si querés sacarlo, usá Anular.');
        return;
    }

    await put(`/api/caja/ventas/${editandoVenta.value.id}`, {
        notas: formVentaNotas.value,
        items: formVentaItems.value.map(i => ({ variante_id: i.variante_id, cantidad: i.cantidad })),
    });

    editandoVenta.value = null;

    if (cajaDetalle.value) await verCajaGuardada(cajaDetalle.value.caja);
    await cargar();
}

// ── Venta: agregar pedido a una caja (abierta o cerrada) ─────────────────────

function abrirNuevaVenta(caja: Caja) {
    agregandoVentaA.value = caja;
    formNuevaVenta.mesa = '';
    formNuevaVenta.notas = '';
    formNuevaVenta.estado = 'pagado';
    formNuevaVentaItems.value = [];
    nuevoMenuParaAgregar.value = '';
    formNuevaVentaEfectivo.value = 0;
    formNuevaVentaTransferencia.value = 0;
}

function cerrarNuevaVenta() {
    agregandoVentaA.value = null;
}

function agregarItemNuevaVenta() {
    if (!nuevoMenuParaAgregar.value) return;
    const m = menuItems.value.find(mi => mi.id === nuevoMenuParaAgregar.value);
    if (!m) return;

    const existente = formNuevaVentaItems.value.find(i => i.variante_id === m.id);
    if (existente) {
        existente.cantidad++;
    } else {
        formNuevaVentaItems.value.push({ variante_id: m.id, nombre: m.nombre, cantidad: 1, precio: m.precio });
    }
    nuevoMenuParaAgregar.value = '';
}

async function guardarNuevaVenta() {
    if (!agregandoVentaA.value) return;
    if (!formNuevaVentaItems.value.length) {
        alert('Agregá al menos un ítem al pedido.');
        return;
    }

    const pagos = [];
    if (formNuevaVentaEfectivo.value > 0) pagos.push({ metodo: 'efectivo', monto: formNuevaVentaEfectivo.value });
    if (formNuevaVentaTransferencia.value > 0) pagos.push({ metodo: 'transferencia', monto: formNuevaVentaTransferencia.value });

    if (!pagos.length) {
        alert('Ingresá al menos un monto de pago (efectivo o transferencia).');
        return;
    }

    await post(`/api/caja/${agregandoVentaA.value.id}/ventas`, {
        mesa: formNuevaVenta.mesa || null,
        notas: formNuevaVenta.notas || null,
        estado: formNuevaVenta.estado,
        items: formNuevaVentaItems.value.map(i => ({ variante_id: i.variante_id, cantidad: i.cantidad })),
        pagos,
    });

    const cajaId = agregandoVentaA.value.id;
    cerrarNuevaVenta();

    if (cajaDetalle.value?.caja.id === cajaId) await verCajaGuardada(cajaDetalle.value.caja);
    await cargar();
}

async function anularVenta(venta: Venta) {
    if (!confirm(`¿Anular el pedido #${venta.numero_orden}? Quedará marcado como anulado y no contará en los totales.`)) return;

    await del(`/api/caja/ventas/${venta.id}`);

    if (editandoVenta.value?.id === venta.id) editandoVenta.value = null;
    if (cajaDetalle.value) await verCajaGuardada(cajaDetalle.value.caja);
    await cargar();
}

// ── Rango de fechas ───────────────────────────────────────────────────────────

async function buscarRango() {
    if (!filtroDesde.value || !filtroHasta.value) return;
    cargandoRango.value = true;
    resumenRango.value = await get<ResumenSemanal>(
        `/api/caja/resumen-rango?desde=${filtroDesde.value}&hasta=${filtroHasta.value}`
    );
    cargandoRango.value = false;
}

// ── Exportar ───────────────────────────────────────────────────────────────────

function exportar() {
    if (!esAdmin.value) return;

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
    a.href = URL.createObjectURL(new Blob([rows.map(r => r.join(',')).join('\n')], { type: 'text/csv' }));
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

                    <div v-if="puedeOperarCaja" class="flex gap-2">
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

                    <div v-else-if="esAdmin" class="flex items-center gap-2">
                        <span class="text-xs text-muted-foreground">Modo administrador</span>

                        <Button
                            v-if="data?.caja?.estado === 'abierta'"
                            variant="destructive" size="sm" @click="cerrarManual(data.caja)" :disabled="loading"
                        >
                            <Lock class="w-4 h-4 mr-1" /> Cerrar caja de hoy
                        </Button>

                        <Button v-if="data?.caja" variant="outline" size="sm" @click="abrirNuevaVenta(data.caja)">
                            <Plus class="w-4 h-4 mr-1" /> Agregar pedido
                        </Button>

                        <Button v-if="data?.caja" variant="outline" size="sm" @click="abrirEdicionCaja(data.caja)">
                            <Pencil class="w-4 h-4 mr-1" /> Editar
                        </Button>
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
                        <p class="text-xl font-semibold" :class="Number(data.stats.ganancia_neta) >= 0 ? 'text-green-600' : 'text-destructive'">
                            {{ fmt(data.stats.ganancia_neta) }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Separación sugerida -->
            <Card v-if="esAdmin && data?.stats.separacion">
                <CardHeader class="pb-2"><CardTitle class="text-sm">Separación sugerida</CardTitle></CardHeader>
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

            <!-- Consulta por rango de fechas -->
            <Card v-if="esAdmin">
                <CardHeader class="pb-2"><CardTitle class="text-sm">Consulta por rango de fechas</CardTitle></CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div class="flex flex-col sm:flex-row gap-3 items-end">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-muted-foreground">Desde</label>
                            <input type="date" v-model="filtroDesde" class="border rounded px-3 py-1.5 text-sm bg-background" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-muted-foreground">Hasta</label>
                            <input type="date" v-model="filtroHasta" class="border rounded px-3 py-1.5 text-sm bg-background" />
                        </div>
                        <Button size="sm" @click="buscarRango" :disabled="cargandoRango || !filtroDesde || !filtroHasta">
                            <RefreshCw class="w-4 h-4 mr-1" :class="cargandoRango ? 'animate-spin' : ''" /> Consultar
                        </Button>
                    </div>

                    <div v-if="resumenRango" class="flex flex-col gap-3">
                        <p class="text-xs text-muted-foreground">
                            {{ fecha(resumenRango.desde) }} → {{ fecha(resumenRango.hasta) }} · {{ resumenRango.cantidad_ventas }} ventas
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
                                <p class="font-semibold text-lg" :class="Number(resumenRango.ganancia_neta) >= 0 ? 'text-green-600' : 'text-destructive'">
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

            <!-- Pedidos del día -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="font-semibold text-sm">
                        Pedidos del día <span class="text-muted-foreground font-normal ml-1">({{ ventasHoy.length }})</span>
                    </h3>
                    <button @click="cargar" class="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1">
                        <RefreshCw class="w-3 h-3" /> Actualizar
                    </button>
                </div>

                <div v-if="!ventasHoy.length" class="text-xs text-muted-foreground">Sin pedidos hoy</div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <Card
                        v-for="venta in ventasOrdenadas"
                        :key="venta.id"
                        class="border"
                        :class="[estadoColor[venta.estado], venta.estado === 'anulado' ? 'opacity-50' : '']"
                    >
                        <CardContent class="p-3">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-black text-lg">#{{ venta.numero_orden }}</span>

                                <div class="flex items-center gap-1">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full border" :class="estadoColor[venta.estado]">
                                        {{ estadoLabel[venta.estado] }}
                                    </span>
                                    <button
                                        v-if="esAdmin && venta.estado !== 'anulado'"
                                        class="text-muted-foreground hover:text-foreground"
                                        @click="abrirEdicionVenta(venta)"
                                        title="Editar pedido"
                                    >
                                        <Pencil class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>

                            <p v-if="venta.mesa" class="text-xs text-muted-foreground mb-1">{{ venta.mesa }}</p>
                            <p v-if="venta.notas" class="text-xs italic mb-2">{{ venta.notas }}</p>

                            <div class="text-xs space-y-0.5 mb-2">
                                <p v-for="d in venta.detalles" :key="d.nombre_snapshot">
                                    {{ d.cantidad }}x {{ d.nombre_snapshot }}
                                </p>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold">{{ fmt(venta.total) }}</span>
                                <span class="text-xs text-muted-foreground">{{ horaVenta(venta.created_at) }}</span>
                            </div>

                            <div v-if="!esAdmin && venta.estado !== 'anulado' && venta.estado !== 'entregado'" class="mt-2 flex flex-wrap gap-1">
                                <button
                                    v-if="venta.estado === 'pendiente'"
                                    class="text-xs px-2 py-1 rounded border hover:bg-muted"
                                    @click="cambiarEstadoVenta(venta, 'preparacion')"
                                >
                                    → En prep.
                                </button>
                                <button
                                    v-if="venta.estado === 'preparacion'"
                                    class="text-xs px-2 py-1 rounded border hover:bg-muted"
                                    @click="cambiarEstadoVenta(venta, 'pagado')"
                                >
                                    → Pagado
                                </button>
                                <button
                                    v-if="venta.estado === 'pagado'"
                                    class="text-xs px-2 py-1 rounded border hover:bg-muted"
                                    @click="cambiarEstadoVenta(venta, 'entregado')"
                                >
                                    → Entregado
                                </button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- Alertas de compra -->
            <Card v-if="esAdmin && alertasCompra.length">
                <CardHeader class="pb-2"><CardTitle class="text-sm text-destructive">⚠ Insumos a comprar</CardTitle></CardHeader>
                <CardContent class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <div v-for="a in alertasCompra" :key="a.nombre" class="text-xs flex justify-between border rounded px-2 py-1">
                        <span>{{ a.nombre }}</span>
                        <span class="font-medium" :class="a.estado === 'falta' ? 'text-destructive' : 'text-yellow-600'">
                            {{ a.stock_actual }} / {{ a.stock_minimo }} {{ a.unidad }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- Exportar CSV -->
            <div v-if="esAdmin" class="flex gap-2">
                <Button variant="outline" size="sm" @click="exportar" :disabled="!ventasHoy.length">
                    <Download class="w-4 h-4 mr-1" /> Exportar CSV
                </Button>
            </div>

            <!-- Ventas de hoy (tabla admin) -->
            <Card v-if="esAdmin">
                <CardHeader class="pb-2"><CardTitle class="text-base">Ventas de hoy</CardTitle></CardHeader>
                <CardContent class="p-0">
                    <div v-if="loading" class="p-4 text-sm text-muted-foreground">Cargando...</div>
                    <div v-else-if="!ventasHoy.length" class="p-4 text-sm text-muted-foreground text-center">Sin ventas</div>

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
                                <th class="text-right p-3 font-medium">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="venta in ventasOrdenadas"
                                :key="venta.id"
                                class="border-b last:border-0 hover:bg-muted/50"
                                :class="venta.estado === 'anulado' ? 'opacity-50' : ''"
                            >
                                <td class="p-3 font-bold">#{{ venta.numero_orden }}</td>
                                <td class="p-3 text-muted-foreground">{{ horaVenta(venta.created_at) }}</td>
                                <td class="p-3">{{ venta.mesa ?? '—' }}</td>
                                <td class="p-3">{{ venta.notas ?? '—' }}</td>
                                <td class="p-3">
                                    <span class="text-xs px-2 py-0.5 rounded-full border" :class="estadoColor[venta.estado]">
                                        {{ estadoLabel[venta.estado] }}
                                    </span>
                                </td>
                                <td class="p-3 text-right">{{ fmt(venta.pagos.find(p => p.metodo === 'efectivo')?.monto ?? 0) }}</td>
                                <td class="p-3 text-right">{{ fmt(venta.pagos.find(p => p.metodo === 'transferencia')?.monto ?? 0) }}</td>
                                <td class="p-3 text-right font-semibold">{{ fmt(venta.total) }}</td>
                                <td class="p-3 text-right">
                                    <div class="flex items-center gap-1 justify-end">
                                        <button
                                            v-if="venta.estado !== 'anulado'"
                                            class="w-7 h-7 rounded border flex items-center justify-center hover:bg-muted"
                                            @click="abrirEdicionVenta(venta)"
                                            title="Editar"
                                        >
                                            <Pencil class="w-3.5 h-3.5" />
                                        </button>
                                        <button
                                            v-if="venta.estado !== 'anulado'"
                                            class="w-7 h-7 rounded border flex items-center justify-center hover:bg-destructive/10 text-destructive"
                                            @click="anularVenta(venta)"
                                            title="Anular"
                                        >
                                            <Trash2 class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- Historial de cajas -->
            <Card v-if="esAdmin">
                <CardHeader class="pb-2"><CardTitle class="text-base">Historial de cajas</CardTitle></CardHeader>
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
                                    <div class="flex items-center gap-1 justify-end">
                                        <Button v-if="caja.estado === 'abierta'" variant="destructive" size="sm" @click="cerrarManual(caja)">
                                            <Lock class="w-4 h-4 mr-1" /> Cerrar
                                        </Button>
                                        <Button variant="outline" size="sm" @click="verCajaGuardada(caja)" :disabled="cargandoCajaDetalle">
                                            <Eye class="w-4 h-4 mr-1" /> Ver
                                        </Button>
                                        <button class="w-8 h-8 rounded border flex items-center justify-center hover:bg-muted" @click="abrirNuevaVenta(caja)" title="Agregar pedido">
                                            <Plus class="w-4 h-4" />
                                        </button>
                                        <button class="w-8 h-8 rounded border flex items-center justify-center hover:bg-muted" @click="abrirEdicionCaja(caja)" title="Editar caja">
                                            <Pencil class="w-4 h-4" />
                                        </button>
                                        <button class="w-8 h-8 rounded border flex items-center justify-center hover:bg-destructive/10 text-destructive" @click="eliminarCaja(caja)" title="Eliminar caja">
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- Modal: editar caja -->
            <div v-if="editandoCaja" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
                <div class="bg-background border rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                    <div class="p-4 border-b flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Editar caja del {{ fecha(editandoCaja.fecha_operativa) }}</h2>
                        <button class="w-8 h-8 rounded-full border flex items-center justify-center hover:bg-muted" @click="cerrarEdicionCaja">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="p-4 flex flex-col gap-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Fecha operativa</label>
                                <input type="date" v-model="formCaja.fecha_operativa" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Estado</label>
                                <select v-model="formCaja.estado" class="border rounded px-3 py-1.5 text-sm bg-background">
                                    <option value="abierta">Abierta</option>
                                    <option value="cerrada">Cerrada</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Cantidad de ventas</label>
                                <input type="number" v-model.number="formCaja.cantidad_ventas" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Total ventas</label>
                                <input type="number" v-model.number="formCaja.total_ventas" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Efectivo</label>
                                <input type="number" v-model.number="formCaja.total_efectivo" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Transferencia</label>
                                <input type="number" v-model.number="formCaja.total_transferencia" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Costo insumos</label>
                                <input type="number" v-model.number="formCaja.costo_insumos" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Ganancia bruta</label>
                                <input type="number" v-model.number="formCaja.ganancia_bruta" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Gastos fijos</label>
                                <input type="number" v-model.number="formCaja.gastos_fijos" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Ganancia neta</label>
                                <input type="number" v-model.number="formCaja.ganancia_neta" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Tip: si solo querés cerrar la caja con los totales calculados automáticamente desde las ventas, usá "Cerrar" en vez de esta edición manual.
                        </p>

                        <div class="flex justify-end gap-2 mt-2">
                            <Button variant="outline" size="sm" @click="cerrarEdicionCaja">Cancelar</Button>
                            <Button size="sm" @click="guardarEdicionCaja" :disabled="loading">Guardar</Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: editar venta (ítems) -->
            <div v-if="editandoVenta" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
                <div class="bg-background border rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                    <div class="p-4 border-b flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Editar pedido #{{ editandoVenta.numero_orden }}</h2>
                        <button class="w-8 h-8 rounded-full border flex items-center justify-center hover:bg-muted" @click="cerrarEdicionVenta">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="p-4 flex flex-col gap-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-muted-foreground">Agregar ítem</label>
                            <div class="flex gap-2">
                                <select v-model="menuParaAgregar" class="flex-1 border rounded px-3 py-1.5 text-sm bg-background">
                                    <option value="">Seleccionar producto...</option>
                                    <option v-for="m in menuItems" :key="m.id" :value="m.id">{{ m.nombre }} — {{ fmt(m.precio) }}</option>
                                </select>
                                <Button size="sm" @click="agregarItem" :disabled="!menuParaAgregar">
                                    <Plus class="w-4 h-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <div
                                v-for="(item, idx) in formVentaItems"
                                :key="item.variante_id"
                                class="flex items-center justify-between border rounded px-3 py-2 text-sm"
                            >
                                <div>
                                    <p class="font-medium">{{ item.nombre }}</p>
                                    <p class="text-xs text-muted-foreground">{{ fmt(item.precio) }} c/u</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button class="w-7 h-7 rounded border flex items-center justify-center hover:bg-muted" @click="restarCantidad(item, idx)">
                                        <Minus class="w-3.5 h-3.5" />
                                    </button>
                                    <span class="w-6 text-center font-semibold">{{ item.cantidad }}</span>
                                    <button class="w-7 h-7 rounded border flex items-center justify-center hover:bg-muted" @click="sumarCantidad(item)">
                                        <Plus class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>

                            <p v-if="!formVentaItems.length" class="text-xs text-muted-foreground">
                                Sin ítems. Agregá al menos uno o anulá el pedido.
                            </p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-muted-foreground">Notas</label>
                            <textarea v-model="formVentaNotas" rows="2" class="border rounded px-3 py-1.5 text-sm bg-background"></textarea>
                        </div>

                        <div class="flex items-center justify-between border-t pt-3">
                            <span class="text-sm text-muted-foreground">Total estimado</span>
                            <span class="text-lg font-semibold">{{ fmt(formVentaTotal) }}</span>
                        </div>

                        <div class="flex justify-between gap-2 mt-2">
                            <Button variant="destructive" size="sm" @click="anularVenta(editandoVenta)">
                                <Trash2 class="w-4 h-4 mr-1" /> Anular pedido
                            </Button>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" @click="cerrarEdicionVenta">Cancelar</Button>
                                <Button size="sm" @click="guardarEdicionVenta" :disabled="loading">Guardar</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: agregar pedido a una caja -->
            <div v-if="agregandoVentaA" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
                <div class="bg-background border rounded-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                    <div class="p-4 border-b flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Agregar pedido — caja del {{ fecha(agregandoVentaA.fecha_operativa) }}</h2>
                        <button class="w-8 h-8 rounded-full border flex items-center justify-center hover:bg-muted" @click="cerrarNuevaVenta">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="p-4 flex flex-col gap-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Mesa (opcional)</label>
                                <input v-model="formNuevaVenta.mesa" type="text" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Estado</label>
                                <select v-model="formNuevaVenta.estado" class="border rounded px-3 py-1.5 text-sm bg-background">
                                    <option value="pendiente">Pendiente</option>
                                    <option value="preparacion">En prep.</option>
                                    <option value="pagado">Pagado</option>
                                    <option value="entregado">Entregado</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-muted-foreground">Agregar ítem</label>
                            <div class="flex gap-2">
                                <select v-model="nuevoMenuParaAgregar" class="flex-1 border rounded px-3 py-1.5 text-sm bg-background">
                                    <option value="">Seleccionar producto...</option>
                                    <option v-for="m in menuItems" :key="m.id" :value="m.id">{{ m.nombre }} — {{ fmt(m.precio) }}</option>
                                </select>
                                <Button size="sm" @click="agregarItemNuevaVenta" :disabled="!nuevoMenuParaAgregar">
                                    <Plus class="w-4 h-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <div
                                v-for="(item, idx) in formNuevaVentaItems"
                                :key="item.variante_id"
                                class="flex items-center justify-between border rounded px-3 py-2 text-sm"
                            >
                                <div>
                                    <p class="font-medium">{{ item.nombre }}</p>
                                    <p class="text-xs text-muted-foreground">{{ fmt(item.precio) }} c/u</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button class="w-7 h-7 rounded border flex items-center justify-center hover:bg-muted" @click="restarCantidad(item, idx)">
                                        <Minus class="w-3.5 h-3.5" />
                                    </button>
                                    <span class="w-6 text-center font-semibold">{{ item.cantidad }}</span>
                                    <button class="w-7 h-7 rounded border flex items-center justify-center hover:bg-muted" @click="sumarCantidad(item)">
                                        <Plus class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>

                            <p v-if="!formNuevaVentaItems.length" class="text-xs text-muted-foreground">
                                Sin ítems. Agregá al menos uno.
                            </p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-xs text-muted-foreground">Notas</label>
                            <textarea v-model="formNuevaVenta.notas" rows="2" class="border rounded px-3 py-1.5 text-sm bg-background"></textarea>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Pago efectivo</label>
                                <input v-model.number="formNuevaVentaEfectivo" type="number" min="0" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-xs text-muted-foreground">Pago transferencia</label>
                                <input v-model.number="formNuevaVentaTransferencia" type="number" min="0" class="border rounded px-3 py-1.5 text-sm bg-background" />
                            </div>
                        </div>

                        <div class="flex items-center justify-between border-t pt-3">
                            <span class="text-sm text-muted-foreground">Total ítems</span>
                            <span class="text-lg font-semibold">{{ fmt(formNuevaVentaTotal) }}</span>
                        </div>

                        <div class="flex justify-end gap-2 mt-2">
                            <Button variant="outline" size="sm" @click="cerrarNuevaVenta">Cancelar</Button>
                            <Button size="sm" @click="guardarNuevaVenta" :disabled="loading">Guardar pedido</Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: detalle de caja guardada -->
            <div v-if="cajaDetalle" class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
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

                        <div class="flex items-center gap-2 shrink-0">
                            <Button v-if="cajaDetalle.caja.estado === 'abierta'" variant="destructive" size="sm" @click="cerrarManual(cajaDetalle.caja)" :disabled="loading">
                                <Lock class="w-4 h-4 mr-1" /> Cerrar manualmente
                            </Button>
                            <Button variant="outline" size="sm" @click="abrirNuevaVenta(cajaDetalle.caja)">
                                <Plus class="w-4 h-4 mr-1" /> Agregar pedido
                            </Button>
                            <Button variant="outline" size="sm" @click="abrirEdicionCaja(cajaDetalle.caja)">
                                <Pencil class="w-4 h-4 mr-1" /> Editar caja
                            </Button>
                            <Button variant="destructive" size="sm" @click="eliminarCaja(cajaDetalle.caja)">
                                <Trash2 class="w-4 h-4 mr-1" /> Eliminar caja
                            </Button>
                            <button class="w-8 h-8 rounded-full border flex items-center justify-center hover:bg-muted" @click="cerrarDetalleCaja">
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
                                    <p class="text-xl font-semibold" :class="Number(cajaDetalle.stats_guardadas.ganancia_neta) >= 0 ? 'text-green-600' : 'text-destructive'">
                                        {{ fmt(cajaDetalle.stats_guardadas.ganancia_neta) }}
                                    </p>
                                </CardContent>
                            </Card>
                        </div>

                        <Card v-if="cajaDetalle.resumen?.productos_top?.length">
                            <CardHeader class="pb-2"><CardTitle class="text-sm">Productos más vendidos</CardTitle></CardHeader>
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
                                            <th class="text-right p-3 font-medium">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="venta in cajaDetalle.ventas"
                                            :key="venta.id"
                                            class="border-b last:border-0 hover:bg-muted/50"
                                            :class="venta.estado === 'anulado' ? 'opacity-50' : ''"
                                        >
                                            <td class="p-3 font-bold">#{{ venta.numero_orden }}</td>
                                            <td class="p-3 text-muted-foreground">{{ horaVenta(venta.created_at) }}</td>
                                            <td class="p-3">{{ venta.mesa ?? '—' }}</td>
                                            <td class="p-3">
                                                <div class="flex flex-col gap-0.5">
                                                    <span v-for="d in venta.detalles" :key="d.nombre_snapshot" class="text-xs">
                                                        {{ d.cantidad }}x {{ d.nombre_snapshot }}
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
                                            <td class="p-3 text-right">
                                                <div class="flex items-center gap-1 justify-end">
                                                    <button
                                                        v-if="venta.estado !== 'anulado'"
                                                        class="w-7 h-7 rounded border flex items-center justify-center hover:bg-muted"
                                                        @click="abrirEdicionVenta(venta)"
                                                        title="Editar"
                                                    >
                                                        <Pencil class="w-3.5 h-3.5" />
                                                    </button>
                                                    <button
                                                        v-if="venta.estado !== 'anulado'"
                                                        class="w-7 h-7 rounded border flex items-center justify-center hover:bg-destructive/10 text-destructive"
                                                        @click="anularVenta(venta)"
                                                        title="Anular"
                                                    >
                                                        <Trash2 class="w-3.5 h-3.5" />
                                                    </button>
                                                </div>
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