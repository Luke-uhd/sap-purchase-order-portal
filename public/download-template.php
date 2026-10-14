<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Bootstrap.php';
require_once dirname(__DIR__) . '/src/XlsxPurchaseOrderImport.php';
require_once dirname(__DIR__) . '/src/PurchaseOrderService.php';

use App\Auth;
use App\PurchaseOrderService;
use App\XlsxPurchaseOrderImport;

try {
    $sap = Auth::requireSapSession();

    $docNum = null;
    $rawDocNum = trim((string) ($_GET['docNum'] ?? ''));

    if ($rawDocNum !== '') {
        if (!ctype_digit($rawDocNum) || (int) $rawDocNum < 1) {
            throw new InvalidArgumentException('Número do pedido inválido.');
        }

        $docNum = (int) $rawDocNum;
    }

    $supplier = trim((string) ($_GET['supplier'] ?? ''));

    if (mb_strlen($supplier) > 100) {
        throw new InvalidArgumentException('Filtro de fornecedor muito longo.');
    }


    foreach (['docDateFrom', 'docDateTo'] as $key) {
        if (isset($_GET[$key]) && !is_string($_GET[$key])) {
            throw new \InvalidArgumentException('Filtro de data inválido.', 422);
        }
    }

    $docDateFrom = trim((string) ($_GET['docDateFrom'] ?? ''));
    $docDateTo = trim((string) ($_GET['docDateTo'] ?? ''));


    if (isset($_GET['costingCode']) && !is_string($_GET['costingCode'])) {
        throw new \InvalidArgumentException('Código de distribuição inválido.', 422);
    }

    $costingCode = trim((string) ($_GET['costingCode'] ?? ''));

    if (mb_strlen($costingCode) > 100) {
        throw new \InvalidArgumentException('Código de distribuição muito longo.', 422);
    }

    $rows = (new PurchaseOrderService($sap['cookie']))->templateRows(
        $docNum,
        $supplier !== '' ? $supplier : null,
        500,
        5000,
        $docDateFrom !== '' ? $docDateFrom : null,
        $docDateTo !== '' ? $docDateTo : null,
        $costingCode !== '' ? $costingCode : null
    );

    $tmp = tempnam(sys_get_temp_dir(), 'sap-po-xlsx-');

    if ($tmp === false) {
        throw new RuntimeException('Não foi possível criar arquivo temporário.');
    }

    $file = $tmp . '.xlsx';
    @unlink($tmp);

    try {
        XlsxPurchaseOrderImport::createTemplate($rows, $file);

        if (!is_file($file) || filesize($file) < 100) {
            throw new RuntimeException('Falha ao gerar o modelo XLSX.');
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="modelo-alteracoes-pedidos.xlsx"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store, private');

        readfile($file);
    } finally {
        @unlink($file);
    }
} catch (Throwable $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
