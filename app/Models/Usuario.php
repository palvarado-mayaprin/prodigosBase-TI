<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Cuenta de la aplicación (adm_usuarios) y proveedor de autenticación.
 *
 * La contraseña es siempre un hash de Laravel (cast `hashed`). No hay
 * «recordarme»: sin columna remember_token. Las cuentas no se eliminan.
 * Las escrituras se hacen desde los servicios administrativos, con
 * DestinoSql::exigirEscritura() y App\Auditoria\Auditor.
 *
 * @property int $id
 * @property string $usuario
 * @property string $nombre
 * @property string $apellidos
 * @property string|null $email
 * @property string|null $telefono
 * @property string $password
 * @property bool $activo
 * @property bool $debe_cambiar_password
 * @property CarbonImmutable|null $ultimo_acceso_utc
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('adm_usuarios')]
#[Hidden(['password'])]
class Usuario extends Authenticatable
{
    /** Sin «recordarme»: Laravel no lee ni escribe remember_token. */
    protected $rememberTokenName = '';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
            'debe_cambiar_password' => 'boolean',
            'ultimo_acceso_utc' => 'immutable_datetime',
        ];
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombre.' '.$this->apellidos);
    }
}
