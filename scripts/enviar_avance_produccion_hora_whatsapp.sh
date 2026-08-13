#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

BASE_URL="${REPORT_BASE_URL:-http://localhost:8081}"
CAPTURE_PUBLIC_PATH="${AVANCE_HORA_CAPTURE_PUBLIC_PATH:-/assets/generated/avance-produccion-hora-whatsapp}"
OUTPUT_DIR="${AVANCE_HORA_CAPTURE_DIR:-/tmp/avance-produccion-hora-whatsapp-captures}"
CHROME_BIN="${CHROME_BIN:-google-chrome}"
SERVICE_WHATSAPP_BASE_URL="${SERVICE_WHATSAPP_BASE_URL:-http://localhost:3000}"
SERVICE_WHATSAPP_IMAGE_ENDPOINT="${SERVICE_WHATSAPP_IMAGE_ENDPOINT:-/send-image}"
SERVICE_WHATSAPP_GROUP_ID="${SERVICE_WHATSAPP_GROUP_ID:-120363409935727036@g.us}"
SERVICE_WHATSAPP_API_KEY="${SERVICE_WHATSAPP_API_KEY:-WhatsappSErVices1313}"
SERVICE_WHATSAPP_IMAGE_REFERENCE="${SERVICE_WHATSAPP_IMAGE_REFERENCE:-path}"
SERVICE_WHATSAPP_DOCKER_CONTAINER="${SERVICE_WHATSAPP_DOCKER_CONTAINER:-whatsapp-service}"
SERVICE_WHATSAPP_CONTAINER_CAPTURE_DIR="${AVANCE_HORA_WHATSAPP_CONTAINER_DIR:-/tmp/avance-produccion-hora-whatsapp}"
CAPTURE_ONLY="${AVANCE_HORA_CAPTURE_ONLY:-0}"
REPORT_VIEW="${AVANCE_HORA_VIEW:-hora}"
REPORT_PERIOD="${AVANCE_HORA_PERIOD:-actual}"
PREVIEW_TURNO="${AVANCE_HORA_PREVIEW_TURNO:-}"
REPORT_HOUR="${AVANCE_HORA_REPORT_HOUR:-$(TZ=Etc/GMT+6 date +%H)}"

if [[ ! "$REPORT_HOUR" =~ ^[0-9]{1,2}$ ]] || ((10#$REPORT_HOUR > 23)); then
  echo "AVANCE_HORA_REPORT_HOUR debe ser una hora entre 0 y 23" >&2
  exit 1
fi

# El envío de tarimas de las 07:xx corresponde al día operativo que acaba
# de cerrar (07:00 del día anterior a 07:00 del día actual).
if [[ "$REPORT_VIEW" == "tarimas" ]] && ((10#$REPORT_HOUR == 7)); then
  REPORT_PERIOD="anterior"
fi

if [[ "$REPORT_VIEW" != "hora" && "$REPORT_VIEW" != "tarimas" && "$REPORT_VIEW" != "turno-anterior" ]]; then
  echo "AVANCE_HORA_VIEW debe ser hora, tarimas o turno-anterior" >&2
  exit 1
fi

if [[ "$REPORT_PERIOD" != "actual" && "$REPORT_PERIOD" != "anterior" ]]; then
  echo "AVANCE_HORA_PERIOD debe ser actual o anterior" >&2
  exit 1
fi

if [[ -n "$PREVIEW_TURNO" && "$PREVIEW_TURNO" != "2" ]]; then
  echo "AVANCE_HORA_PREVIEW_TURNO solo admite el valor 2" >&2
  exit 1
fi

mkdir -p "$OUTPUT_DIR"

json_escape() {
  if command -v python3 >/dev/null 2>&1; then
    JSON_VALUE="$1" python3 -c 'import json, os; print(json.dumps(os.environ["JSON_VALUE"], ensure_ascii=False), end="")'
    return
  fi

  printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g')"
}

capture_report() {
  local preview_suffix=""
  if [[ -n "$PREVIEW_TURNO" ]]; then
    preview_suffix="-preview-turno-${PREVIEW_TURNO}"
  fi
  local output="$OUTPUT_DIR/avance-produccion-${REPORT_VIEW}-${REPORT_PERIOD}${preview_suffix}.png"
  local profile="$OUTPUT_DIR/chrome-profile"
  local url="${BASE_URL%/}/reports/avance-produccion-hora/index.php?capture=1&vista=${REPORT_VIEW}&periodo=${REPORT_PERIOD}"
  if [[ -n "$PREVIEW_TURNO" ]]; then
    url="${url}&preview_turno=${PREVIEW_TURNO}"
  fi
  local capture_height=640
  if [[ "$REPORT_VIEW" == "hora" ]] && { [[ "$PREVIEW_TURNO" == "2" ]] || ((10#$REPORT_HOUR >= 19 || 10#$REPORT_HOUR < 7)); }; then
    capture_height=790
  fi

  echo "Generando avance-produccion-hora: vista=${REPORT_VIEW}, periodo=${REPORT_PERIOD}, hora_cdmx=${REPORT_HOUR}, url=${url}" >&2

  "$CHROME_BIN" \
    --headless \
    --disable-gpu \
    --no-sandbox \
    --disable-dev-shm-usage \
    --disable-crash-reporter \
    --disable-breakpad \
    --disable-background-networking \
    --disable-sync \
    --force-device-scale-factor=2 \
    "--user-data-dir=${profile}" \
    --hide-scrollbars \
    "--window-size=912,${capture_height}" \
    "--screenshot=${output}" \
    "$url" >/dev/null

  if [[ ! -s "$output" ]]; then
    echo "No se pudo generar la captura: $output" >&2
    return 1
  fi

  printf '%s\n' "$output"
}

send_whatsapp_image() {
  local image_path="$1"
  local caption="$2"
  local image_name
  image_name="$(basename "$image_path")"
  local endpoint="${SERVICE_WHATSAPP_BASE_URL%/}/${SERVICE_WHATSAPP_IMAGE_ENDPOINT#/}"
  local image_ref

  if [[ "$SERVICE_WHATSAPP_IMAGE_REFERENCE" == "url" ]]; then
    image_ref="${BASE_URL%/}${CAPTURE_PUBLIC_PATH}/${image_name}?v=$(date +%s)"
  else
    image_ref="${SERVICE_WHATSAPP_CONTAINER_CAPTURE_DIR%/}/${image_name}"
    docker exec "$SERVICE_WHATSAPP_DOCKER_CONTAINER" mkdir -p "$SERVICE_WHATSAPP_CONTAINER_CAPTURE_DIR" >/dev/null
    docker cp "$image_path" "${SERVICE_WHATSAPP_DOCKER_CONTAINER}:${image_ref}" >/dev/null
  fi

  local payload
  payload="{\"groupId\":$(json_escape "$SERVICE_WHATSAPP_GROUP_ID"),\"imageUrl\":$(json_escape "$image_ref"),\"caption\":$(json_escape "$caption")}" 
  local header_args=()
  if [[ -n "$SERVICE_WHATSAPP_API_KEY" ]]; then
    header_args=(-H "x-api-key: ${SERVICE_WHATSAPP_API_KEY}")
  fi

  curl -sS -X POST "$endpoint" \
    -H "Content-Type: application/json" \
    "${header_args[@]}" \
    --data "$payload" >/dev/null

  echo "Enviado a grupo ${SERVICE_WHATSAPP_GROUP_ID}: ${caption}"
}

main() {
  local image_path
  image_path="$(capture_report)"

  if [[ "$CAPTURE_ONLY" == "1" ]]; then
    echo "Captura lista: $image_path"
    return 0
  fi

  local caption
  if [[ "$REPORT_VIEW" == "turno-anterior" ]]; then
    caption="Cierre de Producción | Turno anterior | $(date '+%d/%m/%Y %H:%M')"
  elif [[ "$REPORT_VIEW" == "tarimas" && "$REPORT_PERIOD" == "anterior" ]]; then
    caption="Cierre de Producción | Tarimas por turno | $(date '+%d/%m/%Y %H:%M')"
  elif [[ "$REPORT_VIEW" == "tarimas" ]]; then
    caption="Avance de Producción | Tarimas por turno | $(date '+%d/%m/%Y %H:%M')"
  else
    caption="Avance de Producción Hora por Hora | $(date '+%d/%m/%Y %H:%M')"
  fi
  if [[ -n "$PREVIEW_TURNO" ]]; then
    caption="PRUEBA | ${caption} | Vista Turno ${PREVIEW_TURNO}"
  fi
  send_whatsapp_image "$image_path" "$caption"
}

main "$@"
