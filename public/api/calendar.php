<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Bootstrap.php';

use App\Auth;

function calendar_validate(array $data): array
{
    $weekdays = $data['weekdays'] ?? null;
    $holidays = $data['holidays'] ?? null;

    if (!is_array($weekdays) || !$weekdays || count($weekdays) > 7) {
        throw new RuntimeException('Selecione pelo menos um dia útil.', 422);
    }

    foreach ($weekdays as $day) {
        if (!is_int($day) || $day < 1 || $day > 7) {
            throw new RuntimeException('Dia da semana inválido.', 422);
        }
    }

    $weekdays = array_values(array_unique($weekdays));
    sort($weekdays);

    if (!is_array($holidays) || count($holidays) > 1000) {
        throw new RuntimeException('Limite de 1.000 registros de calendário.', 422);
    }

    $clean = [];
    $seen = [];

    foreach ($holidays as $index => $holiday) {
        $number = $index + 1;

        if (!is_array($holiday)) {
            throw new RuntimeException("Registro {$number} inválido.", 422);
        }

        $date = trim((string) ($holiday['date'] ?? ''));
        $description = trim((string) ($holiday['description'] ?? ''));
        $annual = $holiday['annual'] ?? false;

        if (!is_bool($annual)) {
            throw new RuntimeException("Repetição do registro {$number} inválida.", 422);
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (
            !$parsed
            || $parsed->format('Y-m-d') !== $date
            || $date < '2000-01-01'
            || $date > '2100-12-31'
        ) {
            throw new RuntimeException("Data do registro {$number} inválida.", 422);
        }

        if ($description === '' || strlen($description) > 250) {
            throw new RuntimeException(
                "Descrição do registro {$number}: informe de 1 a 250 bytes.",
                422
            );
        }

        $key = $annual ? 'annual:' . substr($date, 5) : 'date:' . $date;
        if (isset($seen[$key])) {
            throw new RuntimeException(
                "Feriado repetido no registro {$number}.",
                422
            );
        }

        $seen[$key] = true;
        $clean[] = [
            'date' => $date,
            'description' => $description,
            'annual' => $annual,
        ];
    }

    usort($clean, static fn(array $a, array $b): int =>
        strcmp($a['date'], $b['date'])
    );

    return ['weekdays' => $weekdays, 'holidays' => $clean];
}

$lock = null;
$tmp = null;

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new RuntimeException('Método não permitido.', 405);
    }

    $sap = Auth::requireSapSession();

    $input = null;
    if ($method === 'POST') {
        Auth::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        $raw = (string) file_get_contents('php://input');
        if (strlen($raw) > 512 * 1024) {
            throw new RuntimeException('Configuração excede o limite permitido.', 413);
        }

        try {
            $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON inválido.', 400);
        }

        if (!is_array($input)) {
            throw new RuntimeException('Configuração inválida.', 422);
        }
    }

    $dir = \App\app_storage_dir('config');

    if (!is_dir($dir)) {
        throw new RuntimeException('Diretório do calendário não foi criado.', 500);
    }

    $key = hash('sha256', (string) $sap['companyDb']);
    $file = $dir . '/calendar-' . $key . '.json';
    $lockFile = $dir . '/calendar-' . $key . '.lock';

    $lock = fopen($lockFile, 'c+');
    if ($lock === false) {
        throw new RuntimeException('Não foi possível abrir o bloqueio do calendário.', 500);
    }
    chmod($lockFile, 0640);

    $operation = $method === 'POST' ? LOCK_EX : LOCK_SH;
    if (!flock($lock, $operation | LOCK_NB)) {
        throw new RuntimeException(
            'Calendário em uso. Aguarde e tente novamente.',
            409
        );
    }

    $current = null;
    if (is_file($file)) {
        try {
            $contents = file_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException('Falha de leitura.');
            }

            $current = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);

            if (
                !is_array($current)
                || ($current['companyDb'] ?? '') !== $sap['companyDb']
                || !is_int($current['revision'] ?? null)
                || $current['revision'] < 1
            ) {
                throw new RuntimeException('Estrutura inválida.');
            }

            calendar_validate($current);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Arquivo do calendário inválido. Não será sobrescrito automaticamente.',
                500
            );
        }
    }

    if ($method === 'GET') {
        $result = $current ?? [
            'companyDb' => $sap['companyDb'],
            'revision' => 0,
            'weekdays' => [1, 2, 3, 4, 5],
            'holidays' => [],
            'updatedAt' => null,
            'updatedBy' => null,
        ];

        $result['configured'] = $current !== null;
    } else {
        $revision = $input['revision'] ?? null;
        if (!is_int($revision) || $revision !== ($current['revision'] ?? 0)) {
            throw new RuntimeException(
                'Outra pessoa alterou o calendário. Feche e abra novamente antes de salvar.',
                409
            );
        }

        $clean = calendar_validate($input);

        $result = [
            'companyDb' => $sap['companyDb'],
            'revision' => $revision + 1,
            'weekdays' => $clean['weekdays'],
            'holidays' => $clean['holidays'],
            'updatedAt' => date(DATE_ATOM),
            'updatedBy' => $sap['userName'],
        ];

        $encoded = json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );

        $tmp = tempnam($dir, '.calendar-');
        if ($tmp === false) {
            throw new RuntimeException('Falha ao criar arquivo temporário.', 500);
        }

        if (
            !chmod($tmp, 0640)
            || file_put_contents($tmp, $encoded) !== strlen($encoded)
            || !rename($tmp, $file)
        ) {
            throw new RuntimeException('Falha ao salvar o calendário.', 500);
        }

        $tmp = null;
        unset($_SESSION['po_preview']);

        \App\app_log('business_calendar_saved', [
            'companyDb' => $sap['companyDb'],
            'user' => $sap['userName'],
            'revision' => $result['revision'],
            'weekdays' => $result['weekdays'],
            'holidays' => $result['holidays'],
        ]);

        $result['configured'] = true;
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    $lock = null;

    header('Cache-Control: no-store');
    \App\json_response(['ok' => true, 'calendar' => $result]);
} catch (Throwable $e) {
    if (is_string($tmp) && is_file($tmp)) {
        unlink($tmp);
    }

    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    \App\api_error($e);
}
