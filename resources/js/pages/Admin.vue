<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import { onMounted, ref, computed } from 'vue';
import { Plus, Pencil, Trash2, X, ChevronDown, ChevronUp, ClipboardCopy } from 'lucide-vue-next';

const { get, post, put, del, loading } = useApi();

// ── Exportar resumen para Claude ─────────────────────────────────────────────
const exportando = ref(false);
const exportMsg = ref('');

async function exportarParaClaude() {
    exportando.value = true;
    exportMsg.value = 'Generando...';

    try {
        const data = await get<{ texto: string }>('/api/admin/exportar-resumen?dias=30');
        if (!data?.texto) {
            exportMsg.value = 'Error al generar';
            return;
        }

        // Se descarga siempre como .md y además se intenta copiar.
        const blob = new Blob([data.texto], { type: 'text/markdown;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `resumen-volcano-${new Date().toISOString().slice(0, 10)}.md`;
        a.click();
        URL.revokeObjectURL(a.href);

        try {
            await navigator.clipboard.writeText(data.texto);
            exportMsg.value = '✓ Copiado y descargado';
        } catch {
            exportMsg.value = '✓ Descargado';
        }
    } finally {
        exportando.value = false;
        setTimeout(() => (exportMsg.value = ''), 3000);
    }
}

const tab = ref<'productos' | 'combos' | 'insumos' | 'gastos' | 'bebidas'>('productos');
const categorias = ref<any[]>([]);
const productos = ref<any[]>([]);
const insumos = ref<any[]>([]);
const combos = ref<any[]>([]);
const gastos = ref<any[]>([]);
const variantes = ref<any[]>([]);
const bebidas = ref<any>(null);
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

const GRUPOS_SEPARACION: [string, string][] = [
    ['carne', 'Carne'],
    ['pan', 'Pan'],
    ['papas', 'Papas'],
    ['cheddar', 'Cheddar'],
    ['panceta', 'Panceta'],
    ['descartables', 'Descartables'],
    ['bebidas', 'Bebidas'],
    ['vacio', 'Vacío (parrilla)'],
    ['bondiola', 'Bondiola'],
    ['chorizo', 'Chorizo'],
    ['chinchulin', 'Chinchulín'],
    ['pan_sanguche', 'Pan de sanguche'],
    ['varios', 'Varios'],
];

const fInsumo = ref({
    nombre: '',
    unidad: '',
    costo_unitario: 0,
    stock_minimo: 0,
    descuenta_stock: true,
    grupo_separacion: 'varios',
});

const fCategoria = ref({
    nombre: '',
    negocio: 'burger',
});

const fGasto = ref({
    nombre: '',
    negocio: 'compartido',
    periodicidad: 'mensual',
    monto_jornada: 0,
    monto_mensual: 0,
    dias_apertura_mes: 16,
    activo: true,
});

const fInversion = ref({
    monto: 0,
    fecha: new Date().toISOString().slice(0, 10),
    descripcion: '',
});

// Precio de compra (botella, pack, bolsa) de un insumo de bebidas
const fCompra = ref({
    precio: 0,
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
    const [cats, prods, ins, cbs, gsts, bebs] = await Promise.all([
        get<any[]>('/api/admin/categorias'),
        get<any[]>('/api/admin/productos'),
        get<any[]>('/api/admin/insumos'),
        get<any[]>('/api/combos'),
        get<any[]>('/api/admin/gastos-fijos'),
        get<any>('/api/admin/bebidas'),
    ]);

    bebidas.value = bebs;

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

const gastoPorDia = (g: any) =>
    g.periodicidad === 'jornada' ? Number(g.monto_jornada) : Number(g.monto_mensual) / Number(g.dias_apertura_mes);

const totalGastosDia = computed(() =>
    gastos.value
        .filter(g => g.activo)
        .reduce((acc: number, g: any) => acc + gastoPorDia(g), 0)
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
            grupo_separacion: data?.grupo_separacion ?? 'varios',
        };
    } else if (tipo === 'categoria') {
        fCategoria.value = {
            nombre: data?.nombre ?? '',
            negocio: data?.negocio ?? 'burger',
        };
    } else if (tipo === 'gasto') {
        fGasto.value = {
            nombre: data?.nombre ?? '',
            negocio: data?.negocio ?? 'compartido',
            periodicidad: data?.periodicidad ?? 'mensual',
            monto_jornada: Number(data?.monto_jornada ?? 0),
            monto_mensual: Number(data?.monto_mensual ?? 0),
            dias_apertura_mes: Number(data?.dias_apertura_mes ?? 16),
            activo: data?.activo ?? true,
        };
    } else if (tipo === 'inversion') {
        fInversion.value = {
            monto: Number(data?.monto ?? 0),
            fecha: data?.fecha ?? new Date().toISOString().slice(0, 10),
            descripcion: data?.descripcion ?? '',
        };
    } else if (tipo === 'compra') {
        fCompra.value = {
            precio: Math.round(precioCompra(data)),
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
        grupo_separacion: fInsumo.value.grupo_separacion || 'varios',
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

const NEGOCIOS_CATEGORIA = [
    { value: 'burger', label: 'Burger' },
    { value: 'parrilla', label: 'Parrilla' },
    { value: 'general', label: 'Compartido (los dos)' },
];

const NEGOCIOS_GASTO = [
    { value: 'burger', label: 'Burger' },
    { value: 'parrilla', label: 'Parrilla' },
    { value: 'compartido', label: 'Compartido (mitad y mitad)' },
];

const etiquetaNegocio: Record<string, string> = {
    burger: 'Burger',
    parrilla: 'Parrilla',
    general: 'Compartido',
    compartido: 'Compartido',
};

const colorNegocio: Record<string, string> = {
    burger: 'bg-orange-500/10 text-orange-600 border-orange-500/30',
    parrilla: 'bg-red-500/10 text-red-600 border-red-500/30',
    general: 'bg-sky-500/10 text-sky-600 border-sky-500/30',
    compartido: 'bg-muted text-muted-foreground border-border',
};

async function cambiarNegocioCategoria(cat: any, negocio: string) {
    await put(`/api/admin/categorias/${cat.id}`, { negocio } as any);
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

async function guardarInversion() {
    if (editingId.value) {
        await put(`/api/admin/inversiones/${editingId.value}`, fInversion.value as any);
    } else {
        await post('/api/admin/inversiones', {
            ...fInversion.value,
            categoria_id: bebidas.value?.categoria?.id,
        } as any);
    }

    cerrarPanel();
    await cargarTodo();
}

async function eliminarInversion(inv: any) {
    if (!confirm(`¿Eliminar la inversión de ${fmt(inv.monto)}?`)) {
        return;
    }

    await del(`/api/admin/inversiones/${inv.id}`);
    await cargarTodo();
}

// El costo unitario sale de dividir el precio de compra por la equivalencia
// (ej: botella de 750cc a $9.300 → $12.400 por litro).
async function guardarCompra() {
    const ins = insumos.value.find((i: any) => i.id === editingId.value);
    const equivalencia = Number(ins?.equivalencia_compra ?? 0) || 1;

    await put(`/api/admin/insumos/${editingId.value}`, {
        costo_unitario: Math.round((Number(fCompra.value.precio) / equivalencia) * 100) / 100,
    } as any);

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

function precioCompra(ins: any) {
    return Number(ins?.costo_unitario ?? 0) * (Number(ins?.equivalencia_compra ?? 0) || 1);
}

function costoLinea(r: any) {
    return Number(r.cantidad) * Number(r.insumo?.costo_unitario ?? 0);
}

// Cuántos vasos rinde una unidad de compra del insumo más caro que se compra
// por botella/pack (ej: Fernet 1L ÷ 0,2L = 5 vasos x botella).
function rendimiento(v: any) {
    const principal = (v.recetas ?? [])
        .filter((r: any) => Number(r.insumo?.equivalencia_compra) > 0 && Number(r.cantidad) > 0)
        .sort((a: any, b: any) => costoLinea(b) - costoLinea(a))[0];

    if (!principal) {
        return null;
    }

    const cantidad = Number(principal.insumo.equivalencia_compra) / Number(principal.cantidad);

    return `${fmtNumero(Math.round(cantidad * 10) / 10)} x ${principal.insumo.unidad_compra ?? principal.insumo.nombre}`;
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
        <div class="p-3 sm:p-4 flex gap-4 min-h-0">
            <div class="flex-1 flex flex-col gap-4 min-w-0">
                <!-- Tabs -->
                <div class="flex gap-2 flex-wrap">
                    <Button
                        v-for="[k, v] in [['productos', 'Productos'], ['combos', 'Combos'], ['insumos', 'Insumos'], ['gastos', 'Gastos fijos'], ['bebidas', 'Bebidas']]"
                        :key="k"
                        :variant="tab === k ? 'default' : 'outline'"
                        size="sm"
                        @click="tab = k as any"
                    >
                        {{ v }}
                    </Button>

                    <Button
                        size="sm"
                        variant="outline"
                        class="ml-auto"
                        :disabled="exportando"
                        title="Copia precios, costos, márgenes, recetas, gastos y ventas de los últimos 30 días para pegarle a Claude"
                        @click="exportarParaClaude"
                    >
                        <ClipboardCopy class="w-4 h-4 mr-1" />
                        {{ exportMsg || 'Exportar para Claude' }}
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
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <h3 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ cat.nombre }}
                            </h3>

                            <select
                                :value="cat.negocio ?? 'burger'"
                                @change="cambiarNegocioCategoria(cat, ($event.target as HTMLSelectElement).value)"
                                class="text-xs rounded-full border px-2 py-0.5"
                                :class="colorNegocio[cat.negocio ?? 'burger']"
                                title="A qué negocio suman las ventas de esta categoría"
                            >
                                <option v-for="n in NEGOCIOS_CATEGORIA" :key="n.value" :value="n.value">
                                    {{ n.label }}
                                </option>
                            </select>
                        </div>

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
                        <CardContent class="overflow-x-auto p-0">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-xs text-muted-foreground">
                                        <th class="text-left p-3 font-medium">Nombre</th>
                                        <th class="hidden sm:table-cell text-left p-3 font-medium">Unidad</th>
                                        <th class="hidden sm:table-cell text-left p-3 font-medium">Grupo</th>
                                        <th class="text-right p-3 font-medium">Costo unit.</th>
                                        <th class="hidden sm:table-cell text-right p-3 font-medium">Stock mín.</th>
                                        <th class="text-center p-3 font-medium"><span class="sm:hidden">Desc.</span><span class="hidden sm:inline">Descuenta stock</span></th>
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
                                            <span class="block text-xs font-normal text-muted-foreground sm:hidden">
                                                {{ ins.unidad }} · {{ (GRUPOS_SEPARACION.find(([v]) => v === (ins.grupo_separacion ?? 'varios')) ?? ['', 'Varios'])[1] }}
                                            </span>
                                        </td>

                                        <td class="hidden sm:table-cell p-3 text-muted-foreground">
                                            {{ ins.unidad }}
                                        </td>

                                        <td class="hidden sm:table-cell p-3">
                                            <span class="text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground">
                                                {{ (GRUPOS_SEPARACION.find(([v]) => v === (ins.grupo_separacion ?? 'varios')) ?? ['', 'Varios'])[1] }}
                                            </span>
                                        </td>

                                        <td class="p-3 text-right">
                                            {{ fmt(ins.costo_unitario) }}
                                        </td>

                                        <td class="hidden sm:table-cell p-3 text-right text-muted-foreground">
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
                        <CardContent class="overflow-x-auto p-0">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-xs text-muted-foreground">
                                        <th class="text-left p-3 font-medium">Nombre</th>
                                        <th class="hidden sm:table-cell text-left p-3 font-medium">Negocio</th>
                                        <th class="hidden sm:table-cell text-right p-3 font-medium">Monto</th>
                                        <th class="hidden sm:table-cell text-right p-3 font-medium">Días/mes</th>
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
                                            <span class="mt-1 flex flex-wrap items-center gap-1.5 text-xs font-normal text-muted-foreground sm:hidden">
                                                <span
                                                    class="px-2 py-0.5 rounded-full border"
                                                    :class="colorNegocio[g.negocio ?? 'compartido']"
                                                >
                                                    {{ etiquetaNegocio[g.negocio ?? 'compartido'] }}
                                                </span>
                                                <template v-if="g.periodicidad === 'jornada'">{{ fmt(g.monto_jornada) }}/jornada</template>
                                                <template v-else>{{ fmt(g.monto_mensual) }}/mes · {{ fmtNumero(g.dias_apertura_mes) }} días</template>
                                            </span>
                                        </td>

                                        <td class="hidden sm:table-cell p-3">
                                            <span
                                                class="text-xs px-2 py-0.5 rounded-full border"
                                                :class="colorNegocio[g.negocio ?? 'compartido']"
                                            >
                                                {{ etiquetaNegocio[g.negocio ?? 'compartido'] }}
                                            </span>
                                        </td>

                                        <td class="hidden sm:table-cell p-3 text-right whitespace-nowrap">
                                            <template v-if="g.periodicidad === 'jornada'">
                                                {{ fmt(g.monto_jornada) }}
                                                <span class="text-xs text-muted-foreground">/jornada</span>
                                            </template>
                                            <template v-else>
                                                {{ fmt(g.monto_mensual) }}
                                                <span class="text-xs text-muted-foreground">/mes</span>
                                            </template>
                                        </td>

                                        <td class="hidden sm:table-cell p-3 text-right text-muted-foreground">
                                            {{ fmtNumero(g.dias_apertura_mes) }}
                                        </td>

                                        <td class="p-3 text-right font-medium">
                                            {{ fmt(gastoPorDia(g)) }}
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

                <!-- BEBIDAS -->
                <div v-if="tab === 'bebidas'" class="flex flex-col gap-3">
                    <div v-if="!bebidas" class="text-sm text-muted-foreground">
                        No hay una categoría "Bebidas". Creala desde Productos.
                    </div>

                    <template v-else>
                        <!-- Recupero de la inversión -->
                        <Card>
                            <CardContent class="p-4 flex flex-col gap-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="text-sm text-muted-foreground">Recupero de la inversión</p>
                                        <p class="text-2xl font-semibold">
                                            {{ fmt(bebidas.recupero.ganancia) }}
                                            <span class="text-base font-normal text-muted-foreground">
                                                de {{ fmt(bebidas.recupero.invertido) }}
                                            </span>
                                        </p>
                                    </div>

                                    <Button size="sm" variant="outline" @click="abrirPanel('inversion')">
                                        <Plus class="w-4 h-4 mr-1" />
                                        Inversión
                                    </Button>
                                </div>

                                <div class="h-3 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full transition-all"
                                        :class="bebidas.recupero.pct >= 100 ? 'bg-green-500' : 'bg-yellow-500'"
                                        :style="{ width: Math.max(2, Number(bebidas.recupero.pct)) + '%' }"
                                    />
                                </div>

                                <div class="flex justify-between text-xs text-muted-foreground">
                                    <span>
                                        {{ Number(bebidas.recupero.pct).toFixed(1) }}% recuperado (en ganancia)
                                    </span>

                                    <span v-if="bebidas.recupero.falta > 0">
                                        Faltan {{ fmt(bebidas.recupero.falta) }}
                                    </span>

                                    <span v-else class="text-green-600 font-medium">¡Inversión recuperada!</span>
                                </div>

                                <div class="grid grid-cols-3 gap-2 p-2 rounded bg-muted/30 text-center">
                                    <div>
                                        <p class="text-xs text-muted-foreground">Cobrado</p>
                                        <p class="text-sm font-semibold">{{ fmt(bebidas.recupero.ventas) }}</p>
                                    </div>

                                    <div>
                                        <p class="text-xs text-muted-foreground">Unidades</p>
                                        <p class="text-sm font-semibold">{{ fmtNumero(bebidas.recupero.unidades) }}</p>
                                    </div>

                                    <div>
                                        <p class="text-xs text-muted-foreground">Desde</p>
                                        <p class="text-sm font-semibold">{{ bebidas.recupero.desde ?? '—' }}</p>
                                    </div>
                                </div>

                                <div v-if="bebidas.inversiones.length" class="flex flex-col">
                                    <div
                                        v-for="inv in bebidas.inversiones"
                                        :key="inv.id"
                                        class="flex items-center justify-between text-sm py-1.5 border-b last:border-0"
                                    >
                                        <span class="text-muted-foreground">
                                            {{ inv.fecha }} · {{ inv.descripcion || 'Inversión' }}
                                        </span>

                                        <div class="flex items-center gap-1">
                                            <span class="font-medium mr-2">{{ fmt(inv.monto) }}</span>

                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="h-6 w-6"
                                                @click="abrirPanel('inversion', inv)"
                                            >
                                                <Pencil class="w-3 h-3" />
                                            </Button>

                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="h-6 w-6"
                                                @click="eliminarInversion(inv)"
                                            >
                                                <Trash2 class="w-3 h-3" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Carta -->
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                Carta de bebidas
                            </h3>

                            <Button size="sm" @click="abrirPanel('producto', { categoria_id: bebidas.categoria.id })">
                                <Plus class="w-4 h-4 mr-1" />
                                Nueva bebida
                            </Button>
                        </div>

                        <p class="text-xs text-muted-foreground -mt-1">
                            Los gastos fijos (alquiler, empleados, etc.) se descuentan por día al cerrar la caja,
                            igual que con las hamburguesas.
                        </p>

                        <template v-for="prod in bebidas.productos" :key="prod.id">
                            <Card v-if="!prod.variantes.length">
                                <CardContent class="p-3 flex items-center justify-between">
                                    <span class="font-medium text-sm">{{ prod.nombre }}</span>

                                    <Button size="sm" variant="outline" @click="abrirPanel('variante', { producto_id: prod.id })">
                                        <Plus class="w-4 h-4 mr-1" />
                                        Precio / tamaño
                                    </Button>
                                </CardContent>
                            </Card>

                            <Card
                                v-for="v in prod.variantes"
                                :key="v.id"
                                :class="{ 'opacity-50': !prod.activo || !v.activo }"
                            >
                                <CardContent class="p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <div>
                                            <span class="font-medium text-sm">{{ prod.nombre }}</span>
                                            <span class="text-xs text-muted-foreground"> ({{ v.nombre }})</span>
                                            <span class="font-semibold text-sm ml-2">{{ fmt(v.precio_venta) }}</span>
                                        </div>

                                        <div class="flex items-center gap-1">
                                            <button
                                                @click="abrirReceta(v, prod.id)"
                                                class="text-xs px-2 py-0.5 rounded border border-primary text-primary hover:bg-primary hover:text-primary-foreground transition-colors"
                                            >
                                                Receta
                                            </button>

                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="h-7 w-7"
                                                title="Precio"
                                                @click="abrirPanel('variante', { ...v, producto_id: prod.id })"
                                            >
                                                <Pencil class="w-3 h-3" />
                                            </Button>

                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="h-7 w-7"
                                                @click="toggleActivo('variante', v.id, !v.activo)"
                                            >
                                                <Trash2 class="w-3 h-3" />
                                            </Button>
                                        </div>
                                    </div>

                                    <div class="mt-2 pl-2 flex flex-col text-xs text-muted-foreground font-mono">
                                        <div
                                            v-for="r in v.recetas"
                                            :key="r.id"
                                            class="flex justify-between"
                                        >
                                            <span>{{ r.insumo?.nombre }} {{ fmtNumero(r.cantidad) }} {{ r.insumo?.unidad }}</span>
                                            <span>{{ fmt(costoLinea(r)) }}</span>
                                        </div>
                                    </div>

                                    <div class="mt-2 pt-2 border-t flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                        <span>Costo <b>{{ fmt(v.costo_calculado) }}</b></span>

                                        <span class="text-green-600">
                                            Ganancia <b>{{ fmt(Number(v.precio_venta) - Number(v.costo_calculado)) }}</b>
                                            ({{
                                                Number(v.precio_venta) > 0
                                                    ? ((Number(v.precio_venta) - Number(v.costo_calculado)) / Number(v.precio_venta) * 100).toFixed(0)
                                                    : 0
                                            }}%)
                                        </span>

                                        <span v-if="rendimiento(v)" class="text-muted-foreground">
                                            {{ rendimiento(v) }}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>
                        </template>

                        <!-- Precios de compra -->
                        <h3 class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mt-2">
                            Precios de compra
                        </h3>

                        <Card>
                            <CardContent class="overflow-x-auto p-0">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-xs text-muted-foreground">
                                            <th class="text-left p-3 font-medium">Insumo</th>
                                            <th class="text-left p-3 font-medium">Se compra por</th>
                                            <th class="text-right p-3 font-medium">Precio compra</th>
                                            <th class="text-right p-3 font-medium">Costo unit.</th>
                                            <th class="p-3"></th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <tr
                                            v-for="ins in bebidas.insumos"
                                            :key="ins.id"
                                            class="border-b last:border-0 hover:bg-muted/50"
                                        >
                                            <td class="p-3 font-medium">{{ ins.nombre }}</td>

                                            <td class="p-3 text-muted-foreground">
                                                {{ ins.unidad_compra || ins.unidad }}
                                            </td>

                                            <td class="p-3 text-right">{{ fmt(precioCompra(ins)) }}</td>

                                            <td class="p-3 text-right text-muted-foreground">
                                                {{ fmt(ins.costo_unitario) }} / {{ ins.unidad }}
                                            </td>

                                            <td class="p-3 text-right">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    class="h-7 w-7"
                                                    @click="abrirPanel('compra', ins)"
                                                >
                                                    <Pencil class="w-3 h-3" />
                                                </Button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </template>
                </div>
            </div>

            <!-- PANEL LATERAL -->
            <div v-if="panel" class="panel-movil-fondo" @click="cerrarPanel" />
            <div v-if="panel" class="panel-movil">
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
                                            inversion: 'inversión',
                                            compra: 'precio de compra',
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

                            <div>
                                <label class="text-xs text-muted-foreground">Grupo de separación</label>
                                <select
                                    v-model="fInsumo.grupo_separacion"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                >
                                    <option v-for="[valor, etiqueta] in GRUPOS_SEPARACION" :key="valor" :value="valor">
                                        {{ etiqueta }}
                                    </option>
                                </select>
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

                            <div>
                                <label class="text-xs text-muted-foreground">Negocio</label>
                                <select
                                    v-model="fCategoria.negocio"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                >
                                    <option v-for="n in NEGOCIOS_CATEGORIA" :key="n.value" :value="n.value">
                                        {{ n.label }}
                                    </option>
                                </select>
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
                                <label class="text-xs text-muted-foreground">Negocio</label>
                                <select
                                    v-model="fGasto.negocio"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                >
                                    <option v-for="n in NEGOCIOS_GASTO" :key="n.value" :value="n.value">
                                        {{ n.label }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">¿Cada cuánto se paga?</label>
                                <div class="grid grid-cols-2 gap-2 mt-1">
                                    <Button
                                        type="button"
                                        size="sm"
                                        :variant="fGasto.periodicidad === 'jornada' ? 'default' : 'outline'"
                                        @click="fGasto.periodicidad = 'jornada'"
                                    >
                                        Por jornada
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        :variant="fGasto.periodicidad === 'mensual' ? 'default' : 'outline'"
                                        @click="fGasto.periodicidad = 'mensual'"
                                    >
                                        Por mes
                                    </Button>
                                </div>
                            </div>

                            <div v-if="fGasto.periodicidad === 'jornada'">
                                <label class="text-xs text-muted-foreground">Monto por jornada</label>
                                <input
                                    type="number"
                                    v-model="fGasto.monto_jornada"
                                    min="0"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                                <p class="text-xs text-muted-foreground mt-1">Se descuenta entero cada día que abrís (ej. carbón, parrillero).</p>
                            </div>

                            <div v-else>
                                <label class="text-xs text-muted-foreground">Monto mensual</label>
                                <input
                                    type="number"
                                    v-model="fGasto.monto_mensual"
                                    min="0"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                                <p class="text-xs text-muted-foreground mt-1">Se reparte entre los días que abrís en el mes (ej. alquiler).</p>
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

                        <!-- Inversión -->
                        <template v-if="panel === 'inversion'">
                            <div>
                                <label class="text-xs text-muted-foreground">Monto invertido</label>
                                <input
                                    type="number"
                                    v-model="fInversion.monto"
                                    min="0"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Fecha (la ganancia cuenta desde la primera)</label>
                                <input
                                    type="date"
                                    v-model="fInversion.fecha"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div>
                                <label class="text-xs text-muted-foreground">Descripción (opcional)</label>
                                <input
                                    v-model="fInversion.descripcion"
                                    class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                />
                            </div>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                    Cancelar
                                </Button>

                                <Button
                                    size="sm"
                                    class="flex-1"
                                    @click="guardarInversion"
                                    :disabled="loading || Number(fInversion.monto) <= 0"
                                >
                                    Guardar
                                </Button>
                            </div>
                        </template>

                        <!-- Precio de compra -->
                        <template v-if="panel === 'compra'">
                            <template v-for="ins in insumos.filter((i: any) => i.id === editingId)" :key="ins.id">
                                <p class="text-sm font-medium">{{ ins.nombre }}</p>

                                <div>
                                    <label class="text-xs text-muted-foreground">
                                        Precio por {{ ins.unidad_compra || ins.unidad }}
                                    </label>
                                    <input
                                        type="number"
                                        v-model="fCompra.precio"
                                        min="0"
                                        class="w-full mt-1 text-sm rounded border border-input bg-background px-2 py-1.5"
                                    />
                                </div>

                                <p class="text-xs text-muted-foreground">
                                    Costo por {{ ins.unidad }}:
                                    {{ fmt(Number(fCompra.precio) / (Number(ins.equivalencia_compra) || 1)) }}.
                                    Se recalcula el costo de todos los tragos que lo usan.
                                </p>
                            </template>

                            <div class="flex gap-2">
                                <Button variant="outline" size="sm" class="flex-1" @click="cerrarPanel">
                                    Cancelar
                                </Button>

                                <Button size="sm" class="flex-1" @click="guardarCompra" :disabled="loading">
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