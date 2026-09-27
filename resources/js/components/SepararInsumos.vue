<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useApi } from '@/composables/useApi';
import { fmtPesos as fmt, fmtUnidades, type ResumenDia } from '@/types/separacion';
import { Check, PiggyBank, X } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

// Modal "Separar insumos" de una caja: lista lo que hay que apartar por
// proveedor/rubro según su venta_insumos y genera las separaciones.

const props = defineProps<{ cajaId?: number | null }>();
const emit = defineEmits<{ close: []; confirmada: [] }>();

const { get, post, loading, error } = useApi();

const dia = ref<ResumenDia | null>(null);
const filas = ref<{ key: string; incluir: boolean; monto: number }[]>([]);

const total = computed(() => filas.value.reduce((acc, f) => acc + (f.incluir ? Number(f.monto) || 0 : 0), 0));

function cargarFilas() {
    filas.value = (dia.value?.lineas ?? []).map((l) => ({
        key: l.proveedor_id ? `p${l.proveedor_id}` : `g${l.grupo}`,
        incluir: true,
        monto: Math.round(l.monto),
    }));
}

onMounted(async () => {
    const url = props.cajaId ? `/api/separacion/dia?caja_id=${props.cajaId}` : '/api/separacion/dia';

    dia.value = await get<ResumenDia>(url);
    cargarFilas();
});

async function confirmar() {
    if (!dia.value) return;

    const lineas = (dia.value.lineas ?? [])
        .map((l, i) => ({
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
                    <div v-for="(l, i) in dia.separacion.detalle" :key="i" class="flex justify-between text-sm">
                        <span>{{ l.label ?? l.grupo }}</span>
                        <span class="tabular-nums">{{ fmt(l.monto) }}</span>
                    </div>
                    <div class="flex justify-between border-t pt-2 font-semibold">
                        <span>Total separado</span>
                        <span class="tabular-nums">{{ fmt(dia.separacion.total) }}</span>
                    </div>
                </template>

                <!-- Para separar -->
                <template v-else-if="dia">
                    <p v-if="!(dia.lineas ?? []).length" class="text-sm text-muted-foreground">Esta caja no tiene insumos vendidos para separar.</p>

                    <div v-for="(l, i) in dia.lineas ?? []" :key="filas[i]?.key" class="flex items-start gap-3 rounded border p-3">
                        <input v-model="filas[i].incluir" type="checkbox" class="mt-1 h-4 w-4" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">
                                {{ l.label }}
                                <span class="text-xs font-normal text-muted-foreground">{{ l.tipo === 'proveedor' ? 'proveedor' : 'rubro' }}</span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <template v-for="(u, j) in l.insumos" :key="u.insumo_id">
                                    {{ j > 0 ? ' · ' : '' }}{{ u.nombre }}: {{ fmtUnidades(u) }}
                                </template>
                            </p>
                            <p class="text-xs text-muted-foreground">Calculado: {{ fmt(l.monto) }}</p>
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
