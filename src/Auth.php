<?php
declare(strict_types=1);
namespace App;

final class Auth {
    public static function csrfToken(): string {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
    public static function verifyCsrf(string $token): void {
        if ($token === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) throw new \RuntimeException('Token CSRF inválido.', 403);
    }
    public static function storeSapSession(string $companyDb, string $userName, string $cookie, int $timeout): void {
        session_regenerate_id(true);
        $_SESSION['sap'] = ['companyDb'=>$companyDb,'userName'=>$userName,'cookie'=>$cookie,'expiresAt'=>time()+max(60,min(1740,($timeout*60)-60))];
    }
    public static function sapSession(): ?array {
        $sap = $_SESSION['sap'] ?? null;
        if (!is_array($sap) || (int)($sap['expiresAt'] ?? 0) < time()) { unset($_SESSION['sap']); return null; }
        return $sap;
    }
    public static function loggedIn(): bool { return self::sapSession() !== null; }
    public static function requireLogin(): void { if (!self::loggedIn()) { header('Location: login.php'); exit; } }
    public static function requireSapSession(): array { $sap=self::sapSession(); if (!$sap) throw new \RuntimeException('Sua sessão SAP expirou. Faça login novamente.',401); return $sap; }
    public static function destroy(): void { $_SESSION=[]; session_destroy(); }
}
