<?php

namespace App\Services\Applications;

use App\Models\ApplicationDocument;
use App\Models\ProfessionalProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;

/** Renders tailored resumes and cover letters to DOCX (PhpWord) or PDF (Dompdf). */
class DocumentExporter
{
    public function html(ApplicationDocument $doc, ?ProfessionalProfile $profile): string
    {
        return view('exports.document', [
            'doc' => $doc,
            'profile' => $profile,
            'resume' => $doc->type === 'tailored_resume' ? (json_decode($doc->content, true) ?: []) : null,
            'body' => $doc->type !== 'tailored_resume' ? $doc->content : null,
        ])->render();
    }

    public function pdf(ApplicationDocument $doc, ?ProfessionalProfile $profile): string
    {
        return Pdf::loadHTML($this->html($doc, $profile))->setPaper('letter')->output();
    }

    public function docx(ApplicationDocument $doc, ?ProfessionalProfile $profile): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);
        $section = $word->addSection(['marginTop' => 900, 'marginBottom' => 900, 'marginLeft' => 1000, 'marginRight' => 1000]);

        // Reuse the HTML template body so DOCX and PDF stay consistent.
        $html = $this->html($doc, $profile);
        preg_match('#<body[^>]*>(.*)</body>#is', $html, $m);
        $body = $m[1] ?? $html;
        $body = preg_replace('#<style.*?</style>#is', '', $body);
        $body = preg_replace('#\sclass="[^"]*"#', '', $body);
        Html::addHtml($section, $body, false, false);

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($word, 'Word2007')->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
