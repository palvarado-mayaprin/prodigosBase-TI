<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Proveedor de autenticación provisional de Fortify.
 *
 * La Etapa 4 lo sustituye por el modelo propio de `adm_usuarios`. Todavía no
 * tiene tabla: las migraciones del starter kit se eliminaron.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 */
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
