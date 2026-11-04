#!/usr/bin/env bash
# Gera e empacota a extensão TSM Core. Uso: ./build.sh  -> tsm-core-<versao>.zip
set -euo pipefail
cd "$(dirname "$0")"
python3 gen.py
V=$(python3 -c "import json;print(json.load(open('tsm-core/manifest.json'))['version'])")
rm -f "tsm-core-$V.zip"
(cd tsm-core && zip -qr "../tsm-core-$V.zip" .)
echo "tsm-core-$V.zip"
