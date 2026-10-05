<?php

namespace App\Compliance\Dsar\IO\Export;

use ZipArchive;

class DsarExportArchive
{
    /**
     * The export as ZIP bytes — one data.json holding every section.
     *
     * @param  array<string, mixed>  $sections
     */
    public function build(array $sections): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dsar');

        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            $zip->addFromString('data.json', json_encode($sections, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
