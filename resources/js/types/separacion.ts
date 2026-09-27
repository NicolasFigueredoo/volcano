// Tipos compartidos del módulo de separación de insumos / proveedores.

export interface UnidadesInsumo {
    insumo_id: number;
    nombre: string | null;
    grupo: string;
    proveedor_id: number | null;
    unidad: string | null;
    cantidad: number;
    monto: number;
    unidad_compra: string | null;
    cantidad_compra: number | null;
}

export interface GrupoDia {
    grupo: string;
    label: string;
    monto: number;
    insumos: UnidadesInsumo[];
}

export interface LineaSeparacion {
    tipo: 'proveedor' | 'rubro';
    proveedor_id: number | null;
    grupo: string | null;
    label: string;
    monto: number;
    insumos: UnidadesInsumo[];
}

export interface SeparacionCaja {
    id: number;
    fecha: string | null;
    total: number;
    detalle: { proveedor_id?: number | null; grupo?: string | null; label?: string; monto: number }[];
    usuario: string | null;
}

export interface ResumenDia {
    caja: { id: number; fecha_operativa: string | null; estado: string; antes_del_corte: boolean };
    grupos: GrupoDia[];
    total: number;
    lineas?: LineaSeparacion[];
    separacion?: SeparacionCaja | null;
}

export interface InsumoProveedor {
    id: number;
    nombre: string;
    unidad: string;
    costo_unitario: number;
    stock_actual: number;
    descuenta_stock: boolean;
    grupo_separacion: string;
    unidad_compra: string | null;
    equivalencia_compra: number | null;
}

export interface EstadoProveedor {
    id: number;
    nombre: string;
    telefono: string | null;
    notas: string | null;
    activo: boolean;
    insumos: InsumoProveedor[];
    deuda: number;
    vendido_sin_pagar: number;
    en_stock_sin_pagar: number;
    separado: number;
    falta_separar: number;
    consumo_desde_corte: number;
    ultimo_pago: { fecha: string | null; monto: number } | null;
    unidades_hoy: UnidadesInsumo[];
    unidades_desde_ultimo_pago: UnidadesInsumo[];
}

export interface MovimientoProveedor {
    id: number;
    tipo: 'saldo_inicial' | 'entrega' | 'separacion' | 'pago' | 'ajuste';
    fecha: string;
    monto: number;
    monto_vendido: number | null;
    cantidad: number | null;
    unidad: string | null;
    cantidad_insumo: number | null;
    caja_id: number | null;
    observacion: string | null;
    insumo: { id: number; nombre: string; unidad: string } | null;
    user: { id: number; name: string } | null;
    created_at: string;
}

export interface ResultadoMovimiento {
    movimiento: MovimientoProveedor;
    costo_anterior: number | null;
    costo_nuevo: number | null;
    proveedor: EstadoProveedor;
}

export const fmtPesos = (n: unknown) => '$' + Math.round(Number(n ?? 0)).toLocaleString('es-AR');

const fmtCantidad = (n: number) => Number(n).toLocaleString('es-AR', { maximumFractionDigits: 3 });

/** "16 medallón · 1,6 kg" */
export function fmtUnidades(u: UnidadesInsumo): string {
    let txt = `${fmtCantidad(u.cantidad)} ${u.unidad ?? ''}`.trim();

    if (u.unidad_compra && u.cantidad_compra !== null) {
        txt += ` · ${fmtCantidad(u.cantidad_compra)} ${u.unidad_compra}`;
    }

    return txt;
}
