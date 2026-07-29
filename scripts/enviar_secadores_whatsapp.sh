#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

BASE_URL="${REPORT_BASE_URL:-http://localhost:8081}"
CAPTURE_PUBLIC_PATH="${SECADORES_CAPTURE_PUBLIC_PATH:-/assets/generated/secadores-whatsapp}"
OUTPUT_DIR="${SECADORES_CAPTURE_DIR:-${PROJECT_ROOT}${CAPTURE_PUBLIC_PATH}}"
CHROME_BIN="${CHROME_BIN:-google-chrome}"
SERVICE_WHATSAPP_BASE_URL="${SERVICE_WHATSAPP_BASE_URL:-http://localhost:3000}"
SERVICE_WHATSAPP_IMAGE_ENDPOINT="${SERVICE_WHATSAPP_IMAGE_ENDPOINT:-/send-image}"
SERVICE_WHATSAPP_GROUP_ID="${SERVICE_WHATSAPP_GROUP_ID:-120363409935727036@g.us}"
SERVICE_WHATSAPP_API_KEY="${SERVICE_WHATSAPP_API_KEY:-WhatsappSErVices1313}"
SERVICE_WHATSAPP_IMAGE_REFERENCE="${SERVICE_WHATSAPP_IMAGE_REFERENCE:-path}"
SERVICE_WHATSAPP_DOCKER_CONTAINER="${SERVICE_WHATSAPP_DOCKER_CONTAINER:-whatsapp-service}"
SERVICE_WHATSAPP_CONTAINER_CAPTURE_DIR="${SERVICE_WHATSAPP_CONTAINER_CAPTURE_DIR:-/tmp/secadores-whatsapp}"

mkdir -p "$OUTPUT_DIR"

capture_secador() {
  local secador="$1"
  local label="$2"
  local output="$OUTPUT_DIR/${secador}.png"
  local url="${BASE_URL}/reports/secadores/index.php?capture=1&secador=${secador}"
  local chrome_profile="$OUTPUT_DIR/chrome-profile-${secador}"

  "$CHROME_BIN" \
    --headless \
    --disable-gpu \
    --no-sandbox \
    --disable-dev-shm-usage \
    --disable-crash-reporter \
    --disable-breakpad \
    --disable-background-networking \
    --disable-sync \
    "--user-data-dir=${chrome_profile}" \
    --hide-scrollbars \
    --window-size=1120,1600 \
    "--screenshot=${output}" \
    "$url" >/dev/null

  if [[ ! -s "$output" ]]; then
    echo "No se pudo generar la captura: $output" >&2
    return 1
  fi

  printf '%s\n' "$output"
}

json_escape() {
  if command -v python3 >/dev/null 2>&1; then
    JSON_VALUE="$1" python3 -c 'import json, os; print(json.dumps(os.environ["JSON_VALUE"], ensure_ascii=False), end="")'
    return
  fi

  if command -v node >/dev/null 2>&1; then
    JSON_VALUE="$1" node -e 'process.stdout.write(JSON.stringify(process.env.JSON_VALUE || ""))'
    return
  fi

  printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g')"
}

send_whatsapp_image() {
  local image_path="$1"
  local caption="$2"
  local image_name
  image_name="$(basename "$image_path")"

  if [[ -z "${SERVICE_WHATSAPP_GROUP_ID:-}" ]]; then
    echo "SERVICE_WHATSAPP no configurado. Define SERVICE_WHATSAPP_GROUP_ID. Captura lista: $image_path"
    return 0
  fi

  local endpoint
  endpoint="${SERVICE_WHATSAPP_BASE_URL%/}/${SERVICE_WHATSAPP_IMAGE_ENDPOINT#/}"
  local image_ref
  if [[ "$SERVICE_WHATSAPP_IMAGE_REFERENCE" == "url" ]]; then
    image_ref="${BASE_URL%/}${CAPTURE_PUBLIC_PATH}/${image_name}?v=$(date +%s)"
  else
    image_ref="${SERVICE_WHATSAPP_CONTAINER_CAPTURE_DIR%/}/${image_name}"
    if [[ -n "${SERVICE_WHATSAPP_DOCKER_CONTAINER:-}" ]] && command -v docker >/dev/null 2>&1; then
      docker exec "$SERVICE_WHATSAPP_DOCKER_CONTAINER" mkdir -p "$SERVICE_WHATSAPP_CONTAINER_CAPTURE_DIR" >/dev/null
      docker cp "$image_path" "${SERVICE_WHATSAPP_DOCKER_CONTAINER}:${image_ref}" >/dev/null
    fi
  fi
  local payload
  payload="{\"groupId\":$(json_escape "$SERVICE_WHATSAPP_GROUP_ID"),\"imageUrl\":$(json_escape "$image_ref"),\"caption\":$(json_escape "$caption")}"

  local header_args=()
  if [[ -n "${SERVICE_WHATSAPP_API_KEY:-}" ]]; then
    header_args=(-H "x-api-key: ${SERVICE_WHATSAPP_API_KEY}")
  fi

  curl -sS -X POST "$endpoint" \
    -H "Content-Type: application/json" \
    "${header_args[@]}" \
    --data "$payload" >/dev/null

  echo "Enviado a grupo ${SERVICE_WHATSAPP_GROUP_ID}: ${caption}"
}

main() {
  local now
  now="$(date '+%d/%m/%Y %H:%M')"

  local secador_1 secador_2
  secador_1="$(capture_secador "tunel_1" "Secador 1")"
  secador_2="$(capture_secador "tunel_2" "Secador 2")"

  send_whatsapp_image "$secador_1" "Secador 1 | ${now}"
  send_whatsapp_image "$secador_2" "Secador 2 | ${now}"
}

main "$@"
