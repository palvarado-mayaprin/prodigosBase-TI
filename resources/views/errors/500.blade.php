{{-- Texto fijo: nunca se muestra el detalle de la excepción. --}}
@include('errors.plantilla', [
    'codigo' => 500,
    'titulo' => 'Error del servidor',
    'mensaje' => 'Ocurrió un error inesperado. Intente de nuevo más tarde.',
])
