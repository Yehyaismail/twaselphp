#!/usr/bin/env bash
# تجهيز حزمة رفع جاهزة للاستضافة المجانية.
# شغّل: bash build_package.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/../tawasul-php-ready.zip"
cd "$ROOT"
rm -f "$OUT"
zip -r "$OUT" . \
  -x "uploads/*" \
  -x "storage/*" \
  -x "*.log" \
  -x ".DS_Store"
echo "تم إنشاء الحزمة: $OUT"
