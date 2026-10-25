<?php

namespace App\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Rendu PDF d'une vue Blade. Aucune ressource distante ni aucun script n'est
 * chargé : le document ne contient que les données de l'application.
 */
final class Pdf
{
    /**
     * @param  array<string, mixed>  $data
     * @param  'portrait'|'landscape'  $orientation
     */
    public static function render(string $view, array $data, string $footer, string $orientation = 'portrait'): string
    {
        $fonts = storage_path('framework/cache/dompdf');
        if (! is_dir($fonts)) {
            mkdir($fonts, 0775, true);
        }

        $dompdf = new Dompdf(new Options([
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'fontDir' => $fonts,
            'fontCache' => $fonts,
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
        ]));
        $dompdf->loadHtml(view($view, $data)->render(), 'UTF-8');
        $dompdf->setPaper('a4', $orientation);
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(36, $canvas->get_height() - 24, $footer.'  ·  Page {PAGE_NUM} sur {PAGE_COUNT}', $font, 7, [0.39, 0.45, 0.55]);

        return (string) $dompdf->output();
    }
}
