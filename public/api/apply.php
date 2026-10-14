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

    if (!(\App\app_config('write_enabled_companies')[$sap['companyDb']] ?? false)) {
        throw new \RuntimeException('Escrita bloqueada para esta empresa.', 403);
    }

    $payload = json_decode(
        (string) file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $changes = $payload['alteracoes'] ?? [];

    $hash = hash(
        'sha256',
        json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $preview = $_SESSION['po_preview'] ?? null;

    if (
        !is_array($preview)
        || ($preview['hash'] ?? '') !== $hash
        || time() - (int) ($preview['created'] ?? 0) > 300
    ) {
        throw new \RuntimeException(
            'Prévia ausente, divergente ou expirada. Gere uma nova prévia.',
            409
        );
    }

    $result = (new PurchaseOrderService($sap['cookie']))->apply($changes);

    unset($_SESSION['po_preview']);

    \App\app_log('purchase_orders_applied', [
        'companyDb' => $sap['companyDb'],
        'user' => $sap['userName'],
        'result' => $result,
    ]);

    \App\json_response([
        'ok' => true,
        'result' => $result,
    ]);
} catch (\Throwable $e) {
    \App\api_error($e);
}
