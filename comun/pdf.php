<?php
declare(strict_types=1);

/*
 * Generador mínimo de PDF (sin librerías): páginas carta, texto en Helvetica / Helvetica-Bold, rectángulos,
 * líneas e imágenes JPEG. Las coordenadas van en puntos (1/72 in) desde la esquina superior izquierda.
 * El texto se escribe en Windows-1252, así que acentos y ñ salen bien.
 */
final class IntranetPdf
{
    // Anchos de Helvetica y Helvetica-Bold (milésimas del tamaño) para los caracteres 32..126.
    private const WIDTHS = [
        false => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
            1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
            333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584],
        true => [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
            975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
            333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584],
    ];

    private array $pages = [];
    private string $page = '';
    private array $images = [];

    public function __construct(private float $width = 792, private float $height = 612) {}

    public function addPage(): void
    {
        if ($this->page !== '') $this->pages[] = $this->page;
        $this->page = ' ';
    }

    private static function encode(string $text): string
    {
        $text = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    private static function color(array $rgb): string
    {
        return implode(' ', array_map(fn($c) => round($c / 255, 3), $rgb));
    }

    public function textWidth(string $text, float $size, bool $bold = false): float
    {
        // Las letras acentuadas miden como su letra base.
        $plain = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $sum = 0;
        foreach (mb_str_split($plain) as $char) {
            $code = mb_ord($char);
            $sum += $code >= 32 && $code <= 126 ? self::WIDTHS[$bold][$code - 32] : 556;
        }
        return $sum * $size / 1000;
    }

    /** Texto en (x, y = línea base). $align: 'L', 'C' o 'R' dentro de $w; se reduce si no cabe. */
    public function text(float $x, float $y, string $text, float $size = 9, bool $bold = false, string $align = 'L', float $w = 0, array $rgb = [0, 0, 0]): void
    {
        if ($w > 0) {
            while ($size > 5 && $this->textWidth($text, $size, $bold) > $w - 4) $size -= 0.5;
            $tw = $this->textWidth($text, $size, $bold);
            if ($align === 'C') $x += ($w - $tw) / 2;
            elseif ($align === 'R') $x += $w - $tw - 2;
            else $x += 2;
        }
        $this->page .= sprintf("BT %s rg /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n", self::color($rgb), $bold ? 2 : 1, $size, $x, $this->height - $y, self::encode($text));
    }

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = [0, 0, 0], float $lineWidth = 0.6): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->page .= sprintf("%s%s%.2F w %.2F %.2F %.2F %.2F re %s\n", $fill ? self::color($fill) . " rg " : '', $stroke ? self::color($stroke) . ' RG ' : '',
            $lineWidth, $x, $this->height - $y - $h, $w, $h, $op);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $lineWidth = 0.6): void
    {
        $this->page .= sprintf("0 0 0 RG %.2F w %.2F %.2F m %.2F %.2F l S\n", $lineWidth, $x1, $this->height - $y1, $x2, $this->height - $y2);
    }

    public function jpeg(string $path, float $x, float $y, float $w, float $h): void
    {
        $info = @getimagesize($path);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) return;
        $key = $path;
        $this->images[$key] ??= ['data' => file_get_contents($path), 'w' => $info[0], 'h' => $info[1],
            'cs' => ($info['channels'] ?? 3) === 1 ? 'DeviceGray' : (($info['channels'] ?? 3) === 4 ? 'DeviceCMYK' : 'DeviceRGB'), 'n' => count($this->images) + 1];
        $this->page .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $w, $h, $x, $this->height - $y - $h, $this->images[$key]['n']);
    }

    public function output(): string
    {
        $pages = [...$this->pages, $this->page];
        $objects = [];
        // 1 catálogo, 2 páginas, 3 y 4 fuentes, luego imágenes, luego (contenido, página) por cada hoja.
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5;
        $xobjects = '';
        foreach ($this->images as $img) {
            $objects[$next] = ['dict' => "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace /{$img['cs']} /BitsPerComponent 8 /Filter /DCTDecode", 'stream' => $img['data']];
            $xobjects .= "/Im{$img['n']} $next 0 R ";
            $next++;
        }
        $kids = [];
        foreach ($pages as $content) {
            $objects[$next] = ['dict' => '<< /Filter /FlateDecode', 'stream' => gzcompress($content)];
            $objects[$next + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << %s>> >> /Contents %d 0 R >>',
                $this->width, $this->height, $xobjects, $next);
            $kids[] = ($next + 1) . ' 0 R';
            $next += 2;
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $n => $obj) {
            $offsets[$n] = strlen($out);
            $out .= "$n 0 obj\n" . (is_array($obj) ? $obj['dict'] . ' /Length ' . strlen($obj['stream']) . " >>\nstream\n" . $obj['stream'] . "\nendstream" : $obj) . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($next) . "\n0000000000 65535 f \n";
        for ($n = 1; $n < $next; $n++) $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        return $out . "trailer\n<< /Size $next /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}
