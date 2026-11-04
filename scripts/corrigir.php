<?php
// Correção pós-backfill: CNPJ com zero à esquerda perdido e contas duplicadas criadas pela porta única.
// php corrigir.php (simula) | APLICAR=1 php corrigir.php (grava)
require 'bootstrap.php';
$aplicar = getenv('APLICAR') === '1';
$app = new \Espo\Core\Application();
$app->setupSystemUser();
$em = $app->getContainer()->getByClass(\Espo\ORM\EntityManager::class);

$pad = function (string $d): string {
    $n = strlen($d);
    if ($n === 12 || $n === 13) { return str_pad($d, 14, '0', STR_PAD_LEFT); }
    if ($n === 9 || $n === 10) { return str_pad($d, 11, '0', STR_PAD_LEFT); }
    return $d;
};

$cnpjCorrigidos = 0;
foreach ($em->getRDBRepository('Account')->find() as $a) {
    $atual = (string) $a->get('cnpj');
    $novo = '';
    if ($atual !== '') {
        $novo = $pad($atual);
    } elseif (preg_match('/CNPJ\/CPF:\s*([\d.\-\/]+)/', (string) $a->get('description'), $m)) {
        $d = preg_replace('/\D/', '', $m[1]);
        if (strlen($d) >= 9) { $novo = $pad($d); }
    }
    if ($novo !== '' && $novo !== $atual && in_array(strlen($novo), [11, 14], true)) {
        echo "cnpj {$a->get('name')}: '$atual' -> $novo\n";
        $cnpjCorrigidos++;
        if ($aplicar) { $a->set('cnpj', $novo); $em->saveEntity($a, ['silent' => true]); }
    }
}

// duplicadas: mesma raiz de documento, a mais nova criada pela porta única hoje
$porDoc = [];
foreach ($em->getRDBRepository('Account')->order('createdAt', 'ASC')->find() as $a) {
    $doc = $a->get('cnpj');
    if ($doc) { $porDoc[$doc][] = $a; }
}
// contas criadas hoje pela porta única com o mesmo nome de uma conta antiga (sem CNPJ conflitante)
foreach ($em->getRDBRepository('Account')->where(['createdAt>=' => '2026-11-04 03:30:00'])->find() as $nova) {
    $antiga = $em->getRDBRepository('Account')->where(['name' => $nova->get('name'), 'createdAt<' => '2026-11-04 03:30:00'])->order('createdAt', 'ASC')->findOne();
    if ($antiga && (!$antiga->get('cnpj') || !$nova->get('cnpj') || $antiga->get('cnpj') === $nova->get('cnpj'))) {
        $porDoc['nome:' . $nova->getId()] = [$antiga, $nova];
    }
}
$fundidas = 0;
$removidas = [];
foreach ($porDoc as $doc => $lista) {
    if (count($lista) < 2) { continue; }
    $manter = $lista[0];
    foreach (array_slice($lista, 1) as $dup) {
        if (isset($removidas[$dup->getId()]) || isset($removidas[$manter->getId()])) { continue; }
        if ($dup->get('createdAt') < '2026-11-04 03:30:00') {
            echo "AVISO: duplicada antiga, não mexo: {$dup->get('name')} ($doc)\n";
            continue;
        }
        echo "fundir {$dup->get('name')} ({$dup->getId()}) -> {$manter->get('name')} ({$manter->getId()})\n";
        $fundidas++;
        if (!$aplicar) { continue; }
        foreach (['Interacao', 'Fato', 'Opportunity'] as $t) {
            foreach ($em->getRDBRepository($t)->where(['accountId' => $dup->getId()])->find() as $e) {
                $e->set('accountId', $manter->getId());
                $em->saveEntity($e, ['silent' => true]);
            }
        }
        foreach ($em->getRDBRepository('Account')->getRelation($dup, 'contacts')->find() as $c) {
            $em->getRDBRepository('Account')->getRelation($manter, 'contacts')->relate($c);
            if ($c->get('accountId') === $dup->getId()) { $c->set('accountId', $manter->getId()); $em->saveEntity($c, ['silent' => true]); }
        }
        foreach (['papelRede', 'fonte', 'canaisPermitidos'] as $f) {
            $manter->set($f, array_values(array_unique(array_merge($manter->get($f) ?? [], $dup->get($f) ?? []))));
        }
        if ($dup->get('ultimoToque') && (!$manter->get('ultimoToque') || $dup->get('ultimoToque') > $manter->get('ultimoToque'))) {
            $manter->set('ultimoToque', $dup->get('ultimoToque'));
            $manter->set('ultimoToqueCanal', $dup->get('ultimoToqueCanal'));
        }
        $em->saveEntity($manter, ['silent' => true]);
        $em->removeEntity($dup);
        $removidas[$dup->getId()] = true;
    }
}
echo ($aplicar ? 'APLICADO' : 'SIMULACAO'), " cnpjCorrigidos=$cnpjCorrigidos fundidas=$fundidas\n";
