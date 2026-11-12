<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ConsultaAuditoria;
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
 * Auditoría de Modificaciones (administracion.auditoria.modificaciones,
 * soloAdministrador): consulta y exportación de solo lectura. Funciona
 * también con la escritura bloqueada (solo GET).
 */
class AuditoriaController extends Controller
{
    private const FILTROS = ['desde', 'hasta', 'usuario', 'entidad', 'accion', 'texto', 'orden', 'dir'];

    public function index(Request $request, ConsultaAuditoria $auditoria): Response|RedirectResponse
    {
        try {
            $filtros = $this->filtros($request);
        } catch (ValidationException $e) {
            // A la vista sin filtros: volver atrás repetiría la URL no válida.
            return to_route('admin.auditoria')->withErrors($e->errors());
        }

        return Inertia::render('admin/Auditoria', [
            'filtros' => array_merge(array_fill_keys(self::FILTROS, null), $filtros),
            'registros' => $auditoria->listado($filtros),
            'usuarios' => $auditoria->usuarios(),
            'entidades' => $auditoria->entidades(),
            'acciones' => ConsultaAuditoria::ACCIONES,
        ]);
    }

    public function exportar(Request $request, string $formato, ConsultaAuditoria $auditoria, ExportarTabla $exportar): StreamedResponse|BinaryFileResponse
    {
        $filtros = $this->filtros($request);
        $filas = $auditoria->filasExportacion($filtros);
        $nombre = 'auditoria-'.now()->format('Ymd-His').'.'.$formato;

        return $formato === 'csv'
            ? $exportar->csv($nombre, ConsultaAuditoria::ENCABEZADOS, $filas)
            : $exportar->xlsx($nombre, ConsultaAuditoria::ENCABEZADOS, $filas);
    }

    /** @return array<string, string|null> */
    private function filtros(Request $request): array
    {
        /** @var array<string, string|null> */
        return Arr::except($request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'usuario' => ['nullable', 'string', 'max:50'],
            'entidad' => ['nullable', 'string', 'max:100'],
            'accion' => ['nullable', Rule::in(array_keys(ConsultaAuditoria::ACCIONES))],
            'texto' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'orden' => ['nullable', Rule::in(array_keys(ConsultaAuditoria::ORDEN))],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'date_format' => 'Use el formato AAAA-MM-DD en :attribute.',
            'after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'not_regex' => 'El texto contiene caracteres no permitidos.',
        ], ['desde' => 'la fecha inicial', 'hasta' => 'la fecha final', 'texto' => 'el texto']), 'page');
    }
}
