<?php
// Migração inicial TSM Core: preenche CNPJ, papel, ciclo, fonte, canais, base legal, dados de oportunidade e grupos.
// Uso (dentro do container, em /var/www/html): php migrar.php            -> simulação (não grava)
//                                              APLICAR=1 php migrar.php  -> grava
require 'bootstrap.php';

$aplicar = getenv('APLICAR') === '1';
$app = new \Espo\Core\Application();
$app->setupSystemUser();
$em = $app->getContainer()->getByClass(\Espo\ORM\EntityManager::class);

$cont = ['contas' => 0, 'contasCnpj' => 0, 'contasAlteradas' => 0, 'contatosAlterados' => 0, 'oppsAlteradas' => 0, 'grupos' => 0, 'contasNoGrupo' => 0];
$ciclos = [];

$merge = function (?array $atual, array $novos): array {
    return array_values(array_unique(array_merge($atual ?? [], $novos)));
};
$salvar = function ($e) use ($em, $aplicar) {
    if ($aplicar) {
        $em->saveEntity($e, ['silent' => true]);
    }
};

// ---------- Oportunidades (antes, para saber quem já ganhou) ----------
$ganhou = [];
foreach ($em->getRDBRepository('Opportunity')->find() as $o) {
    $nome = (string) $o->get('name');
    $desc = (string) $o->get('description');
    $mudou = false;
    if (!$o->get('cotacaoLogre') && preg_match('/Cota\S*o #(\d+)/u', $nome, $m)) {
        $o->set('cotacaoLogre', $m[1]);
        $mudou = true;
    }
    if (str_contains($desc, '[CLOSER]')) {
        $f = $merge($o->get('fonte'), ['closer', 'logreCotacao']);
        if ($f != ($o->get('fonte') ?? [])) { $o->set('fonte', $f); $mudou = true; }
        if (!$o->get('canalOrigem')) { $o->set('canalOrigem', 'whatsapp'); $mudou = true; }
    }
    if (preg_match('/Rota: (.+?) -> (.+?)(?: \||\n|$)/u', $desc, $m)) {
        if (!$o->get('origem') && trim($m[1]) !== '?') { $o->set('origem', mb_substr(trim($m[1]), 0, 120)); $mudou = true; }
        if (!$o->get('destino') && trim($m[2]) !== '?') { $o->set('destino', mb_substr(trim($m[2]), 0, 120)); $mudou = true; }
    }
    if ($o->get('stage') === 'Closed Won' && $o->get('accountId')) {
        $ganhou[$o->get('accountId')] = true;
    }
    if ($mudou) { $cont['oppsAlteradas']++; $salvar($o); }
}

// ---------- Contas ----------
$cicloConta = [];
$porRaiz = [];
foreach ($em->getRDBRepository('Account')->find() as $a) {
    $cont['contas']++;
    $desc = (string) $a->get('description');
    $closer = str_contains($desc, 'Criada pelo Closer');
    $mudou = false;

    if (!$a->get('cnpj') && preg_match('/CNPJ\/CPF:\s*([\d.\-\/]+)/', $desc, $m)) {
        $d = preg_replace('/\D/', '', $m[1]);
        if (strlen($d) === 14 || strlen($d) === 11) {
            $a->set('cnpj', $d);
            $a->set('cnpjRaiz', strlen($d) === 14 ? substr($d, 0, 8) : null);
            $mudou = true;
        }
    }
    if ($a->get('cnpj')) { $cont['contasCnpj']++; }

    $fonteNova = $closer ? ['closer', 'logreCotacao'] : ['listaFria'];
    $papelNovo = $closer ? ['leadCotacao'] : ['prospect'];
    $f = $merge($a->get('fonte'), $fonteNova);
    if ($f != ($a->get('fonte') ?? [])) { $a->set('fonte', $f); $mudou = true; }
    if (!$a->get('papelRede')) { $a->set('papelRede', $papelNovo); $mudou = true; }

    $ciclo = isset($ganhou[$a->getId()]) ? 'ativo' : ($closer ? 'engajado' : 'identificado');
    if (in_array($a->get('cicloVida'), [null, '', 'identificado'], true) && $a->get('cicloVida') !== $ciclo) {
        $a->set('cicloVida', $ciclo);
        $mudou = true;
    }
    $cicloConta[$a->getId()] = $a->get('cicloVida');
    $ciclos[$a->get('cicloVida')] = ($ciclos[$a->get('cicloVida')] ?? 0) + 1;

    if ($closer && !$a->get('canaisPermitidos')) { $a->set('canaisPermitidos', ['whatsapp']); $mudou = true; }
    if ($closer && in_array($a->get('baseLegal'), [null, '', 'naoDefinida'], true)) { $a->set('baseLegal', 'legitimoInteresse'); $mudou = true; }
    if (!$a->get('confiancaCadastro')) { $a->set('confiancaCadastro', $a->get('cnpj') ? 'media' : 'baixa'); $mudou = true; }

    if ($a->get('cnpjRaiz') && !$a->get('grupoEmpresarialId')) {
        $porRaiz[$a->get('cnpjRaiz')][] = $a;
    }
    if ($mudou) { $cont['contasAlteradas']++; $salvar($a); }
}

// ---------- Grupos por raiz de CNPJ (2+ contas) ----------
foreach ($porRaiz as $raiz => $contas) {
    if (count($contas) < 2) { continue; }
    $existente = $em->getRDBRepository('GrupoEmpresarial')->where(['cnpjRaiz' => $raiz])->findOne();
    $nomes = array_map(fn($c) => (string) $c->get('name'), $contas);
    usort($nomes, fn($x, $y) => mb_strlen($x) <=> mb_strlen($y));
    echo "grupo $raiz: ", implode(' | ', $nomes), "\n";
    if ($aplicar) {
        $g = $existente ?: $em->createEntity('GrupoEmpresarial', ['name' => $nomes[0], 'cnpjRaiz' => $raiz, 'description' => 'Criado automaticamente pela raiz do CNPJ.']);
        foreach ($contas as $c) { $c->set('grupoEmpresarialId', $g->getId()); $em->saveEntity($c, ['silent' => true]); }
    }
    $cont['grupos']++;
    $cont['contasNoGrupo'] += count($contas);
}

// ---------- Contatos ----------
foreach ($em->getRDBRepository('Contact')->find() as $c) {
    $desc = (string) $c->get('description');
    $closer = str_contains($desc, 'Criado pelo Closer');
    $mudou = false;
    $f = $merge($c->get('fonte'), $closer ? ['closer', 'logreCotacao'] : ['listaFria']);
    if ($f != ($c->get('fonte') ?? [])) { $c->set('fonte', $f); $mudou = true; }
    if (!$c->get('papelRede')) { $c->set('papelRede', $closer ? ['leadCotacao'] : ['prospect']); $mudou = true; }
    $ciclo = $cicloConta[$c->get('accountId')] ?? ($closer ? 'engajado' : 'identificado');
    if (in_array($c->get('cicloVida'), [null, '', 'identificado'], true) && $c->get('cicloVida') !== $ciclo) { $c->set('cicloVida', $ciclo); $mudou = true; }
    if ($closer && !$c->get('canaisPermitidos')) { $c->set('canaisPermitidos', ['whatsapp']); $mudou = true; }
    if ($closer && in_array($c->get('baseLegal'), [null, '', 'naoDefinida'], true)) { $c->set('baseLegal', 'legitimoInteresse'); $mudou = true; }
    if ($mudou) { $cont['contatosAlterados']++; $salvar($c); }
}

echo ($aplicar ? 'APLICADO ' : 'SIMULACAO '), json_encode($cont), ' ciclos=', json_encode($ciclos), "\n";
