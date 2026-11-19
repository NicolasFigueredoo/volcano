<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Sin efecto: asignaba la carne de la parrilla al proveedor "carne", pero
     * la parrilla tiene sus propios proveedores (Vacio, Bondiola, Chorizo) y
     * se asignan a mano desde Separación → Proveedores.
     */
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
