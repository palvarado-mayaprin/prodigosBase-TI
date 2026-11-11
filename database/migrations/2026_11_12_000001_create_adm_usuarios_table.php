<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas de la aplicación. La contraseña siempre es un hash de Laravel
 * (bcrypt o argon2); nunca texto plano. Las cuentas no se eliminan: se
 * deshabilitan con `activo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adm_usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('usuario', 50)->unique();
            $table->string('nombre', 100);
            $table->string('apellidos', 100);
            $table->string('email', 255)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('password', 255);
            $table->boolean('activo')->default(true);
            $table->boolean('debe_cambiar_password')->default(false);
            $table->dateTime('ultimo_acceso_utc', 3)->nullable();
            $table->timestamps(3);
        });

        // Email opcional pero único. SQL Server solo admite un NULL en un
        // índice único normal: se usa un índice filtrado.
        if (Schema::getConnection()->getDriverName() === 'sqlsrv') {
            Schema::getConnection()->statement('CREATE UNIQUE INDEX adm_usuarios_email_unique ON adm_usuarios (email) WHERE email IS NOT NULL');
        } else {
            Schema::table('adm_usuarios', fn (Blueprint $table) => $table->unique('email'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_usuarios');
    }
};
