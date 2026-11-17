<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useApi } from '@/composables/useApi';
import { onMounted, ref, computed } from 'vue';
import { ChevronDown, ChevronUp, Minus, Plus, Trash2, RefreshCw, Truck } from 'lucide-vue-next';

interface Variante {
    id: number;
    nombre: string;
    precio_venta: number;
    costo_calculado: number;
    es_extra?: boolean;
}

interface Producto {
    id: number;
    nombre: string;
    variantes: Variante[];
}

type Negocio = 'burger' | 'parrilla' | 'general';

interface Categoria {
    id: number;
    nombre: string;
    negocio?: Negocio;
    productos: Producto[];
}

interface ComboItem {
    variante_id: number;
    descuento: number;
    cantidad: number;
    variante: {
        id: number;
        nombre: string;
        precio_venta: number;
        costo_calculado: number;
        producto: {
            nombre: string;
        };
    };
}

interface Combo {
    id: number;
    nombre: string;
    descripcion?: string | null;
    precio_total: number;
    ahorro: number;
    items: ComboItem[];
}

interface LineaPedido {
    tipo: 'variante' | 'combo';
    variante_id?: number;
    producto_id?: number;
    nombre_producto?: string;
    es_extra?: boolean;
    combo_id?: number;
    nombre: string;
    precio: number;
    costo: number;
    descuento: number;
    cantidad: number;
}

interface Pago {
    metodo: string;
    monto: number;
}

interface DetalleVenta {
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
    notas: string | null;
    estado: string;
    tipo_precio?: TipoPrecio;
    numero_orden: number;
    total: number;
    detalles: DetalleVenta[];
    pagos: Pago[];
}

type TipoPrecio = 'cliente' | 'costo' | 'regalo';

const tiposPrecio: { value: TipoPrecio; label: string }[] = [
    { value: 'cliente', label: 'Cliente' },
    { value: 'costo', label: 'Costo' },
    { value: 'regalo', label: 'Regalo' },
];

const tipoPrecioBadge: Record<string, string> = {
    costo: 'bg-orange-500/10 text-orange-600 border-orange-500/30',
    regalo: 'bg-pink-500/10 text-pink-600 border-pink-500/30',
};

const { get, post, put, loading, error } = useApi();

const categorias = ref<Categoria[]>([]);
const combos = ref<Combo[]>([]);
const categoriaActiva = ref<number | null>(null);
const pedido = ref<LineaPedido[]>([]);
const pedidosActivos = ref<Venta[]>([]);

const mesa = ref('');
const notas = ref('');
const descuento10 = ref(false);
const confirmado = ref(false);
const pagoEfectivo = ref(0);
const pagoTransferencia = ref(0);
const pagoModo = ref<'total' | 'mixto'>('total');
const metodoPago = ref<'efectivo' | 'transferencia'>('efectivo');
const tipoPrecio = ref<TipoPrecio>('cliente');

const mesas = ['Mesa 1', 'Mesa 2', 'Mesa 3', 'Mesa 4', 'Mesa 5', 'Para llevar'];

const masOpciones = ref(false);
const ultimoNumero = ref<number | null>(null);

const negocioDe = (c: Categoria): Negocio => c.negocio ?? 'burger';

// Cada categoría es una pestaña grande en una sola fila (Burger, Parrilla,
// Papas fritas, Bebidas). El envío no: va como botones fijos en el pedido.
const tabs = computed(() => categorias.value.filter(c => negocioDe(c) !== 'general' && c.productos.length));

const EMOJIS: [string, string][] = [
    ['burger', '🍔'],
    ['parrilla', '🔥'],
    ['papa', '🍟'],
    ['bebida', '🥤'],
];

function emojiTab(c: Categoria) {
    const nombre = c.nombre.toLowerCase();
    return EMOJIS.find(([clave]) => nombre.includes(clave))?.[1] ?? '';
}

// Los combos se muestran dentro de la primera pestaña de la burger.
const categoriaConCombos = computed(() => tabs.value.find(c => negocioDe(c) === 'burger')?.id ?? null);

// Lo compartido (envío) no va en la grilla: son botones fijos en el pedido.
const variantesEnvio = computed(() =>
    categorias.value
        .filter(c => negocioDe(c) === 'general')
        .flatMap(c => c.productos.flatMap(p => p.variantes.map(v => ({ variante: v, producto: p })))),
);

function cantidadEnPedido(varianteId: number) {
    return pedido.value
        .filter(l => l.tipo === 'variante' && l.variante_id === varianteId)
        .reduce((acc, l) => acc + l.cantidad, 0);
}

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

const siguienteEstado: Record<string, string> = {
    pendiente: 'preparacion',
    preparacion: 'pagado',
    pagado: 'entregado',
};

const categoriaActivaData = computed(() =>
    categorias.value.find(c => c.id === categoriaActiva.value)
);

// Precio unitario de una línea según el tipo de precio elegido.
function precioLinea(l: LineaPedido) {
    if (tipoPrecio.value === 'regalo') return 0;
    if (tipoPrecio.value === 'costo') return l.costo;
    return l.precio - l.descuento;
}

const subtotal = computed(() =>
    pedido.value.reduce((acc, l) => acc + precioLinea(l) * l.cantidad, 0)
);

const descuentoMonto = computed(() =>
    descuento10.value && tipoPrecio.value === 'cliente' ? Math.round(subtotal.value * 0.1) : 0
);

const total = computed(() => subtotal.value - descuentoMonto.value);

const fmt = (n: number) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');

function horaVenta(d: string) {
    return new Date(d).toLocaleTimeString('es-AR', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function cargarMenu() {
    const data = await get<{ categorias: Categoria[]; combos: Combo[] }>('/api/pos/menu');

    if (data) {
        categorias.value = data.categorias;
        combos.value = data.combos;
        categoriaActiva.value = tabs.value[0]?.id ?? null;
    }
}

async function cargarPedidosActivos() {
    pedidosActivos.value = await get<Venta[]>('/api/pos/pedidos-activos') ?? [];
}

onMounted(async () => {
    await cargarMenu();
    await cargarPedidosActivos();
});

// Variante "Patty extra" de cada producto (no se muestra en la grilla).
const extraPorProducto = computed(() => {
    const map: Record<number, Variante> = {};
    for (const c of categorias.value) {
        for (const p of c.productos) {
            const extra = p.variantes.find(v => v.es_extra);
            if (extra) map[p.id] = extra;
        }
    }
    return map;
});

const variantesVisibles = (p: Producto) => p.variantes.filter(v => !v.es_extra);

function agregarPatty(index: number) {
    const linea = pedido.value[index];
    if (!linea?.producto_id) return;
    const extra = extraPorProducto.value[linea.producto_id];
    if (!extra) return;

    const existente = pedido.value.find(l => l.tipo === 'variante' && l.variante_id === extra.id);
    if (existente) {
        existente.cantidad++;
        return;
    }

    // Se inserta justo debajo de la burger para que se lea como un solo item.
    pedido.value.splice(index + 1, 0, {
        tipo: 'variante',
        variante_id: extra.id,
        producto_id: linea.producto_id,
        nombre_producto: linea.nombre_producto,
        es_extra: true,
        nombre: `${linea.nombre_producto ?? ''} ${extra.nombre}`,
        precio: extra.precio_venta,
        costo: Number(extra.costo_calculado ?? 0),
        descuento: 0,
        cantidad: 1,
    });
}

function agregarVariante(variante: Variante, nombreProducto: string, descuento = 0, cantidad = 1, productoId?: number) {
    const existente = pedido.value.find(l =>
        l.tipo === 'variante' &&
        l.variante_id === variante.id &&
        l.descuento === descuento
    );

    if (existente) {
        existente.cantidad += cantidad;
    } else {
        pedido.value.push({
            tipo: 'variante',
            variante_id: variante.id,
            producto_id: productoId,
            nombre_producto: nombreProducto,
            es_extra: !!variante.es_extra,
            nombre: `${nombreProducto} ${variante.nombre}`,
            precio: variante.precio_venta,
            costo: Number(variante.costo_calculado ?? 0),
            descuento,
            cantidad,
        });
    }
}

function agregarCombo(combo: Combo) {
    const existente = pedido.value.find(l =>
        l.tipo === 'combo' &&
        l.combo_id === combo.id
    );

    if (existente) {
        existente.cantidad++;
    } else {
        pedido.value.push({
            tipo: 'combo',
            combo_id: combo.id,
            nombre: `Combo ${combo.nombre}`,
            precio: combo.precio_total,
            costo: combo.items.reduce(
                (acc, it) => acc + Number(it.variante.costo_calculado ?? 0) * it.cantidad,
                0,
            ),
            descuento: 0,
            cantidad: 1,
        });
    }
}

function cambiarCantidad(index: number, delta: number) {
    pedido.value[index].cantidad += delta;

    if (pedido.value[index].cantidad <= 0) {
        pedido.value.splice(index, 1);
    }
}

function pedirLimpiar() {
    if (pedido.value.length && !window.confirm('¿Borrar todo el pedido?')) return;
    limpiarPedido();
}

function limpiarPedido() {
    pedido.value = [];
    mesa.value = '';
    notas.value = '';
    descuento10.value = false;
    pagoEfectivo.value = 0;
    pagoTransferencia.value = 0;
    pagoModo.value = 'total';
    metodoPago.value = 'efectivo';
    tipoPrecio.value = 'cliente';
    masOpciones.value = false;
}

function buildPagos() {
    if (tipoPrecio.value === 'regalo') return [];

    if (pagoModo.value === 'mixto') {
        return [
            { metodo: 'efectivo', monto: Number(pagoEfectivo.value) },
            { metodo: 'transferencia', monto: Number(pagoTransferencia.value) },
        ].filter(p => p.monto > 0);
    }

    return [
        {
            metodo: metodoPago.value,
            monto: total.value,
        },
    ];
}

async function cobrar() {
    if (!pedido.value.length) return;

    const pagos = buildPagos();

    if (!pagos.length && tipoPrecio.value !== 'regalo') return;

    const items = pedido.value
        .filter(l => l.tipo === 'variante')
        .map(l => ({
            variante_id: l.variante_id,
            cantidad: l.cantidad,
            descuento: l.descuento,
        }));

    const combosPayload = pedido.value
        .filter(l => l.tipo === 'combo')
        .map(l => ({
            combo_id: l.combo_id,
            cantidad: l.cantidad,
        }));

    const payload = {
        mesa: mesa.value || null,
        notas: notas.value || null,
        descuento: descuentoMonto.value,
        tipo_precio: tipoPrecio.value,
        pagos,
        ...(items.length ? { items } : {}),
        ...(combosPayload.length ? { combos: combosPayload } : {}),
    };

    const res = await post<Venta>('/api/pos/venta', payload);

    if (res) {
        confirmado.value = true;
        ultimoNumero.value = res.numero_orden ?? null;
        limpiarPedido();
        await cargarPedidosActivos();

        setTimeout(() => {
            confirmado.value = false;
        }, 2500);
    }
}

async function avanzarEstado(venta: Venta) {
    const next = siguienteEstado[venta.estado];

    if (!next) return;

    await put(`/api/pos/ventas/${venta.id}/estado`, {
        estado: next,
    });

    await cargarPedidosActivos();
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'POS', href: '/pos' }]">
        <!-- Confirmación grande: se ve desde lejos que el pedido entró -->
        <div
            v-if="confirmado"
            class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-2 bg-green-600/95 text-white"
            @click="confirmado = false"
        >
            <p class="text-7xl">✓</p>
            <p class="text-4xl font-bold">Pedido registrado</p>
            <p v-if="ultimoNumero" class="text-6xl font-black">#{{ ultimoNumero }}</p>
        </div>

        <div class="flex h-[calc(100vh-4rem)] gap-4 p-4 overflow-hidden">
            <div class="flex flex-col flex-1 gap-3 min-w-0 overflow-hidden text-foreground">
                <!-- Pestañas: una por categoría, todas en una fila -->
                <div class="grid gap-2" :style="{ gridTemplateColumns: `repeat(${Math.max(tabs.length, 1)}, minmax(0, 1fr))` }">
                    <button
                        v-for="cat in tabs"
                        :key="cat.id"
                        class="h-16 px-1 rounded-xl border-2 flex flex-col items-center justify-center transition-colors"
                        :class="categoriaActiva === cat.id
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'border-input text-foreground hover:bg-muted'"
                        @click="categoriaActiva = cat.id"
                    >
                        <span v-if="emojiTab(cat)" class="text-2xl leading-none">{{ emojiTab(cat) }}</span>
                        <span class="text-base font-bold leading-tight whitespace-nowrap">{{ cat.nombre }}</span>
                    </button>
                </div>

                <div v-if="loading && !categorias.length" class="text-muted-foreground text-lg">
                    Cargando menú...
                </div>

                <div v-else class="flex flex-col gap-4 min-h-0 overflow-y-auto pr-1">
                    <!-- Productos: si tiene varios tamaños, una tarjeta con un botón por tamaño -->
                    <div v-if="categoriaActivaData" class="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-3">
                        <template v-for="producto in categoriaActivaData.productos" :key="producto.id">
                            <div
                                v-if="variantesVisibles(producto).length > 1"
                                class="rounded-xl border-2 border-border bg-card text-card-foreground p-3 flex flex-col gap-2"
                            >
                                <p class="text-xl font-bold leading-tight">{{ producto.nombre }}</p>

                                <div
                                    class="grid gap-2"
                                    :style="{ gridTemplateColumns: `repeat(${variantesVisibles(producto).length}, minmax(0, 1fr))` }"
                                >
                                    <button
                                        v-for="variante in variantesVisibles(producto)"
                                        :key="variante.id"
                                        class="relative rounded-lg border-2 py-2.5 px-1 flex flex-col items-center transition-colors active:scale-[0.97]"
                                        :class="cantidadEnPedido(variante.id)
                                            ? 'border-primary bg-primary/10'
                                            : 'border-input hover:border-primary/60'"
                                        @click="agregarVariante(variante, producto.nombre, 0, 1, producto.id)"
                                    >
                                        <span
                                            v-if="cantidadEnPedido(variante.id)"
                                            class="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-primary text-primary-foreground text-sm font-bold flex items-center justify-center"
                                        >
                                            {{ cantidadEnPedido(variante.id) }}
                                        </span>
                                        <span class="text-base font-semibold">{{ variante.nombre }}</span>
                                        <span class="text-sm text-muted-foreground">{{ fmt(variante.precio_venta) }}</span>
                                    </button>
                                </div>
                            </div>

                            <template v-else>
                                <button
                                    v-for="variante in variantesVisibles(producto)"
                                    :key="variante.id"
                                    class="relative text-left rounded-xl border-2 bg-card text-card-foreground p-4 min-h-28 flex flex-col justify-between transition-colors active:scale-[0.98]"
                                    :class="cantidadEnPedido(variante.id) ? 'border-primary' : 'border-border hover:border-primary/60'"
                                    @click="agregarVariante(variante, producto.nombre, 0, 1, producto.id)"
                                >
                                    <span
                                        v-if="cantidadEnPedido(variante.id)"
                                        class="absolute top-2 right-2 w-9 h-9 rounded-full bg-primary text-primary-foreground text-lg font-bold flex items-center justify-center"
                                    >
                                        {{ cantidadEnPedido(variante.id) }}
                                    </span>

                                    <span>
                                        <span class="block text-xl font-bold leading-tight pr-10">{{ producto.nombre }}</span>
                                        <span class="block text-base text-muted-foreground">{{ variante.nombre }}</span>
                                    </span>

                                    <span class="block text-xl font-bold mt-2">{{ fmt(variante.precio_venta) }}</span>
                                </button>
                            </template>
                        </template>
                    </div>

                    <!-- Combos, dentro de la pestaña de la burger -->
                    <template v-if="combos.length && categoriaActiva === categoriaConCombos">
                        <h3 class="font-semibold text-lg">Combos</h3>

                        <div class="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-3">
                            <button
                                v-for="combo in combos"
                                :key="combo.id"
                                class="text-left rounded-xl border-2 border-border bg-card text-card-foreground p-4 hover:border-primary/60 transition-colors"
                                @click="agregarCombo(combo)"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="text-lg font-semibold">{{ combo.nombre }}</p>
                                        <p v-if="combo.descripcion" class="text-sm text-muted-foreground mt-0.5">
                                            {{ combo.descripcion }}
                                        </p>
                                    </div>

                                    <span class="text-xs bg-primary/10 text-primary px-2 py-0.5 rounded-full shrink-0">
                                        Ahorrás {{ fmt(combo.ahorro) }}
                                    </span>
                                </div>

                                <div class="mt-2 space-y-0.5">
                                    <p v-for="item in combo.items" :key="item.variante_id" class="text-sm text-muted-foreground">
                                        {{ item.cantidad }}x {{ item.variante.producto.nombre }} {{ item.variante.nombre }}
                                    </p>
                                </div>

                                <p class="text-xl font-bold mt-2">{{ fmt(combo.precio_total) }}</p>
                            </button>
                        </div>
                    </template>

                    <!-- Pedidos activos -->
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-lg">Pedidos activos</h3>

                            <button
                                class="h-10 px-3 rounded-lg border text-sm text-muted-foreground hover:text-foreground flex items-center gap-2"
                                @click="cargarPedidosActivos"
                            >
                                <RefreshCw class="w-4 h-4" />
                                Actualizar
                            </button>
                        </div>

                        <p v-if="!pedidosActivos.length" class="text-base text-muted-foreground">
                            Sin pedidos activos
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                            <Card
                                v-for="pedidoActivo in pedidosActivos"
                                :key="pedidoActivo.id"
                                class="border-2"
                                :class="estadoColor[pedidoActivo.estado]"
                            >
                                <CardContent class="p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-black text-2xl">#{{ pedidoActivo.numero_orden }}</span>

                                        <span
                                            class="text-sm font-medium px-2 py-0.5 rounded-full border"
                                            :class="estadoColor[pedidoActivo.estado]"
                                        >
                                            {{ estadoLabel[pedidoActivo.estado] }}
                                        </span>
                                    </div>

                                    <div class="flex items-center justify-between mb-2 text-sm text-muted-foreground">
                                        <span class="flex items-center gap-1">
                                            {{ pedidoActivo.mesa ?? 'Sin mesa' }}
                                            <span
                                                v-if="pedidoActivo.tipo_precio && pedidoActivo.tipo_precio !== 'cliente'"
                                                class="px-1.5 py-0.5 rounded-full border capitalize text-xs"
                                                :class="tipoPrecioBadge[pedidoActivo.tipo_precio]"
                                            >
                                                {{ pedidoActivo.tipo_precio }}
                                            </span>
                                        </span>
                                        <span>{{ horaVenta(pedidoActivo.created_at) }}</span>
                                    </div>

                                    <p v-if="pedidoActivo.notas" class="text-sm mb-2 italic">{{ pedidoActivo.notas }}</p>

                                    <div class="text-base space-y-0.5 mb-3">
                                        <p v-for="d in pedidoActivo.detalles" :key="d.nombre_snapshot" class="leading-tight">
                                            {{ d.cantidad }}x {{ d.nombre_snapshot }}
                                        </p>
                                    </div>

                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-base font-semibold">{{ fmt(pedidoActivo.total) }}</span>

                                        <button
                                            v-if="siguienteEstado[pedidoActivo.estado]"
                                            class="h-11 px-4 rounded-lg bg-primary text-primary-foreground text-base font-medium hover:opacity-90"
                                            @click="avanzarEstado(pedidoActivo)"
                                        >
                                            → {{ estadoLabel[siguienteEstado[pedidoActivo.estado]] }}
                                        </button>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pedido -->
            <div class="w-80 xl:w-96 flex flex-col shrink-0">
                <Card class="flex-1 flex flex-col overflow-hidden">
                    <CardContent class="flex flex-col gap-3 p-4 h-full overflow-hidden">
                        <div class="flex items-center justify-between">
                            <span class="text-xl font-bold">Pedido</span>
                            <button
                                class="h-10 px-3 rounded-lg text-sm text-muted-foreground hover:bg-muted flex items-center gap-1"
                                @click="pedirLimpiar"
                            >
                                <Trash2 class="w-4 h-4" />
                                Limpiar
                            </button>
                        </div>

                        <!-- Mesa -->
                        <div class="grid grid-cols-3 gap-1.5">
                            <button
                                v-for="m in mesas"
                                :key="m"
                                class="h-9 rounded-lg border text-sm transition-colors"
                                :class="mesa === m ? 'bg-foreground text-background border-foreground' : 'border-input hover:bg-muted'"
                                @click="mesa = mesa === m ? '' : m"
                            >
                                {{ m }}
                            </button>
                        </div>

                        <!-- Líneas -->
                        <div class="flex-1 overflow-y-auto flex flex-col gap-2.5 min-h-24">
                            <p v-if="!pedido.length" class="text-base text-muted-foreground text-center py-6">
                                Tocá los productos para agregarlos
                            </p>

                            <div v-for="(linea, i) in pedido" :key="i" class="flex items-center gap-2">
                                <div class="flex-1 min-w-0" :class="linea.es_extra ? 'pl-3' : ''">
                                    <p class="text-base leading-tight" :class="linea.es_extra ? 'text-muted-foreground' : 'font-medium'">
                                        <template v-if="linea.es_extra">+ </template>{{ linea.nombre }}
                                    </p>
                                    <p class="flex items-center gap-2 text-sm text-muted-foreground">
                                        {{ fmt(precioLinea(linea) * linea.cantidad) }}
                                        <button
                                            v-if="!linea.es_extra && linea.producto_id && extraPorProducto[linea.producto_id]"
                                            class="h-7 px-2 rounded-md border text-xs text-foreground hover:bg-muted"
                                            @click="agregarPatty(i)"
                                        >
                                            + Patty
                                        </button>
                                    </p>
                                </div>

                                <div class="flex items-center gap-1.5 shrink-0">
                                    <button
                                        class="w-10 h-10 rounded-full border-2 flex items-center justify-center hover:bg-muted"
                                        @click="cambiarCantidad(i, -1)"
                                    >
                                        <Minus class="w-5 h-5" />
                                    </button>
                                    <span class="w-7 text-center text-lg font-bold">{{ linea.cantidad }}</span>
                                    <button
                                        class="w-10 h-10 rounded-full border-2 flex items-center justify-center hover:bg-muted"
                                        @click="cambiarCantidad(i, 1)"
                                    >
                                        <Plus class="w-5 h-5" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Envío -->
                        <div v-if="variantesEnvio.length" class="flex items-center gap-2">
                            <span class="text-sm text-muted-foreground flex items-center gap-1 shrink-0">
                                <Truck class="w-4 h-4" /> Envío
                            </span>
                            <button
                                v-for="e in variantesEnvio"
                                :key="e.variante.id"
                                class="flex-1 h-10 rounded-lg border text-base font-medium transition-colors"
                                :class="cantidadEnPedido(e.variante.id) ? 'bg-foreground text-background border-foreground' : 'border-input hover:bg-muted'"
                                @click="agregarVariante(e.variante, e.producto.nombre, 0, 1, e.producto.id)"
                            >
                                {{ fmt(e.variante.precio_venta) }}
                            </button>
                        </div>

                        <Separator />

                        <!-- Total -->
                        <div class="flex flex-col gap-0.5">
                            <div v-if="descuento10" class="flex justify-between text-sm text-muted-foreground">
                                <span>Desc. 10%</span>
                                <span>-{{ fmt(descuentoMonto) }}</span>
                            </div>
                            <div class="flex justify-between items-baseline">
                                <span class="text-lg">Total</span>
                                <span class="text-3xl font-black">{{ fmt(total) }}</span>
                            </div>
                        </div>

                        <!-- Pago -->
                        <p v-if="tipoPrecio === 'regalo'" class="text-base text-center text-pink-600">
                            Regalo: no se cobra nada.
                        </p>

                        <div v-else-if="pagoModo === 'total'" class="grid grid-cols-2 gap-2">
                            <button
                                class="h-14 rounded-xl border-2 text-lg font-semibold transition-colors"
                                :class="metodoPago === 'efectivo' ? 'bg-green-600 text-white border-green-600' : 'border-input hover:bg-muted'"
                                @click="metodoPago = 'efectivo'"
                            >
                                💵 Efectivo
                            </button>
                            <button
                                class="h-14 rounded-xl border-2 text-lg font-semibold transition-colors"
                                :class="metodoPago === 'transferencia' ? 'bg-sky-600 text-white border-sky-600' : 'border-input hover:bg-muted'"
                                @click="metodoPago = 'transferencia'"
                            >
                                📱 Transf.
                            </button>
                        </div>

                        <div v-else class="flex flex-col gap-2">
                            <label class="flex items-center gap-2">
                                <span class="text-base w-28 shrink-0">💵 Efectivo</span>
                                <input
                                    type="number"
                                    v-model.number="pagoEfectivo"
                                    min="0"
                                    class="w-full h-11 text-base rounded-lg border border-input bg-background px-3"
                                />
                            </label>
                            <label class="flex items-center gap-2">
                                <span class="text-base w-28 shrink-0">📱 Transf.</span>
                                <input
                                    type="number"
                                    v-model.number="pagoTransferencia"
                                    min="0"
                                    class="w-full h-11 text-base rounded-lg border border-input bg-background px-3"
                                />
                            </label>
                        </div>

                        <!-- Más opciones: lo que se usa poco queda escondido -->
                        <button
                            class="text-sm text-muted-foreground flex items-center justify-center gap-1 hover:text-foreground"
                            @click="masOpciones = !masOpciones"
                        >
                            Más opciones
                            <ChevronUp v-if="masOpciones" class="w-4 h-4" />
                            <ChevronDown v-else class="w-4 h-4" />
                        </button>

                        <div v-if="masOpciones" class="flex flex-col gap-2 rounded-lg border p-2">
                            <div class="grid grid-cols-3 gap-1">
                                <button
                                    v-for="t in tiposPrecio"
                                    :key="t.value"
                                    class="h-9 text-sm rounded-md border transition-colors"
                                    :class="tipoPrecio === t.value ? 'bg-primary text-primary-foreground border-primary' : 'border-input hover:bg-muted'"
                                    @click="tipoPrecio = t.value"
                                >
                                    {{ t.label }}
                                </button>
                            </div>

                            <div v-if="tipoPrecio !== 'regalo'" class="grid grid-cols-2 gap-1">
                                <button
                                    class="h-9 text-sm rounded-md border transition-colors"
                                    :class="pagoModo === 'total' ? 'bg-primary text-primary-foreground border-primary' : 'border-input hover:bg-muted'"
                                    @click="pagoModo = 'total'"
                                >
                                    Un método
                                </button>
                                <button
                                    class="h-9 text-sm rounded-md border transition-colors"
                                    :class="pagoModo === 'mixto' ? 'bg-primary text-primary-foreground border-primary' : 'border-input hover:bg-muted'"
                                    @click="pagoModo = 'mixto'"
                                >
                                    Mixto
                                </button>
                            </div>

                            <label v-if="tipoPrecio === 'cliente'" class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" v-model="descuento10" class="w-4 h-4 rounded" />
                                Descuento 10%
                            </label>

                            <textarea
                                v-model="notas"
                                rows="2"
                                placeholder="Nota del pedido (ej. sin cebolla)"
                                class="w-full text-sm rounded-md border border-input bg-background px-2 py-1.5 resize-none"
                            />
                        </div>

                        <Button
                            class="w-full h-16 text-xl font-bold rounded-xl"
                            :disabled="!pedido.length || loading"
                            @click="cobrar"
                        >
                            <span v-if="loading">Procesando...</span>
                            <span v-else>Confirmar {{ fmt(total) }}</span>
                        </Button>

                        <p v-if="error" class="text-base text-destructive text-center">{{ error }}</p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
