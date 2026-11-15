<?php

namespace Tests\Feature\Visual;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Regla visual (docs/GUIA_DISENO_UI.md): los colores solo existen como tokens
 * en resources/css/tema/tokens.css; todo lo demás los deriva. Cambiar la
 * paleta no puede alterar medidas, tipografía ni estructura.
 */
class PaletaTest extends TestCase
{
    private const TOKENS = 'resources/css/tema/tokens.css';

    private const EJEMPLO = 'docs/ejemplos/paleta-ejemplo.css';

    private const COLOR_TAILWIND = '/\b(text|bg|border|ring|fill|stroke|from|to|via|outline|decoration|placeholder|shadow|accent|caret|divide)-(red|green|blue|yellow|amber|emerald|neutral|gray|slate|zinc|stone|orange|sky|indigo|violet|purple|pink|rose|lime|teal|cyan|white|black)\b/';

    /** @return list<string> Archivos del frontend salvo tokens.css y el código generado. */
    private function archivosFrontend(): array
    {
        $archivos = [];
        foreach (['resources/js', 'resources/css', 'resources/views'] as $carpeta) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($carpeta), FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                $ruta = str_replace('\\', '/', $archivo->getPathname());
                if (! str_ends_with($ruta, self::TOKENS) && ! preg_match('#/resources/js/(actions|routes|wayfinder)/#', $ruta)) {
                    $archivos[] = $ruta;
                }
            }
        }

        return $archivos;
    }

    /** @return array<string, string> Declaraciones de un archivo de paleta, sin comentarios. */
    private function declaraciones(string $archivo): array
    {
        $contenido = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(base_path($archivo)));
        preg_match_all('/([a-z-]+)\s*:\s*([^;]+);/', $contenido, $m);

        return array_combine($m[1], array_map('trim', $m[2]));
    }

    public function test_no_hay_colores_literales_fuera_de_los_tokens(): void
    {
        $archivos = $this->archivosFrontend();
        $this->assertGreaterThan(50, count($archivos));

        foreach ($archivos as $archivo) {
            $contenido = (string) file_get_contents($archivo);
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $contenido, "Color hexadecimal en $archivo");
            $this->assertDoesNotMatchRegularExpression('/\b(rgba?|hsla?|oklch|hwb|lab|lch)\(/', $contenido, "Color literal en $archivo");
            $this->assertDoesNotMatchRegularExpression(self::COLOR_TAILWIND, $contenido, "Color de Tailwind en $archivo");
        }
    }

    public function test_la_paleta_solo_contiene_colores(): void
    {
        foreach ([self::TOKENS, self::EJEMPLO] as $archivo) {
            $declaraciones = $this->declaraciones($archivo);
            $this->assertNotEmpty($declaraciones);
            // Solo tokens --adm-* con valores de color: ninguna medida.
            foreach ($declaraciones as $propiedad => $valor) {
                $this->assertStringStartsWith('--adm-', $propiedad, "$archivo: $propiedad");
                $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $valor, "$archivo: $propiedad no es un color");
            }
        }
    }

    public function test_la_paleta_de_ejemplo_cambia_solo_colores_de_los_mismos_tokens(): void
    {
        $base = $this->declaraciones(self::TOKENS);
        $ejemplo = $this->declaraciones(self::EJEMPLO);

        $this->assertSame(array_keys($base), array_keys($ejemplo));
        // Las medidas viven fuera de la paleta y componentes.css no redefine sus tokens.
        $componentes = (string) file_get_contents(base_path('resources/css/tema/componentes.css'));
        foreach (array_keys($base) as $token) {
            $this->assertStringNotContainsString("$token:", $componentes, "componentes.css redefine $token");
        }
    }

    public function test_todo_token_usado_esta_definido(): void
    {
        preg_match_all('/(--adm-[a-z-]+):/', (string) file_get_contents(base_path('resources/css/tema/componentes.css')), $derivados);
        $definidos = [...array_keys($this->declaraciones(self::TOKENS)), ...$derivados[1]];

        foreach ($this->archivosFrontend() as $archivo) {
            preg_match_all('/var\((--adm-[a-z-]+)\)|[\'"](--adm-[a-z-]+)[\'"]/', (string) file_get_contents($archivo), $usados);
            foreach (array_filter([...$usados[1], ...$usados[2]]) as $token) {
                $this->assertContains($token, $definidos, "Token sin definir en $archivo: $token");
            }
        }
    }

    public function test_los_componentes_base_y_el_documento_derivan_de_los_tokens(): void
    {
        $app = (string) file_get_contents(resource_path('css/app.css'));
        preg_match('/:root\s*\{(.*?)\}/s', $app, $raiz);
        preg_match_all('/(--[a-z0-9-]+):\s*([^;]+);/', $raiz[1], $variables, PREG_SET_ORDER);
        $this->assertGreaterThan(30, count($variables));
        foreach ($variables as [, $variable, $valor]) {
            if ($variable !== '--radius') {
                $this->assertStringStartsWith('var(--adm-', trim($valor), "$variable no deriva de los tokens");
            }
        }
        // Paleta única: sin modo oscuro.
        $this->assertStringNotContainsString('.dark {', $app);
        $this->assertStringNotContainsString("classList.add('dark')", (string) file_get_contents(resource_path('views/app.blade.php')));
        $this->assertStringContainsString("color: 'var(--adm-color-acento)'", (string) file_get_contents(resource_path('js/app.ts')));
    }
}
