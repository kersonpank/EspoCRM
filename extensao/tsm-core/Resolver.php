<?php
namespace Espo\Modules\TsmCore\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Porta única do CRM (POST /api/v1/TsmResolver).
 *
 * Recebe o que um agente/fluxo sabe sobre empresa, pessoa, toque, interação, fatos e oportunidade,
 * resolve a identidade (CNPJ > telefone > e-mail > nome exato da conta), cria ou atualiza sem duplicar
 * e devolve os IDs. Nunca apaga nem sobrescreve dado existente com vazio.
 */
class Resolver implements Action
{
    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private SelectBuilderFactory $selectBuilderFactory,
        private User $user
    ) {}

    public function process(Request $request): Response
    {
        foreach (['Account', 'Contact', 'Interacao'] as $scope) {
            if (!$this->acl->checkScope($scope, 'create') || !$this->acl->checkScope($scope, 'edit')) {
                throw new Forbidden("Sem permissão em $scope.");
            }
        }

        $body = json_decode(json_encode($request->getParsedBody()), true) ?: [];
        $conta = $body['conta'] ?? [];
        $contato = $body['contato'] ?? [];
        $toque = $body['toque'] ?? [];
        $responsavelId = $body['responsavelId'] ?? null;

        $cnpj = $this->documento($conta['cnpj'] ?? '');
        $telefone = $this->digitos($contato['telefone'] ?? '');
        if ($telefone !== '' && strlen($telefone) <= 11) {
            $telefone = '55' . $telefone;
        }
        $email = strtolower(trim((string) ($contato['email'] ?? '')));
        $nomeConta = trim((string) ($conta['nome'] ?? ''));

        if ($cnpj === '' && $telefone === '' && $email === '' && $nomeConta === '') {
            throw new BadRequest('Informe ao menos CNPJ, telefone, e-mail ou nome da conta.');
        }

        $out = ['matchConta' => null, 'matchContato' => null, 'criou' => []];

        // ---------- contato existente (telefone > e-mail) ----------
        $contact = null;
        if (strlen($telefone) >= 10) {
            $contact = $this->buscar('Contact', 'contains', 'phoneNumber', substr($telefone, -9));
            $out['matchContato'] = $contact ? 'telefone' : null;
        }
        if (!$contact && $email !== '') {
            $contact = $this->buscar('Contact', 'equals', 'emailAddress', $email);
            $out['matchContato'] = $contact ? 'email' : null;
        }

        // ---------- conta (CNPJ > conta do contato > e-mail > nome) ----------
        $account = null;
        if (strlen($cnpj) >= 11) {
            $account = $this->em->getRDBRepository('Account')->where(['cnpj' => $cnpj])->findOne();
            $out['matchConta'] = $account ? 'cnpj' : null;
        }
        if (!$account && $contact && $contact->get('accountId')) {
            $candidata = $this->em->getEntityById('Account', $contact->get('accountId'));
            // só aceita a conta do contato se não houver CNPJ conflitante
            if ($candidata && (!$cnpj || !$candidata->get('cnpj') || $candidata->get('cnpj') === $cnpj)) {
                $account = $candidata;
                $out['matchConta'] = 'contato';
            }
        }
        if (!$account && $nomeConta !== '') {
            $candidata = $this->em->getRDBRepository('Account')->where(['name' => $nomeConta])->order('createdAt', 'ASC')->findOne();
            // pelo nome só quando não há CNPJ conflitante
            if ($candidata && (!$cnpj || !$candidata->get('cnpj') || $candidata->get('cnpj') === $cnpj)) {
                $account = $candidata;
                $out['matchConta'] = 'nome';
            }
        }

        $agora = gmdate('Y-m-d H:i:s');
        $toqueEm = !empty($toque['ocorridoEm']) ? $this->dataUtc($toque['ocorridoEm']) : null;

        if (!$account && ($nomeConta !== '' || $cnpj !== '')) {
            $account = $this->em->getNewEntity('Account');
            $account->set([
                'name' => $nomeConta !== '' ? mb_substr($nomeConta, 0, 249) : ('CNPJ ' . $cnpj),
                'cicloVida' => $conta['cicloVida'] ?? 'identificado',
                'createdById' => $this->user->getId(),
                'assignedUserId' => $responsavelId,
            ]);
            $out['criou'][] = 'conta';
            $out['matchConta'] = 'novo';
        }

        if ($account) {
            if ($cnpj && !$account->get('cnpj')) {
                $account->set('cnpj', $cnpj);
            }
            $this->aplicarCampos($account, $conta, ['website', 'industry', 'description']);
            $this->mesclar($account, 'papelRede', $conta['papel'] ?? []);
            $this->mesclar($account, 'fonte', $conta['fonte'] ?? []);
            $this->mesclar($account, 'canaisPermitidos', $conta['canaisPermitidos'] ?? []);
            $this->mesclar($account, 'tiposVeiculo', $conta['tiposVeiculo'] ?? []);
            $this->mesclar($account, 'regioesAtendidas', $conta['regioesAtendidas'] ?? []);
            $this->avancarCiclo($account, $conta['cicloVida'] ?? null);
            if (!empty($conta['baseLegal']) && in_array($account->get('baseLegal'), [null, '', 'naoDefinida'], true)) {
                $account->set('baseLegal', $conta['baseLegal']);
            }
            if ($toqueEm) {
                $this->tocar($account, $toqueEm, $toque['canal'] ?? null);
            }
            if ($telefone && !$account->get('phoneNumber') && in_array('conta', $out['criou'], true)) {
                $account->set('phoneNumber', '+' . $telefone);
            }
            $this->em->saveEntity($account);
            $out['accountId'] = $account->getId();
        }

        // ---------- contato ----------
        $nomePessoa = trim(preg_replace('/^[~\s]+/', '', (string) ($contato['nome'] ?? '')));
        if (!$contact && ($telefone !== '' || $email !== '')) {
            [$first, $last] = $this->dividirNome($nomePessoa);
            $contact = $this->em->getNewEntity('Contact');
            $contact->set([
                'firstName' => $first,
                'lastName' => $last,
                'createdById' => $this->user->getId(),
                'assignedUserId' => $responsavelId,
                'cicloVida' => $contato['cicloVida'] ?? ($account ? $account->get('cicloVida') : 'identificado'),
            ]);
            if ($telefone) {
                $contact->set('phoneNumber', '+' . $telefone);
            }
            if ($email) {
                $contact->set('emailAddress', $email);
            }
            $out['criou'][] = 'contato';
            $out['matchContato'] = 'novo';
        } elseif ($contact && $nomePessoa !== '' && preg_match('/^(contato sem nome|sem nome)$/i', trim((string) $contact->get('name')))) {
            [$first, $last] = $this->dividirNome($nomePessoa);
            $contact->set(['firstName' => $first, 'lastName' => $last]);
        }

        if ($contact) {
            if ($account && !$contact->get('accountId')) {
                $contact->set('accountId', $account->getId());
            }
            if ($email && !$contact->get('emailAddress')) {
                $contact->set('emailAddress', $email);
            }
            $this->aplicarCampos($contact, $contato, ['cCargo', 'whatsappJid', 'cpf', 'rntrc']);
            $this->mesclar($contact, 'papelRede', $contato['papel'] ?? ($conta['papel'] ?? []));
            $this->mesclar($contact, 'fonte', $contato['fonte'] ?? ($conta['fonte'] ?? []));
            $this->mesclar($contact, 'canaisPermitidos', $contato['canaisPermitidos'] ?? []);
            $this->avancarCiclo($contact, $contato['cicloVida'] ?? ($conta['cicloVida'] ?? null));
            if (!empty($contato['baseLegal']) && in_array($contact->get('baseLegal'), [null, '', 'naoDefinida'], true)) {
                $contact->set('baseLegal', $contato['baseLegal']);
            }
            if ($toqueEm) {
                $this->tocar($contact, $toqueEm, $toque['canal'] ?? null);
            }
            $this->em->saveEntity($contact);
            $out['contactId'] = $contact->getId();
        }

        // ---------- oportunidade (upsert por cotação do Logre) ----------
        $opp = null;
        $op = $body['oportunidade'] ?? null;
        if ($op && !empty($op['cotacaoLogre']) && $account && $this->acl->checkScope('Opportunity', 'create')) {
            $opp = $this->em->getRDBRepository('Opportunity')->where(['cotacaoLogre' => (string) $op['cotacaoLogre']])->findOne();
            if (!$opp) {
                $opp = $this->em->getNewEntity('Opportunity');
                $opp->set([
                    'name' => mb_substr($op['nome'] ?? ('Cotação #' . $op['cotacaoLogre'] . ' – ' . $account->get('name')), 0, 200),
                    'accountId' => $account->getId(),
                    'stage' => $op['stage'] ?? 'Prospecting',
                    'closeDate' => $op['closeDate'] ?? gmdate('Y-m-d', time() + 14 * 86400),
                    'cotacaoLogre' => (string) $op['cotacaoLogre'],
                    'createdById' => $this->user->getId(),
                    'assignedUserId' => $responsavelId,
                ]);
                $out['criou'][] = 'oportunidade';
            } elseif (!empty($op['stage'])) {
                $opp->set('stage', $op['stage']);
            }
            foreach (['amount', 'tipoFrete', 'origem', 'destino', 'pesoKg', 'canalOrigem', 'description'] as $f) {
                if (isset($op[$f]) && $op[$f] !== '' && $op[$f] !== null) {
                    $opp->set($f, $op[$f]);
                }
            }
            if (isset($op['amount'])) {
                $opp->set('amountCurrency', 'BRL');
            }
            $this->mesclar($opp, 'fonte', $op['fonte'] ?? ($conta['fonte'] ?? []));
            $this->em->saveEntity($opp);
            if ($contact) {
                $this->em->getRDBRepository('Opportunity')->getRelation($opp, 'contacts')->relate($contact);
            }
            $out['opportunityId'] = $opp->getId();
        }

        // ---------- interação (idempotente por idExterno) ----------
        $it = $body['interacao'] ?? null;
        if ($it) {
            $existente = !empty($it['idExterno'])
                ? $this->em->getRDBRepository('Interacao')->where(['idExterno' => (string) $it['idExterno']])->findOne()
                : null;
            if ($existente) {
                $out['interacaoId'] = $existente->getId();
            } else {
                $i = $this->em->getNewEntity('Interacao');
                $i->set([
                    'name' => mb_substr($it['assunto'] ?? $it['name'] ?? 'Interação', 0, 200),
                    'canal' => $it['canal'] ?? ($toque['canal'] ?? 'outro'),
                    'direcao' => $it['direcao'] ?? 'entrada',
                    'ocorridoEm' => !empty($it['ocorridoEm']) ? $this->dataUtc($it['ocorridoEm']) : ($toqueEm ?? $agora),
                    'duracaoSeg' => $it['duracaoSeg'] ?? null,
                    'resumo' => $it['resumo'] ?? null,
                    'transcricao' => $it['transcricao'] ?? null,
                    'sinais' => $it['sinais'] ?? [],
                    'origemSistema' => $it['origemSistema'] ?? null,
                    'idExterno' => $it['idExterno'] ?? null,
                    'refBruto' => $it['refBruto'] ?? null,
                    'accountId' => $account ? $account->getId() : null,
                    'contactId' => $contact ? $contact->getId() : null,
                    'opportunityId' => $opp ? $opp->getId() : ($it['opportunityId'] ?? null),
                    'createdById' => $this->user->getId(),
                    'assignedUserId' => $responsavelId,
                ]);
                $this->em->saveEntity($i);
                $out['interacaoId'] = $i->getId();
                $out['criou'][] = 'interacao';
            }
        }

        // ---------- fatos (sem repetir campo+valor na mesma conta/contato) ----------
        $out['fatosIds'] = [];
        foreach (($body['fatos'] ?? []) as $f) {
            if (empty($f['campo']) || !isset($f['valor']) || $f['valor'] === '') {
                continue;
            }
            $where = ['campo' => $f['campo'], 'valor' => (string) $f['valor']];
            if ($account) {
                $where['accountId'] = $account->getId();
            } elseif ($contact) {
                $where['contactId'] = $contact->getId();
            }
            $ja = $this->em->getRDBRepository('Fato')->where($where)->findOne();
            if ($ja) {
                $out['fatosIds'][] = $ja->getId();
                continue;
            }
            $fato = $this->em->getNewEntity('Fato');
            $fato->set([
                'name' => mb_substr($f['campo'] . ': ' . $f['valor'], 0, 200),
                'campo' => mb_substr($f['campo'], 0, 100),
                'valor' => (string) $f['valor'],
                'fonte' => $f['fonte'] ?? 'conversa',
                'confianca' => $f['confianca'] ?? 'media',
                'observadoEm' => !empty($f['observadoEm']) ? $this->dataUtc($f['observadoEm']) : ($toqueEm ?? $agora),
                'refBruto' => $f['refBruto'] ?? null,
                'agente' => $f['agente'] ?? null,
                'accountId' => $account ? $account->getId() : null,
                'contactId' => $contact ? $contact->getId() : null,
                'opportunityId' => $opp ? $opp->getId() : null,
                'createdById' => $this->user->getId(),
            ]);
            $this->em->saveEntity($fato);
            $out['fatosIds'][] = $fato->getId();
        }

        return ResponseComposer::json($out);
    }

    private function buscar(string $entityType, string $tipo, string $atributo, string $valor): ?Entity
    {
        $query = $this->selectBuilderFactory->create()
            ->from($entityType)
            ->withWhere(WhereItem::fromRaw(['type' => $tipo, 'attribute' => $atributo, 'value' => $valor]))
            ->build();

        return $this->em->getRDBRepository($entityType)->clone($query)->order('createdAt', 'ASC')->findOne();
    }

    /** CNPJ/CPF só com dígitos, recolocando zeros à esquerda perdidos em planilhas/sistemas. */
    private function documento($v): string
    {
        $d = $this->digitos($v);
        $n = strlen($d);
        if ($n === 12 || $n === 13) {
            return str_pad($d, 14, '0', STR_PAD_LEFT);
        }
        if ($n === 9 || $n === 10) {
            return str_pad($d, 11, '0', STR_PAD_LEFT);
        }
        return $d;
    }

    private function digitos($v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    private function dataUtc($v): string
    {
        $ts = is_numeric($v) ? (int) $v : strtotime((string) $v);
        return gmdate('Y-m-d H:i:s', $ts ?: time());
    }

    private function dividirNome(string $nome): array
    {
        $p = array_values(array_filter(explode(' ', preg_replace('/\s+/', ' ', $nome))));
        if (!$p) {
            return ['', 'Contato sem nome'];
        }
        if (count($p) === 1) {
            return ['', $p[0]];
        }
        return [$p[0], implode(' ', array_slice($p, 1))];
    }

    private function mesclar(Entity $e, string $campo, $novos): void
    {
        $novos = array_values(array_filter((array) $novos, fn($x) => $x !== '' && $x !== null));
        if (!$novos) {
            return;
        }
        $atual = $e->get($campo) ?? [];
        $junto = array_values(array_unique(array_merge($atual, $novos)));
        if ($junto != $atual) {
            $e->set($campo, $junto);
        }
    }

    private function aplicarCampos(Entity $e, array $dados, array $campos): void
    {
        foreach ($campos as $c) {
            if (isset($dados[$c]) && $dados[$c] !== '' && !$e->get($c)) {
                $e->set($c, $dados[$c]);
            }
        }
    }

    private function avancarCiclo(Entity $e, ?string $novo): void
    {
        if (!$novo) {
            return;
        }
        $ordem = ['identificado' => 1, 'engajado' => 2, 'qualificado' => 3, 'ativo' => 4];
        $atual = $e->get('cicloVida') ?: 'identificado';
        // estados terminais/manuais (dormente, perdido, naoTocar) só mudam se o novo for 'ativo' ou 'engajado' vindo de contato real
        if (in_array($atual, ['naoTocar'], true)) {
            return;
        }
        if (!isset($ordem[$novo])) {
            $e->set('cicloVida', $novo);
            return;
        }
        if (!isset($ordem[$atual]) || $ordem[$novo] > $ordem[$atual]) {
            $e->set('cicloVida', $novo);
        }
    }

    private function tocar(Entity $e, string $quando, ?string $canal): void
    {
        $atual = $e->get('ultimoToque');
        if (!$atual || $quando > $atual) {
            $e->set('ultimoToque', $quando);
            if ($canal) {
                $e->set('ultimoToqueCanal', $canal);
            }
        }
    }
}
