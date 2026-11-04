#!/usr/bin/env python3
"""Gera a extensão TSM Core para EspoCRM 10 (módulo TsmCore)."""
import json, os, shutil

ROOT = os.path.join(os.path.dirname(__file__), 'tsm-core')
MOD = 'TsmCore'
VERSION = '0.3.0'
if os.path.exists(ROOT):
    shutil.rmtree(ROOT)

def w(path, data):
    p = os.path.join(ROOT, path)
    os.makedirs(os.path.dirname(p), exist_ok=True)
    with open(p, 'w', encoding='utf-8') as f:
        if isinstance(data, str):
            f.write(data)
        else:
            json.dump(data, f, ensure_ascii=False, indent=4)

B = f'files/custom/Espo/Modules/{MOD}'
R = f'{B}/Resources'

# ---------------- listas ----------------
PAPEL = {
    'clienteCif': 'Cliente CIF (remetente)', 'clienteFob': 'Cliente FOB (destinatário paga)',
    'destinatario': 'Destinatário', 'consignatario': 'Consignatário', 'leadCotacao': 'Lead de cotação',
    'prospect': 'Prospect', 'parceiroTransportadora': 'Transportadora parceira', 'agregado': 'Agregado',
    'motorista': 'Motorista', 'fornecedor': 'Fornecedor', 'concorrente': 'Concorrente',
    'representante': 'Representante', 'interno': 'Interno (TSM)'}
CICLO = {'identificado': 'Identificado', 'engajado': 'Engajado', 'qualificado': 'Qualificado',
         'ativo': 'Ativo', 'dormente': 'Dormente', 'perdido': 'Perdido', 'naoTocar': 'Não tocar'}
FONTE = {'logreCotacao': 'Logre - cotação', 'logreCadastro': 'Logre - cadastro', 'closer': 'Closer (Kerson)',
         'whatsappInbound': 'WhatsApp inbound', 'sdrEmail': 'SDR e-mail', 'listaFria': 'Lista fria',
         'repCampo': 'Representante em campo', 'indicacao': 'Indicação', 'site': 'Site', 'ligacao': 'Ligação',
         'receitaFederal': 'Receita Federal', 'manual': 'Manual'}
CANAL = {'whatsapp': 'WhatsApp', 'email': 'E-mail', 'ligacao': 'Ligação', 'sms': 'SMS',
         'visita': 'Visita', 'reuniao': 'Reunião', 'outro': 'Outro'}
CANAIS_PERM = {k: CANAL[k] for k in ['whatsapp', 'email', 'ligacao', 'sms']}
BASE_LEGAL = {'naoDefinida': 'Não definida', 'legitimoInteresse': 'Legítimo interesse',
              'contrato': 'Execução de contrato', 'consentimento': 'Consentimento'}
VEICULO = {'moto': 'Moto', 'utilitario': 'Utilitário (Fiorino/Saveiro)', 'van': 'Van', 'vuc': 'VUC',
           'tresQuartos': '3/4', 'toco': 'Toco', 'truck': 'Truck', 'carreta': 'Carreta',
           'bitrem': 'Bitrem', 'rodotrem': 'Rodotrem'}
CONF = {'alta': 'Alta', 'media': 'Média', 'baixa': 'Baixa'}
DIRECAO = {'entrada': 'Recebida', 'saida': 'Enviada', 'interna': 'Interna'}
TIPO_FRETE = {'naoDefinido': 'Não definido', 'cif': 'CIF', 'fob': 'FOB'}

def enum(opts, default=None, multi=False, **kw):
    d = {'type': 'multiEnum' if multi else 'enum', 'options': list(opts.keys()), 'isCustom': False}
    if not multi:
        d['default'] = default or list(opts.keys())[0]
    d.update(kw)
    return d

# campos comuns a Conta e Contato
COMMON = {
    'papelRede': enum(PAPEL, multi=True),
    'cicloVida': enum(CICLO, 'identificado'),
    'fonte': enum(FONTE, multi=True),
    'ultimoToque': {'type': 'datetime'},
    'ultimoToqueCanal': enum({'': '', **CANAL}, ''),
    'proximaAcao': {'type': 'varchar', 'maxLength': 255},
    'proximaAcaoEm': {'type': 'date'},
    'naoContatar': {'type': 'bool'},
    'naoContatarMotivo': {'type': 'varchar', 'maxLength': 255},
    'canaisPermitidos': enum(CANAIS_PERM, multi=True),
    'baseLegal': enum(BASE_LEGAL, 'naoDefinida'),
    'regioesAtendidas': {'type': 'array', 'allowCustomOptions': True, 'noEmptyString': True},
    'tiposVeiculo': enum(VEICULO, multi=True),
    'rntrc': {'type': 'varchar', 'maxLength': 20},
    'interacoes': {'type': 'linkMultiple', 'layoutDetailDisabled': True, 'layoutListDisabled': True,
                   'layoutMassUpdateDisabled': True, 'noLoad': True, 'importDisabled': True,
                   'exportDisabled': True, 'isCustom': False},
    'fatos': {'type': 'linkMultiple', 'layoutDetailDisabled': True, 'layoutListDisabled': True,
              'layoutMassUpdateDisabled': True, 'noLoad': True, 'importDisabled': True,
              'exportDisabled': True, 'isCustom': False},
}
for v in COMMON.values():
    v.setdefault('isCustom', False)

acc_fields = dict(COMMON)
acc_fields.update({
    'cnpj': {'type': 'varchar', 'maxLength': 14, 'tooltip': True},
    'cnpjRaiz': {'type': 'varchar', 'maxLength': 8, 'readOnly': True, 'tooltip': True},
    'grupoEmpresarial': {'type': 'link'},
    'confiancaCadastro': enum({'': '', **CONF}, ''),
})
con_fields = dict(COMMON)
con_fields.update({
    'cpf': {'type': 'varchar', 'maxLength': 11},
    'whatsappJid': {'type': 'varchar', 'maxLength': 60},
})

def rel_links(foreign):
    return {
        'interacoes': {'type': 'hasMany', 'entity': 'Interacao', 'foreign': foreign},
        'fatos': {'type': 'hasMany', 'entity': 'Fato', 'foreign': foreign},
    }

acc_links = rel_links('account')
acc_links['grupoEmpresarial'] = {'type': 'belongsTo', 'entity': 'GrupoEmpresarial', 'foreign': 'accounts'}
w(f'{R}/metadata/entityDefs/Account.json', {
    'fields': acc_fields, 'links': acc_links,
    'indexes': {'cnpj': {'columns': ['cnpj', 'deleted']}, 'cnpjRaiz': {'columns': ['cnpjRaiz', 'deleted']}}})
w(f'{R}/metadata/entityDefs/Contact.json', {
    'fields': con_fields, 'links': rel_links('contact'),
    'indexes': {'whatsappJid': {'columns': ['whatsappJid', 'deleted']}}})

opp_fields = {
    'tipoFrete': enum(TIPO_FRETE, 'naoDefinido'),
    'origem': {'type': 'varchar', 'maxLength': 120},
    'destino': {'type': 'varchar', 'maxLength': 120},
    'cotacaoLogre': {'type': 'varchar', 'maxLength': 30},
    'pesoKg': {'type': 'float'},
    'canalOrigem': enum({'': '', **CANAL}, ''),
    'fonte': enum(FONTE, multi=True),
    'interacoes': COMMON['interacoes'], 'fatos': COMMON['fatos'],
}
w(f'{R}/metadata/entityDefs/Opportunity.json', {
    'fields': opp_fields, 'links': rel_links('opportunity'),
    'indexes': {'cotacaoLogre': {'columns': ['cotacaoLogre', 'deleted']}}})

# ---------------- entidades novas ----------------
def base_fields():
    return {
        'createdAt': {'type': 'datetime', 'readOnly': True},
        'modifiedAt': {'type': 'datetime', 'readOnly': True},
        'createdBy': {'type': 'link', 'readOnly': True, 'view': 'views/fields/user'},
        'modifiedBy': {'type': 'link', 'readOnly': True, 'view': 'views/fields/user'},
        'assignedUser': {'type': 'link', 'view': 'views/fields/assigned-user'},
    }

def base_links():
    return {
        'createdBy': {'type': 'belongsTo', 'entity': 'User'},
        'modifiedBy': {'type': 'belongsTo', 'entity': 'User'},
        'assignedUser': {'type': 'belongsTo', 'entity': 'User'},
    }

def scope(icon_tab=True, stream=False):
    return {'entity': True, 'layouts': True, 'tab': icon_tab, 'acl': True, 'aclPortal': False,
            'aclActionList': ['create', 'read', 'edit', 'delete'],
            'customizable': True, 'importable': True, 'notifications': False, 'stream': stream,
            'disabled': False, 'type': 'Base', 'module': MOD, 'object': True, 'isCustom': False,
            'hasAssignedUser': True}

# GrupoEmpresarial
g_fields = base_fields()
g_fields.update({
    'name': {'type': 'varchar', 'required': True, 'maxLength': 150, 'pattern': '$noBadCharacters'},
    'cnpjRaiz': {'type': 'varchar', 'maxLength': 8},
    'description': {'type': 'text'},
    'accounts': {'type': 'linkMultiple', 'layoutDetailDisabled': True, 'layoutListDisabled': True,
                 'noLoad': True, 'importDisabled': True, 'exportDisabled': True},
})
g_links = base_links()
g_links['accounts'] = {'type': 'hasMany', 'entity': 'Account', 'foreign': 'grupoEmpresarial'}
w(f'{R}/metadata/entityDefs/GrupoEmpresarial.json', {
    'fields': g_fields, 'links': g_links,
    'collection': {'orderBy': 'name', 'order': 'asc', 'textFilterFields': ['name', 'cnpjRaiz']},
    'indexes': {'cnpjRaiz': {'columns': ['cnpjRaiz', 'deleted']}}})
w(f'{R}/metadata/scopes/GrupoEmpresarial.json', scope())
w(f'{R}/metadata/clientDefs/GrupoEmpresarial.json', {
    'controller': 'controllers/record', 'iconClass': 'fas fa-sitemap'})

def rel3():
    return {
        'account': {'type': 'link'}, 'contact': {'type': 'link'}, 'opportunity': {'type': 'link'},
    }

def rel3links():
    return {
        'account': {'type': 'belongsTo', 'entity': 'Account', 'foreign': 'interacoes'},
        'contact': {'type': 'belongsTo', 'entity': 'Contact', 'foreign': 'interacoes'},
        'opportunity': {'type': 'belongsTo', 'entity': 'Opportunity', 'foreign': 'interacoes'},
    }

# Interacao
i_fields = base_fields()
i_fields.update({
    'name': {'type': 'varchar', 'required': True, 'maxLength': 200},
    'canal': enum(CANAL, 'whatsapp'),
    'direcao': enum(DIRECAO, 'entrada'),
    'ocorridoEm': {'type': 'datetime', 'required': True, 'default': 'javascript: return this.dateTime.getNow(1);'},
    'duracaoSeg': {'type': 'int', 'min': 0},
    'resumo': {'type': 'text'},
    'transcricao': {'type': 'text'},
    'sinais': {'type': 'array', 'allowCustomOptions': True, 'noEmptyString': True},
    'origemSistema': {'type': 'varchar', 'maxLength': 60},
    'idExterno': {'type': 'varchar', 'maxLength': 150, 'readOnly': False},
    'refBruto': {'type': 'varchar', 'maxLength': 255},
})
i_fields.update(rel3())
i_links = base_links()
i_links.update(rel3links())
w(f'{R}/metadata/entityDefs/Interacao.json', {
    'fields': i_fields, 'links': i_links,
    'collection': {'orderBy': 'ocorridoEm', 'order': 'desc', 'textFilterFields': ['name', 'resumo']},
    'indexes': {'idExterno': {'columns': ['idExterno', 'deleted']},
                'ocorridoEm': {'columns': ['ocorridoEm', 'deleted']}}})
w(f'{R}/metadata/scopes/Interacao.json', scope())
w(f'{R}/metadata/clientDefs/Interacao.json', {
    'controller': 'controllers/record', 'iconClass': 'fas fa-comments'})

# Fato
f_fields = base_fields()
f_fields.update({
    'name': {'type': 'varchar', 'required': True, 'maxLength': 200},
    'campo': {'type': 'varchar', 'maxLength': 100, 'required': True},
    'valor': {'type': 'text'},
    'fonte': enum({**FONTE, 'conversa': 'Conversa', 'iaExtracao': 'Extração por IA'}, 'manual'),
    'confianca': enum(CONF, 'media'),
    'observadoEm': {'type': 'datetime'},
    'refBruto': {'type': 'varchar', 'maxLength': 255},
    'agente': {'type': 'varchar', 'maxLength': 100},
})
f_fields.update(rel3())
f_links = base_links()
f_links.update({
    'account': {'type': 'belongsTo', 'entity': 'Account', 'foreign': 'fatos'},
    'contact': {'type': 'belongsTo', 'entity': 'Contact', 'foreign': 'fatos'},
    'opportunity': {'type': 'belongsTo', 'entity': 'Opportunity', 'foreign': 'fatos'},
})
w(f'{R}/metadata/entityDefs/Fato.json', {
    'fields': f_fields, 'links': f_links,
    'collection': {'orderBy': 'createdAt', 'order': 'desc', 'textFilterFields': ['name', 'campo', 'valor']}})
w(f'{R}/metadata/scopes/Fato.json', scope(icon_tab=False))
w(f'{R}/metadata/clientDefs/Fato.json', {
    'controller': 'controllers/record', 'iconClass': 'fas fa-clipboard-check'})

# ---------------- layouts das entidades novas ----------------
def row(*names):
    return [{'name': n} if n else False for n in names]

w(f'{R}/layouts/GrupoEmpresarial/detail.json', [{'label': '', 'rows': [row('name', 'cnpjRaiz'), row('description', None)]}])
w(f'{R}/layouts/GrupoEmpresarial/detailSmall.json', [{'label': '', 'rows': [row('name', 'cnpjRaiz')]}])
w(f'{R}/layouts/GrupoEmpresarial/list.json', [{'name': 'name', 'link': True}, {'name': 'cnpjRaiz'}, {'name': 'createdAt'}])
w(f'{R}/layouts/GrupoEmpresarial/listSmall.json', [{'name': 'name', 'link': True}, {'name': 'cnpjRaiz'}])
w(f'{R}/layouts/GrupoEmpresarial/filters.json', ['cnpjRaiz', 'createdAt'])
w(f'{R}/layouts/GrupoEmpresarial/relationships.json', ['accounts'])

w(f'{R}/layouts/Interacao/detail.json', [
    {'label': '', 'rows': [row('name', 'ocorridoEm'), row('canal', 'direcao'), row('account', 'contact'),
                           row('opportunity', 'duracaoSeg'), row('resumo', None), row('sinais', None)]},
    {'label': 'Origem', 'rows': [row('origemSistema', 'idExterno'), row('refBruto', None), row('transcricao', None)]}])
w(f'{R}/layouts/Interacao/detailSmall.json', [{'label': '', 'rows': [row('name', 'ocorridoEm'), row('canal', 'direcao'), row('resumo', None)]}])
w(f'{R}/layouts/Interacao/list.json', [
    {'name': 'name', 'link': True}, {'name': 'canal', 'width': 10}, {'name': 'direcao', 'width': 10},
    {'name': 'account'}, {'name': 'contact'}, {'name': 'ocorridoEm', 'width': 14}])
w(f'{R}/layouts/Interacao/listSmall.json', [
    {'name': 'name', 'link': True}, {'name': 'canal', 'width': 14}, {'name': 'direcao', 'width': 14}, {'name': 'ocorridoEm', 'width': 20}])
w(f'{R}/layouts/Interacao/filters.json', ['canal', 'direcao', 'ocorridoEm', 'account', 'contact', 'origemSistema'])

w(f'{R}/layouts/Fato/detail.json', [{'label': '', 'rows': [row('name', 'confianca'), row('campo', 'fonte'), row('valor', None),
                                                         row('account', 'contact'), row('opportunity', 'observadoEm'),
                                                         row('agente', 'refBruto')]}])
w(f'{R}/layouts/Fato/detailSmall.json', [{'label': '', 'rows': [row('campo', 'confianca'), row('valor', None), row('fonte', None)]}])
w(f'{R}/layouts/Fato/list.json', [{'name': 'name', 'link': True}, {'name': 'campo'}, {'name': 'fonte'}, {'name': 'confianca', 'width': 10}, {'name': 'createdAt', 'width': 14}])
w(f'{R}/layouts/Fato/listSmall.json', [{'name': 'name', 'link': True}, {'name': 'confianca', 'width': 14}, {'name': 'fonte', 'width': 20}])
w(f'{R}/layouts/Fato/filters.json', ['campo', 'fonte', 'confianca', 'account', 'contact'])

# ---------------- i18n ----------------
def opt(d):
    return {k: v for k, v in d.items()}

common_labels = {
    'papelRede': 'Papel na rede', 'cicloVida': 'Ciclo de vida', 'fonte': 'Fonte',
    'ultimoToque': 'Último toque', 'ultimoToqueCanal': 'Canal do último toque',
    'proximaAcao': 'Próxima ação', 'proximaAcaoEm': 'Próxima ação em', 'naoContatar': 'Não contatar',
    'naoContatarMotivo': 'Motivo de não contatar', 'canaisPermitidos': 'Canais permitidos',
    'baseLegal': 'Base legal (LGPD)', 'regioesAtendidas': 'Regiões/rotas atendidas',
    'tiposVeiculo': 'Tipos de veículo', 'rntrc': 'RNTRC', 'interacoes': 'Interações', 'fatos': 'Fatos'}
common_opts = {'papelRede': opt(PAPEL), 'cicloVida': opt(CICLO), 'fonte': opt(FONTE),
               'ultimoToqueCanal': {'': '', **CANAL}, 'canaisPermitidos': opt(CANAIS_PERM),
               'baseLegal': opt(BASE_LEGAL), 'tiposVeiculo': opt(VEICULO)}

for lang in ['pt_BR', 'en_US']:
    L = f'{R}/i18n/{lang}'
    w(f'{L}/Global.json', {
        'scopeNames': {'GrupoEmpresarial': 'Grupo empresarial', 'Interacao': 'Interação', 'Fato': 'Fato'},
        'scopeNamesPlural': {'GrupoEmpresarial': 'Grupos empresariais', 'Interacao': 'Interações', 'Fato': 'Fatos'}})
    w(f'{L}/Account.json', {
        'fields': {**common_labels, 'cnpj': 'CNPJ/CPF', 'cnpjRaiz': 'Raiz do CNPJ',
                   'grupoEmpresarial': 'Grupo empresarial', 'confiancaCadastro': 'Confiança do cadastro'},
        'links': {'interacoes': 'Interações', 'fatos': 'Fatos', 'grupoEmpresarial': 'Grupo empresarial'},
        'options': {**common_opts, 'confiancaCadastro': {'': '', **CONF}},
        'tooltips': {'cnpj': 'Só números. A raiz (8 primeiros dígitos) é preenchida automaticamente.',
                     'cnpjRaiz': 'Agrupa matriz e filiais.'}})
    w(f'{L}/Contact.json', {
        'fields': {**common_labels, 'cpf': 'CPF', 'whatsappJid': 'WhatsApp (JID)'},
        'links': {'interacoes': 'Interações', 'fatos': 'Fatos'},
        'options': common_opts})
    w(f'{L}/Opportunity.json', {
        'fields': {'tipoFrete': 'Tipo de frete', 'origem': 'Origem', 'destino': 'Destino',
                   'cotacaoLogre': 'Cotação Logre', 'pesoKg': 'Peso (kg)', 'canalOrigem': 'Canal de origem',
                   'fonte': 'Fonte', 'interacoes': 'Interações', 'fatos': 'Fatos'},
        'links': {'interacoes': 'Interações', 'fatos': 'Fatos'},
        'options': {'tipoFrete': opt(TIPO_FRETE), 'canalOrigem': {'': '', **CANAL}, 'fonte': opt(FONTE)}})
    w(f'{L}/GrupoEmpresarial.json', {
        'fields': {'name': 'Nome', 'cnpjRaiz': 'Raiz do CNPJ', 'description': 'Descrição', 'accounts': 'Contas'},
        'links': {'accounts': 'Contas'},
        'labels': {'Create GrupoEmpresarial': 'Criar grupo empresarial'}})
    w(f'{L}/Interacao.json', {
        'fields': {'name': 'Assunto', 'canal': 'Canal', 'direcao': 'Direção', 'ocorridoEm': 'Ocorrido em',
                   'duracaoSeg': 'Duração (s)', 'resumo': 'Resumo', 'transcricao': 'Transcrição', 'sinais': 'Sinais',
                   'origemSistema': 'Sistema de origem', 'idExterno': 'ID externo', 'refBruto': 'Referência do bruto',
                   'account': 'Conta', 'contact': 'Contato', 'opportunity': 'Oportunidade'},
        'links': {'account': 'Conta', 'contact': 'Contato', 'opportunity': 'Oportunidade'},
        'options': {'canal': opt(CANAL), 'direcao': opt(DIRECAO)},
        'labels': {'Create Interacao': 'Registrar interação'}})
    w(f'{L}/Fato.json', {
        'fields': {'name': 'Resumo', 'campo': 'Campo', 'valor': 'Valor', 'fonte': 'Fonte', 'confianca': 'Confiança',
                   'observadoEm': 'Observado em', 'refBruto': 'Referência do bruto', 'agente': 'Agente',
                   'account': 'Conta', 'contact': 'Contato', 'opportunity': 'Oportunidade'},
        'links': {'account': 'Conta', 'contact': 'Contato', 'opportunity': 'Oportunidade'},
        'options': {'fonte': {**opt(FONTE), 'conversa': 'Conversa', 'iaExtracao': 'Extração por IA'}, 'confianca': opt(CONF)},
        'labels': {'Create Fato': 'Registrar fato'}})

# ---------------- PHP ----------------
w(f'{R}/module.json', {'order': 60})
w(f'{R}/routes.json', [{'route': '/TsmResolver', 'method': 'post', 'actionClassName': 'Espo\\Modules\\TsmCore\\Api\\Resolver'}])
w(f'{B}/Api/Resolver.php', open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'Resolver.php'), encoding='utf-8').read())
for ent in ['GrupoEmpresarial', 'Interacao', 'Fato']:
    w(f'{B}/Entities/{ent}.php', f"""<?php
namespace Espo\\Modules\\{MOD}\\Entities;

class {ent} extends \\Espo\\Core\\ORM\\Entity
{{
    public const ENTITY_TYPE = '{ent}';
}}
""")
    w(f'{B}/Controllers/{ent}.php', f"""<?php
namespace Espo\\Modules\\{MOD}\\Controllers;

class {ent} extends \\Espo\\Core\\Controllers\\Record
{{}}
""")

w(f'{B}/Hooks/Account/NormalizarCnpj.php', f"""<?php
namespace Espo\\Modules\\{MOD}\\Hooks\\Account;

use Espo\\Core\\Hook\\Hook\\BeforeSave;
use Espo\\ORM\\Entity;
use Espo\\ORM\\Repository\\Option\\SaveOptions;

/**
 * Mantém o CNPJ/CPF só com dígitos e preenche a raiz (8 primeiros dígitos) quando for CNPJ.
 * @implements BeforeSave<Entity>
 */
class NormalizarCnpj implements BeforeSave
{{
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {{
        if (!$entity->isNew() && !$entity->isAttributeChanged('cnpj')) {{
            return;
        }}
        $digitos = preg_replace('/\\D/', '', (string) $entity->get('cnpj'));
        $n = strlen($digitos);
        if ($n === 12 || $n === 13) {{
            $digitos = str_pad($digitos, 14, '0', STR_PAD_LEFT);
        }} elseif ($n === 9 || $n === 10) {{
            $digitos = str_pad($digitos, 11, '0', STR_PAD_LEFT);
        }}
        $entity->set('cnpj', $digitos === '' ? null : $digitos);
        $entity->set('cnpjRaiz', strlen($digitos) === 14 ? substr($digitos, 0, 8) : null);
    }}
}}
""")

w(f'{B}/Hooks/Contact/NormalizarCpf.php', f"""<?php
namespace Espo\\Modules\\{MOD}\\Hooks\\Contact;

use Espo\\Core\\Hook\\Hook\\BeforeSave;
use Espo\\ORM\\Entity;
use Espo\\ORM\\Repository\\Option\\SaveOptions;

/**
 * @implements BeforeSave<Entity>
 */
class NormalizarCpf implements BeforeSave
{{
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {{
        if (!$entity->isAttributeChanged('cpf')) {{
            return;
        }}
        $digitos = preg_replace('/\\D/', '', (string) $entity->get('cpf'));
        $entity->set('cpf', $digitos === '' ? null : $digitos);
    }}
}}
""")

w('manifest.json', {
    'name': 'TSM Core', 'version': VERSION, 'acceptableVersions': ['>=10.0.0'], 'php': ['>=8.2'],
    'releaseDate': '2026-11-04', 'author': 'TransMesquita / Claude',
    'description': 'Base do CRM da TSM: CNPJ e raiz, grupo empresarial, papel na rede, ciclo de vida, fonte, LGPD, '
                   'dados de parceiros (veículo, regiões, RNTRC), Interações e Fatos com fonte e confiança.'})
print('ok', sum(len(f) for _, _, f in os.walk(ROOT)), 'arquivos')
