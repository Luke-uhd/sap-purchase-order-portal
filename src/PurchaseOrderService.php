<?php
declare(strict_types=1);
namespace App;

final class PurchaseOrderService {
    private ?BusinessCalendar $businessCalendar = null;

    public function preview(array $changes): array
    {
        $sap = Auth::requireSapSession();

        return BusinessCalendar::withLocked(
            $sap['companyDb'],
            function (BusinessCalendar $calendar) use ($changes): array {
                $this->businessCalendar = $calendar;

                try {
                    $result = $this->previewInternal($changes);
                    $result['calendarToken'] = $calendar->token();
                    return $result;
                } finally {
                    $this->businessCalendar = null;
                }
            }
        );
    }

    public function apply(array $changes): array
    {
        $sap = Auth::requireSapSession();

        return BusinessCalendar::withLocked(
            $sap['companyDb'],
            function (BusinessCalendar $calendar) use ($changes): array {
                $expected = $_SESSION['po_preview']['calendarToken'] ?? null;

                if (
                    !is_string($expected)
                    || !hash_equals($expected, $calendar->token())
                ) {
                    throw new \RuntimeException(
                        'O calendário foi alterado ou não consta da prévia. Gere uma nova prévia.',
                        409
                    );
                }

                $this->businessCalendar = $calendar;

                try {
                    return $this->applyInternal($changes);
                } finally {
                    $this->businessCalendar = null;
                }
            }
        );
    }

    private function calculatedDeliveryDate(string $date): string
    {
        if ($this->businessCalendar === null) {
            throw new \RuntimeException(
                'Calendário não carregado para o cálculo.',
                500
            );
        }

        return $this->businessCalendar->addBusinessDays($date, 2);
    }


    public function __construct(private readonly string $cookie) {}
    public function listPage(
        int $page,
        int $pageSize,
        ?int $docNum = null,
        ?string $supplier = null,
        ?string $docDateFrom = null,
        ?string $docDateTo = null,
        ?string $costingCode = null
    ): array {
        if ($page < 1 || $pageSize < 1 || $pageSize > 500) {
            throw new \InvalidArgumentException('Paginação inválida.', 422);
        }

        $started = microtime(true);
        $requests = 0;

        $conditions = [
            'PurchaseOrders/DocEntry eq PurchaseOrders/DocumentLines/DocEntry',
            "PurchaseOrders/DocumentLines/LineStatus eq 'bost_Open'",
        ];

        if ($docNum !== null) {
            if ($docNum < 1) {
                throw new \InvalidArgumentException('Pedido inválido.', 422);
            }
            $conditions[] = 'PurchaseOrders/DocNum eq ' . $docNum;
        }

        if ($supplier !== null && trim($supplier) !== '') {
            $supplier = trim($supplier);

            if (mb_strlen($supplier) > 100) {
                throw new \InvalidArgumentException(
                    'Filtro de fornecedor muito longo.',
                    422
                );
            }

            $supplier = str_replace("'", "''", $supplier);

            $conditions[] =
                "(contains(PurchaseOrders/CardCode,'{$supplier}')"
                . " or contains(PurchaseOrders/CardName,'{$supplier}'))";
        }

        foreach ([$docDateFrom, $docDateTo] as $dateFilter) {
            if ($dateFilter === null) {
                continue;
            }

            $parsed = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $dateFilter
            );

            if (
                !$parsed
                || $parsed->format('Y-m-d') !== $dateFilter
                || $dateFilter < '2000-01-01'
                || $dateFilter > '2100-12-31'
            ) {
                throw new \InvalidArgumentException(
                    'Data do filtro inválida. Use YYYY-MM-DD entre 2000 e 2100.',
                    422
                );
            }
        }

        if (
            $docDateFrom !== null
            && $docDateTo !== null
            && $docDateFrom > $docDateTo
        ) {
            throw new \InvalidArgumentException(
                'A data inicial não pode ser maior que a final.',
                422
            );
        }

        if ($docDateFrom !== null) {
            $conditions[] = "PurchaseOrders/DocDate ge '{$docDateFrom}'";
        }

        if ($docDateTo !== null) {
            $exclusiveEnd = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $docDateTo
            )->modify('+1 day')->format('Y-m-d');

            $conditions[] = "PurchaseOrders/DocDate lt '{$exclusiveEnd}'";
        }

        if ($costingCode !== null && trim($costingCode) !== '') {
            $costingCode = trim($costingCode);

            if (mb_strlen($costingCode) > 100) {
                throw new \InvalidArgumentException(
                    'Código de distribuição muito longo.',
                    422
                );
            }

            $escaped = str_replace("'", "''", $costingCode);
            $conditions[] =
                "PurchaseOrders/DocumentLines/CostingCode eq '{$escaped}'";
        }

        $filter = implode(' and ', $conditions);

        $groupBase =
            '$apply=filter(' . $filter . ')'
            . '/groupby((PurchaseOrders/DocEntry))'
            . '&$orderby=PurchaseOrders/DocEntry desc';

        // Um pedido adicional permite determinar hasNext.
        $groups = $this->queryListingRows(
            $groupBase,
            ($page - 1) * $pageSize,
            $pageSize + 1,
            $requests
        );

        $ids = [];
        $seenIds = [];
        $previousId = null;

        foreach ($groups as $group) {
            $id = (int) ($group['PurchaseOrders']['DocEntry'] ?? 0);

            if (
                $id <= 0
                || isset($seenIds[$id])
                || ($previousId !== null && $id >= $previousId)
            ) {
                throw new \RuntimeException(
                    'Resposta de pedidos agrupados inconsistente.',
                    502
                );
            }

            $seenIds[$id] = true;
            $previousId = $id;
            $ids[] = $id;
        }

        $hasNext = count($ids) > $pageSize;
        $pageIds = array_slice($ids, 0, $pageSize);

        if (!$pageIds) {
            return [
                'pedidos' => [],
                'page' => $page,
                'pageSize' => $pageSize,
                'hasNext' => false,
                'hasPrevious' => $page > 1,
                'headersLoaded' => 0,
                'linesLoaded' => 0,
                'queryRequests' => $requests,
                'elapsedMs' => round((microtime(true) - $started) * 1000, 1),
            ];
        }

        $chunks = [];
        $chunk = [];

        foreach ($pageIds as $id) {
            $candidate = [...$chunk, $id];
            $candidateQuery = $this->listingDetailQuery(
                $candidate,
                $costingCode
            );

            // Reserva espaço para QueryPath, $skip e $top.
            // Usa o comprimento codificado como medida conservadora.
            if (strlen(rawurlencode($candidateQuery)) > 1750) {
                if (!$chunk) {
                    throw new \RuntimeException(
                        'Consulta de detalhes muito longa. Reduza o código do filtro.',
                        422
                    );
                }

                $chunks[] = $chunk;
                $chunk = [$id];

                if (
                    strlen(rawurlencode(
                        $this->listingDetailQuery($chunk, $costingCode)
                    )) > 1750
                ) {
                    throw new \RuntimeException(
                        'Consulta de detalhes muito longa mesmo para um pedido.',
                        422
                    );
                }
            } else {
                $chunk = $candidate;
            }
        }

        if ($chunk) {
            $chunks[] = $chunk;
        }

        $details = [];

        foreach ($chunks as $idsChunk) {
            $remaining = 10001 - count($details);

            if ($remaining <= 0) {
                break;
            }

            $batch = $this->queryListingRows(
                $this->listingDetailQuery($idsChunk, $costingCode),
                0,
                $remaining,
                $requests
            );

            foreach ($batch as $row) {
                $details[] = $row;
            }

            if (count($details) > 10000) {
                break;
            }
        }

        if (count($details) > 10000) {
            throw new \RuntimeException(
                'A página excede 10.000 linhas. Reduza os pedidos por página ou refine os filtros.',
                422
            );
        }

        $byId = [];
        $lineKeys = [];
        $allowedIds = array_fill_keys($pageIds, true);
        $linesLoaded = 0;
        $previousOrder = null;
        $previousLine = null;

        foreach ($details as $row) {
            $header = $row['PurchaseOrders'] ?? null;
            $line = $row['PurchaseOrders/DocumentLines'] ?? null;

            if (!is_array($header) || !is_array($line)) {
                throw new \RuntimeException(
                    'Resposta de linhas em formato inesperado.',
                    502
                );
            }

            $id = (int) ($header['DocEntry'] ?? 0);
            $number = filter_var(
                $line['LineNum'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );

            if (!isset($allowedIds[$id]) || $number === false) {
                throw new \RuntimeException(
                    'Identificador de linha inesperado na consulta.',
                    502
                );
            }

            if (
                $previousOrder !== null
                && (
                    $id > $previousOrder
                    || ($id === $previousOrder && $number <= $previousLine)
                )
            ) {
                throw new \RuntimeException(
                    'Ordenação das linhas inconsistente. Refaça a consulta.',
                    502
                );
            }

            $previousOrder = $id;
            $previousLine = $number;

            $lineKey = $id . ':' . $number;

            if (isset($lineKeys[$lineKey])) {
                throw new \RuntimeException(
                    'Linha duplicada durante a paginação. Refaça a consulta.',
                    409
                );
            }

            $status = (string) ($line['LineStatus'] ?? '');
            if (!in_array($status, ['O', 'bost_Open'], true)) {
                throw new \RuntimeException(
                    'Estado de linha inesperado na consulta de linhas abertas.',
                    502
                );
            }

            if (
                $costingCode !== null
                && $costingCode !== ''
                && (string) ($line['CostingCode'] ?? '') !== $costingCode
            ) {
                throw new \RuntimeException(
                    'A consulta retornou uma distribuição diferente do filtro.',
                    502
                );
            }

            $lineKeys[$lineKey] = true;
            $line['LineNum'] = $number;
            $line['LineStatus'] = 'bost_Open';

            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'DocEntry' => $id,
                    'DocNum' => $header['DocNum'] ?? '',
                    'CardCode' => $header['CardCode'] ?? '',
                    'CardName' => $header['CardName'] ?? '',
                    'DocDate' => $header['DocDate'] ?? '',
                    'DocDueDate' => $header['DocDueDate'] ?? '',
                    'DocumentLines' => [],
                ];
            }

            $byId[$id]['DocumentLines'][] = $line;
            $linesLoaded++;
        }

        $orders = [];

        foreach ($pageIds as $id) {
            if (!isset($byId[$id])) {
                throw new \RuntimeException(
                    'Um pedido mudou entre a seleção e a leitura das linhas. Recarregue a consulta.',
                    409
                );
            }

            $orders[] = $byId[$id];
        }

        $elapsedMs = round((microtime(true) - $started) * 1000, 1);
        $sap = Auth::requireSapSession();

        app_log('purchase_orders_listing', [
            'companyDb' => $sap['companyDb'],
            'user' => $sap['userName'],
            'page' => $page,
            'pageSize' => $pageSize,
            'orders' => count($orders),
            'lines' => $linesLoaded,
            'requests' => $requests,
            'elapsedMs' => $elapsedMs,
        ]);

        return [
            'pedidos' => $orders,
            'page' => $page,
            'pageSize' => $pageSize,
            'hasNext' => $hasNext,
            'hasPrevious' => $page > 1,
            'headersLoaded' => count($pageIds),
            'linesLoaded' => $linesLoaded,
            'queryRequests' => $requests,
            'elapsedMs' => $elapsedMs,
        ];
    }

    private function listingDetailQuery(
        array $ids,
        ?string $costingCode
    ): string {
        $conditions = [
            'PurchaseOrders/DocEntry eq PurchaseOrders/DocumentLines/DocEntry',
            "PurchaseOrders/DocumentLines/LineStatus eq 'bost_Open'",
        ];

        if ($costingCode !== null && $costingCode !== '') {
            $escaped = str_replace("'", "''", $costingCode);
            $conditions[] =
                "PurchaseOrders/DocumentLines/CostingCode eq '{$escaped}'";
        }

        $conditions[] = '(' . implode(
            ' or ',
            array_map(
                static fn(int $id): string =>
                    'PurchaseOrders/DocEntry eq ' . $id,
                $ids
            )
        ) . ')';

        return
            '$expand=PurchaseOrders('
            . '$select=DocEntry,DocNum,CardCode,CardName,DocDate,DocDueDate),'
            . 'PurchaseOrders/DocumentLines('
            . '$select=LineNum,ItemCode,ItemDescription,Quantity,ShipDate,'
            . 'CostingCode,LineStatus)'
            . '&$filter=' . implode(' and ', $conditions)
            . '&$orderby=PurchaseOrders/DocEntry desc,'
            . 'PurchaseOrders/DocumentLines/LineNum asc';
    }

    private function queryListingRows(
        string $queryBase,
        int $initialSkip,
        int $limit,
        int &$requests
    ): array {
        $client = new ServiceLayerClient();
        $all = [];
        $skip = $initialSkip;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $remaining = $limit - count($all);

            if ($remaining <= 0) {
                return $all;
            }

            $size = min(200, $remaining);

            $response = $client->queryDocumentLines(
                $this->cookie,
                '$crossjoin(PurchaseOrders,PurchaseOrders/DocumentLines)',
                $queryBase . '&$skip=' . $skip . '&$top=' . $size,
                $size
            );

            $requests++;
            $this->ok($response, 'Falha na consulta paginada de pedidos');

            $data = $response['json'] ?? null;

            if (is_string($data)) {
                $data = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
            }

            if (!is_array($data) || !is_array($data['value'] ?? null)) {
                throw new \RuntimeException(
                    'Resposta paginada em formato inesperado.',
                    502
                );
            }

            $rows = $data['value'];

            if (count($rows) > $size) {
                throw new \RuntimeException(
                    'A consulta não respeitou o limite solicitado.',
                    502
                );
            }

            $next = $data['@odata.nextLink']
                ?? $data['odata.nextLink']
                ?? null;

            if (!$rows) {
                if (is_string($next) && $next !== '') {
                    throw new \RuntimeException(
                        'Resposta vazia com continuação inconsistente.',
                        502
                    );
                }

                return $all;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException('Registro inválido na consulta.', 502);
                }
                $all[] = $row;
            }

            $skip += count($rows);

            if (count($all) >= $limit) {
                return $all;
            }

            // Em uma resposta cheia, consulta o bloco seguinte para confirmar o fim.
            // Em resposta parcial com nextLink, avança pelo offset recebido.
            if (count($rows) < $size && !(is_string($next) && $next !== '')) {
                return $all;
            }
        }

        throw new \RuntimeException(
            'Limite de paginação interna atingido. Reduza a página ou refine os filtros.',
            422
        );
    }

    public function templateRows(
        ?int $docNum = null,
        ?string $supplier = null,
        int $maxOrders = 500,
        int $maxLines = 5000,
        ?string $docDateFrom = null,
        ?string $docDateTo = null,
        ?string $costingCode = null
    ): array {
        $page = 1;
        $pageSize = 50;
        $ordersSeen = 0;
        $rows = [];

        do {
            $result = $this->listPage(
                $page,
                $pageSize,
                $docNum,
                $supplier,
                $docDateFrom,
                $docDateTo,
                $costingCode
            );
            $ordersSeen += (int) ($result['headersLoaded'] ?? 0);

            if ($ordersSeen > $maxOrders) {
                throw new \RuntimeException(
                    "A consulta excede o limite de {$maxOrders} pedidos. Refine os filtros."
                );
            }

            foreach ($result['pedidos'] as $order) {
                foreach ($order['DocumentLines'] as $line) {
                    if (count($rows) >= $maxLines) {
                        throw new \RuntimeException(
                            "A consulta excede o limite de {$maxLines} linhas abertas. Refine os filtros."
                        );
                    }

                    $rows[] = [
                        'DocEntry' => (string) ($order['DocEntry'] ?? ''),
                        'DocNum' => (string) ($order['DocNum'] ?? ''),
                        'LineNum' => (string) ($line['LineNum'] ?? ''),
                        'CardCode' => (string) ($order['CardCode'] ?? ''),
                        'CardName' => (string) ($order['CardName'] ?? ''),
                        'ItemCode' => (string) ($line['ItemCode'] ?? ''),
                        'Descricao' => (string) ($line['ItemDescription'] ?? $line['Dscription'] ?? ''),
                        'QuantidadeAtual' => (string) ($line['Quantity'] ?? ''),
                        'EntregaAtual' => substr((string) ($line['ShipDate'] ?? ''), 0, 10),
                    ];
                }
            }

            $page++;
        } while (!empty($result['hasNext']));

        if (!$rows) {
            throw new \RuntimeException(
                'Nenhuma linha aberta encontrada para os filtros informados.'
            );
        }

        return $rows;
    }

    private function previewInternal(array $changes): array { $changes=$this->normalize($changes);$out=[];foreach($changes as $c){$o=$this->getOrder($c['docEntry']);$this->recompose($o,$c['linhas']);$lines=[];foreach($c['linhas'] as $x){$old=$this->line($o['DocumentLines'],$x['lineNum']);$txt='Linha '.$x['lineNum'].' · '.($old['ItemCode']??'');if($x['action']==='delete')$txt.=' — será removida fisicamente; redistribua a quantidade.';else{$parts=[];if(array_key_exists('quantity',$x))$parts[]='Qtd: '.$old['Quantity'].' → '.$x['quantity'];if(array_key_exists('shipDate',$x)){
        $parts[]='Entrega: '.substr((string)($old['ShipDate']??''),0,10).' → '.$x['shipDate'];
        if(substr((string)($old['ShipDate']??''),0,10)!==$x['shipDate']){
            $oldDerived=substr((string)($old['U_AS_NovDataEntLin']??''),0,10);
            $parts[]='U_AS_NovDataEntLin: '.($oldDerived!==''?$oldDerived:'vazio')
                .' → '.$this->calculatedDeliveryDate($x['shipDate']);
        }
    }$txt.=' — '.implode(' · ',$parts);}$lines[]=$txt;}$out[]=['docEntry'=>$c['docEntry'],'docNum'=>$o['DocNum']??'','cardName'=>$o['CardName']??'','lines'=>$lines];}return ['orders'=>$out]; }
    private function applyInternal(array $changes): array { $changes=$this->normalize($changes);$done=[];$client=new ServiceLayerClient();foreach($changes as $c){$o=$this->getOrder($c['docEntry']);$r=$this->recompose($o,$c['linhas']);if($r['replace']){$payload=['DocumentLines'=>array_map([$this,'lineForReplace'],$r['lines'])];$res=$client->patch('/PurchaseOrders('.$c['docEntry'].')',$this->cookie,$payload,['B1S-ReplaceCollectionsOnPatch: true']);}else{$dl=[];foreach($c['linhas'] as $x){$p=['LineNum'=>$x['lineNum']];if(array_key_exists('quantity',$x))$p['Quantity']=$x['quantity'];if(array_key_exists('shipDate',$x)){
        $p['ShipDate']=$x['shipDate'];
        $oldLine=$this->line($o['DocumentLines'],$x['lineNum']);
        if(substr((string)($oldLine['ShipDate']??''),0,10)!==$x['shipDate']){
            $p['U_AS_NovDataEntLin']=$this->calculatedDeliveryDate($x['shipDate']);
        }
    }$dl[]=$p;}$res=$client->patch('/PurchaseOrders('.$c['docEntry'].')',$this->cookie,['DocumentLines'=>$dl]);}$this->ok($res,'Falha ao atualizar pedido '.$c['docEntry']);$done[]=$c['docEntry'];}return ['docEntries'=>$done,'summary'=>count($done).' pedido(s) atualizado(s).']; }
    private function getOrder(int $id): array {$r=(new ServiceLayerClient())->get('/PurchaseOrders('.$id.')',$this->cookie);$this->ok($r,'Falha ao ler pedido '.$id);$o=$r['json']??[];if(!is_array($o['DocumentLines']??null))throw new \RuntimeException('Pedido '.$id.' sem linhas.');return $o;}
    private function normalize(array $changes): array {if(!$changes)throw new \InvalidArgumentException('Nenhuma alteração recebida.');$out=[];foreach($changes as $o){$id=filter_var($o['docEntry']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new \InvalidArgumentException('DocEntry inválido.');if(!is_array($o['linhas']??null)||!$o['linhas'])throw new \InvalidArgumentException('Pedido sem linhas.');$ls=[];foreach($o['linhas'] as $x){$n=filter_var($x['lineNum']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($n===false)throw new \InvalidArgumentException('LineNum inválido.');$a=strtolower((string)($x['action']??'update'));if(!in_array($a,['update','delete'],true))throw new \InvalidArgumentException('Ação inválida.');if($a==='delete'){$ls[]=['lineNum'=>$n,'action'=>'delete'];continue;}$z=['lineNum'=>$n,'action'=>'update'];if(array_key_exists('quantity',$x)){if(!is_numeric($x['quantity'])||(float)$x['quantity']<=0)throw new \InvalidArgumentException('Quantidade inválida.');$z['quantity']=(float)$x['quantity'];}if(array_key_exists('shipDate',$x)){$d=\DateTime::createFromFormat('Y-m-d',(string)$x['shipDate']);if(!$d||$d->format('Y-m-d')!==$x['shipDate'])throw new \InvalidArgumentException('Data inválida.');$z['shipDate']=$d->format('Y-m-d');}if(count($z)===2)throw new \InvalidArgumentException('Linha sem alteração.');$ls[]=$z;}$out[]=['docEntry'=>$id,'linhas'=>$ls];}return $out;}
    private function recompose(array $order,array $changes): array {$orig=$order['DocumentLines'];$by=[];foreach($orig as $l)$by[(int)$l['LineNum']]=$l;$cx=[];foreach($changes as $c){if(!isset($by[$c['lineNum']]))throw new \DomainException('Linha inexistente ou alterada por outro usuário.');if(($by[$c['lineNum']]['LineStatus']??'')!=='bost_Open')throw new \DomainException('Linha fechada.');$cx[$c['lineNum']]=$c;}$final=[];$delete=false;foreach($orig as $l){$n=(int)$l['LineNum'];$c=$cx[$n]??null;if($c&&$c['action']==='delete'){$delete=true;continue;}if($c&&array_key_exists('quantity',$c))$l['Quantity']=$c['quantity'];if($c&&array_key_exists('shipDate',$c)){
        if(substr((string)($l['ShipDate']??''),0,10)!==$c['shipDate']){
            $l['U_AS_NovDataEntLin']=$this->calculatedDeliveryDate($c['shipDate']);
        }
        $l['ShipDate']=$c['shipDate'];
    }$final[]=$l;}if(!$final)throw new \DomainException('Não é permitido excluir todas as linhas.');
$quantityChanged=false;
foreach($changes as $change){
    if(($change['action']??'update')==='update'&&array_key_exists('quantity',$change)){
        $quantityChanged=true;
        break;
    }
}
if($delete||$quantityChanged)$this->sameTotals($orig,$final);
return ['replace'=>$delete,'lines'=>$final];}
private function sameTotals(array $before, array $after): void
{
    $sum = static function (array $lines): array {
        $totals = [];

        foreach ($lines as $line) {
            $item = (string) ($line['ItemCode'] ?? '');

            if ($item === '') {
                continue;
            }

            $totals[$item] = ($totals[$item] ?? 0)
                + (float) ($line['Quantity'] ?? 0);
        }

        ksort($totals);

        return $totals;
    };

    $a = $sum($before);
    $b = $sum($after);

    if (array_keys($a) !== array_keys($b)) {
        throw new \DomainException(
            'Não é permitido remover um ItemCode inteiro.',
            422
        );
    }

    foreach ($a as $item => $qty) {
        $newQty = $b[$item] ?? 0;

        if (abs($qty - $newQty) > 0.0001) {
            throw new \DomainException(
                "Quantidade total do item {$item} mudou: {$qty} → {$newQty}",
                422
            );
        }
    }
}
    private function lineForReplace(array $l): array {$p=['LineNum'=>(int)$l['LineNum'],'ItemCode'=>(string)$l['ItemCode'],'Quantity'=>(float)$l['Quantity']];foreach(['UnitPrice','DiscountPercent','WarehouseCode','ShipDate','TaxCode','CostingCode','CostingCode2','CostingCode3','CostingCode4','CostingCode5','ProjectCode','FreeText','U_AS_NovDataEntLin'] as $f)if(array_key_exists($f,$l)&&$l[$f]!==null)$p[$f]=$l[$f];return $p;}
    private function line(array $lines,int $n): array {foreach($lines as $l)if((int)($l['LineNum']??-1)===$n)return $l;throw new \DomainException('Linha não encontrada.');}
    private function ok(array $r,string $prefix): void {if($r['status']>=200&&$r['status']<300)return;$j=$r['json'];$m=$j['error']['message']['value']??$j['error']['message']??$j['message']??$r['body'];if(is_array($m))$m=json_encode($m,JSON_UNESCAPED_UNICODE);throw new \RuntimeException($prefix.': '.($m?:'erro desconhecido.'));}
}
