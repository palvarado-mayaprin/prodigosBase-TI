<?php

/*
 * Mensajes de validación en español para las reglas que usa la
 * administración. Una regla sin traducción usa el mensaje en inglés.
 */
return [
    'boolean' => 'Valor no válido para :attribute.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'email' => 'Ingrese un correo electrónico válido.',
    'integer' => ':Attribute debe ser un número entero.',
    'max' => [
        'string' => ':Attribute admite como máximo :max caracteres.',
    ],
    'min' => [
        'string' => ':Attribute debe tener al menos :min caracteres.',
    ],
    'prohibited' => ':Attribute no se puede modificar.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'Indique :attribute.',
    'string' => 'Ingrese texto en :attribute.',
    'unique' => ':Attribute ya está en uso.',

    'password' => [
        'letters' => ':Attribute debe incluir al menos una letra.',
        'mixed' => ':Attribute debe incluir mayúsculas y minúsculas.',
        'numbers' => ':Attribute debe incluir al menos un número.',
        'symbols' => ':Attribute debe incluir al menos un símbolo.',
        'uncompromised' => ':Attribute aparece en una filtración de datos. Elija otra.',
    ],

    'attributes' => [
        'usuario' => 'el usuario',
        'nombre' => 'el nombre',
        'apellidos' => 'los apellidos',
        'email' => 'el correo',
        'telefono' => 'el teléfono',
        'password' => 'la contraseña',
        'contrasena_actual' => 'la contraseña actual',
        'activo' => 'el estado',
        'grupo' => 'el grupo',
    ],
];
