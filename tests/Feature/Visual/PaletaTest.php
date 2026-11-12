<?php

namespace Tests\Feature\Visual;

use Tests\TestCase;

/**
 * Regla visual: los colores solo existen como tokens en tokens.css. Revisa
 * los componentes del tema y las pantallas que ya usan el sistema adm-.
 */
class PaletaTest extends TestCase
{
    private const ARCHIVOS = [
        'resources/css/tema/componentes.css',
        'resources/js/lib/alertas.ts',
        'resources/js/pages/admin/Usuarios.vue',
        'resources/js/pages/admin/Grupos.vue',
        'resources/js/pages/admin/Membresias.vue',
        'resources/js/pages/admin/Permisos.vue',
        'resources/js/pages/admin/Sesiones.vue',
        'resources/js/pages/MiPerfil.vue',
    ];

    public function test_no_hay_colores_literales_fuera_de_los_tokens(): void
    {
        foreach (self::ARCHIVOS as $archivo) {
            $contenido = (string) file_get_contents(base_path($archivo));

            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $contenido, "Color hexadecimal en $archivo");
            $this->assertDoesNotMatchRegularExpression('/\b(rgba?|hsla?|oklch)\(/', $contenido, "Color literal en $archivo");
            $this->assertDoesNotMatchRegularExpression('/\b(text|bg|border)-(red|green|blue|gray|neutral|slate)-\d{2,3}\b/', $contenido, "Color de Tailwind en $archivo");
        }
    }

    public function test_tokens_define_la_paleta_completa(): void
    {
        $tokens = (string) file_get_contents(base_path('resources/css/tema/tokens.css'));
        $componentes = (string) file_get_contents(base_path('resources/css/tema/componentes.css'));

        preg_match_all('/var\((--adm-[a-z-]+)\)/', $componentes, $usados);
        foreach (array_unique($usados[1]) as $token) {
            $definido = str_contains($tokens, "$token:") || str_contains($componentes, "$token:");
            $this->assertTrue($definido, "Token sin definir: $token");
        }
    }
}
