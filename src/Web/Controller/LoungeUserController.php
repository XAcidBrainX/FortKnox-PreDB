<?php

declare(strict_types=1);

namespace FortKnox\Web\Controller;

final class LoungeUserController
{
    private const HELPER = '/usr/local/bin/fortknox-lounge-userctl';
    private const SERVICE = 'fortknox-fish-lounge-test.service';
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{1,31}$/';

    public function index(): void
    {
        if (!$this->authorize()) {
            return;
        }

        $result = $this->runHelper(['list']);
        if ($result['code'] !== 0) {
            $this->respondError($result, 'Lounge-Benutzer konnten nicht geladen werden.');
            return;
        }

        $data = json_decode($result['out'], true);
        if (!is_array($data) || empty($data['success']) || !is_array($data['users'] ?? null)) {
            $this->json(['success' => false, 'error' => 'Ungültige Antwort des Lounge-Helfers.'], 502);
            return;
        }

        $this->json([
            'success' => true,
            'service' => $this->serviceStatus(),
            'users' => $data['users'],
        ]);
    }

    public function create(): void
    {
        if (!$this->authorize()) {
            return;
        }

        $data = $this->input();
        $name = trim((string) ($data['name'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $saveLogs = !empty($data['log']);

        if (!$this->validName($name)) {
            $this->json([
                'success' => false,
                'error' => 'Ungültiger Benutzername. Erlaubt sind 2-32 Zeichen: Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich.',
            ], 400);
            return;
        }

        $passwordError = $this->validatePassword($password);
        if ($passwordError !== null) {
            $this->json(['success' => false, 'error' => $passwordError], 400);
            return;
        }

        $result = $this->runHelper(['add', $name, $saveLogs ? '1' : '0'], $password);
        if ($result['code'] !== 0) {
            $this->respondError($result, 'Benutzer konnte nicht angelegt werden.');
            return;
        }

        $this->json([
            'success' => true,
            'name' => $name,
            'message' => $saveLogs
                ? 'Benutzer wurde angelegt. Chatverläufe werden auf der Festplatte gespeichert.'
                : 'Benutzer wurde angelegt. Chatverläufe werden nicht gespeichert.',
        ]);
    }

    public function reset(string $name): void
    {
        if (!$this->authorize()) {
            return;
        }

        $name = trim($name);
        if (!$this->validName($name)) {
            $this->json(['success' => false, 'error' => 'Ungültiger Benutzername.'], 400);
            return;
        }

        $data = $this->input();
        $password = (string) ($data['password'] ?? '');
        $passwordError = $this->validatePassword($password);
        if ($passwordError !== null) {
            $this->json(['success' => false, 'error' => $passwordError], 400);
            return;
        }

        $result = $this->runHelper(['reset', $name], $password);
        if ($result['code'] !== 0) {
            $this->respondError($result, 'Passwort konnte nicht zurückgesetzt werden.');
            return;
        }

        $this->json([
            'success' => true,
            'name' => $name,
            'message' => 'Passwort wurde zurückgesetzt. Alle bestehenden Lounge-Sitzungen dieses Benutzers wurden beendet.',
        ]);
    }

    public function delete(string $name): void
    {
        if (!$this->authorize()) {
            return;
        }

        $name = trim($name);
        if (!$this->validName($name)) {
            $this->json(['success' => false, 'error' => 'Ungültiger Benutzername.'], 400);
            return;
        }

        $result = $this->runHelper(['remove', $name]);
        if ($result['code'] !== 0) {
            $this->respondError($result, 'Benutzer konnte nicht gelöscht werden.');
            return;
        }

        $this->json([
            'success' => true,
            'name' => $name,
            'message' => 'Benutzer wurde gelöscht.',
        ]);
    }

    private function authorize(): bool
    {
        if (AuthController::check()) {
            return true;
        }

        $this->json(['success' => false, 'error' => 'Nicht angemeldet.'], 401);
        return false;
    }

    private function validName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    private function validatePassword(string $password): ?string
    {
        if ($password === '') {
            return 'Das Passwort darf nicht leer sein.';
        }
        if (strlen($password) < 8) {
            return 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if (strlen($password) > 200) {
            return 'Das Passwort darf höchstens 200 Zeichen lang sein.';
        }
        if (str_contains($password, "\0")) {
            return 'Das Passwort enthält ungültige Zeichen.';
        }
        return null;
    }

    /**
     * @return array{code:int,out:string,err:string}
     */
    private function runHelper(array $arguments, ?string $stdin = null): array
    {
        $command = array_merge(
            ['/usr/bin/sudo', '-n', '-u', 'thelounge', self::HELPER],
            $arguments
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['code' => -1, 'out' => '', 'err' => 'Prozess konnte nicht gestartet werden.'];
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'out' => $stdout, 'err' => $stderr];
    }

    /**
     * @param array{code:int,out:string,err:string} $result
     */
    private function respondError(array $result, string $fallback): void
    {
        $decoded = json_decode($result['out'], true);
        $message = is_array($decoded) && is_string($decoded['error'] ?? null)
            ? $decoded['error']
            : $fallback;

        if ($message === $fallback && $result['err'] !== '') {
            error_log('[lounge-users] helper exit ' . $result['code'] . ': ' . substr($result['err'], 0, 300));
        }

        $status = match ($result['code']) {
            2 => 400,
            3 => 404,
            4 => 409,
            default => 500,
        };

        $this->json(['success' => false, 'error' => $message], $status);
    }

    private function serviceStatus(): string
    {
        $status = trim((string) @shell_exec(
            '/usr/bin/systemctl is-active ' . escapeshellarg(self::SERVICE) . ' 2>/dev/null'
        ));
        return $status === 'active' ? 'active' : 'inactive';
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
