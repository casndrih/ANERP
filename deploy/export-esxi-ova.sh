#!/usr/bin/env bash
set -Eeuo pipefail

: "${GOVC_URL:?Set GOVC_URL to the ESXi/vCenter endpoint, e.g. https://esxi.example.local/sdk}"
: "${GOVC_USERNAME:?Set GOVC_USERNAME to an export-capable ESXi/vCenter account}"
: "${GOVC_PASSWORD:?Set GOVC_PASSWORD}"
: "${ANERP_ESXI_VM:?Set ANERP_ESXI_VM to the powered-off, validated ANERP appliance VM name}"

command -v govc >/dev/null 2>&1 || { echo "govc is required (https://github.com/vmware/govmomi)." >&2; exit 1; }
command -v tar >/dev/null 2>&1 || { echo "tar is required." >&2; exit 1; }

OUTPUT_DIR="${1:-dist}"
mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd)"
EXPORT_DIR="$(mktemp -d)"
trap 'rm -rf "$EXPORT_DIR"' EXIT

if [[ "${GOVC_INSECURE:-}" == "1" ]]; then
  export GOVC_INSECURE=1
fi

govc vm.info "$ANERP_ESXI_VM" >/dev/null
echo "Exporting '$ANERP_ESXI_VM'. Ensure it is powered off and has been validated on the target ESXi version."
govc export.ovf -vm "$ANERP_ESXI_VM" -o "$EXPORT_DIR"

mapfile -t OVF_FILES < <(find "$EXPORT_DIR" -maxdepth 1 -type f -name '*.ovf' -printf '%f\n' | sort)
if [[ "${#OVF_FILES[@]}" -ne 1 ]]; then
  echo "Expected exactly one OVF descriptor; found ${#OVF_FILES[@]}." >&2
  exit 1
fi
OVF_FILE="${OVF_FILES[0]}"
mapfile -t OTHER_FILES < <(find "$EXPORT_DIR" -maxdepth 1 -type f ! -name '*.ovf' -printf '%f\n' | sort)
if [[ "${#OTHER_FILES[@]}" -eq 0 ]]; then
  echo "The OVF export contains no virtual disk or supporting files." >&2
  exit 1
fi

SAFE_NAME="$(printf '%s' "$ANERP_ESXI_VM" | tr -cs 'A-Za-z0-9._-' '-')"
OVA_PATH="$OUTPUT_DIR/$SAFE_NAME.ova"
tar -cf "$OVA_PATH" -C "$EXPORT_DIR" "$OVF_FILE" "${OTHER_FILES[@]}"
tar -tf "$OVA_PATH" >/dev/null
sha256sum "$OVA_PATH" > "$OVA_PATH.sha256"

echo "OVA created: $OVA_PATH"
echo "SHA-256 recorded: $OVA_PATH.sha256"
echo "Test-import the OVA into a non-production ESXi inventory before release."
