<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EurgrosController extends Controller
{
    const FILE = 'Voorraad/Voorraadlijst/Voorraad_Eurogros.csv';

    public function downloadVoorraadlijst(): StreamedResponse
    {
        $stream = Storage::disk('sftp')->readStream(self::FILE);

        return new StreamedResponse(function () use ($stream) {
            fpassthru($stream); // Schrijft de stream direct naar output
            fclose($stream);
        }, 200, [
            'Content-Type'        => Storage::disk('sftp')->mimeType(self::FILE) ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.basename(self::FILE).'"',
        ]);
    }
}
