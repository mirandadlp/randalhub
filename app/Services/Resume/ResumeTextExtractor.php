<?php

namespace App\Services\Resume;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class ResumeTextExtractor
{
    public function extract(string $bytes, string $mime): string
    {
        $text = match ($mime) {
            'application/pdf' => $this->pdf($bytes),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->docx($bytes),
            default => throw new \InvalidArgumentException('Unsupported resume format.'),
        };

        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        if (mb_strlen($text) < 50) {
            throw new \RuntimeException('Very little text could be extracted. If this is a scanned PDF, upload a text-based PDF or DOCX.');
        }

        return mb_substr($text, 0, 60000);
    }

    private function pdf(string $bytes): string
    {
        return (new PdfParser)->parseContent($bytes)->getText();
    }

    private function docx(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'resume');
        file_put_contents($tmp, $bytes);
        try {
            $phpWord = IOFactory::load($tmp, 'Word2007');
            $lines = [];
            foreach ($phpWord->getSections() as $section) {
                $this->walk($section, $lines);
            }

            return implode("\n", $lines);
        } finally {
            @unlink($tmp);
        }
    }

    private function walk(AbstractContainer $container, array &$lines): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof TextRun) {
                $line = '';
                foreach ($element->getElements() as $child) {
                    if ($child instanceof Text) {
                        $line .= $child->getText();
                    }
                }
                $lines[] = $line;
            } elseif ($element instanceof Text) {
                $lines[] = $element->getText();
            } elseif ($element instanceof TextBreak) {
                $lines[] = '';
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->walk($cell, $lines);
                    }
                }
            } elseif ($element instanceof AbstractContainer) {
                $this->walk($element, $lines);
            }
        }
    }
}
