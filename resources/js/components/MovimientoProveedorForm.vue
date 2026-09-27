<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useApi } from '@/composables/useApi';
import { fmtPesos as fmt, type EstadoProveedor, type MovimientoProveedor, type ResultadoMovimiento } from '@/types/separacion';
import { Check, X } from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';

// Formulario inline para cargar un movimiento en la cuenta de un proveedor
// (saldo inicial, entrega, separación, pago o ajuste).

type Tipo = MovimientoProveedor['tipo'];

const props = withDefaults(defineProps<{ proveedor: EstadoProveedor; tipo?: Tipo; tiposElegibles?: boolean }>(), {
    tipo: 'pago',
    tiposElegibles: false,
});

const emit = defineEmits<{ guardado: [res: ResultadoMovimiento]; cancelar: [] }>();

const { post, loading, error } = useApi();

const TIPOS: { value: Tipo; label: string }[] = [
    { value: 'entrega', label: 'Entrega' },
    { value: 'pago', label: 'Pago' },
    { value: 'separacion', label: 'Separación' },
    { value: 'saldo_inicial', label: 'Saldo inicial' },
    { value: 'ajuste', label: 'Ajuste de deuda' },
];

// Fecha local (toISOString da UTC y después de las 21 hs ya sería mañana).
const hoy = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const form = reactive({
    tipo: props.tipo as Tipo,
    fecha: hoy(),
    monto: '' as number | '',
    monto_vendido: '' as number | '',
    insumo_id: '' as number | '',
    cantidad: '' as number | '',
    unidad: '',
    actualizar_costo: true,
    observacion: '',
});

watch(
    () => props.tipo,
    (t) => (form.tipo = t),
);

const insumo = computed(() => props.proveedor.insumos.find((i) => i.id === form.insumo_id) ?? null);

const unidades = computed(() => {
    if (!insumo.value) return [];
    const lista = [insumo.value.unidad];
    if (insumo.value.unidad_compra && insumo.value.equivalencia_compra) lista.unshift(insumo.value.unidad_compra);
    return lista;
});

watch(insumo, (i) => {
    form.unidad = i ? (unidades.value[0] ?? i.unidad) : '';
});

if (props.proveedor.insumos.length === 1) form.insumo_id = props.proveedor.insumos[0].id;

// Cantidad en la unidad del insumo y costo unitario resultante de la entrega.
const cantidadInsumo = computed(() => {
    const i = insumo.value;
    const c = Number(form.cantidad) || 0;
    if (!i) return 0;
    return form.unidad === i.unidad_compra && i.equivalencia_compra ? c * Number(i.equivalencia_compra) : c;
});

const costoEntrega = computed(() => (cantidadInsumo.value > 0 ? (Number(form.monto) || 0) / cantidadInsumo.value : null));

const ayuda = computed(
    () =>
        ({
            entrega: 'Suma a la deuda y al stock.',
            pago: 'Resta de la deuda y de lo separado.',
            separacion: 'Plata apartada para este proveedor.',
            saldo_inicial: 'Deuda con la que arrancás en el corte.',
            ajuste: 'Corrige la deuda: positivo la sube, negativo la baja.',
        })[form.tipo],
);

async function guardar() {
    const body: Record<string, unknown> = {
        tipo: form.tipo,
        fecha: form.fecha || undefined,
        monto: form.monto === '' ? undefined : Number(form.monto),
        observacion: form.observacion.trim() || undefined,
    };

    if (form.tipo === 'saldo_inicial' && form.monto_vendido !== '') {
        body.monto_vendido = Number(form.monto_vendido);
    }

    if (form.tipo === 'entrega' && form.insumo_id !== '') {
        body.insumo_id = form.insumo_id;
        body.cantidad = form.cantidad === '' ? undefined : Number(form.cantidad);
        body.unidad = form.unidad || undefined;
        body.actualizar_costo = form.actualizar_costo;
    }

    const res = await post<ResultadoMovimiento>(`/api/proveedores/${props.proveedor.id}/movimientos`, body);

    if (res) emit('guardado', res);
}

const inputClass = 'mt-1 w-full rounded border border-input bg-background px-2 py-1.5 text-sm';
</script>

<template>
    <div class="flex flex-col gap-2 rounded border bg-muted/40 p-3">
        <div v-if="tiposElegibles" class="flex flex-wrap gap-1">
            <button
                v-for="t in TIPOS"
                :key="t.value"
                class="rounded border px-2 py-1 text-xs"
                :class="form.tipo === t.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                @click="form.tipo = t.value"
            >
                {{ t.label }}
            </button>
        </div>
        <p v-else class="text-sm font-medium">{{ TIPOS.find((t) => t.value === form.tipo)?.label }} · {{ proveedor.nombre }}</p>

        <p class="text-xs text-muted-foreground">{{ ayuda }}</p>

        <!-- Entrega: insumo + cantidad -->
        <template v-if="form.tipo === 'entrega'">
            <div>
                <label class="text-xs text-muted-foreground">Insumo</label>
                <select v-model.number="form.insumo_id" :class="inputClass">
                    <option value="">— sin insumo (solo monto) —</option>
                    <option v-for="i in proveedor.insumos" :key="i.id" :value="i.id">{{ i.nombre }}</option>
                </select>
            </div>

            <div v-if="insumo" class="grid grid-cols-2 gap-2">
                <div>
                    <label class="text-xs text-muted-foreground">Cantidad</label>
                    <input v-model.number="form.cantidad" type="number" min="0" step="0.001" :class="inputClass" />
                </div>
                <div>
                    <label class="text-xs text-muted-foreground">Unidad</label>
                    <select v-model="form.unidad" :class="inputClass">
                        <option v-for="u in unidades" :key="u" :value="u">{{ u }}</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="text-xs text-muted-foreground">Monto{{ form.tipo === 'ajuste' ? ' (±)' : '' }}</label>
                <input v-model.number="form.monto" type="number" step="1" :min="form.tipo === 'ajuste' ? undefined : 0" :class="inputClass" />
            </div>
            <div>
                <label class="text-xs text-muted-foreground">Fecha</label>
                <input v-model="form.fecha" type="date" :class="inputClass" />
            </div>
        </div>

        <div v-if="form.tipo === 'saldo_inicial'">
            <label class="text-xs text-muted-foreground">De eso, ¿cuánto era mercadería ya vendida?</label>
            <input v-model.number="form.monto_vendido" type="number" min="0" step="1" placeholder="0" :class="inputClass" />
        </div>

        <!-- Entrega: comparar precio con el costo cargado -->
        <div v-if="form.tipo === 'entrega' && insumo && costoEntrega !== null" class="rounded border bg-background p-2 text-xs">
            <p>
                {{ fmt(costoEntrega) }} por {{ insumo.unidad }} (cargado hoy: {{ fmt(insumo.costo_unitario) }})
                <span
                    v-if="Math.round(costoEntrega) !== Math.round(Number(insumo.costo_unitario))"
                    :class="costoEntrega > Number(insumo.costo_unitario) ? 'text-destructive' : 'text-emerald-600'"
                >
                    · {{ costoEntrega > Number(insumo.costo_unitario) ? '+' : '-' }}{{ fmt(Math.abs(costoEntrega - Number(insumo.costo_unitario))) }}
                </span>
            </p>
            <label class="mt-1 flex items-center gap-2">
                <input v-model="form.actualizar_costo" type="checkbox" class="h-4 w-4" />
                Actualizar el costo del insumo y recalcular las recetas
            </label>
        </div>

        <div>
            <label class="text-xs text-muted-foreground">Observación{{ form.tipo === 'ajuste' ? '' : ' (opcional)' }}</label>
            <textarea v-model="form.observacion" rows="2" :class="inputClass"></textarea>
        </div>

        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>

        <div class="flex gap-2">
            <Button size="sm" :disabled="loading" @click="guardar"> <Check class="mr-1 h-4 w-4" /> Guardar </Button>
            <Button variant="outline" size="sm" @click="emit('cancelar')"> <X class="mr-1 h-4 w-4" /> Cancelar </Button>
        </div>
    </div>
</template>
