<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría administrativa. La escribe solo App\Auditoria\Auditor, dentro de
 * la transacción del cambio. `cambios` es JSON sin contraseñas ni secretos.
 * La aplicación solo puede insertar y consultar (database/sql/roles.sql).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlsrv = Schema::getConnection()->getDriverName() === 'sqlsrv';

        Schema::create('adm_auditoria', function (Blueprint $table) use ($sqlsrv) {
            $table->id();
            $table->dateTime('fecha_utc', 3)
                ->default(new Expression($sqlsrv ? 'SYSUTCDATETIME()' : 'CURRENT_TIMESTAMP'));
            // Sin usuario cuando actúa un comando de consola.
            $table->foreignId('usuario_id')->nullable()->constrained('adm_usuarios');
            // Copia del nombre en el momento del cambio.
            $table->string('usuario', 50)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('entidad', 100);
            $table->string('registro', 200)->nullable();
            $table->enum('accion', ['CREAR', 'EDITAR', 'HABILITAR', 'DESHABILITAR', 'ASIGNAR', 'RETIRAR', 'REVOCAR']);
            $table->json('cambios')->nullable();
            $table->index('fecha_utc');
            $table->index(['entidad', 'fecha_utc']);
            $table->index(['usuario_id', 'fecha_utc']);
        });

        if ($sqlsrv) {
            // ISJSON exige SQL Server 2016 y nivel de compatibilidad 130 o superior.
            Schema::getConnection()->statement('ALTER TABLE adm_auditoria ADD CONSTRAINT adm_auditoria_cambios_json CHECK (cambios IS NULL OR ISJSON(cambios) = 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('adm_auditoria');
    }
};
