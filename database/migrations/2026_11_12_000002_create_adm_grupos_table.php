<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de usuarios. Un grupo con `es_administrador = 1` tiene acceso
 * universal; no hay identificadores especiales fijos en el código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_grupos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->string('descripcion', 300)->nullable();
            $table->boolean('es_administrador')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_grupos');
    }
};
