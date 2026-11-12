<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SeguimientoUso;
use App\Support\ExportarTabla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seguimiento de Uso (administracion.seguimiento.uso, soloAdministrador):
 * indicadores, reportes por usuario, módulo y día, historial y exportaciones.
 * Solo lectura: disponible también con la escritura bloqueada.
 */
class SeguimientoController extends Controller
{
    private const FILTROS = ['desde', 'hasta', 'usuario', 'modulo'];

    public function index(Request $request, SeguimientoUso $seguimiento): Response|RedirectResponse
    {
        try {
            $filtros = $this->filtros($request);
        } catch (ValidationException $e) {
            return to_route('admin.seguimiento')->withErrors($e->errors());
        }

        return Inertia::render('admin/Seguimiento', [
            'filtros' => array_merge(array_fill_keys(self::FILTROS, null), $filtros),
            'indicadores' => $seguimiento->indicadores($filtros),
            'porUsuario' => $seguimiento->porUsuario($filtros),
            'porModulo' => $seguimiento->porModulo($filtros),
            'diario' => $seguimiento->diario($filtros),
            'historial' => $seguimiento->historial($filtros),
            'modulos' => SeguimientoUso::modulos(),
        ]);
    }

    public function exportar(Request $request, string $reporte, string $formato, SeguimientoUso $seguimiento, ExportarTabla $exportar): StreamedResponse|BinaryFileResponse
    {
        $filtros = $this->filtros($request);
        $filas = $seguimiento->filasExportacion($reporte, $filtros);
        $encabezados = SeguimientoUso::ENCABEZADOS[$reporte];
        $nombre = "seguimiento-$reporte-".now()->format('Ymd-His').'.'.$formato;

        return $formato === 'csv'
            ? $exportar->csv($nombre, $encabezados, $filas)
            : $exportar->xlsx($nombre, $encabezados, $filas);
    }

    /** @return array<string, string|null> */
    private function filtros(Request $request): array
    {
        /** @var array<string, string|null> */
        return Arr::except($request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'usuario' => ['nullable', 'string', 'max:50', 'not_regex:/[\x00-\x1F\x7F]/'],
            'modulo' => ['nullable', Rule::in(array_keys(SeguimientoUso::modulos()))],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'date_format' => 'Use el formato AAAA-MM-DD en :attribute.',
            'after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'not_regex' => 'El usuario contiene caracteres no permitidos.',
            'in' => 'Seleccione un módulo del catálogo.',
        ], ['desde' => 'la fecha inicial', 'hasta' => 'la fecha final']), 'page');
    }
}
