<?php
// Teste da extensão TSM Core (rodar dentro do container, em /var/www/html)
require 'bootstrap.php';

$app = new \Espo\Core\Application();
$app->setupSystemUser();
$em = $app->getContainer()->getByClass(\Espo\ORM\EntityManager::class);
$meta = $app->getContainer()->getByClass(\Espo\Core\Utils\Metadata::class);

echo 'campos Account: ', implode(',', array_intersect(['cnpj', 'cnpjRaiz', 'papelRede', 'cicloVida', 'grupoEmpresarial'], array_keys($meta->get('entityDefs.Account.fields')))), "\n";
echo 'campos Opportunity: ', implode(',', array_intersect(['tipoFrete', 'cotacaoLogre', 'origem'], array_keys($meta->get('entityDefs.Opportunity.fields')))), "\n";

$a = $em->createEntity('Account', ['name' => 'TESTE HML TSM', 'cnpj' => '12.345.678/0001-90', 'papelRede' => ['leadCotacao'], 'cicloVida' => 'engajado', 'fonte' => ['closer']]);
echo 'cnpj=', $a->get('cnpj'), ' raiz=', $a->get('cnpjRaiz'), "\n";

$g = $em->createEntity('GrupoEmpresarial', ['name' => 'Grupo teste', 'cnpjRaiz' => '12345678']);
$a->set('grupoEmpresarialId', $g->getId());
$em->saveEntity($a);

$c = $em->createEntity('Contact', ['lastName' => 'Teste HML', 'cpf' => '123.456.789-01', 'accountId' => $a->getId()]);
echo 'cpf=', $c->get('cpf'), "\n";

$i = $em->createEntity('Interacao', ['name' => 'teste', 'canal' => 'whatsapp', 'direcao' => 'entrada', 'ocorridoEm' => date('Y-m-d H:i:s'), 'accountId' => $a->getId(), 'contactId' => $c->getId(), 'idExterno' => 'hml-1']);
$f = $em->createEntity('Fato', ['name' => 'decisor: Fulano', 'campo' => 'decisor', 'valor' => 'Fulano', 'confianca' => 'alta', 'accountId' => $a->getId()]);
$o = $em->createEntity('Opportunity', ['name' => 'Cotação teste', 'stage' => 'Proposal', 'closeDate' => date('Y-m-d'), 'accountId' => $a->getId(), 'tipoFrete' => 'cif', 'cotacaoLogre' => '9999']);

$repA = $em->getRDBRepository('Account');
echo 'interacoes da conta=', $repA->getRelation($a, 'interacoes')->count(),
    ' fatos da conta=', $repA->getRelation($a, 'fatos')->count(),
    ' contas do grupo=', $em->getRDBRepository('GrupoEmpresarial')->getRelation($g, 'accounts')->count(),
    ' opp tipoFrete=', $em->getEntityById('Opportunity', $o->getId())->get('tipoFrete'), "\n";

foreach ([$i, $f, $o, $c, $a, $g] as $e) {
    $em->removeEntity($e);
}
echo "TESTE_OK\n";
