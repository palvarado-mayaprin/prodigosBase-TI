{{-- Texto fijo: el mensaje de Laravel puede citar la ruta solicitada. --}}
@include('errors.plantilla', [
    'codigo' => 404,
    'titulo' => 'Página no encontrada',
    'mensaje' => 'La página o el registro solicitado no existe.',
])
