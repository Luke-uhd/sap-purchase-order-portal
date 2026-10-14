<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Bootstrap.php';

use App\Auth;
use App\PurchaseOrderService;

try {
    $sap = Auth::requireSapSession();

    $page = \App\positive_int($_GET['page'] ?? 1, 1, 100000);

    $size = \App\positive_int(
        $_GET['pageSize'] ?? \App\app_config('page_size_default'),
        5,
        (int) \App\app_config('page_size_max')
    );

    $docNum = null;
    $rawDocNum = trim((string) ($_GET['docNum'] ?? ''));

    if ($rawDocNum !== '') {
        if (!ctype_digit($rawDocNum) || (int) $rawDocNum < 1) {
            throw new \InvalidArgumentException('Número do pedido inválido.');
        }

        $docNum = (int) $rawDocNum;
    }

    $supplier = trim((string) ($_GET['supplier'] ?? ''));

    if (mb_strlen($supplier) > 100) {
        throw new \InvalidArgumentException('Filtro de fornecedor muito longo.');
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

    $result = (new PurchaseOrderService($sap['cookie']))->listPage(
        $page,
        $size,
        $docNum,
        $supplier !== '' ? $supplier : null,
        $docDateFrom !== '' ? $docDateFrom : null,
        $docDateTo !== '' ? $docDateTo : null,
        $costingCode !== '' ? $costingCode : null
    );

    \App\json_response([
        'ok' => true,
        ...$result,
    ]);
} catch (\Throwable $e) {
    \App\api_error($e);
}
