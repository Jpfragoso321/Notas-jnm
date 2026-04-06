<?php

declare(strict_types=1);

final class SimplePdf
{
    private array $pages = [];
    private int $pageWidth;
    private int $pageHeight;

    public function __construct(int $pageWidth = 595, int $pageHeight = 842)
    {
        $this->pageWidth = $pageWidth;
        $this->pageHeight = $pageHeight;
    }

    public function addPage(): int
    {
        $this->pages[] = '';
        return count($this->pages) - 1;
    }

    public function text(
        int $pageIndex,
        float $x,
        float $y,
        string $text,
        int $fontSize = 10,
        string $variant = 'regular',
        ?array $color = null
    ): void {
        if (!isset($this->pages[$pageIndex])) {
            return;
        }

        $fontKey = strtolower($variant) === 'bold' ? 'F2' : 'F1';
        $safeText = $this->escapeText($this->toPdfEncoding($text));

        $parts = ['q'];
        if (is_array($color) && count($color) === 3) {
            $parts[] = sprintf(
                '%.3F %.3F %.3F rg',
                $this->clampColor((float) $color[0]),
                $this->clampColor((float) $color[1]),
                $this->clampColor((float) $color[2])
            );
        }

        $parts[] = sprintf(
            'BT /%s %d Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            $fontKey,
            $fontSize,
            $x,
            $y,
            $safeText
        );
        $parts[] = 'Q';

        $this->pages[$pageIndex] .= implode(' ', $parts) . "\n";
    }

    public function line(
        int $pageIndex,
        float $x1,
        float $y1,
        float $x2,
        float $y2,
        ?array $strokeColor = null,
        float $lineWidth = 1.0
    ): void {
        if (!isset($this->pages[$pageIndex])) {
            return;
        }

        $parts = ['q'];
        $parts[] = sprintf('%.2F w', max(0.1, $lineWidth));

        if (is_array($strokeColor) && count($strokeColor) === 3) {
            $parts[] = sprintf(
                '%.3F %.3F %.3F RG',
                $this->clampColor((float) $strokeColor[0]),
                $this->clampColor((float) $strokeColor[1]),
                $this->clampColor((float) $strokeColor[2])
            );
        }

        $parts[] = sprintf('%.2F %.2F m %.2F %.2F l S', $x1, $y1, $x2, $y2);
        $parts[] = 'Q';

        $this->pages[$pageIndex] .= implode(' ', $parts) . "\n";
    }

    public function rect(
        int $pageIndex,
        float $x,
        float $y,
        float $width,
        float $height,
        ?array $fillColor = null,
        ?array $strokeColor = null,
        float $lineWidth = 1.0
    ): void {
        if (!isset($this->pages[$pageIndex]) || $width <= 0 || $height <= 0) {
            return;
        }

        $hasFill = is_array($fillColor) && count($fillColor) === 3;
        $hasStroke = is_array($strokeColor) && count($strokeColor) === 3;

        $parts = ['q'];
        $parts[] = sprintf('%.2F w', max(0.1, $lineWidth));

        if ($hasFill) {
            $parts[] = sprintf(
                '%.3F %.3F %.3F rg',
                $this->clampColor((float) $fillColor[0]),
                $this->clampColor((float) $fillColor[1]),
                $this->clampColor((float) $fillColor[2])
            );
        }

        if ($hasStroke) {
            $parts[] = sprintf(
                '%.3F %.3F %.3F RG',
                $this->clampColor((float) $strokeColor[0]),
                $this->clampColor((float) $strokeColor[1]),
                $this->clampColor((float) $strokeColor[2])
            );
        }

        $operator = 'S';
        if ($hasFill && $hasStroke) {
            $operator = 'B';
        } elseif ($hasFill) {
            $operator = 'f';
        }

        $parts[] = sprintf('%.2F %.2F %.2F %.2F re %s', $x, $y, $width, $height, $operator);
        $parts[] = 'Q';

        $this->pages[$pageIndex] .= implode(' ', $parts) . "\n";
    }

    public function output(): string
    {
        if (count($this->pages) === 0) {
            $this->addPage();
        }

        $pageCount = count($this->pages);
        $fontRegularObj = 1;
        $fontBoldObj = 2;
        $pagesObj = 3 + ($pageCount * 2);
        $catalogObj = $pagesObj + 1;

        $objects = [];
        $objects[$fontRegularObj] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBoldObj] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        for ($i = 0; $i < $pageCount; $i++) {
            $contentObj = 3 + ($i * 2);
            $pageObj = 4 + ($i * 2);
            $stream = $this->pages[$i];

            $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
            $objects[$pageObj] = "<< /Type /Page /Parent {$pagesObj} 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] /Resources << /Font << /F1 {$fontRegularObj} 0 R /F2 {$fontBoldObj} 0 R >> >> /Contents {$contentObj} 0 R >>";
        }

        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (4 + ($i * 2)) . ' 0 R';
        }

        $objects[$pagesObj] = '<< /Type /Pages /Count ' . $pageCount . ' /Kids [ ' . implode(' ', $kids) . ' ] >>';
        $objects[$catalogObj] = "<< /Type /Catalog /Pages {$pagesObj} 0 R >>";

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $objNum => $objContent) {
            $offsets[$objNum] = strlen($pdf);
            $pdf .= $objNum . " 0 obj\n" . $objContent . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $objTotal = max(array_keys($objects)) + 1;

        $pdf .= "xref\n0 {$objTotal}\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i < $objTotal; $i++) {
            $offset = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size {$objTotal} /Root {$catalogObj} 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    private function toPdfEncoding(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }

    private function escapeText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        return str_replace(')', '\\)', $text);
    }

    private function clampColor(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
