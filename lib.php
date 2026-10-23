<?php

const DB_FILENAME = 'sqlite:/var/www/user.sqlite';
const LOGIN_ATTEMPTS_FILE = '/tmp/editor-login-attempts.json';
const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_SECONDS = 900;
const SAVE_ATTEMPTS = 3;
const SESSION_LIFETIME = 30 * 24 * 3600;

class GitHubException extends RuntimeException
{
}

function config(string $name, ?string $default = null): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default === null) {
            throw new GitHubException("Konfiguration $name fehlt.");
        }
        return $default;
    }
    return $value;
}

// ---------------------------------------------------------------------------
// GitHub Contents API
// ---------------------------------------------------------------------------

function github_request(string $method, string $path, ?array $body = null): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . config('GITHUB_TOKEN'),
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: editor.trailampel.de',
    ];
    $ch = curl_init(config('GITHUB_API', 'https://api.github.com') . $path);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        throw new GitHubException('GitHub nicht erreichbar: ' . curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, json_decode($response, associative: true) ?? []];
}

function contents_path(): string
{
    return '/repos/' . config('GITHUB_REPO', 'cyper85/trailampel.de') . '/contents/' . config('TRAILS_FILE', 'trails.json');
}

/**
 * @return array{0: array, 1: string} trails und sha der aktuellen Dateiversion
 */
function load_trails(): array
{
    $branch = config('GITHUB_BRANCH', 'main');
    [$status, $data] = github_request('GET', contents_path() . '?ref=' . rawurlencode($branch));
    if ($status !== 200) {
        throw new GitHubException("Laden fehlgeschlagen (HTTP $status): " . ($data['message'] ?? ''));
    }
    $trails = json_decode(base64_decode($data['content']), associative: true);
    if (!is_array($trails)) {
        throw new GitHubException('trails.json ist kein gültiges JSON.');
    }
    return [$trails, $data['sha']];
}

/**
 * @return bool false, wenn die Datei zwischenzeitlich geändert wurde (sha veraltet)
 */
function save_trails(array $trails, string $sha, string $message, string $author): bool
{
    $json = json_encode($trails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$status, $data] = github_request('PUT', contents_path(), [
        'message' => $message,
        'content' => base64_encode($json),
        'sha' => $sha,
        'branch' => config('GITHUB_BRANCH', 'main'),
        'author' => ['name' => $author, 'email' => config('AUTHOR_EMAIL', 'editor@trailampel.de')],
    ]);
    if ($status === 409) {
        return false;
    }
    if ($status !== 200 && $status !== 201) {
        throw new GitHubException("Speichern fehlgeschlagen (HTTP $status): " . ($data['message'] ?? ''));
    }
    return true;
}

/**
 * Lädt die aktuelle trails.json, wendet $modify an und speichert. Hat jemand
 * anderes zwischenzeitlich gespeichert, wird die Änderung neu angewendet.
 */
function update_trails(callable $modify, string $message, string $author): void
{
    for ($i = 0; $i < SAVE_ATTEMPTS; $i++) {
        [$trails, $sha] = load_trails();
        if (save_trails($modify($trails), $sha, $message, $author)) {
            return;
        }
    }
    throw new GitHubException('Die Datei wurde gleichzeitig mehrfach geändert. Bitte erneut speichern.');
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

function find_password_hash(string $username): string|false
{
    $db = new PDO(DB_FILENAME);
    $statement = $db->prepare('SELECT password FROM user WHERE username = :username');
    $statement->bindValue(':username', $username, PDO::PARAM_STR);
    $statement->execute();
    return $statement->fetchColumn();
}

function authenticate(string $username, string $password): bool
{
    $hash = find_password_hash($username);
    // Auch bei unbekanntem Nutzer einen Hash prüfen, damit die Antwortzeit nichts verrät
    $valid = password_verify($password, $hash !== false ? $hash : password_hash('', PASSWORD_DEFAULT));
    return $hash !== false && $valid;
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

function start_session(): void
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    session_name('trailampel_editor');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

/**
 * Ändert sich das Passwort oder wird der Nutzer gelöscht, wird die Session ungültig.
 */
function password_fingerprint(string|false $hash): string
{
    return $hash === false ? '' : hash('sha256', $hash);
}

function login(string $username): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = $username;
    $_SESSION['fingerprint'] = password_fingerprint(find_password_hash($username));
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function current_user(): ?string
{
    $username = $_SESSION['user'] ?? null;
    if ($username === null) {
        return null;
    }
    $fingerprint = password_fingerprint(find_password_hash($username));
    if ($fingerprint === '' || !hash_equals($_SESSION['fingerprint'] ?? '', $fingerprint)) {
        logout();
        return null;
    }
    return $username;
}

function csrf_token(): string
{
    return $_SESSION['csrf'];
}

function csrf_valid(): bool
{
    return isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/**
 * Fehlversuche pro Nutzername (nicht pro IP, da der Container hinter einem Proxy läuft).
 */
function with_login_attempts(callable $callback): mixed
{
    $file = fopen(LOGIN_ATTEMPTS_FILE, 'c+');
    flock($file, LOCK_EX);
    $attempts = json_decode(stream_get_contents($file), associative: true) ?: [];
    $attempts = array_filter($attempts, fn($a) => $a['since'] > time() - LOGIN_LOCK_SECONDS);
    $result = $callback($attempts);
    ftruncate($file, 0);
    rewind($file);
    fwrite($file, json_encode($attempts));
    flock($file, LOCK_UN);
    fclose($file);
    return $result;
}

function login_locked(string $username): bool
{
    $key = mb_strtolower($username);
    return with_login_attempts(fn(array &$attempts) => ($attempts[$key]['count'] ?? 0) >= LOGIN_MAX_FAILURES);
}

function login_failed(string $username): void
{
    $key = mb_strtolower($username);
    with_login_attempts(function (array &$attempts) use ($key) {
        $attempts[$key] ??= ['count' => 0, 'since' => time()];
        $attempts[$key]['count']++;
    });
}

function login_succeeded(string $username): void
{
    $key = mb_strtolower($username);
    with_login_attempts(function (array &$attempts) use ($key) {
        unset($attempts[$key]);
    });
}
