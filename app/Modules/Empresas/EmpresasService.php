<?php

namespace App\Modules\Empresas;

use App\Modules\Empresas\Data\EmpresasData;
use App\Shared\Helpers\ArchivoHelper;
use App\Shared\Responses\ApiResponse;
use Illuminate\Http\UploadedFile;

class EmpresasService
{
    /**
     * Obtener el listado de empresas
     */
    public static function get_empresas()
    {
        $empresas = EmpresasData::get_empresas();

        // Convertir path_logo a URL completa (compatible con registros viejos y nuevos)
        foreach ($empresas as $empresa) {
            if ($empresa->path_logo && ! str_starts_with($empresa->path_logo, 'http')) {
                $empresa->path_logo = asset('storage/'.$empresa->path_logo);
            }
        }

        return ApiResponse::success($empresas);
    }

    /**
     * Crear una nueva empresa
     *
     * @param  array{
     *   ruc: string,
     *   razon_social: string,
     *   path_logo?: string|null,
     *   id_departamento?: int|null,
     *   id_provincia?: int|null,
     *   id_distrito?: int|null,
     *   domicilio_fiscal?: string|null
     * }  $data
     */
    public static function crear_empresa(array $data, ?UploadedFile $logo = null)
    {
        if (EmpresasData::verificar_ruc_duplicado($data['ruc'])) {
            return ApiResponse::error('Ya existe una empresa registrada con este RUC.');
        }

        $path_logo = null;
        if ($logo && $logo->isValid()) {
            $archivos = ArchivoHelper::guardarArchivos('logos-empresas', [$logo]);
            if (! empty($archivos)) {
                // Guardar URL completa en BD (no solo el path relativo)
                $path_logo = asset('storage/'.$archivos[0]['path_relativo']);
            }
        }

        $data['path_logo'] = $path_logo;

        $id_empresa = EmpresasData::crear_empresa($data);
        $nuevaEmpresa = EmpresasData::get_empresa_by_id($id_empresa);

        return ApiResponse::success($nuevaEmpresa, 'Empresa registrada correctamente');
    }

    /**
     * Actualizar el logo de una empresa
     */
    public static function actualizar_logo(int $id_empresa, ?UploadedFile $file)
    {
        if (! $file || ! $file->isValid()) {
            return ApiResponse::error('Archivo no válido.');
        }

        $archivos = ArchivoHelper::guardarArchivos('logos-empresas', [$file]);
        if (empty($archivos)) {
            return ApiResponse::error('No se pudo procesar la imagen.');
        }

        // Guardar URL completa en BD (no solo el path relativo)
        $path_logo = asset('storage/'.$archivos[0]['path_relativo']);
        EmpresasData::actualizar_logo($id_empresa, $path_logo);

        $empresa = EmpresasData::get_empresa_by_id($id_empresa);

        return ApiResponse::success($empresa, 'Logo de empresa actualizado correctamente');
    }

    /**
     * Actualizar los datos de una empresa (sin logo).
     *
     * @param  array{
     *   ruc?: string,
     *   razon_social?: string,
     *   id_departamento?: int|null,
     *   id_provincia?: int|null,
     *   id_distrito?: int|null,
     *   domicilio_fiscal?: string|null
     * }  $data
     */
    public static function actualizar_empresa(int $idEmpresa, array $data)
    {
        if (isset($data['ruc']) && EmpresasData::verificar_ruc_duplicado($data['ruc'], $idEmpresa)) {
            return ApiResponse::error('Ya existe otra empresa registrada con este RUC.');
        }

        // Si viene RUC, lo actualizamos también.
        if (array_key_exists('ruc', $data)) {
            $data['ruc'] = $data['ruc'];
        }

        EmpresasData::actualizar_empresa($idEmpresa, $data);

        // Si se actualizó el RUC, sincronizarlo aparte (actualizar_empresa no lo toca).
        if (array_key_exists('ruc', $data)) {
            \App\Models\Empresa::where('id', $idEmpresa)->update(['ruc' => $data['ruc']]);
        }

        $empresa = EmpresasData::get_empresa_by_id($idEmpresa);

        return ApiResponse::success($empresa, 'Empresa actualizada correctamente');
    }
}
