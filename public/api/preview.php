<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Bootstrap.php';

use App\Auth;
use App\PurchaseOrderService;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new \RuntimeException('Método não permitido.', 405);
    }

    Auth::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    $sap = Auth::requireSapSession();

    $payload = json_decode(
        (string) file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $changes = $payload['alteracoes'] ?? [];

    $preview = (new PurchaseOrderService($sap['cookie']))->preview($changes);

    $_SESSION['po_preview'] = [
        'hash' => hash(
            'sha256',
            json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ),
        'created' => time(),
        'calendarToken' => $preview['calendarToken'],
    ];

    \App\json_response([
        'ok' => true,
        'preview' => $preview,
    ]);
} catch (\Throwable $e) {
    \App\api_error($e);
}
