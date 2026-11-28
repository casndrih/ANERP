#!/usr/bin/env bash
set -Eeuo pipefail

: "${GOVC_URL:?Set GOVC_URL to the ESXi/vCenter endpoint, e.g. https://esxi.example.local/sdk}"
: "${GOVC_USERNAME:?Set GOVC_USERNAME to an export-capable ESXi/vCenter account}"
: "${GOVC_PASSWORD:?Set GOVC_PASSWORD}"
: "${ANERP_ESXI_VM:?Set ANERP_ESXI_VM to the powered-off, validated ANERP appliance VM name}"

command -v govc >/dev/null 2>&1 || { echo "govc is required (https://github.com/vmware/govmomi)." >&2; exit 1; }
OUTPUT_DIR="${1:-dist}"
mkdir -p "$OUTPUT_DIR"

if [[ "${GOVC_INSECURE:-}" == "1" ]]; then
  export GOVC_INSECURE=1
fi

if ! govc vm.info "$ANERP_ESXI_VM" >/dev/null; then
  echo "VM '$ANERP_ESXI_VM' was not found or is not accessible." >&2
  exit 1
fi

echo "Exporting '$ANERP_ESXI_VM' to '$OUTPUT_DIR'. Power off the VM and verify its guest networking before export."
govc export.ovf -vm "$ANERP_ESXI_VM" -o "$OUTPUT_DIR"
if ! find "$OUTPUT_DIR" -maxdepth 1 -type f \( -name '*.ova' -o -name '*.ovf' \) -print -quit | grep -q .; then
  echo "Export completed without an OVA/OVF file in $OUTPUT_DIR." >&2
  exit 1
fi
echo "Export artifact created. Test-import it into a non-production ESXi inventory before release."
