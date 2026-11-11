<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Membresías por identificador. Sin borrado en cascada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_usuarios_grupos', function (Blueprint $table) {
            $table->foreignId('usuario_id')->constrained('adm_usuarios');
            $table->foreignId('grupo_id')->constrained('adm_grupos');
            $table->dateTime('created_at', 3)->nullable();
            $table->primary(['usuario_id', 'grupo_id']);
            $table->index('grupo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_usuarios_grupos');
    }
};
