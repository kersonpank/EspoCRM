# EspoCRM TSM — coração comercial da TransMesquita

Código e operação do EspoCRM em https://vendas.mesquitagrupo.com.br (Espo **10.0.11**, Docker Swarm via Portainer, MariaDB 11.4).

## Estrutura
| Pasta | O que é |
|---|---|
| `extensao/tsm-core/` | Extensão própria **TSM Core** (módulo `TsmCore`). `gen.py` gera tudo; `Resolver.php` é a porta única. `./build.sh` → `tsm-core-<versão>.zip` |
| `custom-espo/Resources/` | Espelho de `custom/Espo/Custom` da produção (layouts, rótulos e campos criados pela UI). Referência/restauração — a fonte é a produção. |
| `scripts/` | Scripts de manutenção já usados: `migrar.php` (migração inicial), `corrigir.php` (CNPJ com zero à esquerda + fusão de duplicadas, simula por padrão, `APLICAR=1` grava), `teste-hml.php` |
| `ops/` | `backup-espocrm.sh` (cron 02h30), `espocrm-prod-v10.yml` (stack do Portainer, senhas por variável de ambiente), `hml-compose.yml` (homologação descartável) |

## Instalar / atualizar a extensão (sem perder dados)
1. Subir a versão em `gen.py` (`VERSION`), rodar `./build.sh`.
2. Backup antes: `/home/claude-ops/bin/backup-espocrm.sh`.
3. Administração → Extensões → enviar o zip **ou** no servidor:
   `docker cp tsm-core-X.zip <container>:/tmp/t.zip && docker exec -u www-data <container> php command.php extension --file=/tmp/t.zip`
4. Testar em homologação antes quando mexer em hook ou Resolver.

Extensão = atualizável: atualizações do Espo não apagam `custom/Espo/Modules/TsmCore`.

## Porta única — `POST /api/v1/TsmResolver`
Todo agente/fluxo grava no CRM **só** por aqui (nunca POST direto em Account/Contact).

```json
{
  "conta":   {"cnpj":"", "nome":"", "papel":[], "fonte":[], "cicloVida":"", "canaisPermitidos":[], "baseLegal":"", "tiposVeiculo":[], "regioesAtendidas":[]},
  "contato": {"nome":"", "telefone":"", "email":"", "papel":[], "fonte":[], "whatsappJid":"", "cpf":""},
  "toque":   {"canal":"whatsapp", "ocorridoEm":"ISO-8601"},
  "interacao": {"assunto":"", "canal":"", "direcao":"entrada|saida", "ocorridoEm":"", "resumo":"", "transcricao":"", "sinais":[], "origemSistema":"", "idExterno":"", "refBruto":""},
  "fatos": [{"campo":"", "valor":"", "fonte":"", "confianca":"alta|media|baixa", "agente":"", "refBruto":""}],
  "oportunidade": {"cotacaoLogre":"", "nome":"", "stage":"", "amount":0, "tipoFrete":"cif|fob", "origem":"", "destino":""},
  "responsavelId": ""
}
```
Retorna `matchConta`, `matchContato`, `criou[]`, `accountId`, `contactId`, `opportunityId`, `interacaoId`, `fatosIds`.

Regras: identidade CNPJ (com zeros à esquerda recolocados) > telefone/e-mail do contato > nome exato sem CNPJ conflitante · nunca sobrescreve com vazio · arrays somam · ciclo de vida só avança (respeita `naoTocar`) · Interação idempotente por `idExterno` · Fato sem repetir campo+valor.

## Modelo de dados (resumo)
- **Conta/Contato**: `papelRede`, `cicloVida`, `fonte`, `ultimoToque(+Canal)`, `proximaAcao(+Em)`, LGPD (`naoContatar`, `canaisPermitidos`, `baseLegal`), capacidade (`regioesAtendidas`, `tiposVeiculo`, `rntrc`). Conta: `cnpj`, `cnpjRaiz`, `grupoEmpresarial`, `confiancaCadastro`. Contato: `cpf`, `whatsappJid`.
- **Oportunidade**: `cotacaoLogre` (chave), `tipoFrete`, `origem`, `destino`, `pesoKg`, `canalOrigem`, `fonte`.
- **Novas**: `GrupoEmpresarial`, `Interacao`, `Fato`.

## Backup
- Servidor: cron 02h30 (`ops/backup-espocrm.sh`) → `/home/claude-ops/backups/espocrm/<data>` (dump completo + volumes v10), 7 dias.
- Externo: n8n `GHcQZtEDQJkoVv5U` às 03h15 → Google Drive (Comercial TSM) `Backups TSM/EspoCRM`, retenção 7 diários + 8 semanais + 12 mensais (vai para a lixeira), alerta no grupo VENDAS se falhar.

## Integrações
- n8n `KDPu2lKimsyXaXgn` — Closer → Espo (a cada 2 min), grava via porta única.
- Extensão oficial `ext-mcp` (servidor MCP dentro do Espo) para agentes lerem o CRM.
