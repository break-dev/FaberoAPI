<?php

namespace App\Shared\Helpers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ArchivoHelper
{
    /**
     * Guarda un array de archivos de forma segura en el disco público.
     *
     * @param  string  $carpetaDestino  Carpeta dentro de storage/app/public (ej. "pagos").
     * @param  UploadedFile[]  $archivos  Archivos recibidos en el request.
     * @return array Retorna: { url (URL pública), nombre_original (nombre original del archivo), extension (docx, jpg, etc) }
     *
     * Comando de ayuda: php artisan storage:link
     */
    public static function guardarArchivos(string $carpetaDestino, array $archivos): array
    {
        $resultados = [];

        // Agrupamos los archivos en subcarpetas por fecha (ej. "pagos/28-02-26")
        $rutaDestino = trim($carpetaDestino, '/').'/'.date('d-m-y');

        foreach ($archivos as $archivo) {
            if (! $archivo instanceof UploadedFile || ! $archivo->isValid()) {
                continue;
            }

            // Extraemos el nombre original y lo limpiamos
            $nombreOriginal = pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME);
            $nombreLimpio = Str::slug($nombreOriginal);

            // Defensa en profundidad: si el cliente envia un File sin extension
            // detectable por nombre o por guessExtension(), inferirla del MIME
            // type real del archivo temporal. Usamos mime_content_type() de
            // PHP nativo (lee magic bytes) en lugar de getMimeType() de
            // UploadedFile, que depende de la extension y devuelve
            // application/octet-stream si la extension esta vacia.
            $extension = $archivo->getClientOriginalExtension();
            if (empty($extension)) {
                $extension = $archivo->guessExtension();
            }
            if (empty($extension) || $extension === 'bin') {
                $realPath = $archivo->getRealPath();
                $realMime = $realPath ? @mime_content_type($realPath) : null;
                $extension = self::extensionFromMime($realMime) ?? 'bin';
            }

            // Construir el nombre final con la extension que decidimos.
            // Usamos storeAs() (no store()) porque store() calcula su propia
            // extension via guessExtension() (lee magic bytes) e IGNORA la
            // extension del clientOriginalName. Con storeAs() forzamos la
            // extension ya calculada arriba, respetando la intencion del
            // usuario al subir el archivo.
            $hash = Str::random(40);
            $nombreArchivo = $extension !== 'bin'
                ? $hash.'.'.$extension
                : $hash;

            $pathRelativo = $archivo->storeAs(
                $rutaDestino,
                $nombreArchivo,
                ['disk' => 'public'],
            );

            if ($pathRelativo) {
                $resultados[] = [
                    'url' => asset('storage/'.$pathRelativo),
                    'path_relativo' => $pathRelativo,
                    'nombre_original' => $nombreLimpio,
                    'extension' => $extension,
                ];
            }
        }

        return $resultados;
    }

    /**
     * Mapea un MIME type a su extension comun. Usado como ultimo fallback
     * cuando getClientOriginalExtension() y guessExtension() no pueden
     * determinar la extension de un UploadedFile.
     */
    private static function extensionFromMime(?string $mime): ?string
    {
        if (empty($mime)) {
            return null;
        }

        $map = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $map[$mime] ?? null;
    }
}
