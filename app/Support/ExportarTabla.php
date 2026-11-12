<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Exportación de reportes tabulares a CSV y Excel (XLSX) sin librerías. La
 * usan Auditoría y Seguimiento de Uso. Recibe filas iterables (cursor o
 * generador) para no cargar el reporte en memoria y neutraliza las celdas que
 * una hoja de cálculo interpretaría como fórmulas.
 */
class ExportarTabla
{
    /**
     * @param  list<string>  $encabezados
     * @param  iterable<list<string|int|null>>  $filas
     */
    public function csv(string $nombre, array $encabezados, iterable $filas): StreamedResponse
    {
        return response()->streamDownload(function () use ($encabezados, $filas): void {
            $salida = fopen('php://output', 'w');
            if ($salida === false) {
                return;
            }
            fwrite($salida, "\xEF\xBB\xBF"); // BOM: Excel reconoce UTF-8.
            // Punto y coma: separador de listas de Excel en configuraciones regionales en español.
            fputcsv($salida, $encabezados, ';', '"', '');
            foreach ($filas as $fila) {
                fputcsv($salida, array_map($this->seguro(...), $fila), ';', '"', '');
            }
            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * XLSX mínimo (una hoja, cadenas en línea) con ZipArchive. La hoja se
     * escribe fila a fila en un archivo temporal.
     *
     * @param  list<string>  $encabezados
     * @param  iterable<list<string|int|null>>  $filas
     */
    public function xlsx(string $nombre, array $encabezados, iterable $filas): BinaryFileResponse
    {
        $hoja = tempnam(sys_get_temp_dir(), 'admhoja');
        $libro = tempnam(sys_get_temp_dir(), 'admxlsx');
        $salida = fopen($hoja, 'w');
        if ($salida === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del reporte.');
        }
        fwrite($salida, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
        $numero = 0;
        $escribir = function (array $fila) use ($salida, &$numero): void {
            $numero++;
            $estilo = $numero === 1 ? ' s="1"' : ''; // Encabezado en negrita.
            $celdas = '';
            foreach (array_values($fila) as $i => $valor) {
                $ref = $this->columna($i).$numero;
                $celdas .= is_int($valor)
                    ? "<c r=\"$ref\"$estilo><v>$valor</v></c>"
                    : "<c r=\"$ref\"$estilo t=\"inlineStr\"><is><t xml:space=\"preserve\">".$this->xml($this->seguro($valor)).'</t></is></c>';
            }
            fwrite($salida, "<row r=\"$numero\">$celdas</row>");
        };
        $escribir($encabezados);
        foreach ($filas as $fila) {
            $escribir($fila);
        }
        fwrite($salida, '</sheetData></worksheet>');
        fclose($salida);

        $zip = new ZipArchive;
        $zip->open($libro, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Reporte" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFile($hoja, 'xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($hoja);

        return response()->download($libro, $nombre, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Evita que un texto se interprete como fórmula (=, +, -, @, tabulador, retorno). */
    public function seguro(string|int|null $valor): string|int
    {
        if ($valor === null || is_int($valor)) {
            return $valor ?? '';
        }

        return preg_match('/^[=+\-@\t\r]/', $valor) === 1 ? "'".$valor : $valor;
    }

    private function xml(string|int $valor): string
    {
        $texto = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $valor) ?? '';

        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function columna(int $indice): string
    {
        $letras = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26).$letras;
        }

        return $letras;
    }
}
