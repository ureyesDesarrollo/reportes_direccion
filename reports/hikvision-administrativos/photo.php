<?php

declare(strict_types=1);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';

$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
$username = (string)($config['username'] ?? '');
$password = (string)($config['password'] ?? '');
$verifySsl = (bool)($config['verify_ssl'] ?? false);
$timeout = (int)($config['curl_timeout'] ?? 60);

if ($baseUrl === '' || $username === '' || $password === '') {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'Configura las credenciales de Hikvision.';
  exit;
}

if (!function_exists('curl_init')) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'PHP no tiene habilitada la extensión cURL.';
  exit;
}

$encodedUrl = trim((string)($_GET['url'] ?? ''));
if ($encodedUrl === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'Falta la URL de la imagen.';
  exit;
}

$imageUrl = base64_decode(strtr($encodedUrl, '-_', '+/'), true);
if (!is_string($imageUrl) || $imageUrl === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'La URL de la imagen no es válida.';
  exit;
}

$imageUrl = trim($imageUrl);
$baseParts = parse_url($baseUrl);
$imageParts = parse_url($imageUrl);

if (!is_array($baseParts) || !is_array($imageParts)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'No fue posible interpretar la URL de la imagen.';
  exit;
}

$sameHost = strtolower((string)($baseParts['host'] ?? '')) === strtolower((string)($imageParts['host'] ?? ''));
$sameScheme = strtolower((string)($baseParts['scheme'] ?? 'https')) === strtolower((string)($imageParts['scheme'] ?? 'https'));
$samePort = (int)($baseParts['port'] ?? 443) === (int)($imageParts['port'] ?? 443);

if (!$sameHost || !$sameScheme || !$samePort) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'La imagen solicitada no pertenece al equipo Hikvision configurado.';
  exit;
}

$ch = curl_init($imageUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
  CURLOPT_USERPWD => $username . ':' . $password,
  CURLOPT_TIMEOUT => $timeout,
  CURLOPT_SSL_VERIFYPEER => $verifySsl,
  CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
  CURLOPT_HEADER => true,
]);

$response = curl_exec($ch);
if ($response === false) {
  $error = curl_error($ch);
  curl_close($ch);
  http_response_code(502);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'No fue posible descargar la imagen: ' . $error;
  exit;
}

$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

$headersRaw = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

if ($httpCode < 200 || $httpCode >= 300) {
  http_response_code($httpCode > 0 ? $httpCode : 502);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'Hikvision devolvió un error al consultar la imagen.';
  exit;
}

if ($contentType === '') {
  if (preg_match('/^Content-Type:\s*([^\r\n]+)/mi', $headersRaw, $matches) === 1) {
    $contentType = trim($matches[1]);
  } else {
    $contentType = 'image/jpeg';
  }
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($body));
echo $body;
