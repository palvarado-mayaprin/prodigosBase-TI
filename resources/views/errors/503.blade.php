{{-- Texto fijo: mantenimiento o servicio de autorización no disponible. --}}
@include('errors.plantilla', [
    'codigo' => 503,
    'titulo' => 'Servicio no disponible',
    'mensaje' => 'El servicio no está disponible temporalmente. Intente de nuevo en unos minutos.',
])
