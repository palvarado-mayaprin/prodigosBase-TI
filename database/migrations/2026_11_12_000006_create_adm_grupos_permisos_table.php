<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Permisos asignados a cada grupo. Sin borrado en cascada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_grupos_permisos', function (Blueprint $table) {
            $table->foreignId('grupo_id')->constrained('adm_grupos');
            $table->foreignId('permiso_id')->constrained('adm_permisos');
            $table->dateTime('created_at', 3)->nullable();
            $table->primary(['grupo_id', 'permiso_id']);
            $table->index('permiso_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_grupos_permisos');
    }
};
