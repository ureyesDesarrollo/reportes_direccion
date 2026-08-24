<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, max-age=300');

function financeDetailResponse(int $status, array $payload): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$provider = filter_input(INPUT_GET, 'proveedor', FILTER_VALIDATE_INT);
$invoice = trim((string)($_GET['factura'] ?? ''));
if (
  !is_int($provider)
  || $provider <= 0
  || $invoice === ''
  || strlen($invoice) > 100
  || preg_match('/[\x00-\x1F\x7F]/', $invoice) === 1
  || strpos($invoice, '/') !== false
  || strpos($invoice, '\\') !== false
) {
  financeDetailResponse(400, ['ok' => false, 'message' => 'La factura solicitada no es válida.']);
}

try {
  $config = require __DIR__ . '/config.php';
  $apiConfig = (array)($config['api_facturas_compra'] ?? []);
  $baseUrl = rtrim(trim((string)($apiConfig['url'] ?? '')), '/');
  $segment = trim((string)($apiConfig['detail_segment'] ?? 'MAT'));
  if ($baseUrl === '' || preg_match('/^[A-Za-z0-9_-]+$/', $segment) !== 1) {
    throw new RuntimeException('La consulta de partidas no está configurada.');
  }
  if (!function_exists('curl_init')) throw new RuntimeException('La extensión cURL no está disponible.');

  $salesConfig = require __DIR__ . '/../ventas/config.php';
  $apiKey = trim((string)($salesConfig['pedidos_api']['api_key'] ?? ''));
  if ($apiKey === '') throw new RuntimeException('No está configurada la autorización del API.');

  $requestUrl = $baseUrl . '/' . rawurlencode($segment) . '/' . $provider . '/' . rawurlencode($invoice);
  $ch = curl_init($requestUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey, 'Accept: application/json'],
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => max(5, (int)($apiConfig['timeout'] ?? 30)),
  ]);
  $response = curl_exec($ch);
  $curlError = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if (!is_string($response)) throw new RuntimeException($curlError !== '' ? $curlError : 'El API no respondió.');
  if ($status === 404) financeDetailResponse(404, ['ok' => false, 'message' => 'No se encontraron partidas para esta factura.']);
  if ($status < 200 || $status >= 300) throw new RuntimeException('El API respondió con HTTP ' . $status . '.');

  try {
    $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
  } catch (Throwable $exception) {
    throw new RuntimeException('El API devolvió una respuesta JSON inválida.');
  }
  $data = (array)($payload['data'] ?? []);
  if (($payload['ok'] ?? false) !== true || !is_array($data['partidas'] ?? null)) {
    throw new RuntimeException('El API devolvió una estructura inesperada.');
  }

  $items = [];
  foreach ($data['partidas'] as $row) {
    if (!is_array($row)) continue;
    $amounts = (array)($row['importes'] ?? []);
    $quantities = (array)($row['cantidades'] ?? []);
    $references = (array)($row['referencias'] ?? []);
    $items[] = [
      'partida' => is_numeric($row['partida'] ?? null) ? (int)$row['partida'] : null,
      'clave_producto' => trim((string)($row['clave_producto'] ?? '')),
      'producto' => trim((string)($row['producto']['nombre'] ?? '')),
      'descripcion' => trim((string)($row['producto']['descripcion'] ?? '')),
      'cantidad' => is_numeric($quantities['cantidad'] ?? null) ? (float)$quantities['cantidad'] : null,
      'cantidad_surtida' => is_numeric($quantities['cantidad_surtida'] ?? null) ? (float)$quantities['cantidad_surtida'] : null,
      'unidad' => trim((string)($row['unidad'] ?? '')),
      'valor_unitario' => is_numeric($amounts['valor_unitario'] ?? null) ? (float)$amounts['valor_unitario'] : null,
      'subtotal' => is_numeric($amounts['subtotal'] ?? null) ? (float)$amounts['subtotal'] : null,
      'descuento' => is_numeric($amounts['descuento'] ?? null) ? (float)$amounts['descuento'] : null,
      'iva' => is_numeric($amounts['iva'] ?? null) ? (float)$amounts['iva'] : null,
      'ieps' => is_numeric($amounts['ieps'] ?? null) ? (float)$amounts['ieps'] : null,
      'pedido' => is_numeric($references['numero_pedido'] ?? null) ? (int)$references['numero_pedido'] : null,
      'orden' => is_numeric($references['numero_orden'] ?? null) ? (int)$references['numero_orden'] : null,
      'requisicion' => is_numeric($references['numero_requisicion'] ?? null) ? (int)$references['numero_requisicion'] : null,
      'lote' => trim((string)($row['lote']['numero'] ?? '')),
    ];
  }

  financeDetailResponse(200, [
    'ok' => true,
    'cantidad' => count($items),
    'moneda' => (string)($data['factura']['moneda']['clave'] ?? ''),
    'partidas' => $items,
  ]);
} catch (Throwable $exception) {
  financeDetailResponse(502, ['ok' => false, 'message' => 'No fue posible consultar las partidas: ' . $exception->getMessage()]);
}
