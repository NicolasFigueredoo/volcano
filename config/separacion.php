<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fecha de corte
    |--------------------------------------------------------------------------
    |
    | La separación por proveedor/rubro solo cuenta ventas de cajas con
    | fecha operativa igual o posterior a esta fecha. Lo anterior se contó
    | a mano.
    |
    */

    'fecha_corte' => env('SEPARACION_FECHA_CORTE', '2026-09-28'),

];
