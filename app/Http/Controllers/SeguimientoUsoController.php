<?php

namespace App\Http\Controllers;

use App\Services\Admin\SeguimientoUso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Recepción del registro de uso (resources/js/lib/seguimientoUso.ts). Solo
 * cuentas autenticadas con su sesión vigente (ValidarSesion); la sesión y el
 * usuario salen del servidor, nunca del navegador. Son escrituras: con la
 * escritura bloqueada ControlarAcceso las rechaza y no se registra uso.
 *
 * Llegan en segundo plano (p. ej. al ocultar la pestaña, entre una
 * redirección y la página siguiente): conservan los datos flash para la
 * petición que realmente los muestra.
 */
class SeguimientoUsoController extends Controller
{
    public function visita(Request $request, SeguimientoUso $seguimiento): JsonResponse|Response
    {
        $request->session()->reflash();
        $datos = $request->validate(['ruta' => ['required', 'string', 'max:255', 'starts_with:/', 'not_regex:/[\x00-\x1F\x7F]/']]);

        $visita = $seguimiento->registrarVisita($request, $datos['ruta']);

        return $visita === null ? response()->noContent() : response()->json(['visita' => $visita]);
    }

    public function actividad(Request $request, SeguimientoUso $seguimiento): Response
    {
        $request->session()->reflash();
        $datos = $request->validate([
            'visita' => ['required', 'integer', 'min:1'],
            'segundos' => ['required', 'integer', 'min:1', 'max:'.SeguimientoUso::MAX_SEGUNDOS],
        ]);

        $seguimiento->sumarActividad($request, (int) $datos['visita'], (int) $datos['segundos']);

        return response()->noContent();
    }
}
