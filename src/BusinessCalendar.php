<?php
declare(strict_types=1);
namespace App;

final class BusinessCalendar
{
    private array $weekdays;
    private array $annual = [];
    private array $dates = [];

    private function __construct(
        private readonly string $companyDb,
        private readonly string $token,
        array $data
    ) {
        $this->weekdays = $data['weekdays'];

        foreach ($data['holidays'] as $holiday) {
            if ($holiday['annual']) {
                $this->annual[substr($holiday['date'], 5)] = true;
            } else {
                $this->dates[$holiday['date']] = true;
            }
        }
    }

    public function token(): string
    {
        return $this->token;
    }

    public static function withLocked(
        string $companyDb,
        callable $callback
    ): mixed {
        $dir = app_storage_dir('config');
        $key = hash('sha256', $companyDb);
        $file = $dir . '/calendar-' . $key . '.json';
        $lockFile = $dir . '/calendar-' . $key . '.lock';

        if (!is_file($file)) {
            throw new \RuntimeException(
                'Cadastre e salve o calendário desta empresa antes de gerar a prévia.',
                422
            );
        }

        $lock = fopen($lockFile, 'c+');

        if ($lock === false) {
            throw new \RuntimeException(
                'Não foi possível abrir o bloqueio do calendário.',
                500
            );
        }

        try {
            if (!flock($lock, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException(
                    'Calendário em edição. Aguarde e tente novamente.',
                    409
                );
            }

            try {
                $raw = file_get_contents($file);

                if ($raw === false) {
                    throw new \RuntimeException('Falha de leitura.');
                }

                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                self::validate($data, $companyDb);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Calendário inválido. Revise a configuração antes de continuar.',
                    500,
                    $e
                );
            }

            $calendar = new self(
                $companyDb,
                hash('sha256', $companyDb . "\n" . $raw),
                $data
            );

            return $callback($calendar);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function validate(mixed $data, string $companyDb): void
    {
        if (
            !is_array($data)
            || ($data['companyDb'] ?? '') !== $companyDb
            || !is_int($data['revision'] ?? null)
            || $data['revision'] < 1
            || !is_array($data['weekdays'] ?? null)
            || !$data['weekdays']
            || count($data['weekdays']) > 7
            || !is_array($data['holidays'] ?? null)
            || count($data['holidays']) > 1000
        ) {
            throw new \RuntimeException('Estrutura de calendário inválida.');
        }

        foreach ($data['weekdays'] as $day) {
            if (!is_int($day) || $day < 1 || $day > 7) {
                throw new \RuntimeException('Dia útil inválido.');
            }
        }

        foreach ($data['holidays'] as $holiday) {
            if (
                !is_array($holiday)
                || !is_string($holiday['date'] ?? null)
                || !is_bool($holiday['annual'] ?? null)
            ) {
                throw new \RuntimeException('Registro de calendário inválido.');
            }

            $date = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $holiday['date']
            );

            if (
                !$date
                || $date->format('Y-m-d') !== $holiday['date']
                || $holiday['date'] < '2000-01-01'
                || $holiday['date'] > '2100-12-31'
            ) {
                throw new \RuntimeException('Data de calendário inválida.');
            }
        }
    }

    public function addBusinessDays(string $date, int $days = 2): string
    {
        $current = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new \DateTimeZone('America/Sao_Paulo')
        );

        if (!$current || $current->format('Y-m-d') !== $date) {
            throw new \RuntimeException('Data de entrega inválida.', 422);
        }

        if ($days < 1 || $days > 30) {
            throw new \RuntimeException('Prazo de dias úteis inválido.', 422);
        }

        $count = 0;

        for ($attempt = 0; $attempt < 3660; $attempt++) {
            $current = $current->modify('+1 day');

            if (!in_array((int) $current->format('N'), $this->weekdays, true)) {
                continue;
            }

            if (
                isset($this->annual[$current->format('m-d')])
                || isset($this->dates[$current->format('Y-m-d')])
            ) {
                continue;
            }

            $count++;

            if ($count === $days) {
                return $current->format('Y-m-d');
            }
        }

        throw new \RuntimeException(
            'Não foi possível encontrar dias úteis. Revise o calendário.',
            422
        );
    }
}
