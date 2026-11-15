@include('errors.plantilla', [
    'codigo' => 429,
    'titulo' => 'Demasiados intentos',
    'mensaje' => 'Se superó el número de intentos permitido. Espere un minuto e intente de nuevo.',
])
