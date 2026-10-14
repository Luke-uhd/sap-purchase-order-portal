<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/XlsxPurchaseOrderImport.php';
require_once dirname(__DIR__, 2) . '/src/PurchaseOrderService.php';

use App\Auth;
use App\PurchaseOrderService;
use App\XlsxPurchaseOrderImport;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Método não permitido.', 405);
    }

    Auth::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    $sap = Auth::requireSapSession();

    if (!(\App\app_config('write_enabled_companies')[$sap['companyDb']] ?? false)) {
        throw new RuntimeException('Escrita bloqueada para esta empresa.', 403);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new InvalidArgumentException('Selecione um arquivo XLSX.');
    }

    $file = $_FILES['file'];
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            'Falha no upload do arquivo. Código: ' . $uploadError,
            400
        );
    }

    $originalName = (string) ($file['name'] ?? '');
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);

    if (!str_ends_with(strtolower($originalName), '.xlsx')) {
        throw new InvalidArgumentException('Envie somente arquivos com extensão .xlsx.');
    }

    if ($size < 100 || $size > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('O arquivo deve ter entre 100 bytes e 5 MB.');
    }

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Arquivo temporário de upload inválido.', 400);
    }

    $rows = XlsxPurchaseOrderImport::readUpload($tmpName);

    if (!$rows) {
        throw new InvalidArgumentException('A planilha não contém linhas para importar.');
    }

    if (count($rows) > 5000) {
        throw new InvalidArgumentException(
            'A planilha excede o limite de 5.000 linhas.'
        );
    }

    $errors = [];
    $changesByOrder = [];

    foreach ($rows as $row) {
        $line = (int) ($row['_row'] ?? 0);
        $prefix = "Linha {$line}: ";

        $docEntryRaw = trim((string) ($row['DocEntry'] ?? ''));
        $lineNumRaw = trim((string) ($row['LineNum'] ?? ''));
        $actionRaw = strtoupper(trim((string) ($row['Acao'] ?? 'ATUALIZAR')));
        $quantityRaw = trim((string) ($row['NovaQuantidade'] ?? ''));
        $dateRaw = trim((string) ($row['NovaDataEntrega'] ?? ''));

        if (!ctype_digit($docEntryRaw) || (int) $docEntryRaw < 1) {
            $errors[] = $prefix . 'DocEntry inválido.';
            continue;
        }

        if (!ctype_digit($lineNumRaw)) {
            $errors[] = $prefix . 'LineNum inválido.';
            continue;
        }

        if (!in_array($actionRaw, ['ATUALIZAR', 'REMOVER'], true)) {
            $errors[] = $prefix . 'Acao deve ser ATUALIZAR ou REMOVER.';
            continue;
        }

        $docEntry = (int) $docEntryRaw;
        $lineNum = (int) $lineNumRaw;

        if ($actionRaw === 'REMOVER') {
            if ($quantityRaw !== '' || $dateRaw !== '') {
                $errors[] = $prefix
                    . 'Para REMOVER, deixe NovaQuantidade e NovaDataEntrega vazias.';
                continue;
            }

            $change = [
                'lineNum' => $lineNum,
                'action' => 'delete',
            ];
        } else {
            $change = [
                'lineNum' => $lineNum,
                'action' => 'update',
            ];

            if ($quantityRaw !== '') {
                $normalizedQuantity = str_replace(',', '.', $quantityRaw);

                if (
                    !is_numeric($normalizedQuantity)
                    || (float) $normalizedQuantity <= 0
                ) {
                    $errors[] = $prefix . 'NovaQuantidade deve ser maior que zero.';
                    continue;
                }

                $change['quantity'] = (float) $normalizedQuantity;
            }

            if ($dateRaw !== '') {
                $date = DateTime::createFromFormat('Y-m-d', $dateRaw);

                if (
                    !$date
                    || $date->format('Y-m-d') !== $dateRaw
                ) {
                    $errors[] = $prefix
                        . 'NovaDataEntrega deve estar no formato YYYY-MM-DD.';
                    continue;
                }

                $change['shipDate'] = $dateRaw;
            }

            if (!isset($change['quantity']) && !isset($change['shipDate'])) {
                continue;
            }
        }

        if (isset($changesByOrder[$docEntry][$lineNum])) {
            $errors[] = $prefix
                . "linha {$lineNum} do pedido {$docEntry} foi repetida no arquivo.";
            continue;
        }

        $changesByOrder[$docEntry][$lineNum] = $change;
    }

    if ($errors) {
        throw new RuntimeException(
            "Importação bloqueada. Corrija o arquivo:\n- "
            . implode("\n- ", array_slice($errors, 0, 50)),
            422
        );
    }

    $changes = [];

    foreach ($changesByOrder as $docEntry => $lines) {
        if ($lines) {
            $changes[] = [
                'docEntry' => (int) $docEntry,
                'linhas' => array_values($lines),
            ];
        }
    }

    if (!$changes) {
        throw new InvalidArgumentException(
            'Nenhuma alteração encontrada. Preencha NovaQuantidade, '
            . 'NovaDataEntrega ou informe Acao REMOVER.'
        );
    }

    if (count($changes) > 500) {
        throw new InvalidArgumentException(
            'A planilha excede o limite de 500 pedidos.'
        );
    }

    $preview = (new PurchaseOrderService($sap['cookie']))->preview($changes);

    \App\app_log('purchase_orders_xlsx_import_validated', [
        'companyDb' => $sap['companyDb'],
        'user' => $sap['userName'],
        'orders' => count($changes),
        'rows' => count($rows),
    ]);

    \App\json_response([
        'ok' => true,
        'alteracoes' => $changes,
        'preview' => $preview,
        'summary' => count($changes)
            . ' pedido(s) e '
            . array_sum(array_map(
                static fn(array $change): int => count($change['linhas']),
                $changes
            ))
            . ' linha(s) importada(s) e validada(s).',
    ]);
} catch (Throwable $e) {
    \App\api_error($e);
}
