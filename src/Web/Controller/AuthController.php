<?php

declare(strict_types=1);

namespace FortKnox\Web\Controller;

final class AuthController
{
    public function __construct(
        private readonly string $adminUser,
        private readonly string $adminPasswordHash
    ) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }

    public function login(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];

        $user = (string) ($data['username'] ?? '');
        $pass = (string) ($data['password'] ?? '');

        if ($this->adminUser !== '' && $user === $this->adminUser && password_verify($pass, $this->adminPasswordHash)) {
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_user'] = $user;
            echo json_encode(['success' => true, 'user' => $user]);
            return;
        }

        http_response_code(401);
        echo json_encode(['error' => 'Ungültige Anmeldedaten.']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
    }

    public function status(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $authenticated = !empty($_SESSION['admin_authenticated']);
        echo json_encode([
            'authenticated' => $authenticated,
            'user' => $authenticated ? ($_SESSION['admin_user'] ?? 'admin') : null,
        ]);
    }

    public static function check(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['admin_authenticated']);
    }
}
