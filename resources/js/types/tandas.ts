// Compras de la parrilla (tandas): lo que se pagó por cada carne y cómo se
// va juntando en el sobre con los sanguches vendidos.

export interface Tanda {
    id: number;
    insumo_id: number;
    insumo: string | null;
    unidad: string | null;
    abierta: boolean;
    fecha: string | null;
    cerrada_at: string | null;
    caja_cierre_fecha: string | null;
    usuario: string | null;
    observacion: string | null;
    cantidad: number;
    total: number;
    precio_unidad: number | null;
    porciones_estimadas: number;
    aparta_por_porcion: number;
    porciones_vendidas: number;
    apartado: number;
    falta: number;
    ventas: number;
    otros_insumos: number;
    ganancia: number;
    ganancia_por_porcion: number | null;
    costo_por_porcion: number | null;
    costo_pct: number | null;
    rendimiento: number | null;
}

export interface OpcionTanda {
    id: number;
    nombre: string;
    unidad: string;
    // Sanguches por kg (o por unidad) que rindió la última compra cerrada.
    rendimiento: number | null;
    ultima_compra: { fecha: string | null; cantidad: number; total: number; porciones_estimadas: number } | null;
}

export interface EstadoTandas {
    abiertas: Tanda[];
    historial: Tanda[];
    opciones: OpcionTanda[];
}

export const fmtCant = (n: unknown, max = 2) => Number(n ?? 0).toLocaleString('es-AR', { maximumFractionDigits: max });
