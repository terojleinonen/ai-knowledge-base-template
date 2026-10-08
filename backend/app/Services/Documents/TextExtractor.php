<?php

namespace App\Services\Documents;

use Exception;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

class TextExtractor
{
    public function __construct(private readonly PdfParser $pdfParser) {}

    /**
     * Extract normalized plain text from raw file contents.
     *
     * @throws DocumentProcessingException
     */
    public function extract(string $contents, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'txt', 'md' => $this->toUtf8($contents),
            'pdf' => $this->fromPdf($contents),
            'docx' => $this->fromDocx($contents),
            default => throw new DocumentProcessingException("Unsupported file type [.{$extension}]."),
        };

        return $this->normalize($text);
    }

    private function fromPdf(string $contents): string
    {
        try {
            return $this->pdfParser->parseContent($contents)->getText();
        } catch (Exception $e) {
            // Parser exceptions mean a bad file. PHP Errors (server problems) propagate so the job retries and logs them.
            throw new DocumentProcessingException('The PDF could not be read. It may be corrupted, encrypted or password-protected.', previous: $e);
        }
    }

    private function fromDocx(string $contents): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DocumentProcessingException('DOCX support requires the PHP zip extension on the server.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'docx');

        try {
            file_put_contents($tmp, $contents);

            $zip = new ZipArchive;

            if ($zip->open($tmp) !== true) {
                throw new DocumentProcessingException('The DOCX file is corrupted or not a valid Word document.');
            }

            // Guard against zip bombs: a small .docx can unpack to gigabytes.
            $stat = $zip->statName('word/document.xml');

            if ($stat !== false && $stat['size'] > (int) config('knowledge.uploads.max_docx_xml_bytes')) {
                $zip->close();

                throw new DocumentProcessingException('The DOCX file is too large to process.');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                throw new DocumentProcessingException('The DOCX file does not contain a document body.');
            }

            $xml = str_replace(['</w:p>', '<w:br/>', '<w:tab/>'], ["\n\n", "\n", "\t"], $xml);

            return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } finally {
            @unlink($tmp);
        }
    }

    private function toUtf8(string $contents): string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    private function normalize(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
