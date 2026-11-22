<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import AppLayout from '@/layouts/AppLayout.vue';
import { fmtCant, type EstadoTandas, type OpcionTanda, type Tanda } from '@/types/tandas';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Flame, Pencil, Plus, RotateCcw, Sandwich, Trash2, X } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

// Cuentas de un solo negocio (burger o parrilla). En la parrilla, además, las
// compras de carne: cuánto se pagó, cuánto lleva juntado el sobre y cuánto
// rindió cada una.

const props = defineProps<{ negocio: 'burger' | 'parrilla' }>();

interface Jornada {
    caja_id: number;
    fecha: string | null;
    estado: string;
    ventas: number;
    costo_insumos: number;
    ganancia_bruta: number;
    gastos_fijos: number;
    ganancia_neta: number;
    unidades: number;
}

interface Resumen {
    desde: string;
    hasta: string;
    totales: Omit<Jornada, 'caja_id' | 'fecha' | 'estado'> & { jornadas: number };
    jornadas: Jornada[];
    productos: { nombre: string; cantidad: number; monto: number }[];
    tandas: EstadoTandas | null;
}

const { get, post, put, del, loading, error } = useApi();

const esParrilla = computed(() => props.negocio === 'parrilla');
const titulo = computed(() => (esParrilla.value ? 'Parrilla' : 'Burger'));

const fmt = (n: unknown) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');
const fmtFecha = (f: string | null) => (f ? new Date(f.length <= 10 ? f + 'T12:00' : f).toLocaleDateString('es-AR', { weekday: 'short', day: 'numeric', month: 'numeric' }) : '—');
const colorPlata = (n: number) => (n < 0 ? 'text-destructive' : 'text-emerald-600 dark:text-emerald-400');

// ── Rango ────────────────────────────────────────────────────────────────────
type Rango = 'semana' | 'mes' | 'mes_anterior' | 'todo';
const rango = ref<Rango>('mes');
const RANGOS: [Rango, string][] = [
    ['semana', '7 días'],
    ['mes', 'Este mes'],
    ['mes_anterior', 'Mes pasado'],
    ['todo', 'Todo'],
];

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function fechasDe(r: Rango): { desde: string; hasta: string } {
    const hoy = new Date();
    if (r === 'semana') return { desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() - 6)), hasta: iso(hoy) };
    if (r === 'mes_anterior') {
        return { desde: iso(new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1)), hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 0)) };
    }
    if (r === 'todo') return { desde: '2000-01-01', hasta: iso(hoy) };
    return { desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), hasta: iso(hoy) };
}

const data = ref<Resumen | null>(null);

async function cargar() {
    const { desde, hasta } = fechasDe(rango.value);
    const res = await get<Resumen>(`/api/negocio/${props.negocio}?desde=${desde}&hasta=${hasta}`);
    if (res) data.value = res;
}

watch(rango, cargar);
onMounted(cargar);

const kpis = computed(() => {
    const t = data.value?.totales;
    if (!t) return [];
    return [
        { label: 'Ventas', valor: fmt(t.ventas), color: '' },
        { label: 'Costo insumos', valor: fmt(t.costo_insumos), color: '' },
        { label: 'Gastos fijos', valor: fmt(t.gastos_fijos), color: '' },
        { label: 'Ganancia', valor: fmt(t.ganancia_neta), color: colorPlata(t.ganancia_neta) },
        { label: esParrilla.value ? 'Sanguches / jornadas' : 'Unidades / jornadas', valor: `${t.unidades} / ${t.jornadas}`, color: '' },
    ];
});

// ── Compras de parrilla ──────────────────────────────────────────────────────
const tandas = computed(() => data.value?.tandas ?? null);

function aplicarTandas(res: EstadoTandas | null) {
    if (res && data.value) data.value.tandas = res;
}

interface Formulario {
    id: number | null;
    insumo_id: number | null;
    cantidad: number | null;
    total: number | null;
    porciones_estimadas: number | null;
    observacion: string;
}

const form = ref<Formulario | null>(null);
// Si el usuario escribió los sanguches a mano, no se pisan al cambiar los kg.
const porcionesAMano = ref(false);

const opcionElegida = computed<OpcionTanda | null>(() => tandas.value?.opciones.find((o) => o.id === form.value?.insumo_id) ?? null);
const esKg = computed(() => (opcionElegida.value?.unidad ?? 'kg') === 'kg');

function nuevaCompra() {
    porcionesAMano.value = false;
    form.value = { id: null, insumo_id: tandas.value?.opciones[0]?.id ?? null, cantidad: null, total: null, porciones_estimadas: null, observacion: '' };
}

function editarCompra(t: Tanda) {
    porcionesAMano.value = true;
    form.value = { id: t.id, insumo_id: t.insumo_id, cantidad: t.cantidad, total: t.total, porciones_estimadas: t.porciones_estimadas, observacion: t.observacion ?? '' };
}

function sugerir() {
    if (!form.value || porcionesAMano.value) return;
    const r = opcionElegida.value?.rendimiento;
    const c = Number(form.value.cantidad);
    if (opcionElegida.value?.unidad !== 'kg' && c > 0 && !r) form.value.porciones_estimadas = Math.round(c);
    else form.value.porciones_estimadas = r && c > 0 ? Math.max(1, Math.round(c * r)) : null;
}

watch(() => [form.value?.insumo_id, form.value?.cantidad], sugerir);

const apartaPorSanguche = computed(() => {
    const f = form.value;
    if (!f || !Number(f.total) || !Number(f.porciones_estimadas)) return null;
    return Number(f.total) / Number(f.porciones_estimadas);
});

const puedeGuardar = computed(() => {
    const f = form.value;
    return !!f && !!f.insumo_id && Number(f.cantidad) > 0 && Number(f.total) > 0 && Number(f.porciones_estimadas) > 0;
});

async function guardarCompra() {
    const f = form.value;
    if (!f || !puedeGuardar.value) return;

    const body = {
        cantidad: Number(f.cantidad),
        total: Number(f.total),
        porciones_estimadas: Number(f.porciones_estimadas),
        observacion: f.observacion || null,
    };

    const res = f.id ? await put<EstadoTandas>(`/api/tandas/${f.id}`, body) : await post<EstadoTandas>('/api/tandas', { ...body, insumo_id: f.insumo_id });

    if (res) {
        aplicarTandas(res);
        form.value = null;
        await cargar();
    }
}

async function terminar(t: Tanda) {
    if (!confirm(`¿Se terminó ${t.insumo}? El sobre tiene ${fmt(t.apartado)} de ${fmt(t.total)}.`)) return;
    aplicarTandas(await post<EstadoTandas>(`/api/tandas/${t.id}/cerrar`, {}));
}

async function reabrir(t: Tanda) {
    if (!confirm(`¿Volver a abrir la compra de ${t.insumo}? Se deshace el "se terminó".`)) return;
    aplicarTandas(await post<EstadoTandas>(`/api/tandas/${t.id}/reabrir`, {}));
}

async function borrar(t: Tanda) {
    if (!confirm(`¿Borrar la compra de ${t.insumo}? Solo si la cargaste por error.`)) return;
    aplicarTandas(await del<EstadoTandas>(`/api/tandas/${t.id}`));
}

const progreso = (t: Tanda) => Math.min(100, t.total > 0 ? (t.apartado / t.total) * 100 : 0);
const unidadCorta = (u: string | null) => (u === 'kg' ? 'kg' : 'u.');
</script>

<template>
    <Head :title="titulo" />

    <AppLayout :breadcrumbs="[{ title: titulo, href: `/${negocio}` }]">
        <div class="flex flex-col gap-4 p-3 sm:p-4">
            <!-- Encabezado + rango -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="flex items-center gap-2 text-xl font-bold">
                    <Flame v-if="esParrilla" class="h-5 w-5 text-orange-500" />
                    <Sandwich v-else class="h-5 w-5 text-primary" />
                    {{ titulo }}
                </h1>
                <div class="no-scrollbar -mx-3 flex gap-1.5 overflow-x-auto px-3 sm:mx-0 sm:px-0">
                    <button
                        v-for="[r, label] in RANGOS"
                        :key="r"
                        class="h-9 shrink-0 rounded-full border px-3 text-sm transition-colors"
                        :class="rango === r ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        @click="rango = r"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>

            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
            <p v-if="!data && loading" class="text-sm text-muted-foreground">Cargando…</p>

            <template v-if="data">
                <!-- Totales del rango -->
                <div class="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-5">
                    <Card v-for="(k, i) in kpis" :key="k.label" :class="i === kpis.length - 1 ? 'col-span-2 lg:col-span-1' : ''">
                        <CardContent class="p-3 sm:p-4">
                            <p class="text-xs text-muted-foreground">{{ k.label }}</p>
                            <p class="text-lg font-bold tabular-nums sm:text-xl" :class="k.color">{{ k.valor }}</p>
                        </CardContent>
                    </Card>
                </div>

                <!-- ── Compras de carne (solo parrilla) ── -->
                <Card v-if="tandas">
                    <CardHeader class="pb-2">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <CardTitle class="text-base">Carnes</CardTitle>
                                <p class="text-xs text-muted-foreground">Cada sanguche aparta lo que pagaste ÷ los sanguches que salen.</p>
                            </div>
                            <Button size="sm" @click="nuevaCompra"><Plus class="mr-1 h-4 w-4" /> Cargar compra</Button>
                        </div>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <p v-if="!tandas.abiertas.length" class="rounded border border-dashed p-4 text-center text-sm text-muted-foreground">
                            No hay carne cargada. Cuando compres, tocá <b>Cargar compra</b>.
                        </p>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <div v-for="t in tandas.abiertas" :key="t.id" class="flex flex-col gap-2 rounded-xl border p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-semibold">{{ t.insumo }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ fmtCant(t.cantidad, 3) }} {{ unidadCorta(t.unidad) }} · {{ fmt(t.total) }}
                                            <template v-if="t.precio_unidad"> ({{ fmt(t.precio_unidad) }}/{{ unidadCorta(t.unidad) }})</template>
                                            · {{ fmtFecha(t.fecha) }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 gap-0.5">
                                        <Button size="icon" variant="ghost" class="h-8 w-8" title="Corregir" @click="editarCompra(t)">
                                            <Pencil class="h-3.5 w-3.5" />
                                        </Button>
                                        <Button
                                            v-if="!t.porciones_vendidas"
                                            size="icon"
                                            variant="ghost"
                                            class="h-8 w-8 text-destructive"
                                            title="Borrar"
                                            @click="borrar(t)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                </div>

                                <div>
                                    <div class="flex justify-between text-xs">
                                        <span>En el sobre <b class="tabular-nums">{{ fmt(t.apartado) }}</b></span>
                                        <span v-if="t.falta >= 0" class="text-muted-foreground">falta {{ fmt(t.falta) }}</span>
                                        <span v-else class="text-amber-600 dark:text-amber-400">pasó lo pagado por {{ fmt(-t.falta) }}</span>
                                    </div>
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-muted">
                                        <div class="h-full rounded-full bg-orange-500 transition-all" :style="{ width: progreso(t) + '%' }" />
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                    <span class="text-muted-foreground">Vendidos</span>
                                    <span class="text-right font-medium tabular-nums">{{ t.porciones_vendidas }} de ~{{ t.porciones_estimadas }}</span>
                                    <span class="text-muted-foreground">Aparta por sanguche</span>
                                    <span class="text-right font-medium tabular-nums">{{ fmt(t.aparta_por_porcion) }}</span>
                                    <template v-if="t.porciones_vendidas">
                                        <span class="text-muted-foreground">Deja cada uno</span>
                                        <span class="text-right font-medium tabular-nums" :class="colorPlata(t.ganancia_por_porcion ?? 0)">
                                            {{ fmt(t.ganancia_por_porcion) }}
                                        </span>
                                    </template>
                                </div>

                                <Button size="sm" variant="outline" class="mt-auto" @click="terminar(t)">
                                    <CheckCircle2 class="mr-1 h-4 w-4" /> Se terminó
                                </Button>
                            </div>
                        </div>

                        <!-- Historial de compras terminadas -->
                        <template v-if="tandas.historial.length">
                            <p class="mt-2 text-sm font-semibold">Compras terminadas</p>

                            <!-- Celular: tarjetas -->
                            <div class="flex flex-col gap-2 md:hidden">
                                <div v-for="t in tandas.historial" :key="t.id" class="rounded-lg border p-3 text-sm">
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="font-semibold">{{ t.insumo }}</p>
                                            <p class="text-xs text-muted-foreground">
                                                {{ fmtCant(t.cantidad, 3) }} {{ unidadCorta(t.unidad) }} · {{ fmt(t.total) }} · terminó {{ fmtFecha(t.cerrada_at) }}
                                            </p>
                                        </div>
                                        <p class="font-bold tabular-nums" :class="colorPlata(t.ganancia)">{{ fmt(t.ganancia) }}</p>
                                    </div>
                                    <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
                                        <span class="text-muted-foreground">Sanguches</span>
                                        <span class="text-right tabular-nums">{{ t.porciones_vendidas }}<template v-if="t.rendimiento"> ({{ fmtCant(t.rendimiento) }} por {{ unidadCorta(t.unidad) }})</template></span>
                                        <span class="text-muted-foreground">Costo por sanguche</span>
                                        <span class="text-right tabular-nums">{{ fmt(t.costo_por_porcion) }}<template v-if="t.costo_pct !== null"> · {{ fmtCant(t.costo_pct, 0) }}%</template></span>
                                        <span class="text-muted-foreground">Deja cada uno</span>
                                        <span class="text-right tabular-nums" :class="colorPlata(t.ganancia_por_porcion ?? 0)">{{ fmt(t.ganancia_por_porcion) }}</span>
                                        <template v-if="Math.abs(t.falta) >= 1">
                                            <span class="text-muted-foreground">{{ t.falta > 0 ? 'Faltó en el sobre' : 'Sobró en el sobre' }}</span>
                                            <span class="text-right tabular-nums">{{ fmt(Math.abs(t.falta)) }}</span>
                                        </template>
                                    </div>
                                    <button class="mt-2 flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="reabrir(t)">
                                        <RotateCcw class="h-3 w-3" /> No se terminó, reabrir
                                    </button>
                                </div>
                            </div>

                            <!-- Compu: tabla -->
                            <div class="hidden overflow-x-auto md:block">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-xs text-muted-foreground">
                                            <th class="p-2 text-left font-medium">Terminó</th>
                                            <th class="p-2 text-left font-medium">Carne</th>
                                            <th class="p-2 text-right font-medium">Compra</th>
                                            <th class="p-2 text-right font-medium">Sanguches</th>
                                            <th class="p-2 text-right font-medium">Rinde</th>
                                            <th class="p-2 text-right font-medium">Costo c/u</th>
                                            <th class="p-2 text-right font-medium">Costo %</th>
                                            <th class="p-2 text-right font-medium">Deja c/u</th>
                                            <th class="p-2 text-right font-medium">Ganancia</th>
                                            <th class="p-2 text-right font-medium">Ajuste sobre</th>
                                            <th class="p-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="t in tandas.historial" :key="t.id" class="border-b last:border-0">
                                            <td class="p-2 whitespace-nowrap">{{ fmtFecha(t.cerrada_at) }}</td>
                                            <td class="p-2 font-medium">{{ t.insumo }}</td>
                                            <td class="p-2 text-right whitespace-nowrap tabular-nums">
                                                {{ fmtCant(t.cantidad, 3) }} {{ unidadCorta(t.unidad) }} · {{ fmt(t.total) }}
                                            </td>
                                            <td class="p-2 text-right tabular-nums">{{ t.porciones_vendidas }}</td>
                                            <td class="p-2 text-right whitespace-nowrap tabular-nums">
                                                {{ t.rendimiento ? `${fmtCant(t.rendimiento)}/${unidadCorta(t.unidad)}` : '—' }}
                                            </td>
                                            <td class="p-2 text-right tabular-nums">{{ fmt(t.costo_por_porcion) }}</td>
                                            <td class="p-2 text-right tabular-nums">{{ t.costo_pct !== null ? fmtCant(t.costo_pct, 0) + '%' : '—' }}</td>
                                            <td class="p-2 text-right tabular-nums" :class="colorPlata(t.ganancia_por_porcion ?? 0)">{{ fmt(t.ganancia_por_porcion) }}</td>
                                            <td class="p-2 text-right font-semibold tabular-nums" :class="colorPlata(t.ganancia)">{{ fmt(t.ganancia) }}</td>
                                            <td class="p-2 text-right whitespace-nowrap tabular-nums text-muted-foreground">
                                                <template v-if="Math.abs(t.falta) >= 1">{{ t.falta > 0 ? '+' : '−' }}{{ fmt(Math.abs(t.falta)) }}</template>
                                                <template v-else>—</template>
                                            </td>
                                            <td class="p-2 text-right">
                                                <Button size="icon" variant="ghost" class="h-7 w-7" title="Reabrir" @click="reabrir(t)">
                                                    <RotateCcw class="h-3.5 w-3.5" />
                                                </Button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </CardContent>
                </Card>

                <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <!-- Jornadas -->
                    <Card>
                        <CardHeader class="pb-2"><CardTitle class="text-base">Jornadas</CardTitle></CardHeader>
                        <CardContent class="overflow-x-auto p-0 sm:px-2 sm:pb-2">
                            <p v-if="!data.jornadas.length" class="p-4 text-sm text-muted-foreground">No hubo ventas de {{ titulo.toLowerCase() }} en este período.</p>
                            <table v-else class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-xs text-muted-foreground">
                                        <th class="p-2 pl-4 text-left font-medium sm:pl-2">Día</th>
                                        <th class="p-2 text-right font-medium">Ventas</th>
                                        <th class="hidden p-2 text-right font-medium sm:table-cell">Insumos</th>
                                        <th class="hidden p-2 text-right font-medium sm:table-cell">Gastos</th>
                                        <th class="p-2 pr-4 text-right font-medium sm:pr-2">Ganancia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="j in data.jornadas" :key="j.caja_id" class="border-b last:border-0">
                                        <td class="p-2 pl-4 sm:pl-2">
                                            <span class="whitespace-nowrap">{{ fmtFecha(j.fecha) }}</span>
                                            <span v-if="j.estado === 'abierta'" class="ml-1 rounded-full bg-emerald-500/15 px-1.5 py-0.5 text-[10px] text-emerald-600">abierta</span>
                                            <span class="block text-xs text-muted-foreground">{{ j.unidades }} {{ esParrilla ? 'sanguches' : 'unidades' }}</span>
                                        </td>
                                        <td class="p-2 text-right tabular-nums">{{ fmt(j.ventas) }}</td>
                                        <td class="hidden p-2 text-right tabular-nums text-muted-foreground sm:table-cell">{{ fmt(j.costo_insumos) }}</td>
                                        <td class="hidden p-2 text-right tabular-nums text-muted-foreground sm:table-cell">{{ fmt(j.gastos_fijos) }}</td>
                                        <td class="p-2 pr-4 text-right font-semibold tabular-nums sm:pr-2" :class="colorPlata(j.ganancia_neta)">{{ fmt(j.ganancia_neta) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>

                    <!-- Lo más vendido -->
                    <Card>
                        <CardHeader class="pb-2"><CardTitle class="text-base">Lo más vendido</CardTitle></CardHeader>
                        <CardContent class="flex flex-col gap-1.5">
                            <p v-if="!data.productos.length" class="text-sm text-muted-foreground">Sin ventas.</p>
                            <div v-for="p in data.productos" :key="p.nombre" class="flex items-center justify-between gap-2 text-sm">
                                <span class="min-w-0 truncate">{{ p.nombre }}</span>
                                <span class="shrink-0 tabular-nums">
                                    <b>{{ p.cantidad }}</b>
                                    <span class="ml-2 text-muted-foreground">{{ fmt(p.monto) }}</span>
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </template>
        </div>

        <!-- Panel: cargar / corregir compra -->
        <template v-if="form && tandas">
            <!-- Hoja desde abajo en celular, ventana centrada en compu -->
            <div class="fixed inset-0 z-40 bg-black/60" @click="form = null" />
            <div
                class="fixed inset-x-0 bottom-0 z-50 max-h-[88dvh] overflow-y-auto rounded-t-2xl bg-background p-2 pb-[calc(0.5rem+env(safe-area-inset-bottom))] shadow-2xl sm:inset-x-auto sm:bottom-auto sm:left-1/2 sm:top-1/2 sm:w-[26rem] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-2xl"
            >
                <Card>
                    <CardHeader class="pb-2">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-base">{{ form.id ? 'Corregir compra' : 'Cargar compra' }}</CardTitle>
                            <button class="text-muted-foreground hover:text-foreground" @click="form = null"><X class="h-4 w-4" /></button>
                        </div>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-3">
                        <div>
                            <p class="mb-1.5 text-xs text-muted-foreground">¿Qué compraste?</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="o in tandas.opciones"
                                    :key="o.id"
                                    :disabled="!!form.id"
                                    class="h-9 rounded-full border px-3 text-sm transition-colors disabled:opacity-50"
                                    :class="form.insumo_id === o.id ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                                    @click="form.insumo_id = o.id"
                                >
                                    {{ o.nombre }}
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <label class="text-xs text-muted-foreground">
                                {{ esKg ? 'Kg comprados' : 'Unidades' }}
                                <input
                                    v-model.number="form.cantidad"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    :step="esKg ? 0.01 : 1"
                                    :placeholder="esKg ? '9,2' : '24'"
                                    class="mt-1 h-10 w-full rounded border border-input bg-background px-2 text-base text-foreground"
                                />
                            </label>
                            <label class="text-xs text-muted-foreground">
                                Total pagado
                                <input
                                    v-model.number="form.total"
                                    type="number"
                                    inputmode="numeric"
                                    min="0"
                                    step="1"
                                    placeholder="80000"
                                    class="mt-1 h-10 w-full rounded border border-input bg-background px-2 text-base text-foreground"
                                />
                            </label>
                        </div>

                        <label class="text-xs text-muted-foreground">
                            ¿Cuántos sanguches calculás sacar?
                            <input
                                v-model.number="form.porciones_estimadas"
                                type="number"
                                inputmode="numeric"
                                min="1"
                                step="1"
                                class="mt-1 h-10 w-full rounded border border-input bg-background px-2 text-base text-foreground"
                                @input="porcionesAMano = true"
                            />
                            <span v-if="opcionElegida?.rendimiento" class="mt-1 block">
                                La última compra rindió <b>{{ fmtCant(opcionElegida.rendimiento) }} por {{ esKg ? 'kg' : 'unidad' }}</b>; el número sale de ahí.
                            </span>
                            <span v-else-if="esKg" class="mt-1 block">Es la primera vez: estimalo vos. Cuando se termine, el sistema aprende cuánto rinde.</span>
                        </label>

                        <div v-if="apartaPorSanguche" class="rounded-lg bg-muted p-3 text-sm">
                            Cada sanguche aparta <b class="tabular-nums">{{ fmt(apartaPorSanguche) }}</b> al sobre.
                            <span v-if="esKg && Number(form.cantidad) > 0" class="block text-xs text-muted-foreground">
                                {{ fmt(Number(form.total) / Number(form.cantidad)) }} el kg
                            </span>
                        </div>

                        <label class="text-xs text-muted-foreground">
                            Nota (opcional)
                            <input
                                v-model="form.observacion"
                                placeholder="2 vacíos, carnicería X"
                                class="mt-1 h-10 w-full rounded border border-input bg-background px-2 text-sm text-foreground"
                            />
                        </label>

                        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

                        <div class="flex gap-2">
                            <Button variant="outline" class="flex-1" @click="form = null">Cancelar</Button>
                            <Button class="flex-1" :disabled="loading || !puedeGuardar" @click="guardarCompra">Guardar</Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </template>
    </AppLayout>
</template>
