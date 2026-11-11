<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por inicio de sesión. La sesión de Laravel vive en archivos; esta
 * tabla registra su ciclo de vida y su revocación (Sesiones Activas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_sesiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('adm_usuarios');
            $table->string('ip', 45)->nullable();
            $table->string('agente', 255)->nullable();
            $table->dateTime('inicio_utc', 3);
            $table->dateTime('ultima_actividad_utc', 3);
            $table->dateTime('cierre_utc', 3)->nullable();
            $table->dateTime('revocada_utc', 3)->nullable();
            $table->foreignId('revocada_por_id')->nullable()->constrained('adm_usuarios');
            $table->index(['usuario_id', 'inicio_utc']);
        });

        if (Schema::getConnection()->getDriverName() === 'sqlsrv') {
            // Sesiones abiertas: lo que consultan el listado y la revocación.
            Schema::getConnection()->statement('CREATE INDEX adm_sesiones_activas_index ON adm_sesiones (usuario_id) INCLUDE (ultima_actividad_utc) WHERE cierre_utc IS NULL AND revocada_utc IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_sesiones');
    }
};
