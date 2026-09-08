<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useApi } from '@/composables/useApi';
import AppLayout from '@/layouts/AppLayout.vue';
import { Check, PiggyBank, RefreshCw, X } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

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
    grupos: Grupo[];
    total_general: number;
    historial: HistorialItem[];
}

// ── Estado ───────────────────────────────────────────────────────────────────

const { get, post, loading, error } = useApi();

const data = ref<SeparacionData | null>(null);

// Grupo cuyo panel de confirmación está abierto (sin Dialog/radix).
const grupoActivo = ref<string | null>(null);
const form = ref<{ monto_real: string; observacion: string }>({
    monto_real: '',
    observacion: '',
});

// ── Helpers ──────────────────────────────────────────────────────────────────

const fmt = (n: any) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');

function fecha(d: string | null) {
    if (!d) return '—';

    return new Date(d).toLocaleDateString('es-AR');
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
}

onMounted(cargar);

// ── Reposición ───────────────────────────────────────────────────────────────

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
        data.value = {
            grupos: res.grupos,
            total_general: res.total_general,
            historial: res.historial,
        };
        cancelar();
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Separación', href: '/separacion' }]">
        <div class="flex flex-col gap-4 p-4">
            <!-- Encabezado -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <PiggyBank class="h-5 w-5 text-muted-foreground" />
                    <div>
                        <h1 class="text-lg font-semibold leading-tight">Separación de insumos</h1>
                        <p class="text-xs text-muted-foreground">Plata a apartar por rubro desde la última reposición.</p>
                    </div>
                </div>

                <Button variant="outline" size="sm" :disabled="loading" @click="cargar"> <RefreshCw class="mr-1 h-4 w-4" /> Actualizar </Button>
            </div>

            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

            <!-- Cards por grupo -->
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

            <!-- Total general -->
            <Card>
                <CardContent class="flex items-center justify-between p-4">
                    <div>
                        <p class="mb-1 text-xs text-muted-foreground">Total a separar</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ fmt(data?.total_general ?? 0) }}</p>
                    </div>
                    <p class="max-w-xs text-right text-xs text-muted-foreground">
                        Suma de los {{ data?.grupos?.length ?? 0 }} grupos, desde la última reposición de cada uno.
                    </p>
                </CardContent>
            </Card>

            <!-- Historial -->
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-sm font-medium">Historial de reposiciones</CardTitle>
                </CardHeader>

                <CardContent class="p-0">
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
        </div>
    </AppLayout>
</template>
