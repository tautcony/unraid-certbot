#!/bin/bash
set -e

ENV_FILE=".env"
CONFIG_DIR="./cloudflare"
CERTBOT_IMAGE="certbot/dns-cloudflare"
CERT_DIR="/mnt/user/appdata/letsencrypt"
CLOUDFLARE_CRED_FILE="${CONFIG_DIR}/cloudflare.ini"

# ===== Load configuration =====
if [ ! -f "$ENV_FILE" ]; then
  echo "❌ Config file .env not found, please create it"
  exit 1
fi

export $(grep -v '^#' "$ENV_FILE" | xargs)

# Check required variables in .env
if [ -z "$EMAIL" ] || [ -z "$HOSTNAME" ] || [ -z "$DOMAINS" ]; then
  echo "❌ .env must contain EMAIL, HOSTNAME, and DOMAINS"
  exit 1
fi

# Check if cloudflare.ini exists
if [ ! -f "$CLOUDFLARE_CRED_FILE" ]; then
  echo "❌ Missing Cloudflare credentials file: ${CLOUDFLARE_CRED_FILE}"
  echo "Please create this file manually and set permissions to 600"
  exit 1
fi

# Ensure cert directory exists
mkdir -p "$CERT_DIR"

# Build domain parameters
IFS=',' read -ra DOMAIN_ARR <<< "$DOMAINS"
DOMAIN_ARGS=()
for domain in "${DOMAIN_ARR[@]}"; do
  DOMAIN_ARGS+=("-d" "$domain")
done

# Run certbot docker command
docker run --rm \
  -v "${CERT_DIR}:/etc/letsencrypt" \
  -v "${CONFIG_DIR}:/cloudflare" \
  ${CERTBOT_IMAGE} certonly \
  --dns-cloudflare \
  --dns-cloudflare-credentials /cloudflare/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  --non-interactive \
  --agree-tos \
  --email "${EMAIL}" \
  "${DOMAIN_ARGS[@]}"

# Merge certificates
PRIMARY_DOMAIN=$(echo "$DOMAINS" | cut -d',' -f1)
LIVE_PATH="${CERT_DIR}/live/${PRIMARY_DOMAIN}"

CERT_FILE="${LIVE_PATH}/fullchain.pem"
KEY_FILE="${LIVE_PATH}/privkey.pem"
OUTPUT_FILE="/boot/config/ssl/certs/${HOSTNAME}_unraid_bundle.pem"
TEMP_BUNDLE=$(mktemp)

if [[ ! -f "$CERT_FILE" || ! -f "$KEY_FILE" ]]; then
  echo "❌ Certificate files not generated: $CERT_FILE or $KEY_FILE not found"
  exit 1
fi

cat "$CERT_FILE" "$KEY_FILE" > "$TEMP_BUNDLE"
chmod 600 "$TEMP_BUNDLE"

# Compare with current certificate
if [ -f "$OUTPUT_FILE" ]; then
  if cmp -s "$TEMP_BUNDLE" "$OUTPUT_FILE"; then
    echo "ℹ️ Certificate has not changed. Skipping merge and restart."
    rm "$TEMP_BUNDLE"
    exit 0
  fi

  # Certificate changed, backup
  BACKUP_DATE=$(date +"%Y%m%d")
  BACKUP_FILE="${OUTPUT_FILE}.${BACKUP_DATE}"
  echo "🌀 Backing up old certificate to: $BACKUP_FILE"
  cp "$OUTPUT_FILE" "$BACKUP_FILE"
  chmod 600 "$BACKUP_FILE"
fi

# Replace with new certificate
mv "$TEMP_BUNDLE" "$OUTPUT_FILE"
chmod 600 "$OUTPUT_FILE"
echo "✅ New certificate written to: $OUTPUT_FILE"

# Restart nginx service
echo "🔁 Restarting Unraid Web Management service..."
/etc/rc.d/rc.nginx restart

echo "✅ SSL certificate request and merge completed"
