<script setup lang="ts">
import MovimientoProveedorForm from '@/components/MovimientoProveedorForm.vue';
import SepararInsumos from '@/components/SepararInsumos.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    fmtPesos as fmt,
    fmtUnidades,
    type EstadoProveedor,
    type MovimientoProveedor,
    type ResultadoMovimiento,
    type ResumenDia,
} from '@/types/separacion';
import { Check, PiggyBank, Plus, RefreshCw, Truck, X } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

// ── Types ────────────────────────────────────────────────────────────────────

interface UltimaReposicion {
    id: number;
    fecha: string | null;
    monto_acumulado: number;
    monto_real: number | null;
    usuario: string | null;
}

interface Grupo {
    grupo: string;
    label: string;
    monto_acumulado: number;
    cantidad_ventas: number;
    ultima_reposicion: UltimaReposicion | null;
}

interface HistorialItem {
    id: number;
    grupo: string;
    label: string;
    fecha: string | null;
    monto_acumulado: number;
    monto_real: number | null;
    diferencia: number | null;
    observacion: string | null;
    usuario: string | null;
}

interface SeparacionData {
    fecha_corte: string;
    hoy: ResumenDia | null;
    proveedores: EstadoProveedor[];
    grupos: Grupo[];
    total_general: number;
    historial: HistorialItem[];
}

interface InsumoAdmin {
    id: number;
    nombre: string;
    unidad: string;
    proveedor_id: number | null;
    unidad_compra: string | null;
    equivalencia_compra: number | null;
    activo: boolean;
}

// ── Estado ───────────────────────────────────────────────────────────────────

const { get, post, put, loading, error } = useApi();

const tab = ref<'separacion' | 'proveedores'>('separacion');

const data = ref<SeparacionData | null>(null);

// Rubro cuyo panel de reposición está abierto (sin Dialog/radix).
const grupoActivo = ref<string | null>(null);
const form = ref<{ monto_real: string; observacion: string }>({
    monto_real: '',
    observacion: '',
});

// Panel de movimiento abierto en una tarjeta de proveedor.
const movimientoActivo = ref<{ proveedorId: number; tipo: MovimientoProveedor['tipo'] } | null>(null);
const aviso = ref<string | null>(null);

// Modal "Separar insumos" de la caja de hoy.
const separandoCaja = ref<number | null>(null);

// ── Helpers ──────────────────────────────────────────────────────────────────

function fecha(d: string | null) {
    if (!d) return '—';

    return new Date(d.length === 10 ? d + 'T12:00' : d).toLocaleDateString('es-AR');
}

function fechaHora(d: string | null) {
    if (!d) return '—';

    return new Date(d).toLocaleString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

// ── Carga ────────────────────────────────────────────────────────────────────

async function cargar() {
    data.value = await get<SeparacionData>('/api/separacion');

    if (tab.value === 'proveedores') await cargarProveedores();
}

onMounted(cargar);

// ── Reposición (rubros sin proveedor) ────────────────────────────────────────

function abrirConfirmacion(grupo: Grupo) {
    grupoActivo.value = grupo.grupo;
    form.value = { monto_real: '', observacion: '' };
}

function cancelar() {
    grupoActivo.value = null;
    form.value = { monto_real: '', observacion: '' };
}

async function confirmarReposicion(grupo: Grupo) {
    const body: Record<string, unknown> = {};

    if (form.value.monto_real !== '') {
        body.monto_real = Number(form.value.monto_real);
    }

    if (form.value.observacion.trim() !== '') {
        body.observacion = form.value.observacion.trim();
    }

    const res = await post<SeparacionData>(`/api/separacion/${grupo.grupo}/reponer`, body);

    if (res) {
        data.value = res;
        cancelar();
    }
}

// ── Movimientos de proveedor ─────────────────────────────────────────────────

function abrirMovimiento(p: EstadoProveedor, tipo: MovimientoProveedor['tipo']) {
    aviso.value = null;
    movimientoActivo.value = { proveedorId: p.id, tipo };
}

async function movimientoGuardado(res: ResultadoMovimiento) {
    movimientoActivo.value = null;

    if (res.costo_nuevo !== null && res.costo_anterior !== null && Math.round(res.costo_nuevo) !== Math.round(res.costo_anterior)) {
        aviso.value = `Costo de ${res.movimiento.insumo?.nombre ?? 'insumo'} actualizado: ${fmt(res.costo_anterior)} → ${fmt(res.costo_nuevo)}. Se recalcularon las recetas.`;
    }

    await cargar();

    if (proveedorSel.value?.id === res.proveedor.id) await cargarMovimientos();
}

// ── Pestaña Proveedores ──────────────────────────────────────────────────────

const proveedores = ref<EstadoProveedor[]>([]);
const insumos = ref<InsumoAdmin[]>([]);
const proveedorSelId = ref<number | null>(null);
const proveedorSel = computed(() => proveedores.value.find((p) => p.id === proveedorSelId.value) ?? null);

const formProveedor = reactive({ id: null as number | null, nombre: '', telefono: '', notas: '', activo: true });
const editandoProveedor = ref(false);

// Asignación de insumos del proveedor seleccionado.
const asignacion = ref<Record<number, { incluido: boolean; unidad_compra: string; equivalencia_compra: number | '' }>>({});

const movimientos = ref<MovimientoProveedor[]>([]);
const filtros = reactive({ tipo: '', desde: '', hasta: '' });
const formMovAbierto = ref(false);
const tipoMovTab = ref<MovimientoProveedor['tipo']>('entrega');

const ACCIONES_RAPIDAS: { tipo: MovimientoProveedor['tipo']; label: string }[] = [
    { tipo: 'entrega', label: 'Nueva entrega' },
    { tipo: 'pago', label: 'Registrar pago' },
    { tipo: 'separacion', label: 'Separar' },
];

const TIPO_LABELS: Record<string, string> = {
    saldo_inicial: 'Saldo inicial',
    entrega: 'Entrega',
    separacion: 'Separación',
    pago: 'Pago',
    ajuste: 'Ajuste',
};

async function cargarProveedores() {
    proveedores.value = (await get<EstadoProveedor[]>('/api/proveedores')) ?? [];
    insumos.value = (await get<InsumoAdmin[]>('/api/admin/insumos')) ?? [];

    if (proveedorSelId.value === null && proveedores.value.length) {
        await seleccionarProveedor(proveedores.value[0].id);
    } else if (proveedorSel.value) {
        armarAsignacion();
    }
}

async function abrirTabProveedores() {
    tab.value = 'proveedores';
    await cargarProveedores();
}

function armarAsignacion() {
    const p = proveedorSel.value;
    asignacion.value = Object.fromEntries(
        insumos.value.map((i) => [
            i.id,
            {
                incluido: p !== null && i.proveedor_id === p.id,
                unidad_compra: i.unidad_compra ?? '',
                equivalencia_compra: i.equivalencia_compra ?? '',
            },
        ]),
    );
}

async function seleccionarProveedor(id: number) {
    proveedorSelId.value = id;
    formMovAbierto.value = false;
    armarAsignacion();
    await cargarMovimientos();
}

async function cargarMovimientos() {
    if (!proveedorSel.value) return;

    const params: Record<string, string> = {};
    if (filtros.tipo) params.tipo = filtros.tipo;
    if (filtros.desde) params.desde = filtros.desde;
    if (filtros.hasta) params.hasta = filtros.hasta;

    const qs = new URLSearchParams(params).toString();
    movimientos.value = (await get<MovimientoProveedor[]>(`/api/proveedores/${proveedorSel.value.id}/movimientos${qs ? '?' + qs : ''}`)) ?? [];
}

function nuevoProveedor() {
    Object.assign(formProveedor, { id: null, nombre: '', telefono: '', notas: '', activo: true });
    editandoProveedor.value = true;
}

function editarProveedor(p: EstadoProveedor) {
    Object.assign(formProveedor, { id: p.id, nombre: p.nombre, telefono: p.telefono ?? '', notas: p.notas ?? '', activo: p.activo });
    editandoProveedor.value = true;
}

async function guardarProveedor() {
    const body = {
        nombre: formProveedor.nombre.trim(),
        telefono: formProveedor.telefono.trim() || null,
        notas: formProveedor.notas.trim() || null,
        activo: formProveedor.activo,
    };

    const res = formProveedor.id
        ? await put<EstadoProveedor>(`/api/proveedores/${formProveedor.id}`, body)
        : await post<EstadoProveedor>('/api/proveedores', body);

    if (res) {
        editandoProveedor.value = false;
        await cargarProveedores();
        await seleccionarProveedor(res.id);
    }
}

async function guardarAsignacion() {
    if (!proveedorSel.value) return;

    const lista = Object.entries(asignacion.value)
        .filter(([, a]) => a.incluido)
        .map(([id, a]) => ({
            id: Number(id),
            unidad_compra: a.unidad_compra.trim() || null,
            equivalencia_compra: a.equivalencia_compra === '' ? null : Number(a.equivalencia_compra),
        }));

    const res = await put<EstadoProveedor>(`/api/proveedores/${proveedorSel.value.id}/insumos`, { insumos: lista });

    if (res) {
        aviso.value = `Insumos de ${res.nombre} actualizados.`;
        await cargarProveedores();
        await cargar();
    }
}

function nombreProveedor(id: number | null) {
    return proveedores.value.find((p) => p.id === id)?.nombre ?? null;
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Separación', href: '/separacion' }]">
        <div class="flex flex-col gap-4 p-4 text-foreground">
            <!-- Encabezado -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <PiggyBank class="h-5 w-5 text-muted-foreground" />
                    <div>
                        <h1 class="text-lg font-semibold leading-tight">Separación de insumos</h1>
                        <p class="text-xs text-muted-foreground">
                            Cuánto debés a cada proveedor y cuánta plata apartar. Cuenta ventas desde el {{ fecha(data?.fecha_corte ?? null) }}.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <div class="flex rounded border p-0.5 text-sm">
                        <button
                            class="rounded px-3 py-1"
                            :class="tab === 'separacion' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            @click="tab = 'separacion'"
                        >
                            Separación
                        </button>
                        <button
                            class="rounded px-3 py-1"
                            :class="tab === 'proveedores' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            @click="abrirTabProveedores"
                        >
                            Proveedores
                        </button>
                    </div>
                    <Button variant="outline" size="sm" :disabled="loading" @click="cargar"> <RefreshCw class="mr-1 h-4 w-4" /> Actualizar </Button>
                </div>
            </div>

            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
            <p v-if="aviso" class="flex items-center justify-between rounded border border-emerald-500/30 bg-emerald-500/10 p-2 text-sm">
                {{ aviso }}
                <button @click="aviso = null"><X class="h-4 w-4" /></button>
            </p>

            <!-- ═══════════════ Pestaña Separación ═══════════════ -->
            <template v-if="tab === 'separacion'">
                <!-- Hoy -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">
                            Hoy
                            <span v-if="data?.hoy" class="font-normal text-muted-foreground">
                                · caja del {{ fecha(data.hoy.caja.fecha_operativa) }} ({{ data.hoy.caja.estado }})
                            </span>
                        </CardTitle>
                        <Button v-if="data?.hoy" variant="outline" size="sm" @click="separandoCaja = data.hoy.caja.id">
                            <PiggyBank class="mr-1 h-4 w-4" /> Separar insumos
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <p v-if="!data?.hoy || !data.hoy.grupos.length" class="text-sm text-muted-foreground">Todavía no hay insumos vendidos en esta caja.</p>

                        <div v-else class="flex flex-col divide-y">
                            <div v-for="g in data.hoy.grupos" :key="g.grupo" class="flex items-start justify-between gap-3 py-2 text-sm">
                                <div class="min-w-0">
                                    <p class="font-medium">{{ g.label }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        <template v-for="(u, j) in g.insumos" :key="u.insumo_id">
                                            {{ j > 0 ? ' · ' : '' }}{{ g.insumos.length > 1 ? u.nombre + ': ' : '' }}{{ fmtUnidades(u) }}
                                        </template>
                                    </p>
                                </div>
                                <p class="shrink-0 font-semibold tabular-nums">{{ fmt(g.monto) }}</p>
                            </div>
                            <div class="flex justify-between pt-2 text-sm font-semibold">
                                <span>Total insumos</span>
                                <span class="tabular-nums">{{ fmt(data.hoy.total) }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Tarjetas de proveedores -->
                <div v-if="data?.proveedores?.length" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <Card v-for="p in data.proveedores" :key="p.id">
                        <CardHeader class="pb-2">
                            <CardTitle class="flex items-center gap-2 text-sm font-medium">
                                <Truck class="h-4 w-4 text-muted-foreground" /> {{ p.nombre }}
                            </CardTitle>
                        </CardHeader>

                        <CardContent class="flex flex-col gap-2">
                            <div>
                                <p class="text-xs text-muted-foreground">Deuda total</p>
                                <p class="text-2xl font-semibold tabular-nums">{{ fmt(p.deuda) }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                <span class="text-muted-foreground">Vendido sin pagar</span>
                                <span class="text-right tabular-nums">{{ fmt(p.vendido_sin_pagar) }}</span>
                                <span class="text-muted-foreground">En stock sin pagar</span>
                                <span class="text-right tabular-nums">{{ fmt(p.en_stock_sin_pagar) }}</span>
                                <span class="text-muted-foreground">Separado</span>
                                <span class="text-right tabular-nums">{{ fmt(p.separado) }}</span>
                                <span class="font-medium">Falta separar</span>
                                <span class="text-right font-semibold tabular-nums" :class="p.falta_separar > 0 ? 'text-destructive' : 'text-emerald-600'">
                                    {{ fmt(Math.max(0, p.falta_separar)) }}
                                </span>
                            </div>

                            <div class="text-xs leading-relaxed text-muted-foreground">
                                <p>
                                    Hoy:
                                    <span class="text-foreground">
                                        <template v-if="p.unidades_hoy.length">
                                            <template v-for="(u, j) in p.unidades_hoy" :key="u.insumo_id">
                                                {{ j > 0 ? ' · ' : '' }}{{ fmtUnidades(u) }} → {{ fmt(u.monto) }}
                                            </template>
                                        </template>
                                        <template v-else>nada</template>
                                    </span>
                                </p>
                                <p>
                                    Desde el último pago{{ p.ultimo_pago ? ` (${fecha(p.ultimo_pago.fecha)})` : '' }}:
                                    <span class="text-foreground">
                                        <template v-if="p.unidades_desde_ultimo_pago.length">
                                            <template v-for="(u, j) in p.unidades_desde_ultimo_pago" :key="u.insumo_id">
                                                {{ j > 0 ? ' · ' : '' }}{{ fmtUnidades(u) }} → {{ fmt(u.monto) }}
                                            </template>
                                        </template>
                                        <template v-else>nada</template>
                                    </span>
                                </p>
                            </div>

                            <MovimientoProveedorForm
                                v-if="movimientoActivo?.proveedorId === p.id"
                                :proveedor="p"
                                :tipo="movimientoActivo.tipo"
                                @guardado="movimientoGuardado"
                                @cancelar="movimientoActivo = null"
                            />

                            <div v-else class="flex gap-2">
                                <Button size="sm" class="flex-1" :disabled="loading" @click="abrirMovimiento(p, 'pago')">Registrar pago</Button>
                                <Button variant="outline" size="sm" class="flex-1" :disabled="loading" @click="abrirMovimiento(p, 'entrega')">
                                    Registrar entrega
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Rubros sin proveedor -->
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <Card v-for="g in data?.grupos ?? []" :key="g.grupo">
                        <CardHeader class="pb-2">
                            <CardTitle class="text-sm font-medium">{{ g.label }}</CardTitle>
                        </CardHeader>

                        <CardContent class="flex flex-col gap-2">
                            <p class="text-2xl font-semibold tabular-nums">{{ fmt(g.monto_acumulado) }}</p>

                            <div class="text-xs leading-relaxed text-muted-foreground">
                                <p>
                                    Última reposición:
                                    <span class="text-foreground">
                                        {{ g.ultima_reposicion ? fecha(g.ultima_reposicion.fecha) : 'nunca' }}
                                    </span>
                                </p>
                                <p>{{ g.cantidad_ventas }} venta{{ g.cantidad_ventas === 1 ? '' : 's' }} en el acumulado</p>
                            </div>

                            <!-- Panel de confirmación inline (sin Dialog/radix) -->
                            <div v-if="grupoActivo === g.grupo" class="flex flex-col gap-2 rounded border bg-muted/40 p-3">
                                <p class="text-xs">
                                    Vas a marcar <strong>{{ g.label }}</strong> como repuesto por <strong>{{ fmt(g.monto_acumulado) }}</strong
                                    >. El acumulado vuelve a cero y queda registrado en el historial.
                                </p>

                                <div>
                                    <label class="text-xs text-muted-foreground">Monto real gastado (opcional)</label>
                                    <input
                                        v-model="form.monto_real"
                                        type="number"
                                        min="0"
                                        step="1"
                                        :placeholder="String(Math.round(g.monto_acumulado))"
                                        class="mt-1 w-full rounded border border-input bg-background px-2 py-1.5 text-sm"
                                    />
                                </div>

                                <div>
                                    <label class="text-xs text-muted-foreground">Observación (opcional)</label>
                                    <textarea
                                        v-model="form.observacion"
                                        rows="2"
                                        class="mt-1 w-full rounded border border-input bg-background px-2 py-1.5 text-sm"
                                    ></textarea>
                                </div>

                                <div class="flex gap-2">
                                    <Button size="sm" :disabled="loading" @click="confirmarReposicion(g)">
                                        <Check class="mr-1 h-4 w-4" /> Confirmar
                                    </Button>
                                    <Button variant="outline" size="sm" @click="cancelar"> <X class="mr-1 h-4 w-4" /> Cancelar </Button>
                                </div>
                            </div>

                            <Button v-else variant="outline" size="sm" class="w-full" :disabled="loading" @click="abrirConfirmacion(g)">
                                Repuse este insumo
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <!-- Total rubros -->
                <Card>
                    <CardContent class="flex items-center justify-between p-4">
                        <div>
                            <p class="mb-1 text-xs text-muted-foreground">Total a separar en rubros sin proveedor</p>
                            <p class="text-2xl font-semibold tabular-nums">{{ fmt(data?.total_general ?? 0) }}</p>
                        </div>
                        <p class="max-w-xs text-right text-xs text-muted-foreground">
                            Suma de los {{ data?.grupos?.length ?? 0 }} rubros, desde la última reposición de cada uno.
                        </p>
                    </CardContent>
                </Card>

                <!-- Historial -->
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium">Historial de reposiciones</CardTitle>
                    </CardHeader>

                    <CardContent class="overflow-x-auto p-0">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-xs text-muted-foreground">
                                    <th class="p-3 text-left font-medium">Grupo</th>
                                    <th class="p-3 text-left font-medium">Fecha</th>
                                    <th class="p-3 text-right font-medium">Acumulado</th>
                                    <th class="p-3 text-right font-medium">Real</th>
                                    <th class="p-3 text-right font-medium">Diferencia</th>
                                    <th class="p-3 text-left font-medium">Observación</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr v-for="h in data?.historial ?? []" :key="h.id" class="border-b last:border-0 hover:bg-muted/50">
                                    <td class="p-3">{{ h.label }}</td>
                                    <td class="p-3 text-muted-foreground">{{ fechaHora(h.fecha) }}</td>
                                    <td class="p-3 text-right tabular-nums">{{ fmt(h.monto_acumulado) }}</td>
                                    <td class="p-3 text-right tabular-nums">
                                        {{ h.monto_real === null ? '—' : fmt(h.monto_real) }}
                                    </td>
                                    <td
                                        class="p-3 text-right tabular-nums"
                                        :class="
                                            h.diferencia === null ? 'text-muted-foreground' : h.diferencia > 0 ? 'text-destructive' : 'text-emerald-600'
                                        "
                                    >
                                        <template v-if="h.diferencia === null">—</template>
                                        <template v-else>{{ h.diferencia > 0 ? '+' : '-' }}{{ fmt(Math.abs(h.diferencia)) }}</template>
                                    </td>
                                    <td class="p-3 text-xs text-muted-foreground">{{ h.observacion ?? '—' }}</td>
                                </tr>

                                <tr v-if="!(data?.historial ?? []).length">
                                    <td colspan="6" class="p-6 text-center text-sm text-muted-foreground">Todavía no registraste ninguna reposición.</td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </template>

            <!-- ═══════════════ Pestaña Proveedores ═══════════════ -->
            <div v-else class="grid gap-4 lg:grid-cols-[280px_1fr]">
                <!-- Lista -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Proveedores</CardTitle>
                        <Button variant="outline" size="sm" @click="nuevoProveedor"><Plus class="mr-1 h-4 w-4" /> Nuevo</Button>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-1 p-2">
                        <button
                            v-for="p in proveedores"
                            :key="p.id"
                            class="flex items-center justify-between rounded px-2 py-2 text-left text-sm"
                            :class="p.id === proveedorSelId ? 'bg-muted font-medium' : 'hover:bg-muted/50'"
                            @click="seleccionarProveedor(p.id)"
                        >
                            <span :class="{ 'text-muted-foreground line-through': !p.activo }">{{ p.nombre }}</span>
                            <span class="tabular-nums text-xs">{{ fmt(p.deuda) }}</span>
                        </button>
                        <p v-if="!proveedores.length" class="p-2 text-sm text-muted-foreground">Todavía no cargaste proveedores.</p>
                    </CardContent>
                </Card>

                <div class="flex min-w-0 flex-col gap-4">
                    <!-- Alta / edición -->
                    <Card v-if="editandoProveedor">
                        <CardHeader class="pb-2">
                            <CardTitle class="text-sm font-medium">{{ formProveedor.id ? 'Editar proveedor' : 'Nuevo proveedor' }}</CardTitle>
                        </CardHeader>
                        <CardContent class="grid gap-2 sm:grid-cols-2">
                            <div>
                                <label class="text-xs text-muted-foreground">Nombre</label>
                                <input v-model="formProveedor.nombre" class="mt-1 w-full rounded border border-input bg-background px-2 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label class="text-xs text-muted-foreground">Teléfono</label>
                                <input v-model="formProveedor.telefono" class="mt-1 w-full rounded border border-input bg-background px-2 py-1.5 text-sm" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-xs text-muted-foreground">Notas</label>
                                <textarea
                                    v-model="formProveedor.notas"
                                    rows="2"
                                    class="mt-1 w-full rounded border border-input bg-background px-2 py-1.5 text-sm"
                                ></textarea>
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="formProveedor.activo" type="checkbox" class="h-4 w-4" /> Activo
                            </label>
                            <div class="flex justify-end gap-2 sm:col-span-2">
                                <Button variant="outline" size="sm" @click="editandoProveedor = false">Cancelar</Button>
                                <Button size="sm" :disabled="loading || !formProveedor.nombre.trim()" @click="guardarProveedor">
                                    <Check class="mr-1 h-4 w-4" /> Guardar
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <template v-if="proveedorSel">
                        <!-- Resumen + acciones rápidas -->
                        <Card>
                            <CardHeader class="flex flex-row flex-wrap items-center justify-between gap-2 pb-2">
                                <CardTitle class="flex items-center gap-2 text-sm font-medium">
                                    <Truck class="h-4 w-4 text-muted-foreground" /> {{ proveedorSel.nombre }}
                                    <span v-if="proveedorSel.telefono" class="font-normal text-muted-foreground">· {{ proveedorSel.telefono }}</span>
                                </CardTitle>
                                <Button variant="outline" size="sm" @click="editarProveedor(proveedorSel)">Editar</Button>
                            </CardHeader>
                            <CardContent class="flex flex-col gap-3">
                                <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                                    <div>
                                        <p class="text-xs text-muted-foreground">Deuda</p>
                                        <p class="font-semibold tabular-nums">{{ fmt(proveedorSel.deuda) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">Vendido sin pagar</p>
                                        <p class="font-semibold tabular-nums">{{ fmt(proveedorSel.vendido_sin_pagar) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">En stock sin pagar</p>
                                        <p class="font-semibold tabular-nums">{{ fmt(proveedorSel.en_stock_sin_pagar) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">Separado</p>
                                        <p class="font-semibold tabular-nums">{{ fmt(proveedorSel.separado) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-muted-foreground">Falta separar</p>
                                        <p class="font-semibold tabular-nums" :class="proveedorSel.falta_separar > 0 ? 'text-destructive' : 'text-emerald-600'">
                                            {{ fmt(Math.max(0, proveedorSel.falta_separar)) }}
                                        </p>
                                    </div>
                                </div>

                                <MovimientoProveedorForm
                                    v-if="formMovAbierto"
                                    :proveedor="proveedorSel"
                                    :tipo="tipoMovTab"
                                    tipos-elegibles
                                    @guardado="
                                        (r) => {
                                            formMovAbierto = false;
                                            movimientoGuardado(r);
                                        }
                                    "
                                    @cancelar="formMovAbierto = false"
                                />
                                <div v-else class="flex flex-wrap gap-2">
                                    <Button size="sm" @click="((tipoMovTab = 'entrega'), (formMovAbierto = true))">
                                        <Plus class="mr-1 h-4 w-4" /> Nuevo movimiento
                                    </Button>
                                    <Button
                                        v-for="a in ACCIONES_RAPIDAS"
                                        :key="a.tipo"
                                        variant="outline"
                                        size="sm"
                                        @click="((tipoMovTab = a.tipo), (formMovAbierto = true))"
                                    >
                                        {{ a.label }}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Insumos asignados -->
                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium">Insumos que provee</CardTitle>
                                <p class="text-xs text-muted-foreground">
                                    Unidad de compra y equivalencia: por ejemplo "kg" = 10 medallones. Se usa para cargar entregas y mostrar kilos.
                                </p>
                            </CardHeader>
                            <CardContent class="overflow-x-auto p-0">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-xs text-muted-foreground">
                                            <th class="p-2 text-left font-medium"></th>
                                            <th class="p-2 text-left font-medium">Insumo</th>
                                            <th class="p-2 text-left font-medium">Unidad</th>
                                            <th class="p-2 text-left font-medium">Unidad de compra</th>
                                            <th class="p-2 text-left font-medium">Equivalencia</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="i in insumos.filter((x) => x.activo)"
                                            :key="i.id"
                                            class="border-b last:border-0"
                                            :class="{ 'opacity-50': i.proveedor_id && i.proveedor_id !== proveedorSel.id && !asignacion[i.id]?.incluido }"
                                        >
                                            <td class="p-2"><input v-model="asignacion[i.id].incluido" type="checkbox" class="h-4 w-4" /></td>
                                            <td class="p-2">
                                                {{ i.nombre }}
                                                <span
                                                    v-if="i.proveedor_id && i.proveedor_id !== proveedorSel.id"
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    ({{ nombreProveedor(i.proveedor_id) }})
                                                </span>
                                            </td>
                                            <td class="p-2 text-muted-foreground">{{ i.unidad }}</td>
                                            <td class="p-2">
                                                <input
                                                    v-model="asignacion[i.id].unidad_compra"
                                                    :disabled="!asignacion[i.id].incluido"
                                                    placeholder="kg"
                                                    class="w-20 rounded border border-input bg-background px-2 py-1 text-sm disabled:opacity-50"
                                                />
                                            </td>
                                            <td class="p-2">
                                                <input
                                                    v-model.number="asignacion[i.id].equivalencia_compra"
                                                    :disabled="!asignacion[i.id].incluido"
                                                    type="number"
                                                    min="0"
                                                    step="0.001"
                                                    placeholder="10"
                                                    class="w-20 rounded border border-input bg-background px-2 py-1 text-sm disabled:opacity-50"
                                                />
                                                <span class="text-xs text-muted-foreground"> {{ i.unidad }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="flex justify-end p-3">
                                    <Button size="sm" :disabled="loading" @click="guardarAsignacion"><Check class="mr-1 h-4 w-4" /> Guardar insumos</Button>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Movimientos -->
                        <Card>
                            <CardHeader class="flex flex-row flex-wrap items-end justify-between gap-2 pb-2">
                                <CardTitle class="text-sm font-medium">Movimientos</CardTitle>
                                <div class="flex flex-wrap items-end gap-2 text-sm">
                                    <select v-model="filtros.tipo" class="rounded border border-input bg-background px-2 py-1" @change="cargarMovimientos">
                                        <option value="">Todos</option>
                                        <option v-for="(label, t) in TIPO_LABELS" :key="t" :value="t">{{ label }}</option>
                                    </select>
                                    <input
                                        v-model="filtros.desde"
                                        type="date"
                                        class="rounded border border-input bg-background px-2 py-1"
                                        @change="cargarMovimientos"
                                    />
                                    <input
                                        v-model="filtros.hasta"
                                        type="date"
                                        class="rounded border border-input bg-background px-2 py-1"
                                        @change="cargarMovimientos"
                                    />
                                </div>
                            </CardHeader>
                            <CardContent class="overflow-x-auto p-0">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-xs text-muted-foreground">
                                            <th class="p-2 text-left font-medium">Fecha</th>
                                            <th class="p-2 text-left font-medium">Tipo</th>
                                            <th class="p-2 text-left font-medium">Detalle</th>
                                            <th class="p-2 text-right font-medium">Monto</th>
                                            <th class="p-2 text-left font-medium">Usuario</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="m in movimientos" :key="m.id" class="border-b last:border-0 hover:bg-muted/50">
                                            <td class="p-2 text-muted-foreground">{{ fecha(m.fecha) }}</td>
                                            <td class="p-2">{{ TIPO_LABELS[m.tipo] ?? m.tipo }}</td>
                                            <td class="p-2 text-xs text-muted-foreground">
                                                <template v-if="m.insumo">
                                                    {{ m.insumo.nombre }}: {{ Number(m.cantidad).toLocaleString('es-AR') }} {{ m.unidad }}
                                                    <template v-if="m.cantidad_insumo && m.unidad !== m.insumo.unidad">
                                                        ({{ Number(m.cantidad_insumo).toLocaleString('es-AR') }} {{ m.insumo.unidad }})
                                                    </template>
                                                </template>
                                                <template v-if="m.tipo === 'saldo_inicial' && Number(m.monto_vendido) > 0">
                                                    ya vendido {{ fmt(m.monto_vendido) }}
                                                </template>
                                                {{ m.observacion ?? '' }}
                                            </td>
                                            <td
                                                class="p-2 text-right tabular-nums"
                                                :class="{ 'text-emerald-600': m.tipo === 'pago', 'text-destructive': m.tipo === 'entrega' }"
                                            >
                                                {{ m.tipo === 'pago' ? '-' : '' }}{{ fmt(m.monto) }}
                                            </td>
                                            <td class="p-2 text-xs text-muted-foreground">{{ m.user?.name ?? '—' }}</td>
                                        </tr>
                                        <tr v-if="!movimientos.length">
                                            <td colspan="5" class="p-6 text-center text-sm text-muted-foreground">Sin movimientos.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </template>
                </div>
            </div>

            <!-- Modal: separar insumos de la caja de hoy -->
            <SepararInsumos
                v-if="separandoCaja"
                :caja-id="separandoCaja"
                @close="separandoCaja = null"
                @confirmada="cargar"
            />
        </div>
    </AppLayout>
</template>
