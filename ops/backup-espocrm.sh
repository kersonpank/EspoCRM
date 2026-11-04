#!/usr/bin/env bash
# Backup diário do EspoCRM (stack Swarm "espocrm").
# Gera: db.sql.gz (dump completo do MariaDB), espocrm_data.tgz (volume da aplicação),
# services.json (definição dos serviços) e SHA256SUMS. Mantém RETENCAO_DIAS cópias locais.
# Grava o resultado em $BASE/ULTIMO_STATUS para o monitor do n8n.
set -euo pipefail
umask 077

BASE=/home/claude-ops/backups/espocrm
RETENCAO_DIAS=${RETENCAO_DIAS:-7}
STAMP=$(date +%F_%H%M)
D="$BASE/$STAMP"
STATUS="$BASE/ULTIMO_STATUS"

falhar() { echo "ERRO $(date -Is) $1" > "$STATUS"; echo "ERRO: $1" >&2; exit 1; }
trap 'falhar "linha $LINENO"' ERR

mkdir -p "$D"
DB=$(docker ps -q -f name=espocrm_espocrm-db.1 | head -1)
[ -n "$DB" ] || falhar "container do banco não encontrado"

docker exec "$DB" sh -c 'P="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}"; mariadb-dump -uroot -p"$P" --single-transaction --routines --triggers --events --all-databases' | gzip > "$D/db.sql.gz"
gunzip -t "$D/db.sql.gz" || falhar "dump corrompido"
zcat "$D/db.sql.gz" | tail -1 | grep -q "Dump completed" || falhar "dump incompleto"

# EspoCRM 10: data, custom e client/custom em volumes separados (o tar reproduz a árvore data/, custom/, client/custom/)
docker run --rm -v espocrm_v10_data:/v/data:ro -v espocrm_v10_custom:/v/custom:ro -v espocrm_v10_custom_client:/v/client/custom:ro -v "$D":/dst alpine sh -c "tar czf /dst/espocrm_data.tgz -C /v . && chown $(id -u):$(id -g) /dst/espocrm_data.tgz && chmod 600 /dst/espocrm_data.tgz"
tar tzf "$D/espocrm_data.tgz" > /dev/null || falhar "tar corrompido"

docker service inspect espocrm_espocrm espocrm_espocrm-db espocrm_espocrm-daemon espocrm_espocrm-websocket > "$D/services.json" 2>/dev/null || true
(cd "$D" && sha256sum db.sql.gz espocrm_data.tgz services.json > SHA256SUMS)

# retenção local
find "$BASE" -mindepth 1 -maxdepth 1 -type d -name '20*' -mtime +"$RETENCAO_DIAS" -exec rm -rf {} +

TAM=$(du -sh "$D" | cut -f1)
echo "OK $(date -Is) $STAMP $TAM" > "$STATUS"
echo "OK $STAMP $TAM"
