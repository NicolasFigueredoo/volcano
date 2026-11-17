<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { computed } from 'vue';

export interface FilaNegocio {
    ventas: number;
    costo_insumos: number;
    ganancia_bruta: number;
    gastos_fijos: number;
    ganancia_neta: number;
    unidades: number;
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

const total = computed(() => {
    const t: Record<string, number> = { ventas: 0, costo_insumos: 0, ganancia_bruta: 0, gastos_fijos: 0, ganancia_neta: 0 };
    for (const n of Object.keys(props.porNegocio)) {
        for (const k of Object.keys(t)) {
            t[k] += Number((props.porNegocio[n] as any)[k] ?? 0);
        }
    }
    return t;
});

const filas: { key: keyof FilaNegocio; label: string; tono?: 'gasto' | 'neta' }[] = [
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
        <CardContent class="p-0 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-xs text-muted-foreground">
                        <th class="text-left p-3 font-medium"></th>
                        <th v-for="n in columnas" :key="n" class="text-right p-3 font-medium">{{ NOMBRES[n] }}</th>
                        <th class="text-right p-3 font-medium">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in filas" :key="f.key" class="border-b last:border-0">
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
                </tbody>
            </table>
            <p class="px-3 pb-3 pt-1 text-xs text-muted-foreground">
                Compartido = bebidas y envíos, que venden los dos negocios. El alquiler y los gastos compartidos se
                reparten mitad y mitad entre burger y parrilla (si uno no vendió ese día, lo paga el otro).
            </p>
        </CardContent>
    </Card>
</template>
