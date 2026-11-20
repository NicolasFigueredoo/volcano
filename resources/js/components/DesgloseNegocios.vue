<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { computed } from 'vue';

export interface GastoDetalle {
    nombre: string;
    monto: number;
    compartido: boolean;
}

export interface FilaNegocio {
    ventas: number;
    costo_insumos: number;
    ganancia_bruta: number;
    gastos_fijos: number;
    ganancia_neta: number;
    unidades: number;
    gastos_detalle?: GastoDetalle[];
}

const props = defineProps<{
    porNegocio: Record<string, FilaNegocio>;
}>();

const fmt = (n: any) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');

const NOMBRES: Record<string, string> = {
    burger: 'Burger',
    parrilla: 'Parrilla',
    general: 'Compartido',
};

// Solo se muestran los negocios que vendieron o tienen gastos ese día.
const columnas = computed(() =>
    Object.keys(NOMBRES).filter((n) => {
        const f = props.porNegocio[n];
        return f && (Number(f.ventas) !== 0 || Number(f.gastos_fijos) !== 0);
    }),
);

type Clave = 'ventas' | 'costo_insumos' | 'ganancia_bruta' | 'gastos_fijos' | 'ganancia_neta';

const total = computed(() => {
    const t: Record<Clave, number> = { ventas: 0, costo_insumos: 0, ganancia_bruta: 0, gastos_fijos: 0, ganancia_neta: 0 };
    for (const n of Object.keys(props.porNegocio)) {
        for (const k of Object.keys(t) as Clave[]) {
            t[k] += Number(props.porNegocio[n][k] ?? 0);
        }
    }
    return t;
});

// Cada gasto fijo con lo que le toca a cada negocio, para mostrarlo abajo de "Gastos fijos".
const gastos = computed(() => {
    const mapa = new Map<string, { nombre: string; compartido: boolean; por: Record<string, number>; total: number }>();
    for (const n of Object.keys(props.porNegocio)) {
        for (const g of props.porNegocio[n].gastos_detalle ?? []) {
            const item = mapa.get(g.nombre) ?? { nombre: g.nombre, compartido: g.compartido, por: {} as Record<string, number>, total: 0 };
            item.por[n] = (item.por[n] ?? 0) + Number(g.monto);
            item.total += Number(g.monto);
            mapa.set(g.nombre, item);
        }
    }
    return [...mapa.values()].sort((a, b) => b.total - a.total);
});

// Tarjetas del celular: una por negocio y una con el total.
type Tarjeta = { key: string; nombre: string; valores: Record<Clave, number>; gastos: GastoDetalle[] };

const tarjetas = computed((): Tarjeta[] => [
    ...columnas.value.map((n) => ({
        key: n,
        nombre: NOMBRES[n],
        valores: props.porNegocio[n] as Record<Clave, number>,
        gastos: [...(props.porNegocio[n].gastos_detalle ?? [])]
            .map((g) => ({ nombre: g.nombre, monto: Number(g.monto), compartido: g.compartido }))
            .sort((a, b) => b.monto - a.monto),
    })),
    {
        key: 'total',
        nombre: 'Total',
        valores: total.value,
        gastos: gastos.value.map((g) => ({ nombre: g.nombre, monto: g.total, compartido: false })),
    },
]);

const filas: { key: Clave; label: string; tono?: 'gasto' | 'neta' }[] = [
    { key: 'ventas', label: 'Ventas' },
    { key: 'costo_insumos', label: 'Costo insumos', tono: 'gasto' },
    { key: 'ganancia_bruta', label: 'Ganancia bruta' },
    { key: 'gastos_fijos', label: 'Gastos fijos', tono: 'gasto' },
    { key: 'ganancia_neta', label: 'Ganancia neta', tono: 'neta' },
];

function clase(tono: string | undefined, valor: number) {
    if (tono === 'gasto') return 'text-destructive';
    if (tono === 'neta') return valor >= 0 ? 'text-green-600 font-semibold' : 'text-destructive font-semibold';
    return '';
}
</script>

<template>
    <Card v-if="columnas.length">
        <CardHeader class="pb-2">
            <CardTitle class="text-sm">Por negocio</CardTitle>
        </CardHeader>
        <CardContent class="p-0">
            <!-- Desktop / tablet: tabla -->
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="p-3 text-left font-medium"></th>
                            <th v-for="n in columnas" :key="n" class="p-3 text-right font-medium">{{ NOMBRES[n] }}</th>
                            <th class="p-3 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="f in filas" :key="f.key">
                            <tr :class="f.key === 'gastos_fijos' && gastos.length ? '' : 'border-b last:border-0'">
                                <td class="p-3 text-muted-foreground">{{ f.label }}</td>
                                <td
                                    v-for="n in columnas"
                                    :key="n"
                                    class="p-3 text-right"
                                    :class="clase(f.tono, Number(porNegocio[n][f.key]))"
                                >
                                    {{ fmt(porNegocio[n][f.key]) }}
                                </td>
                                <td class="p-3 text-right font-medium" :class="clase(f.tono, total[f.key])">
                                    {{ fmt(total[f.key]) }}
                                </td>
                            </tr>
                            <template v-if="f.key === 'gastos_fijos'">
                                <tr
                                    v-for="(g, i) in gastos"
                                    :key="g.nombre"
                                    class="text-xs text-muted-foreground"
                                    :class="i === gastos.length - 1 ? 'border-b' : ''"
                                >
                                    <td class="py-1.5 pl-7 pr-3" :class="i === gastos.length - 1 ? 'pb-3' : ''">
                                        {{ g.nombre }}
                                        <span v-if="g.compartido" class="ml-1 rounded bg-muted px-1.5 py-0.5 text-[10px]">compartido</span>
                                    </td>
                                    <td
                                        v-for="n in columnas"
                                        :key="n"
                                        class="px-3 py-1.5 text-right"
                                        :class="i === gastos.length - 1 ? 'pb-3' : ''"
                                    >
                                        {{ g.por[n] ? fmt(g.por[n]) : '—' }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right" :class="i === gastos.length - 1 ? 'pb-3' : ''">
                                        {{ fmt(g.total) }}
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Celular: una tarjeta por negocio -->
            <div class="space-y-3 px-3 md:hidden">
                <div v-for="t in tarjetas" :key="t.key" class="rounded-lg border p-3">
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <span class="font-semibold">{{ t.nombre }}</span>
                        <span class="text-sm" :class="clase('neta', Number(t.valores.ganancia_neta))">
                            {{ fmt(t.valores.ganancia_neta) }}
                        </span>
                    </div>
                    <dl class="space-y-1 text-sm">
                        <template v-for="f in filas" :key="f.key">
                            <div class="flex justify-between gap-3" :class="f.key === 'ganancia_neta' ? 'mt-1 border-t pt-1' : ''">
                                <dt class="text-muted-foreground">{{ f.label }}</dt>
                                <dd class="text-right" :class="clase(f.tono, Number(t.valores[f.key]))">
                                    {{ fmt(t.valores[f.key]) }}
                                </dd>
                            </div>
                            <template v-if="f.key === 'gastos_fijos'">
                                <div
                                    v-for="g in t.gastos"
                                    :key="g.nombre"
                                    class="flex justify-between gap-3 pl-3 text-xs text-muted-foreground"
                                >
                                    <dt class="truncate">
                                        {{ g.nombre }}<span v-if="g.compartido"> (su parte)</span>
                                    </dt>
                                    <dd class="shrink-0">{{ fmt(g.monto) }}</dd>
                                </div>
                            </template>
                        </template>
                    </dl>
                </div>
            </div>

            <p class="px-3 pb-3 pt-2 text-xs text-muted-foreground">
                Compartido = envíos (el delivery lo usan los dos negocios). El alquiler y los gastos compartidos se
                reparten mitad y mitad entre burger y parrilla (si uno no vendió ese día, lo paga el otro).
            </p>
        </CardContent>
    </Card>
</template>
