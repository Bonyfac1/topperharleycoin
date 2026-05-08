#!/usr/bin/env bash
# Deploy index.html to Forpsi web hosting via FTP.
#
# Reads FTP_HOST / FTP_USER / FTP_PASS from .ftp-credentials (gitignored).
# Uses curl (built into macOS) for the FTP transfer.
#
# To force TLS instead of plain FTP, add --ssl-reqd to the curl call below.

set -euo pipefail

cd "$(dirname "$0")"

CRED_FILE=".ftp-credentials"
# Files to upload, as "local:remote" pairs. Remote paths are relative to the
# FTP login directory; Forpsi's web root is www/.
UPLOADS=(
  "index.html:www/index.html"
  "history.php:www/history.php"
  "holders.php:www/holders.php"
  "assets/logo.png:www/assets/logo.png"
)

if [[ ! -f "$CRED_FILE" ]]; then
  echo "Error: $CRED_FILE not found in $(pwd)." >&2
  echo "Create it with:" >&2
  echo "  FTP_HOST=ftpx.forpsi.com" >&2
  echo "  FTP_USER=www.topperharleycoin.com" >&2
  echo "  FTP_PASS=yourpassword" >&2
  exit 1
fi

# Refuse to run if the credentials file is world- or group-readable.
if [[ "$(uname)" == "Darwin" ]]; then
  perms=$(stat -f '%A' "$CRED_FILE")
else
  perms=$(stat -c '%a' "$CRED_FILE")
fi
if [[ "${perms: -2}" != "00" ]]; then
  echo "Warning: $CRED_FILE is readable by others (mode $perms)." >&2
  echo "Tighten with: chmod 600 $CRED_FILE" >&2
fi

# Source the file. Values with spaces or special chars must be quoted in the file.
set -a
# shellcheck disable=SC1090
. "./$CRED_FILE"
set +a

: "${FTP_HOST:?FTP_HOST not set in $CRED_FILE}"
: "${FTP_USER:?FTP_USER not set in $CRED_FILE}"
: "${FTP_PASS:?FTP_PASS not set in $CRED_FILE}"

for entry in "${UPLOADS[@]}"; do
  local_file="${entry%%:*}"
  remote_path="${entry#*:}"
  if [[ ! -f "$local_file" ]]; then
    echo "Error: $local_file not found in $(pwd)." >&2
    exit 1
  fi
done

# --user passes credentials via the auth header, not the URL, so the password
# never appears in process listings or the URL. -S shows errors, -s silences
# the progress bar but keeps error output, --fail returns non-zero on HTTP/FTP
# errors so `set -e` catches them.
for entry in "${UPLOADS[@]}"; do
  local_file="${entry%%:*}"
  remote_path="${entry#*:}"
  echo "Uploading $local_file -> ftp://$FTP_HOST/$remote_path"
  curl --fail -S -s --ftp-create-dirs \
       --user "$FTP_USER:$FTP_PASS" \
       --upload-file "$local_file" \
       "ftp://$FTP_HOST/$remote_path"
done

echo "Upload complete."
