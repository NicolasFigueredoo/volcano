<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useApi } from '@/composables/useApi';
import { fmtPesos as fmt, fmtUnidades, NOMBRES_NEGOCIO, type ResumenDia } from '@/types/separacion';
import { Check, Flame, PiggyBank, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

// Modal "Separar insumos" de una caja: lista lo que hay que apartar por
// negocio (un sobre para la burger y otro para la parrilla) y, dentro de cada
// uno, por proveedor/rubro según su venta_insumos.

const props = defineProps<{ cajaId?: number | null }>();
const emit = defineEmits<{ close: []; confirmada: [] }>();

const { get, post, loading, error } = useApi();

const dia = ref<ResumenDia | null>(null);
const filas = ref<{ key: string; incluir: boolean; monto: number }[]>([]);

const total = computed(() => filas.value.reduce((acc, f) => acc + (f.incluir ? Number(f.monto) || 0 : 0), 0));

const nombreNegocio = (n?: string | null) => NOMBRES_NEGOCIO[n ?? 'burger'] ?? n ?? 'Burger';

// Bloques por negocio con el índice de cada línea (filas[i] va en paralelo a lineas[i]).
const bloques = computed(() => {
    const out: { negocio: string; indices: number[] }[] = [];
    (dia.value?.lineas ?? []).forEach((l, i) => {
        const negocio = l.negocio ?? 'burger';
        let b = out.find((x) => x.negocio === negocio);
        if (!b) out.push((b = { negocio, indices: [] }));
        b.indices.push(i);
    });
    return out;
});

const subtotal = (indices: number[]) =>
    indices.reduce((acc, i) => acc + (filas.value[i]?.incluir ? Number(filas.value[i].monto) || 0 : 0), 0);

// Lo ya separado, agrupado por negocio.
const separadoPorNegocio = computed(() => {
    const out: { negocio: string; lineas: { label: string; monto: number }[]; total: number }[] = [];
    for (const l of dia.value?.separacion?.detalle ?? []) {
        const negocio = l.negocio ?? 'burger';
        let b = out.find((x) => x.negocio === negocio);
        if (!b) out.push((b = { negocio, lineas: [], total: 0 }));
        b.lineas.push({ label: l.label ?? l.grupo ?? '', monto: Number(l.monto) });
        b.total += Number(l.monto);
    }
    return out;
});

function cargarFilas() {
    filas.value = (dia.value?.lineas ?? []).map((l) => ({
        key: `${l.negocio ?? 'burger'}-${l.proveedor_id ? `p${l.proveedor_id}` : `g${l.grupo}`}`,
        incluir: true,
        monto: Math.round(l.monto),
    }));
}

const url = () => (props.cajaId ? `/api/separacion/dia?caja_id=${props.cajaId}` : '/api/separacion/dia');

onMounted(async () => {
    dia.value = await get<ResumenDia>(url());
    cargarFilas();
});

// ── Carnes de parrilla: ¿sigue o se terminó? ─────────────────────────────────
const siguen = ref<number[]>([]);
const tandasPendientes = computed(() => (dia.value?.tandas_abiertas ?? []).filter((t) => !siguen.value.includes(t.id)));

async function terminarTanda(id: number) {
    if (!(await post(`/api/tandas/${id}/cerrar`, {}))) return;

    // Se vuelve a pedir el día: la línea de esa carne ya trae el ajuste.
    const nuevo = await get<ResumenDia>(url());
    if (nuevo) {
        dia.value = nuevo;
        cargarFilas();
    }
}

async function confirmar() {
    if (!dia.value) return;

    const lineas = (dia.value.lineas ?? [])
        .map((l, i) => ({
            negocio: l.negocio ?? 'burger',
            proveedor_id: l.proveedor_id,
            grupo: l.grupo,
            label: l.label,
            monto: filas.value[i].incluir ? Number(filas.value[i].monto) || 0 : 0,
        }))
        .filter((l) => l.monto > 0);

    if (!lineas.length) return;

    const res = await post<ResumenDia>(`/api/separacion/caja/${dia.value.caja.id}/confirmar`, { lineas });

    if (res) {
        dia.value = res;
        emit('confirmada');
    }
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border bg-background text-foreground">
            <div class="flex items-center justify-between border-b p-4">
                <div class="flex items-center gap-2">
                    <PiggyBank class="h-5 w-5 text-muted-foreground" />
                    <div>
                        <h2 class="text-lg font-semibold leading-tight">Separar insumos</h2>
                        <p v-if="dia" class="text-xs text-muted-foreground">
                            Caja del {{ dia.caja.fecha_operativa ? new Date(dia.caja.fecha_operativa + 'T12:00').toLocaleDateString('es-AR') : '—' }}
                        </p>
                    </div>
                </div>
                <button class="flex h-8 w-8 items-center justify-center rounded-full border hover:bg-muted" @click="emit('close')">
                    <X class="h-4 w-4" />
                </button>
            </div>

            <div class="flex flex-col gap-3 p-4">
                <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
                <p v-if="!dia && loading" class="text-sm text-muted-foreground">Cargando…</p>

                <!-- Ya separada -->
                <template v-if="dia?.separacion">
                    <p class="rounded border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-700 dark:text-emerald-400">
                        Esta caja ya se separó el {{ new Date(dia.separacion.fecha ?? '').toLocaleString('es-AR') }}
                        <template v-if="dia.separacion.usuario"> por {{ dia.separacion.usuario }}</template>.
                    </p>
                    <div v-for="b in separadoPorNegocio" :key="b.negocio" class="flex flex-col gap-1">
                        <p v-if="separadoPorNegocio.length > 1" class="text-xs font-semibold uppercase text-muted-foreground">
                            Sobre {{ nombreNegocio(b.negocio) }}
                        </p>
                        <div v-for="(l, i) in b.lineas" :key="i" class="flex justify-between text-sm">
                            <span>{{ l.label }}</span>
                            <span class="tabular-nums">{{ fmt(l.monto) }}</span>
                        </div>
                        <div v-if="separadoPorNegocio.length > 1" class="flex justify-between text-sm font-medium">
                            <span>Total {{ nombreNegocio(b.negocio) }}</span>
                            <span class="tabular-nums">{{ fmt(b.total) }}</span>
                        </div>
                    </div>
                    <div class="flex justify-between border-t pt-2 font-semibold">
                        <span>Total separado</span>
                        <span class="tabular-nums">{{ fmt(dia.separacion.total) }}</span>
                    </div>
                </template>

                <!-- Para separar -->
                <template v-else-if="dia">
                    <p
                        v-if="dia.caja.antes_del_corte && (dia.lineas ?? []).length"
                        class="rounded border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400"
                    >
                        Esta caja es anterior a la fecha de corte: sus ventas no suman en los proveedores. Si ya la contaste a mano, no la separes
                        acá para no contarla dos veces.
                    </p>

                    <div
                        v-if="(dia.tandas_abiertas ?? []).length"
                        class="flex flex-col gap-2 rounded-lg border border-orange-500/40 bg-orange-500/5 p-3"
                    >
                        <p class="flex items-center gap-1.5 text-sm font-semibold">
                            <Flame class="h-4 w-4 text-orange-500" /> ¿Queda carne de la parrilla?
                        </p>
                        <p v-if="!tandasPendientes.length" class="text-xs text-muted-foreground">Listo, todas siguen para la próxima jornada.</p>
                        <div v-for="t in tandasPendientes" :key="t.id" class="flex flex-wrap items-center gap-2 rounded border bg-background p-2">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">{{ t.insumo }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ t.porciones_vendidas }} de ~{{ t.porciones_estimadas }} vendidos · en el sobre {{ fmt(t.apartado) }} de {{ fmt(t.total) }}
                                </p>
                            </div>
                            <div class="flex gap-1.5">
                                <Button variant="outline" size="sm" @click="siguen.push(t.id)">Sigue</Button>
                                <Button size="sm" variant="destructive" :disabled="loading" @click="terminarTanda(t.id)">Se terminó</Button>
                            </div>
                        </div>
                    </div>

                    <p v-if="!(dia.lineas ?? []).length" class="text-sm text-muted-foreground">Esta caja no tiene insumos vendidos para separar.</p>

                    <div v-for="b in bloques" :key="b.negocio" class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold">Sobre {{ nombreNegocio(b.negocio) }}</p>
                            <p class="text-sm font-semibold tabular-nums">{{ fmt(subtotal(b.indices)) }}</p>
                        </div>
                        <div v-for="i in b.indices" :key="filas[i]?.key" class="flex items-start gap-3 rounded border p-3">
                            <input v-model="filas[i].incluir" type="checkbox" class="mt-1 h-4 w-4" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">
                                    {{ dia.lineas![i].label }}
                                    <span class="text-xs font-normal text-muted-foreground">{{ dia.lineas![i].tipo === 'proveedor' ? 'proveedor' : 'rubro' }}</span>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    <template
                                        v-for="(u, j) in dia.lineas![i].insumos"
                                        :key="u.ajuste ? `t${u.tanda_id}` : (u.insumo_id ?? `g${u.gasto_fijo_id}`)"
                                    >
                                        {{ j > 0 ? ' · ' : '' }}{{ u.nombre }}: {{ fmtUnidades(u) }}
                                    </template>
                                </p>
                                <p class="text-xs text-muted-foreground">Calculado: {{ fmt(dia.lineas![i].monto) }}</p>
                            </div>
                            <input
                                v-model.number="filas[i].monto"
                                type="number"
                                min="0"
                                step="1"
                                :disabled="!filas[i].incluir"
                                class="w-28 rounded border border-input bg-background px-2 py-1.5 text-right text-sm tabular-nums disabled:opacity-50"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t pt-3">
                        <span class="text-sm text-muted-foreground">Total a apartar</span>
                        <span class="text-xl font-semibold tabular-nums">{{ fmt(total) }}</span>
                    </div>

                    <div class="flex justify-end gap-2">
                        <Button variant="outline" size="sm" @click="emit('close')">Más tarde</Button>
                        <Button size="sm" :disabled="loading || total <= 0" @click="confirmar">
                            <Check class="mr-1 h-4 w-4" /> Confirmar separación
                        </Button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
