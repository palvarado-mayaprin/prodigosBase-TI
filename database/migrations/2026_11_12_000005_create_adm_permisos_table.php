<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Acciones de cada funcionalidad. `enum` crea un CHECK en SQL Server y en SQLite. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_permisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funcionalidad_id')->constrained('adm_funcionalidades');
            $table->enum('accion', ['VER', 'CREAR', 'EDITAR', 'ELIMINAR']);
            $table->unique(['funcionalidad_id', 'accion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_permisos');
    }
};
