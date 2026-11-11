<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Seguimiento de uso: una fila por visita a un módulo, con sus segundos activos. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_actividad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_id')->constrained('adm_sesiones');
            $table->string('modulo', 150);
            $table->string('ruta', 255);
            $table->dateTime('visita_utc', 3);
            $table->integer('segundos_activos')->default(0);
            $table->index('sesion_id');
            $table->index('visita_utc');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlsrv') {
            Schema::getConnection()->statement('ALTER TABLE adm_actividad ADD CONSTRAINT adm_actividad_segundos_check CHECK (segundos_activos >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_actividad');
    }
};
