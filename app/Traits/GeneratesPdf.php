<?php

namespace App\Traits;

use Dompdf\Dompdf;
use Dompdf\Options;
use App\Models\Company;

trait GeneratesPdf
{
    /**
     * Créer les options DomPDF standardisées
     */
    protected function createDompdfOptions(): Options
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isPhpEnabled', true);
        $options->set('chroot', base_path());
        
        return $options;
    }

    /**
     * Générer un PDF à partir d'une vue Blade
     *
     * @param string $view Nom de la vue Blade
     * @param array $data Données à passer à la vue
     * @param string $paper Format de papier (A4, letter, etc.)
     * @param string $orientation Orientation (portrait, landscape)
     * @return Dompdf
     */
    protected function generatePdfFromView(
        string $view,
        array $data = [],
        string $paper = 'A4',
        string $orientation = 'portrait'
    ): Dompdf {
        $options = $this->createDompdfOptions();
        $dompdf = new Dompdf($options);
        
        // Ajouter automatiquement la company si elle n'est pas présente
        if (!isset($data['company'])) {
            $data['company'] = Company::getInstance();
        }
        
        // Rendre la vue Blade en HTML
        $html = view($view, $data)->render();
        
        // S'assurer que le HTML est en UTF-8
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        
        // Charger le HTML dans DomPDF avec encodage UTF-8
        $dompdf->loadHtml($html, 'UTF-8');
        
        // Définir le format de papier
        $dompdf->setPaper($paper, $orientation);
        
        // Rendre le PDF
        $dompdf->render();
        
        return $dompdf;
    }

    /**
     * Retourner une réponse PDF pour téléchargement
     *
     * @param Dompdf $dompdf Instance Dompdf
     * @param string $filename Nom du fichier
     * @return \Illuminate\Http\Response
     */
    protected function pdfDownloadResponse(Dompdf $dompdf, string $filename): \Illuminate\Http\Response
    {
        $pdfContent = $dompdf->output();
        
        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"')
            ->header('Content-Length', strlen($pdfContent))
            ->header('Cache-Control', 'private, max-age=0, must-revalidate')
            ->header('Pragma', 'public');
    }

    /**
     * Retourner une réponse PDF pour affichage dans le navigateur
     *
     * @param Dompdf $dompdf Instance Dompdf
     * @param string $filename Nom du fichier
     * @return \Illuminate\Http\Response
     */
    protected function pdfInlineResponse(Dompdf $dompdf, string $filename): \Illuminate\Http\Response
    {
        $pdfContent = $dompdf->output();
        
        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'Content-Length' => strlen($pdfContent),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}

