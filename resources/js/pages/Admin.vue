<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import { onMounted, ref, computed } from 'vue';
import { Plus, Pencil, Trash2, X, ChevronDown, ChevronUp } from 'lucide-vue-next';

const { get, post, put, loading } = useApi();

const tab = ref<'productos' | 'combos' | 'insumos' | 'gastos'>('productos');
const categorias = ref<any[]>([]);
const productos = ref<any[]>([]);
const insumos = ref<any[]>([]);
const combos = ref<any[]>([]);
const gastos = ref<any[]>([]);
const variantes = ref<any[]>([]);
const panel = ref<string | null>(null);
const editingId = ref<number | null>(null);
const expandido = ref<number | null>(null);

const fmt = (n: any) => {
    const num = Number(n ?? 0);

    return '$' + Math.round(num).toLocaleString('es-AR');
};

const fmtNumero = (n: any) => {
    const num = Number(n ?? 0);

    if (Number.isInteger(num)) {
        return num.toLocaleString('es-AR', {
            maximumFractionDigits: 0,
        });
    }

    return num.toLocaleString('es-AR', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 3,
    });
};

// Formularios
const fProducto = ref({
    nombre: '',
    categoria_id: '' as any,
    descripcion: '',
    activo: true,
});

const fVariante = ref({
    nombre: '',
    precio_venta: 0,
    activo: true,
});

const fVarianteProductoId = ref<number | null>(null);

const fInsumo = ref({
    nombre: '',
    unidad: '',
    costo_unitario: 0,
    stock_minimo: 0,
    descuenta_stock: true,
});

const fCategoria = ref({
    nombre: '',
});

const fGasto = ref({
    nombre: '',
    monto_mensual: 0,
    dias_apertura_mes: 16,
    activo: true,
});

const fCombo = ref({
    nombre: '',
    descripcion: '',
});

const fComboItems = ref<{
    variante_id: number;
    descuento: number;
    cantidad: number;
}[]>([]);

// Receta editor
const recetaData = ref<any>(null);

const recetaItems = ref<{
    insumo_id: number;
    cantidad: number;
}[]>([]);

async function cargarTodo() {
    const [cats, prods, ins, cbs, gsts] = await Promise.all([
        get<any[]>('/api/admin/categorias'),
        get<any[]>('/api/admin/productos'),
        get<any[]>('/api/admin/insumos'),
        get<any[]>('/api/combos'),
        get<any[]>('/api/admin/gastos-fijos'),
    ]);

    categorias.value = cats ?? [];
    productos.value = prods ?? [];
    insumos.value = ins ?? [];
    combos.value = cbs ?? [];
    gastos.value = gsts ?? [];

    variantes.value = (prods ?? []).flatMap((p: any) =>
        (p.variantes ?? [])
            .filter((v: any) => v.activo)
            .map((v: any) => ({
                ...v,
                label: `${p.nombre} ${v.nombre} — ${fmt(v.precio_venta)}`,
                producto_nombre: p.nombre,
            }))
    );
}

onMounted(cargarTodo);

const productosPorCategoria = computed(() =>
    categorias.value.map(cat => ({
        ...cat,
        productos: productos.value.filter(p => p.categoria_id === cat.id),
    }))
);

const totalGastosDia = computed(() =>
    gastos.value
        .filter(g => g.activo)
        .reduce((acc: number, g: any) => acc + (Number(g.monto_mensual) / Number(g.dias_apertura_mes)), 0)
);

function abrirPanel(tipo: string, data: any = null) {
    panel.value = tipo;
    editingId.value = data?.id ?? null;
    recetaData.value = null;

    if (tipo === 'producto') {
        fProducto.value = {
            nombre: data?.nombre ?? '',
            categoria_id: data?.categoria_id ?? '',
            descripcion: data?.descripcion ?? '',
            activo: data?.activo ?? true,
        };
    } else if (tipo === 'variante') {
        fVarianteProductoId.value = data?.producto_id ?? null;

        fVariante.value = {
            nombre: data?.nombre ?? '',
            precio_venta: Number(data?.precio_venta ?? 0),
            activo: data?.activo ?? true,
        };
    } else if (tipo === 'insumo') {
        fInsumo.value = {
            nombre: data?.nombre ?? '',
            unidad: data?.unidad ?? '',
            costo_unitario: Number(data?.costo_unitario ?? 0),
            stock_minimo: Number(data?.stock_minimo ?? 0),
            descuenta_stock: data?.descuenta_stock === true || data?.descuenta_stock === 1,
        };
    } else if (tipo === 'categoria') {
        fCategoria.value = {
            nombre: data?.nombre ?? '',
        };
    } else if (tipo === 'gasto') {
        fGasto.value = {
            nombre: data?.nombre ?? '',
            monto_mensual: Number(data?.monto_mensual ?? 0),
            dias_apertura_mes: Number(data?.dias_apertura_mes ?? 16),
            activo: data?.activo ?? true,
        };
    } else if (tipo === 'combo') {
        fCombo.value = {
            nombre: data?.nombre ?? '',
            descripcion: data?.descripcion ?? '',
        };

        fComboItems.value = data?.items?.map((i: any) => ({
            variante_id: i.variante_id,
            descuento: Number(i.descuento ?? 0),
            cantidad: Number(i.cantidad ?? 1),
        })) ?? [];
    }
}

async function abrirReceta(variante: any, productoId: number) {
    panel.value = 'receta';
    editingId.value = variante.id;
    fVarianteProductoId.value = productoId;

    const data = await get<any>(`/api/admin/variantes/${variante.id}/receta`);

    if (data) {
        recetaData.value = data;
        recetaItems.value = data.recetas.map((r: any) => ({
            insumo_id: r.insumo_id,
            cantidad: Number(r.cantidad ?? 0),
        }));
    }
}

function cerrarPanel() {
    panel.value = null;
    editingId.value = null;
    recetaData.value = null;
}

async function guardarProducto() {
    if (editingId.value) {
        await put(`/api/admin/productos/${editingId.value}`, fProducto.value as any);
    } else {
        await post('/api/admin/productos', fProducto.value as any);
    }

    cerrarPanel();
    await cargarTodo();
}

async function guardarVariante() {
    if (editingId.value) {
        await put(`/api/admin/variantes/${editingId.value}`, fVariante.value as any);
    } else if (fVarianteProductoId.value) {
        await post(`/api/admin/productos/${fVarianteProductoId.value}/variantes`, fVariante.value as any);
    }

    cerrarPanel();
    await cargarTodo();
}

async function guardarInsumo() {
    const body = {
        ...fInsumo.value,
        costo_unitario: Number(fInsumo.value.costo_unitario ?? 0),
        stock_minimo: Number(fInsumo.value.stock_minimo ?? 0),
        descuenta_stock: Boolean(fInsumo.value.descuenta_stock),
    };

    if (editingId.value) {
        await put(`/api/admin/insumos/${editingId.value}`, body as any);
    } else {
        await post('/api/admin/insumos', body as any);
    }

    cerrarPanel();
    await cargarTodo();
}

async function guardarCategoria() {
    await post('/api/admin/categorias', fCategoria.value as any);

    cerrarPanel();
    await cargarTodo();
}

async function guardarGasto() {
    if (editingId.value) {
        await put(`/api/admin/gastos-fijos/${editingId.value}`, fGasto.value as any);
    } else {
        await post('/api/admin/gastos-fijos', fGasto.value as any);
    }

    cerrarPanel();
    await cargarTodo();
}

async function guardarCombo() {
    const body = {
        ...fCombo.value,
        items: fComboItems.value,
    } as any;

    if (editingId.value) {
        await put(`/api/admin/combos/${editingId.value}`, body);
    } else {
        await post('/api/admin/combos', body);
    }

    cerrarPanel();
    await cargarTodo();
}

async function guardarReceta() {
    await post(`/api/admin/variantes/${editingId.value}/receta`, {
        recetas: recetaItems.value,
    } as any);

    const data = await get<any>(`/api/admin/variantes/${editingId.value}/receta`);

    if (data) {
        recetaData.value = data;
        recetaItems.value = data.recetas.map((r: any) => ({
            insumo_id: r.insumo_id,
            cantidad: Number(r.cantidad ?? 0),
        }));
    }

    await cargarTodo();
}

async function toggleActivo(tipo: string, id: number, activo: boolean) {
    const endpoints: Record<string, string> = {
        producto: 'productos',
        insumo: 'insumos',
        variante: 'variantes',
        gasto: 'gastos-fijos',
    };

    if (tipo === 'combo') {
        await put(`/api/admin/combos/${id}`, { activo } as any);
    } else {
        await put(`/api/admin/${endpoints[tipo]}/${id}`, { activo } as any);
    }

    await cargarTodo();
}

async function toggleDescuentaStock(insumo: any) {
    await put(`/api/admin/insumos/${insumo.id}`, {
        descuenta_stock: !(insumo.descuenta_stock === true || insumo.descuenta_stock === 1),
    } as any);

    await cargarTodo();
}

function agregarComboItem() {
    fComboItems.value.push({
        variante_id: variantes.value[0]?.id ?? 0,
        descuento: 0,
        cantidad: 1,
    });
}

function quitarComboItem(i: number) {
    fComboItems.value.splice(i, 1);
}

function agregarRecetaItem() {
    recetaItems.value.push({
        insumo_id: insumos.value[0]?.id ?? 0,
        cantidad: 1,
    });
}

function quitarRecetaItem(i: number) {
    recetaItems.value.splice(i, 1);
}

const costoRecetaActual = computed(() =>
    recetaItems.value.reduce((acc, item) => {
        const ins = insumos.value.find((i: any) => i.id === item.insumo_id);
        return acc + (ins ? Number(ins.costo_unitario) * Number(item.cantidad) : 0);
    }, 0)
);

const margenActual = computed(() => {
    if (!recetaData.value?.precio_venta || costoRecetaActual.value <= 0) {
        return 0;
    }

    return (
        (Number(recetaData.value.precio_venta) - costoRecetaActual.value) /
        Number(recetaData.value.precio_venta) *
        100
    ).toFixed(1);
});
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Admin', href: '/admin' }]">
        <div class="p-4 flex gap-4 min-h-0">
            <div class="flex-1 flex flex-col gap-4 min-w-0">
                <!-- Tabs -->
                <div class="flex gap-2 flex-wrap">
                    <Button
                        v-for="[k, v] in [['productos', 'Productos'], ['combos', 'Combos'], ['insumos', 'Insumos'], ['gastos', 'Gastos fijos']]"
                        :key="k"
                        :variant="tab === k ? 'default' : 'outline'"
                        size="sm"
                        @click="tab = k as any"
                    >
                        {{ v }}
                    </Button>
                </div>

                <!-- PRODUCTOS -->
                <div v-if="tab === 'productos'" class="flex flex-col gap-3">
                    <div class="flex gap-2">
                        <Button size="sm" @click="abrirPanel('producto')">
                            <Plus class="w-4 h-4 mr-1" />
                            Nuevo producto
                        </Button>

                        <Button size="sm" variant="outline" @click="abrirPanel('categoria')">
                            <Plus class="w-4 h-4 mr-1" />
                            Nueva categoría
                        </Button>
                    </div>

                    <div v-for="cat in productosPorCategoria" :key="cat.id">
                        <h3 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">
                            {{ cat.nombre }}
                        </h3>

                        <Card v-for="prod in cat.productos" :key="prod.id" class="mb-2">
                            <CardContent class="p-3">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <button
                                            @click="expandido = expandido === prod.id ? null : prod.id"
                                            class="text-muted-foreground"
                                        >
                                            <ChevronDown v-if="expandido !== prod.id" class="w-4 h-4" />
                                            <ChevronUp v-else class="w-4 h-4" />
                                        </button>

                                        <span class="font-medium text-sm">{{ prod.nombre }}</span>

                                        <span
                                            v-if="!prod.activo"
                                            class="text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground"
                                        >
                                            Inactivo
                                        </span>
                                    </div>

                                    <div class="flex gap-1">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            class="h-7 w-7"
                                            @click="abrirPanel('variante', { producto_id: prod.id })"
                                            title="Agregar variante"
                                        >
                                            <Plus class="w-3 h-3" />
                                        </Button>

                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            class="h-7 w-7"
                                            @click="abrirPanel('producto', prod)"
                                        >
                                            <Pencil class="w-3 h-3" />
                                        </Button>

                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            class="h-7 w-7"
                                            @click="toggleActivo('producto', prod.id, !prod.activo)"
                                        >
                                            <Trash2 class="w-3 h-3" />
                                        </Button>
                                    </div>
                                </div>

                                <div v-if="expandido === prod.id" class="mt-2 pl-6 flex flex-col gap-1">
                                    <div
                                        v-for="v in prod.variantes"
                                        :key="v.id"
                                        class="flex items-center justify-between text-sm py-1.5 border-b last:border-0"
                                    >
                                        <span class="text-muted-foreground">{{ v.nombre }}</span>

                                        <div class="flex items-center gap-3">
                                            <span class="font-medium">{{ fmt(v.precio_venta) }}</span>

                                            <span v-if="Number(v.costo_calculado) > 0" class="text-xs text-muted-foreground">
                                                Margen
                                                {{
                                                    (
                                                        (Number(v.precio_venta) - Number(v.costo_calculado)) /
                                                        Number(v.precio_venta) *
                                                        100
                                                    ).toFixed(0)
                                                }}%
                                            </span>

                                            <button
                                                @click="abrirReceta(v, prod.id)"
                                                class="text-xs px-2 py-0.5 rounded border border-primary text-primary hover:bg-primary hover:text-primary-foreground transition-colors"
                                            >
                                                Receta
                                            </button>

                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="h-6 w-6"
                                                @click="abrirPanel('variante', { ...v, producto_id: prod.id })"
                                            >
                                                <Pencil class="w-3 h-3" />
                                            </Button>

                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="h-6 w-6"
                                                @click="toggleActivo('variante', v.id, !v.activo)"
                                            >
                                                <Trash2 class="w-3 h-3" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- COMBOS -->
                <div v-if="tab === 'combos'" class="flex flex-col gap-3">
                    <Button size="sm" class="self-start" @click="abrirPanel('combo')">
                        <Plus class="w-4 h-4 mr-1" />
                        Nuevo combo
                    </Button>

                    <div v-if="!combos.length" class="text-sm text-muted-foreground">
                        Sin combos creados aún.
                    </div>

                    <Card v-for="combo in combos" :key="combo.id">
                        <CardContent class="p-3">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ combo.nombre }}</span>

                                    <span
                                        v-if="!combo.activo"
                                        class="text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground"
                                    >
                                        Inactivo
                                    </span>
                                </div>

                                <div class="flex gap-1">
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="h-7 w-7"
                                        @click="abrirPanel('combo', combo)"
                                    >
                                        <Pencil class="w-3 h-3" />
                                    </Button>

                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="h-7 w-7"
                                        @click="toggleActivo('combo', combo.id, !combo.activo)"
                                    >
                                        <Trash2 class="w-3 h-3" />
                                    </Button>
                                </div>
                            </div>

                            <div class="space-y-1">
                                <div
                                    v-for="item in combo.items"
                                    :key="item.id"
                                    class="flex justify-between text-xs text-muted-foreground"
                                >
                                    <span>
                                        {{ item.variante?.producto?.nombre }}
                                        {{ item.variante?.nombre }}
                                        x {{ fmtNumero(item.cantidad) }}
                                    </span>

                                    <span class="text-primary">
                                        -{{ fmt(item.descuento) }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-2 flex justify-between text-sm border-t pt-2">
                                <span class="text-muted-foreground">Total combo</span>

                                <span class="font-semibold">
                                    {{ fmt(combo.precio_total) }}
                                    <span class="text-primary text-xs">
                                        (ahorrás {{ fmt(combo.ahorro) }})
                                    </span>
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- INSUMOS -->
                <div v-if="tab === 'insumos'" class="flex flex-col gap-3">
                    <Button size="sm" class="self-start" @click="abrirPanel('insumo')">
                        <Plus class="w-4 h-4 mr-1" />
                        Nuevo insumo
                    </Button>

                    <Card>
                        <CardContent class="p-0">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-xs text-muted-foreground">
                                        <th class="text-left p-3 font-medium">Nombre</th>
                                        <th class="text-left p-3 font-medium">Unidad</th>
                                        <th class="text-right p-3 font-medium">Costo unit.</th>
                                        <th class="text-right p-3 font-medium">Stock mín.</th>
                                        <th class="text-center p-3 font-medium">Descuenta stock</th>
                                        <th class="p-3"></th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr
                                        v-for="ins in insumos"
                                        :key="ins.id"
                                        class="border-b last:border-0 hover:bg-muted/50"
                                    >
                                        <td class="p-3 font-medium">
                                            {{ ins.nombre }}
                                        </td>

                                        <td class="p-3 text-muted-foreground">
                                            {{ ins.unidad }}
                                        </td>

                                        <td class="p-3 text-right">
                                            {{ fmt(ins.costo_unitario) }}
                                        </td>

                                        <td class="p-3 text-right text-muted-foreground">
                                            {{ fmtNumero(ins.stock_minimo) }}
                                        </td>

                                        <td class="p-3 text-center">
                                            <button
                                                type="button"
                                                @click="toggleDescuentaStock(ins)"
                                                class="text-xs px-2 py-0.5 rounded-full border transition-colors"
                                                :class="(ins.descuenta_stock === true || ins.descuenta_stock === 1)
                                                    ? 'bg-green-500/10 text-green-600 border-green-500/30 hover:bg-green-500/20'
                                                    : 'bg-muted text-muted-foreground border-border hover:bg-muted/80'"
                                            >
                                                {{ (ins.descuenta_stock === true || ins.descuenta_stock === 1) ? 'Sí' : 'No' }}
                                            </button>
                                        </td>

                                        <td class="p-3 text-right">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="h-7 w-7"
                                                @click="abrirPanel('insumo', ins)"
                                            >
                                                <Pencil class="w-3 h-3" />
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>

                <!-- GASTOS -->
                <div v-if="tab === 'gastos'" class="flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-muted-foreground">Costo diario estimado</p>
                            <p class="text-2xl font-semibold">{{ fmt(totalGastosDia) }}</p>
                        </div>

                        <Button size="sm" @click="abrirPanel('gasto')">
                            <Plus class="w-4 h-4 mr-1" />
                            Nuevo gasto
                        </Button>
                    </div>

                    <Card>
                        <CardContent class="p-0">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-xs text-muted-foreground">
                                        <th class="text-left p-3 font-medium">Nombre</th>
                                        <th class="text-right p-3 font-medium">Mensual</th>
                                        <th class="text-right p-3 font-medium">Días</th>
                                        <th class="text-right p-3 font-medium">Por día</th>
                                        <th class="text-center p-3 font-medium">Activo</th>
                                        <th class="p-3"></th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr
                                        v-for="g in gastos"
                                        :key="g.id"
                                        class="border-b last:border-0 hover:bg-muted/50"
                                        :class="{ 'opacity-50': !g.activo }"
                                    >
                                        <td class="p-3 font-medium">
                                            {{ g.nombre }}
                                        </td>

                                        <td class="p-3 text-right">
                                            {{ fmt(g.monto_mensual) }}
                                        </td>

                                        <td class="p-3 text-right text-muted-foreground">
                                            {{ fmtNumero(g.dias_apertura_mes) }}
                                        </td>

                                        <td class="p-3 text-right font-medium">
                                            {{ fmt(Number(g.monto_mensual) / Number(g.dias_apertura_mes)) }}
                                        </td>

                                        <td class="p-3 text-center">
                                            <input
                                                type="checkbox"
                                                :checked="g.activo"
                                                @change="toggleActivo('gasto', g.id, !g.activo)"
                                                class="w-4 h-4"
                                            />
                                        </td>

                                        <td class="p-3 text-right">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="h-7 w-7"
                                                @click="abrirPanel('gasto', g)"
                                            >
                                                <Pencil class="w-3 h-3" />
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- PANEL LATERAL -->
            <div v-if="panel" class="w-80 shrink-0">
                <Card>
                    <CardHeader class="pb-2">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-sm">
                                {{
                                    panel === 'receta'
                                        ? `Receta — ${recetaData?.variante?.nombre ?? ''}`
                                        : (editingId ? 'Editar' : 'Nuevo') + ' ' + ({
                                            producto: 'producto',
                                            variante: 'variante',
                                            insumo: 'insumo',
                                            categoria: 'categoría',
                                            gasto: 'gasto fijo',
                                            combo: 'combo',
                                        }[panel] ?? '')
                                }}
                            </CardTitle>

                            <button
                                @click="cerrarPanel"
                                class="text-muted-foreground hover:text-foreground"
                            >
                                <X class="w-4 h-4" />
                            </button>
                        </div>
                    </CardHeader>

                    <CardContent class="flex flex-col gap-3 max-h-[calc(100vh-8rem)] overflow-y-auto">
                        <!-- Producto -->
                        <template v-if="panel === 'producto'">
                            <div>
                                <label class="text-xs text-muted-foreground">Nombre</label>
                                <input
                                    v-model="fProducto.nombre"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Categoría</label>
                                <select
                                    v-model="fProducto.categoria_id"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                >
                                    <option v-for="c in categorias" :key="c.id" :value="c.id">
                                        {{ c.nombre }}
                                    </option>
                                </select>
                            </div>

                            <label class="flex items-center gap-2 text-xs cursor-pointer">
                                <input type="checkbox" v-model="fProducto.activo" class="w-4 h-4" />
                                Activo
                            </label>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                    Cancelar
                                </Button>

                                <Button size="sm" class="flex-1" @click="guardarProducto" :disabled="loading">
                                    Guardar
                                </Button>
                            </div>
                        </template>

                        <!-- Variante -->
                        <template v-if="panel === 'variante'">
                            <div>
                                <label class="text-xs text-muted-foreground">Nombre (ej: Simple, Doble)</label>
                                <input
                                    v-model="fVariante.nombre"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Precio de venta</label>
                                <input
                                    type="number"
                                    v-model="fVariante.precio_venta"
                                    min="0"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <label class="flex items-center gap-2 text-xs cursor-pointer">
                                <input type="checkbox" v-model="fVariante.activo" class="w-4 h-4" />
                                Activa
                            </label>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                    Cancelar
                                </Button>

                                <Button size="sm" class="flex-1" @click="guardarVariante" :disabled="loading">
                                    Guardar
                                </Button>
                            </div>
                        </template>

                        <!-- Receta -->
                        <template v-if="panel === 'receta'">
                            <div v-if="!recetaData" class="text-sm text-muted-foreground">
                                Cargando...
                            </div>

                            <template v-else>
                                <div class="grid grid-cols-3 gap-2 p-2 rounded bg-muted/30">
                                    <div class="text-center">
                                        <p class="text-xs text-muted-foreground">Precio</p>
                                        <p class="text-sm font-semibold">{{ fmt(recetaData.precio_venta) }}</p>
                                    </div>

                                    <div class="text-center">
                                        <p class="text-xs text-muted-foreground">Costo</p>
                                        <p class="text-sm font-semibold text-destructive">{{ fmt(costoRecetaActual) }}</p>
                                    </div>

                                    <div class="text-center">
                                        <p class="text-xs text-muted-foreground">Margen</p>
                                        <p class="text-sm font-semibold text-green-600">{{ margenActual }}%</p>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium">Insumos</span>

                                    <button
                                        @click="agregarRecetaItem"
                                        class="text-xs text-primary hover:underline"
                                    >
                                        + Agregar
                                    </button>
                                </div>

                                <div
                                    v-for="(item, i) in recetaItems"
                                    :key="i"
                                    class="flex items-center gap-2 p-2 rounded border border-input bg-muted/20"
                                >
                                    <div class="flex-1">
                                        <select
                                            v-model="item.insumo_id"
                                            class="w-full text-xs rounded border border-input bg-background px-1 py-1"
                                        >
                                            <option v-for="ins in insumos" :key="ins.id" :value="ins.id">
                                                {{ ins.nombre }} ({{ ins.unidad }})
                                            </option>
                                        </select>
                                    </div>

                                    <input
                                        type="number"
                                        v-model="item.cantidad"
                                        min="0.001"
                                        step="0.1"
                                        class="w-16 text-xs rounded border border-input bg-background px-1 py-1 text-right"
                                    />

                                    <span class="text-xs text-muted-foreground w-16 text-right shrink-0">
                                        {{
                                            fmt(
                                                (insumos.find((ins: any) => ins.id === item.insumo_id)?.costo_unitario ?? 0) *
                                                item.cantidad
                                            )
                                        }}
                                    </span>

                                    <button
                                        @click="quitarRecetaItem(i)"
                                        class="text-destructive hover:opacity-70 shrink-0"
                                    >
                                        <X class="w-3 h-3" />
                                    </button>
                                </div>

                                <div class="flex justify-between text-xs text-muted-foreground border-t pt-2">
                                    <span>Costo total receta</span>
                                    <span class="font-medium">{{ fmt(costoRecetaActual) }}</span>
                                </div>

                                <div class="flex gap-2">
                                    <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                        Cerrar
                                    </Button>

                                    <Button size="sm" class="flex-1" @click="guardarReceta" :disabled="loading">
                                        Guardar receta
                                    </Button>
                                </div>
                            </template>
                        </template>

                        <!-- Insumo -->
                        <template v-if="panel === 'insumo'">
                            <div>
                                <label class="text-xs text-muted-foreground">Nombre</label>
                                <input
                                    v-model="fInsumo.nombre"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Unidad</label>
                                <input
                                    v-model="fInsumo.unidad"
                                    placeholder="ej: unidad, kg, porción"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Costo unitario</label>
                                <input
                                    type="number"
                                    v-model="fInsumo.costo_unitario"
                                    min="0"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Stock mínimo</label>
                                <input
                                    type="number"
                                    v-model="fInsumo.stock_minimo"
                                    min="0"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <label class="flex items-center gap-2 text-xs cursor-pointer">
                                <input
                                    type="checkbox"
                                    v-model="fInsumo.descuenta_stock"
                                    class="w-4 h-4"
                                />
                                Descuenta stock
                            </label>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                    Cancelar
                                </Button>

                                <Button size="sm" class="flex-1" @click="guardarInsumo" :disabled="loading">
                                    Guardar
                                </Button>
                            </div>
                        </template>

                        <!-- Categoría -->
                        <template v-if="panel === 'categoria'">
                            <div>
                                <label class="text-xs text-muted-foreground">Nombre</label>
                                <input
                                    v-model="fCategoria.nombre"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                    Cancelar
                                </Button>

                                <Button size="sm" class="flex-1" @click="guardarCategoria" :disabled="loading">
                                    Guardar
                                </Button>
                            </div>
                        </template>

                        <!-- Gasto -->
                        <template v-if="panel === 'gasto'">
                            <div>
                                <label class="text-xs text-muted-foreground">Nombre</label>
                                <input
                                    v-model="fGasto.nombre"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Monto mensual</label>
                                <input
                                    type="number"
                                    v-model="fGasto.monto_mensual"
                                    min="0"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Días de apertura por mes</label>
                                <input
                                    type="number"
                                    v-model="fGasto.dias_apertura_mes"
                                    min="1"
                                    max="31"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <label class="flex items-center gap-2 text-xs cursor-pointer">
                                <input type="checkbox" v-model="fGasto.activo" class="w-4 h-4" />
                                Activo
                            </label>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                    Cancelar
                                </Button>

                                <Button size="sm" class="flex-1" @click="guardarGasto" :disabled="loading">
                                    Guardar
                                </Button>
                            </div>
                        </template>

                        <!-- Combo -->
                        <template v-if="panel === 'combo'">
                            <div>
                                <label class="text-xs text-muted-foreground">Nombre del combo</label>
                                <input
                                    v-model="fCombo.nombre"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Descripción (opcional)</label>
                                <input
                                    v-model="fCombo.descripcion"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium">Ítems del combo</span>

                                <button
                                    @click="agregarComboItem"
                                    class="text-xs text-primary hover:underline"
                                >
                                    + Agregar
                                </button>
                            </div>

                            <div
                                v-for="(item, i) in fComboItems"
                                :key="i"
                                class="flex flex-col gap-1 p-2 rounded border border-input bg-muted/30"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-muted-foreground">Ítem {{ i + 1 }}</span>

                                    <button @click="quitarComboItem(i)" class="text-destructive">
                                        <X class="w-3 h-3" />
                                    </button>
                                </div>

                                <select
                                    v-model="item.variante_id"
                                    class="text-xs rounded border border-input bg-background px-2 py-1"
                                >
                                    <option v-for="v in variantes" :key="v.id" :value="v.id">
                                        {{ v.label }}
                                    </option>
                                </select>

                                <div class="flex gap-2">
                                    <div class="flex-1">
                                        <label class="text-xs text-muted-foreground">Cant.</label>
                                        <input
                                            type="number"
                                            v-model="item.cantidad"
                                            min="1"
                                            class="w-full text-xs rounded border border-input bg-background px-2 py-1"
                                        />
                                    </div>

                                    <div class="flex-1">
                                        <label class="text-xs text-muted-foreground">Descuento $</label>
                                        <input
                                            type="number"
                                            v-model="item.descuento"
                                            min="0"
                                            class="w-full text-xs rounded border border-input bg-background px-2 py-1"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                    Cancelar
                                </Button>

                                <Button
                                    size="sm"
                                    class="flex-1"
                                    @click="guardarCombo"
                                    :disabled="loading || !fComboItems.length"
                                >
                                    Guardar
                                </Button>
                            </div>
                        </template>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>