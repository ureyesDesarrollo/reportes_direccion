<?php

declare(strict_types=1);

/**
 * Lee un CFDI de gas natural y devuelve el consumo y sus importes principales.
 *
 * El consumo se obtiene de todos los conceptos con ClaveProdServ 15111512
 * expresados en metros cúbicos. El costo usado por el reporte corresponde al
 * subtotal antes de IVA; el total facturado se conserva como referencia.
 *
 * @throws InvalidArgumentException Cuando el archivo no es válido o no es CFDI.
 * @throws RuntimeException Cuando el CFDI no contiene el consumo esperado.
 */
function readGasNaturalInvoiceXml(string $filePath, ?float $calorificValueMjM3 = null): array
{
    $filePath = trim($filePath);
    if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
        throw new InvalidArgumentException('El XML de gas natural no existe o no se puede leer.');
    }

    $fileSize = @filesize($filePath);
    if (is_int($fileSize) && $fileSize > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('El XML de gas natural excede el límite de 10 MB.');
    }

    $contents = @file_get_contents($filePath);
    if (!is_string($contents) || trim($contents) === '') {
        throw new InvalidArgumentException('El XML de gas natural está vacío.');
    }

    return readGasNaturalInvoiceXmlContent($contents, $calorificValueMjM3);
}

/**
 * Procesa el contenido de un CFDI de gas natural sin depender de un archivo.
 *
 * Esta variante permite reutilizar el lector con archivos subidos o XML
 * obtenidos posteriormente desde una API.
 */
function readGasNaturalInvoiceXmlContent(string $contents, ?float $calorificValueMjM3 = null): array
{
    if (trim($contents) === '') {
        throw new InvalidArgumentException('El contenido del XML de gas natural está vacío.');
    }

    $previousInternalErrors = libxml_use_internal_errors(true);
    libxml_clear_errors();

    try {
        $xml = simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$xml instanceof SimpleXMLElement) {
            throw new InvalidArgumentException('El archivo no contiene un XML válido.');
        }

        if (strcasecmp($xml->getName(), 'Comprobante') !== 0) {
            throw new InvalidArgumentException('El XML no corresponde a un CFDI de factura.');
        }

        $namespaces = $xml->getNamespaces(true);
        $cfdiNamespace = (string)($namespaces['cfdi'] ?? '');
        if ($cfdiNamespace === '') {
            throw new InvalidArgumentException('El XML no contiene el namespace CFDI.');
        }

        $attributes = $xml->attributes();
        $cfdi = $xml->children($cfdiNamespace);
        $consumptionM3 = 0.0;
        $consumptionGj = 0.0;
        $gasConceptAmount = 0.0;
        $gasConcepts = 0;
        $estimatedConsumption = false;

        foreach ($cfdi->Conceptos->Concepto as $concept) {
            $conceptAttributes = $concept->attributes();
            $productKey = trim((string)($conceptAttributes['ClaveProdServ'] ?? ''));
            $unitKey = strtoupper(trim((string)($conceptAttributes['ClaveUnidad'] ?? '')));
            $unit = strtoupper(str_replace('³', '3', trim((string)($conceptAttributes['Unidad'] ?? ''))));
            $description = trim((string)($conceptAttributes['Descripcion'] ?? ''));
            $asciiDescription = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $description);
            $normalizedDescription = strtoupper($asciiDescription !== false ? $asciiDescription : $description);
            $isGasPurchase = $productKey === '15111512' || strpos($normalizedDescription, 'COMPRAVENTA DE GAS NATURAL') !== false;
            $isCubicMeters = $unitKey === 'MTQ' || $unit === 'M3';
            $isGigajoules = $unitKey === 'GV' || $unit === 'GJ';

            if (!$isGasPurchase || (!$isCubicMeters && !$isGigajoules)) {
                continue;
            }

            $quantity = normalizeGasInvoiceNumber((string)($conceptAttributes['Cantidad'] ?? ''));
            $amount = normalizeGasInvoiceNumber((string)($conceptAttributes['Importe'] ?? ''));
            if ($quantity === null || $quantity < 0) {
                throw new InvalidArgumentException('El concepto de gas contiene una cantidad inválida.');
            }

            if ($isCubicMeters) {
                $consumptionM3 += $quantity;
            } elseif ($calorificValueMjM3 !== null && $calorificValueMjM3 > 0) {
                $consumptionGj += $quantity;
                $consumptionM3 += ($quantity * 1000) / $calorificValueMjM3;
                $estimatedConsumption = true;
            } else {
                continue;
            }
            if ($amount !== null && $amount >= 0) {
                $gasConceptAmount += $amount;
            }
            $gasConcepts++;
        }

        if ($gasConcepts === 0) {
            throw new RuntimeException('El CFDI no contiene conceptos de gas natural en metros cúbicos.');
        }

        $subtotal = normalizeGasInvoiceNumber((string)($attributes['SubTotal'] ?? ''));
        $total = normalizeGasInvoiceNumber((string)($attributes['Total'] ?? ''));
        if ($subtotal === null || $subtotal < 0 || $total === null || $total < 0) {
            throw new InvalidArgumentException('El CFDI no contiene importes válidos.');
        }

        $taxesTransferred = null;
        if (isset($cfdi->Impuestos)) {
            $taxAttributes = $cfdi->Impuestos->attributes();
            $taxesTransferred = normalizeGasInvoiceNumber((string)($taxAttributes['TotalImpuestosTrasladados'] ?? ''));
        }

        $invoiceDate = trim((string)($attributes['Fecha'] ?? ''));
        if ($invoiceDate !== '') {
            $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', substr($invoiceDate, 0, 19));
            $invoiceDate = $parsedDate instanceof DateTimeImmutable ? $parsedDate->format('Y-m-d') : substr($invoiceDate, 0, 10);
        }

        return [
            'consumo_m3' => $consumptionM3,
            'consumo_gj' => $consumptionGj > 0 ? $consumptionGj : null,
            'consumo_estimado' => $estimatedConsumption,
            'poder_calorifico_mj_m3' => $estimatedConsumption ? $calorificValueMjM3 : null,
            'costo' => $subtotal,
            'costo_sin_iva' => $subtotal,
            'total_factura' => $total,
            'impuestos_trasladados' => $taxesTransferred,
            'importe_concepto_gas' => $gasConceptAmount,
            'conceptos_gas' => $gasConcepts,
            'fecha_factura' => $invoiceDate,
            'serie' => trim((string)($attributes['Serie'] ?? '')),
            'folio' => trim((string)($attributes['Folio'] ?? '')),
            'moneda' => trim((string)($attributes['Moneda'] ?? '')),
        ];
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);
    }
}

function normalizeGasInvoiceNumber(string $value): ?float
{
    $normalized = str_replace([',', ' '], '', trim($value));
    return $normalized !== '' && is_numeric($normalized) ? (float)$normalized : null;
}
