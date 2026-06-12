<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useApi } from '@/composables/useApi';
import { onMounted, ref, computed } from 'vue';
import { Minus, Plus, Trash2, RefreshCw } from 'lucide-vue-next';

interface Variante {
    id: number;
    nombre: string;
    precio_venta: number;
}

interface Producto {
    id: number;
    nombre: string;
    variantes: Variante[];
}

interface Categoria {
    id: number;
    nombre: string;
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
    combo_id?: number;
    nombre: string;
    precio: number;
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
    numero_orden: number;
    total: number;
    detalles: DetalleVenta[];
    pagos: Pago[];
}

const { get, post, put, loading, error } = useApi();

const categorias = ref<Categoria[]>([]);
const combos = ref<Combo[]>([]);
const categoriaActiva = ref<number | 'combos' | null>(null);
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

const mesas = ['Mesa 1', 'Mesa 2', 'Mesa 3', 'Mesa 4', 'Mesa 5', 'Para llevar'];

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

const subtotal = computed(() =>
    pedido.value.reduce((acc, l) => acc + (l.precio - l.descuento) * l.cantidad, 0)
);

const descuentoMonto = computed(() =>
    descuento10.value ? Math.round(subtotal.value * 0.1) : 0
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
        categoriaActiva.value = data.categorias[0]?.id ?? (data.combos.length ? 'combos' : null);
    }
}

async function cargarPedidosActivos() {
    pedidosActivos.value = await get<Venta[]>('/api/pos/pedidos-activos') ?? [];
}

onMounted(async () => {
    await cargarMenu();
    await cargarPedidosActivos();
});

function agregarVariante(variante: Variante, nombreProducto: string, descuento = 0, cantidad = 1) {
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
            nombre: `${nombreProducto} ${variante.nombre}`,
            precio: variante.precio_venta,
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

function limpiarPedido() {
    pedido.value = [];
    mesa.value = '';
    notas.value = '';
    descuento10.value = false;
    pagoEfectivo.value = 0;
    pagoTransferencia.value = 0;
    pagoModo.value = 'total';
    metodoPago.value = 'efectivo';
}

function buildPagos() {
    if (pagoModo.value === 'mixto') {
        return [
            { metodo: 'efectivo', monto: Number(pagoEfectivo.value) },
            { metodo: 'transferencia', monto: Number(pagoTransferencia.value) },
        ].filter(p => p.monto > 0);
    }

    return [
        {
            metodo: metodoPago.value,
            monto: total(pagoTransferencia.value) },
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

    if (!pagos.length) return;

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
        pagos,
        ...(items.length ? { items } : {}),
        ...(combosPayload.length ? { combos: combosPayload } : {}),
    };

    const res = await post('/api/pos/venta', payload);

    if (res) {
        confirmado.value = true;
        await cargarPedidosActivos();

        setTimeout(() => {
            confirmado.value = false;
            limpiarPedido();
        }, 2000);
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
        <div class="flex h-[calc(100vh-4rem)] gap-4 p-4 overflow-hidden">
            <div class="flex flex-col flex-1 gap-3 min-w-0 overflow-hidden">
                <div class="flex gap-2 flex-wrap">
                    <Button
                        v-for="cat in categorias"
                        :key="cat.id"
                        :variant="cat.id === categoriaActiva ? 'default' : 'outline'"
                        size="sm"
                        @click="categoriaActiva = cat.id"
                    >
                        {{ cat.nombre }}
                    </Button>

                    <Button
                        v-if="combos.length"
                        :variant="categoriaActiva === 'combos' ? 'default' : 'outline'"
                        size="sm"
                        @click="categoriaActiva = 'combos'"
                    >
                        🔥 Combos
                    </Button>
                </div>

                <div v-if="loading" class="text-muted-foreground text-sm">
                    Cargando menú...
                </div>

                <div v-else-if="error" class="text-destructive text-sm">
                    {{ error }}
                </div>

                <div v-else class="flex flex-col gap-4 min-h-0 overflow-y-auto pr-1">
                    <div
                        v-if="categoriaActiva !== 'combos'"
                        class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3"
                    >
                        <template v-if="categoriaActivaData">
                            <template
                                v-for="producto in categoriaActivaData.productos"
                                :key="producto.id"
                            >
                                <Card
                                    v-for="variante in producto.variantes"
                                    :key="variante.id"
                                    class="cursor-pointer hover:border-primary transition-colors"
                                    @click="agregarVariante(variante, producto.nombre)"
                                >
                                    <CardContent class="p-3">
                                        <p class="font-medium text-sm leading-tight">
                                            {{ producto.nombre }}
                                        </p>

                                        <p class="text-xs text-muted-foreground mb-2">
                                            {{ variante.nombre }}
                                        </p>

                                        <p class="font-semibold text-sm">
                                            {{ fmt(variante.precio_venta) }}
                                        </p>
                                    </CardContent>
                                </Card>
                            </template>
                        </template>
                    </div>

                    <div
                        v-else
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3"
                    >
                        <Card
                            v-for="combo in combos"
                            :key="combo.id"
                            class="cursor-pointer hover:border-primary transition-colors"
                            @click="agregarCombo(combo)"
                        >
                            <CardContent class="p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-semibold text-sm">
                                            {{ combo.nombre }}
                                        </p>

                                        <p
                                            v-if="combo.descripcion"
                                            class="text-xs text-muted-foreground mt-0.5"
                                        >
                                            {{ combo.descripcion }}
                                        </p>
                                    </div>

                                    <span class="text-xs bg-primary/10 text-primary px-2 py-0.5 rounded-full shrink-0">
                                        Ahorrás {{ fmt(combo.ahorro) }}
                                    </span>
                                </div>

                                <div class="mt-2 space-y-0.5">
                                    <p
                                        v-for="item in combo.items"
                                        :key="item.variante_id"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ item.cantidad }}x
                                        {{ item.variante.producto.nombre }}
                                        {{ item.variante.nombre }}

                                        <span
                                            v-if="item.descuento > 0"
                                            class="text-primary"
                                        >
                                            -{{ fmt(item.descuento) }}
                                        </span>
                                    </p>
                                </div>

                                <p class="font-bold text-sm mt-2">
                                    {{ fmt(combo.precio_total) }}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-sm">Pedidos activos</h3>

                            <button
                                @click="cargarPedidosActivos"
                                class="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1"
                            >
                                <RefreshCw class="w-3 h-3" />
                                Actualizar
                            </button>
                        </div>

                        <p
                            v-if="!pedidosActivos.length"
                            class="text-xs text-muted-foreground"
                        >
                            Sin pedidos activos
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                            <Card
                                v-for="pedidoActivo in pedidosActivos"
                                :key="pedidoActivo.id"
                                class="border"
                                :class="estadoColor[pedidoActivo.estado]"
                            >
                                <CardContent class="p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-black text-lg">
                                            #{{ pedidoActivo.numero_orden }}
                                        </span>

                                        <span
                                            class="text-xs font-medium px-2 py-0.5 rounded-full border"
                                            :class="estadoColor[pedidoActivo.estado]"
                                        >
                                            {{ estadoLabel[pedidoActivo.estado] }}
                                        </span>
                                    </div>

                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs text-muted-foreground">
                                            {{ pedidoActivo.mesa ?? 'Sin mesa' }}
                                        </span>

                                        <span class="text-xs text-muted-foreground">
                                            {{ horaVenta(pedidoActivo.created_at) }}
                                        </span>
                                    </div>

                                    <p
                                        v-if="pedidoActivo.notas"
                                        class="text-xs mb-2 italic"
                                    >
                                        {{ pedidoActivo.notas }}
                                    </p>

                                    <div class="text-xs space-y-0.5 mb-3">
                                        <p
                                            v-for="d in pedidoActivo.detalles"
                                            :key="d.nombre_snapshot"
                                            class="leading-tight"
                                        >
                                            {{ d.cantidad }}x {{ d.nombre_snapshot }}
                                        </p>
                                    </div>

                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold">
                                            {{ fmt(pedidoActivo.total) }}
                                        </span>

                                        <button
                                            v-if="siguienteEstado[pedidoActivo.estado]"
                                            @click="avanzarEstado(pedidoActivo)"
                                            class="text-xs px-2 py-1 rounded bg-primary text-primary-foreground hover:opacity-90"
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

            <div class="w-80 flex flex-col gap-3 shrink-0">
                <Card class="flex-1 flex flex-col overflow-hidden">
                    <CardContent class="flex flex-col gap-3 p-4 h-full overflow-hidden">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">Pedido</span>

                            <select
                                v-model="mesa"
                                class="h-7 text-xs rounded-md border border-input bg-background px-2 py-1 text-foreground"
                            >
                                <option value="">Mesa</option>
                                <option v-for="m in mesas" :key="m" :value="m">
                                    {{ m }}
                                </option>
                            </select>
                        </div>

                        <textarea
                            v-model="notas"
                            rows="2"
                            placeholder="Descripción del pedido..."
                            class="w-full text-xs rounded-md border border-input bg-background px-2 py-1.5 resize-none"
                        />

                        <Separator />

                        <div class="flex-1 overflow-y-auto flex flex-col gap-2 min-h-0">
                            <p
                                v-if="!pedido.length"
                                class="text-xs text-muted-foreground text-center py-4"
                            >
                                Agregá productos del menú
                            </p>

                            <div
                                v-for="(linea, i) in pedido"
                                :key="i"
                                class="flex items-center gap-2"
                            >
                                <span class="flex-1 text-xs leading-tight">
                                    {{ linea.nombre }}
                                </span>

                                <div class="flex items-center gap-1 shrink-0">
                                    <button
                                        class="w-5 h-5 rounded-full border flex items-center justify-center hover:bg-muted"
                                        @click="cambiarCantidad(i, -1)"
                                    >
                                        <Minus class="w-3 h-3" />
                                    </button>

                                    <span class="w-4 text-center text-xs font-medium">
                                        {{ linea.cantidad }}
                                    </span>

                                    <button
                                        class="w-5 h-5 rounded-full border flex items-center justify-center hover:bg-muted"
                                        @click="cambiarCantidad(i, 1)"
                                    >
                                        <Plus class="w-3 h-3" />
                                    </button>
                                </div>

                                <span class="text-xs text-muted-foreground w-16 text-right shrink-0">
                                    {{ fmt((linea.precio - linea.descuento) * linea.cantidad) }}
                                </span>
                            </div>
                        </div>

                        <Separator />

                        <div class="flex flex-col gap-1 text-sm">
                            <div class="flex justify-between text-muted-foreground">
                                <span>Subtotal</span>
                                <span>{{ fmt(subtotal) }}</span>
                            </div>

                            <div
                                v-if="descuento10"
                                class="flex justify-between text-muted-foreground"
                            >
                                <span>Desc. 10%</span>
                                <span>-{{ fmt(descuentoMonto) }}</span>
                            </div>

                            <div class="flex justify-between font-semibold">
                                <span>Total</span>
                                <span>{{ fmt(total) }}</span>
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer">
                            <input type="checkbox" v-model="descuento10" class="rounded" />
                            Descuento 10%
                        </label>

                        <Separator />

                        <div class="flex flex-col gap-2">
                            <div class="flex gap-2">
                                <button
                                    @click="pagoModo = 'total'"
                                    class="flex-1 text-xs py-1 rounded border transition-colors"
                                    :class="pagoModo === 'total'
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'border-input hover:bg-muted'"
                                >
                                    Un método
                                </button>

                                <button
                                    @click="pagoModo = 'mixto'"
                                    class="flex-1 text-xs py-1 rounded border transition-colors"
                                    :class="pagoModo === 'mixto'
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'border-input hover:bg-muted'"
                                >
                                    Mixto
                                </button>
                            </div>

                            <div v-if="pagoModo === 'total'" class="grid grid-cols-2 gap-1">
                                <button
                                    v-for="m in ['efectivo', 'transferencia']"
                                    :key="m"
                                    @click="metodoPago = m as any"
                                    class="text-xs py-1.5 rounded border capitalize transition-colors"
                                    :class="metodoPago === m
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'border-input hover:bg-muted'"
                                >
                                    {{ m }}
                                </button>
                            </div>

                            <div v-else class="flex flex-col gap-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs w-24 shrink-0">Efectivo</span>

                                    <input
                                        type="number"
                                        v-model.number="pagoEfectivo"
                                        min="0"
                                        class="w-full text-xs rounded border border-input bg-background px-2 py-1"
                                    />
                                </div>

                                <div class="flex items-center gap-2">
                                    <span class="text-xs w-24 shrink-0">Transferencia</span>

                                    <input
                                        type="number"
                                        v-model.number="pagoTransferencia"
                                        min="0"
                                        class="w-full text-xs rounded border border-input bg-background px-2 py-1"
                                    />
                                </div>
                            </div>
                        </div>

                        <Button class="w-full" :disabled="!pedido.length || loading" @click="cobrar">
                            <span v-if="confirmado">✓ Pedido registrado</span>
                            <span v-else-if="loading">Procesando...</span>
                            <span v-else>Confirmar {{ fmt(total) }}</span>
                        </Button>

                        <Button variant="ghost" size="sm" class="w-full text-xs" @click="limpiarPedido">
                            <Trash2 class="w-3 h-3 mr-1" />
                            Limpiar
                        </Button>

                        <p v-if="error" class="text-xs text-destructive">
                            {{ error }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>