{{-- Muestra el mensaje explícito de abort(403, '...'), redactado por la aplicación. --}}
@include('errors.plantilla', [
    'codigo' => 403,
    'titulo' => 'Acceso denegado',
    'mensaje' => trim((string) $exception->getMessage()) ?: 'No tiene permiso para acceder a esta página.',
])
