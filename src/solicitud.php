<?php

namespace App\Auxiliares;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Exports\SatDiferenciasExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;

class VerificarSAT
{
    public static function verificar($empresa, Request $request)
    {
        $datos = [
            'exito' => false,
            'errores' => [],
            'mensaje' => ''
        ];

        // Validación de configuración FIEL
        if (!$empresa->verificador_sat) {
            $datos['mensaje'] = 'Configuración requerida';
            $datos['errores'][] = 'La empresa no ha configurado el verificador del SAT.';
            return response()->json($datos);
        }

        try {
            $cerPath = storage_path($empresa->obtenerAjuste('verificador_sat', 'fiel'));
            $keyPath = storage_path($empresa->obtenerAjuste('verificador_sat', 'llave_fiel'));
            $password = Crypt::decryptString($empresa->obtenerAjuste('verificador_sat', 'clave_fiel'));

            // Verificar archivos y contraseña
            if (!file_exists($cerPath)) {
                $datos['mensaje'] = 'Archivo de certificado no encontrado';
                $datos['errores'][] = 'No se encontró el archivo del certificado FIEL (.cer).';
                return response()->json($datos);
            }
            if (!file_exists($keyPath)) {
                $datos['mensaje'] = 'Archivo de llave no encontrado';
                $datos['errores'][] = 'No se encontró el archivo de la llave FIEL (.key).';
                return response()->json($datos);
            }
            if (empty($password)) {
                $datos['mensaje'] = 'Contraseña inválida';
                $datos['errores'][] = 'La contraseña de la FIEL está vacía o es inválida.';
                return response()->json($datos);
            }

            // Validar fechas
            $fechaInicio = Carbon::parse($request->fecha_inicio);
            $fechaFinal = Carbon::parse($request->fecha_final);
            if ($fechaInicio->greaterThan($fechaFinal)) {
                $datos['mensaje'] = 'Rango de fechas inválido';
                $datos['errores'][] = 'La fecha inicial no puede ser mayor a la fecha final.';
                return response()->json($datos);
            }

            // ======= Solicitud al microservicio SAT =======
            $client = new Client(['timeout' => 120]);
            $microservicioUrl = env('MICROSERVIDOR_URL');
            $resp = $client->post($microservicioUrl, [
                'multipart' => [
                    ['name' => 'certificado_cer', 'contents' => fopen($cerPath, 'r'), 'filename' => basename($cerPath)],
                    ['name' => 'certificado_key', 'contents' => fopen($keyPath, 'r'), 'filename' => basename($keyPath)],
                    ['name' => 'password', 'contents' => $password],
                    ['name' => 'fecha_inicio', 'contents' => $request->fecha_inicio],
                    ['name' => 'fecha_final', 'contents' => $request->fecha_final],
                    ['name' => 'tipo', 'contents' => $request->tipo],
                ]
            ]);

            $rawResponse = $resp->getBody()->getContents();
            $jsonStart = strpos($rawResponse, '{');
            $cleanJson = ($jsonStart !== false) ? substr($rawResponse, $jsonStart) : $rawResponse;
            $responseData = json_decode($cleanJson, true);

            // Manejar casos donde el microservicio no devuelva items
            if (!isset($responseData['items']) || count($responseData['items']) === 0) {
                return response()->json([
                    'exito' => false,
                    'mensaje' => $responseData['mensaje'] ?? 'No se encontraron comprobantes',
                    'errores' => $responseData['errores'] ?? [],
                    'tipo' => 'sin_resultados'
                ]);
            }

            // ======= PROCESAR Y COMPARAR COMPROBANTES =======
            $reporte = [
                'leidos' => [],
                'totales' => 0,
                'diferencias' => []
            ];

            $tipoConsulta = $request->tipo; // 'emitidos' o 'recibidos'

            foreach ($responseData['items'] as $item) {
                // ADAPTACIÓN: El microservicio ahora devuelve los datos directamente en el item
                // sin el nivel 'metadata'
                $uuid = $item['uuid'];
                $estatusSat = $item['estatus']; // 1 o 0
                $tipoComprobante = $item['efecto_comprobante']; // Ingreso, Egreso, Pago, Nomina, etc.

                // Convertir total de string a float si es necesario
                $total = is_string($item['total']) ?
                    floatval(str_replace(['$', ','], '', $item['total'])) :
                    floatval($item['total']);

                // Omitir comprobantes tipo 'Nómina' para emitidos
                if ($tipoConsulta == 'emitidos' && $tipoComprobante == 'Nomina') {
                    continue;
                }

                // Agregar a leídos - estructura adaptada al nuevo formato
                $reporte['leidos'][] = [
                    'uuid' => $uuid,
                    'status' => $estatusSat,
                    'emisor' => $item['emisor']['nombre'],
                    'rfc_emisor' => $item['emisor']['rfc'],
                    'receptor' => $item['receptor']['nombre'],
                    'rfc' => $item['receptor']['rfc'],
                    'completo' => $item, // Guardamos el item completo
                    'monto' => '$' . number_format($total, 2),
                    'tipo' => $tipoComprobante,
                    'fecha' => Carbon::parse($item['fecha_certificacion'])->format('d/m/Y H:i:s')
                ];

                // REALIZAR COMPARACIONES CON LA LÓGICA COMPLETA
                // Pasamos el item completo como metadata
                if ($tipoConsulta == 'emitidos') {
                    self::compararEmitidos($empresa, $uuid, $estatusSat, $tipoComprobante, $item, $reporte, $total);
                } else {
                    self::compararRecibidos($empresa, $uuid, $estatusSat, $tipoComprobante, $item, $reporte, $total);
                }
            }

            return response()->json([
                'exito' => true,
                'mensaje' => $responseData['mensaje'] ?? 'Consulta exitosa',
                'errores' => $responseData['errores'] ?? [],
                'items' => $reporte['leidos'],
                'diferencias' => $reporte['diferencias'],
                'totales_diferencias' => $reporte['totales'],
                'resumen' => [
                    'total' => count($reporte['leidos']),
                    'tipo' => $tipoConsulta,
                    'rango_fechas' => $fechaInicio->format('d/m/Y') . ' - ' . $fechaFinal->format('d/m/Y'),
                ]
            ]);

        } catch (RequestException $e) {
            Log::error('Error en VerificarSAT (RequestException): ' . $e->getMessage());
            return response()->json([
                'exito' => false,
                'mensaje' => 'Error de conexión con el SAT',
                'errores' => [$e->getMessage()],
                'tipo' => 'conexion'
            ]);

        } catch (\Exception $e) {
            Log::error('Error general en VerificarSAT: ' . $e->getMessage());
            return response()->json([
                'exito' => false,
                'mensaje' => 'Error interno del sistema',
                'errores' => [$e->getMessage()],
                'tipo' => 'interno'
            ]);
        }
    }

    private static function compararEmitidos($empresa, $uuid, $estatusSat, $tipoComprobante, $metadata, &$reporte, $total)
    {
        if ($tipoComprobante == 'Ingreso') {
            // Facturas y Anticipos
            $factura = $empresa->facturas()->where('uuid', $uuid)->first();
            if ($factura) {
                if ($estatusSat == '1') {
                    if ($factura->status == 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Factura',
                            $metadata['receptor']['nombre'],
                            $total,
                            'La factura esta cancelada pero vigente en el SAT.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('facturas.ver', [$empresa, $factura])
                        );
                    }
                } else {
                    if ($factura->status != 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Factura',
                            $metadata['receptor']['nombre'],
                            $total,
                            'La factura está vigente pero cancelada en el SAT.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('facturas.ver', [$empresa, $factura])
                        );
                    }
                }
            } else {
                $anticipo = $empresa->anticipos()->where('uuid', $uuid)->first();
                if ($anticipo) {
                    if ($estatusSat == '1') {
                        if ($anticipo->status == 0) {
                            self::agregarDiferencia(
                                $reporte,
                                'Anticipo',
                                $metadata['receptor']['nombre'],
                                $total,
                                'El anticipo está cancelado pero vigente en el SAT.',
                                $uuid,
                                $metadata['fecha_certificacion'],
                                route('anticipos.ver', [$empresa, $anticipo])
                            );
                        }
                    } else {
                        if ($anticipo->status != 0) {
                            self::agregarDiferencia(
                                $reporte,
                                'Anticipo',
                                $metadata['receptor']['nombre'],
                                $total,
                                'El anticipo está vigente pero cancelado en el SAT.',
                                $uuid,
                                $metadata['fecha_certificacion'],
                                route('anticipos.ver', [$empresa, $anticipo])
                            );
                        }
                    }
                } else {
                    if ($estatusSat == '1') {
                        self::agregarDiferencia(
                            $reporte,
                            'Factura',
                            $metadata['receptor']['nombre'],
                            $total,
                            'No se encontró la factura/anticipo.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            null
                        );
                    }
                }
            }
        } elseif ($tipoComprobante == 'Pago') {
            // Complementos de Pago
            $pago = $empresa->facturasPagos()->where('cfdi', $uuid)->first();
            if ($pago) {
                if ($estatusSat == '1') {
                    if ($pago->status == 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Pago',
                            $metadata['receptor']['nombre'],
                            $total,
                            'El complemento de pago está cancelado pero vigente en el SAT.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('facturas_pagos.ver', [$empresa, $pago])
                        );
                    }
                } else {
                    if ($pago->status != 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Pago',
                            $metadata['receptor']['nombre'],
                            $total,
                            'El complemento de pago esta cancelado en el SAT.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('facturas_pagos.ver', [$empresa, $pago])
                        );
                    }
                }
            } else {
                if ($estatusSat == '1') {
                    self::agregarDiferencia(
                        $reporte,
                        'Pago',
                        $metadata['receptor']['nombre'],
                        $total,
                        'No se encontró el complemento de pago.',
                        $uuid,
                        $metadata['fecha_certificacion'],
                        null
                    );
                }
            }
        } elseif ($tipoComprobante == 'Egreso') {
            // Notas de Crédito
            $nota = $empresa->notas_credito()->where('uuid', $uuid)->first();
            if ($nota) {
                if ($estatusSat == '1') {
                    if ($nota->status == 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Nota de crédito',
                            $metadata['receptor']['nombre'],
                            $total,
                            'La nota de crédito está cancelada pero vigente en el SAT.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('notas_credito.ver', [$empresa, $nota])
                        );
                    }
                } else {
                    if ($nota->status != 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Nota de crédito',
                            $metadata['receptor']['nombre'],
                            $total,
                            'La nota de crédito está vigente pero cancelada en el SAT.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('notas_credito.ver', [$empresa, $nota])
                        );
                    }
                }
            } else {
                if ($estatusSat == '1') {
                    self::agregarDiferencia(
                        $reporte,
                        'Nota de crédito',
                        $metadata['receptor']['nombre'],
                        $total,
                        'No se encontró la nota de crédito.',
                        $uuid,
                        $metadata['fecha_certificacion'],
                        null
                    );
                }
            }
        }
    }

    private static function compararRecibidos($empresa, $uuid, $estatusSat, $tipoComprobante, $metadata, &$reporte, $total)
    {
        if ($tipoComprobante == 'Ingreso') {
            // Compras
            $compra = $empresa->compras()->where('uuid', $uuid)->first();
            if ($compra) {
                if ($estatusSat == '1') {
                    if ($compra->status == 0) {
                        $reemplazo = $empresa->compras()->where('uuid', $uuid)->where('status', '!=', 0)->first();
                        if (!$reemplazo) {
                            self::agregarDiferencia(
                                $reporte,
                                'Compra',
                                $metadata['emisor']['nombre'],
                                $total,
                                'La compra está cancelada pero vigente en el SAT.',
                                $uuid,
                                $metadata['fecha_certificacion'],
                                route('compras.ver', [$empresa, $compra])
                            );
                        }
                    }
                } else {
                    if ($compra->status != 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Compra',
                            $metadata['emisor']['nombre'],
                            $total,
                            'La compra está vigente pero cancelada en el SAT.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('compras.ver', [$empresa, $compra])
                        );
                    }
                }
            } else {
                if ($estatusSat != '0') {
                    self::agregarDiferencia(
                        $reporte,
                        'Compra',
                        $metadata['emisor']['nombre'],
                        $total,
                        'No se encontró la compra a proveedor.',
                        $uuid,
                        $metadata['fecha_certificacion'],
                        null
                    );
                }
            }
        } elseif ($tipoComprobante == 'Pago') {
            // Complementos de Pago de Proveedor
            $pago = $empresa->pagosComplemento()->where('uuid', $uuid)->first();
            if ($pago) {
                if ($estatusSat == '1') {
                    if ($pago->pago->status == 0) {
                        // Aquí decidiste no marcar diferencia cuando el pago en sistema está cancelado
                    }
                } else {
                    if ($pago->pago->status != 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Pago',
                            $metadata['emisor']['nombre'],
                            $total,
                            'El complemento de pago de proveedor está cancelado pero lo tienes vinculado a un pago activo.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('pagoscompras.ver', [$empresa, $pago->pago])
                        );
                    }
                }
            } else {
                if ($estatusSat != '0') {
                    self::agregarDiferencia(
                        $reporte,
                        'Pago a proveedor',
                        $metadata['emisor']['nombre'],
                        $total,
                        'No se encontró el complemento de pago de proveedor.',
                        $uuid,
                        $metadata['fecha_certificacion'],
                        null
                    );
                }
            }
        } elseif ($tipoComprobante == 'Egreso') {
            // Notas de Crédito de Proveedor
            $nota = $empresa->compras_notas_credito()->where('uuid', $uuid)->first();

            if ($nota) {
                $compra = $nota->compra; // Puede ser null si algo está mal ligado

                if ($compra) {
                    // SAT CANCELADA y compra vigente → diferencia (mensaje original)
                    if ($estatusSat == '0' && $compra->status != 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Nota de crédito',
                            $metadata['emisor']['nombre'],
                            $total,
                            'La nota de crédito de proveedor está cancelada y en sistema está cargada.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('compras.ver', [$empresa, $compra])
                        );
                    }

                    // SAT VIGENTE y compra cancelada → también diferencia
                    if ($estatusSat == '1' && $compra->status == 0) {
                        self::agregarDiferencia(
                            $reporte,
                            'Nota de crédito',
                            $metadata['emisor']['nombre'],
                            $total,
                            'La nota de crédito de proveedor está vigente en el SAT pero en sistema está cancelada.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            route('compras.ver', [$empresa, $compra])
                        );
                    }
                } else {
                    // Nota existe pero sin compra ligada
                    if ($estatusSat != '0') {
                        self::agregarDiferencia(
                            $reporte,
                            'Nota de crédito',
                            $metadata['emisor']['nombre'],
                            $total,
                            'La nota de crédito de proveedor existe en sistema pero no tiene compra relacionada.',
                            $uuid,
                            $metadata['fecha_certificacion'],
                            null
                        );
                    }
                }
            } else {
                if ($estatusSat != '0') {
                    self::agregarDiferencia(
                        $reporte,
                        'Nota de crédito',
                        $metadata['emisor']['nombre'],
                        $total,
                        'No se encontró la nota de crédito de proveedor.',
                        $uuid,
                        $metadata['fecha_certificacion'],
                        null
                    );
                }
            }
        }
    }

    private static function agregarDiferencia(&$reporte, $tipo, $persona, $total, $problema, $uuid, $fecha, $url)
    {
        $reporte['diferencias'][] = [
            'tipo' => $tipo,
            'persona' => $persona,
            'total' => '$ ' . number_format($total, 2),
            'problema' => $problema,
            'uuid' => $uuid,
            'fecha' => Carbon::parse($fecha)->format('d/m/Y H:i:s'),
            'url' => $url
        ];

        $reporte['totales']++;
    }
}
