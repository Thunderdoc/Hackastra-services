<?php

namespace App\Support;

/**
 * dompdf page-number footer.
 *
 * dompdf 3.x does NOT resolve the CSS `pages` counter (it renders 0),
 * so "Page X of Y" cannot come from a stylesheet. The supported path
 * is the canvas page_text() template, which dompdf substitutes per
 * page at render time ({PAGE_NUM} / {PAGE_COUNT}). Coordinates are
 * A4 points, computed from the orientation — never from the canvas
 * size before rendering (that is unset until the render pass).
 * Failure-proof by design: a footer problem must never break the PDF.
 */
class PdfFooter
{
    public static function stampPageNumbers($pdf, string $orientation = 'portrait'): void
    {
        try {
            // page_text() stamps ONLY the pages that exist at call time
            // (it iterates the canvas page list), so the document MUST
            // be rendered first — otherwise it lands once, as "Page 1
            // of 1". download()/output() do not re-render after this.
            if (method_exists($pdf, 'render')) {
                $pdf->render();
            }
            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();
            $metrics = $dompdf->getFontMetrics();
            $font = $metrics->getFont('DejaVu Sans', 'normal');
            [$w, $h] = $orientation === 'landscape' ? [841.89, 595.28] : [595.28, 841.89];
            // Right-align against the widest realistic label so the
            // number never drifts as pages grow from 9 to 10+.
            $tw = $metrics->getTextWidth('Page 99 of 99', $font, 10);
            $canvas->page_text($w - 28.35 - $tw, $h - 26, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 10, [0.44, 0.50, 0.59]);
        } catch (\Throwable $e) {
            // The document without page numbers is still valid.
        }
    }
}
