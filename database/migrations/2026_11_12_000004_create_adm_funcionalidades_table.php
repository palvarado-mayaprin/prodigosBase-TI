<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de funcionalidades (area.modulo.funcionalidad). Lo llena solo el
 * comando de sincronización del catálogo de permisos; nunca se edita a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_funcionalidades', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 150)->unique();
            $table->string('area', 60);
            $table->string('modulo', 60);
            $table->string('nombre', 150);
            $table->string('descripcion', 300)->nullable();
            $table->string('ruta', 150)->nullable();
            $table->boolean('solo_administrador')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_funcionalidades');
    }
};
