<?php

const DB_FILENAME = 'sqlite:/var/www/user.sqlite';
const LOGIN_ATTEMPTS_FILE = '/tmp/editor-login-attempts.json';
const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_SECONDS = 900;
const SAVE_ATTEMPTS = 3;
const SESSION_LIFETIME = 30 * 24 * 3600;
const FEED_MAX_ITEMS = 50;

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

function repo_path(string $path): string
{
    return '/repos/' . config('GITHUB_REPO', 'cyper85/trailampel.de') . $path;
}

function trails_file(): string
{
    return config('TRAILS_FILE', 'trails.json');
}

function feed_file(): string
{
    return config('FEED_FILE', 'feed.xml');
}

/**
 * @return string|null Dateiinhalt im angegebenen Stand, null wenn die Datei nicht existiert
 */
function load_file(string $file, string $ref): ?string
{
    [$status, $data] = github_request('GET', repo_path('/contents/' . $file . '?ref=' . rawurlencode($ref)));
    if ($status === 404) {
        return null;
    }
    if ($status !== 200) {
        throw new GitHubException("Laden von $file fehlgeschlagen (HTTP $status): " . ($data['message'] ?? ''));
    }
    return base64_decode($data['content']);
}

function parse_trails(?string $json): array
{
    $trails = $json === null ? null : json_decode($json, associative: true);
    if (!is_array($trails)) {
        throw new GitHubException(trails_file() . ' fehlt oder ist kein gültiges JSON.');
    }
    return $trails;
}

function load_trails(): array
{
    return parse_trails(load_file(trails_file(), config('GITHUB_BRANCH', 'main')));
}

/**
 * @return array{0: string, 1: string} sha des aktuellen Commits auf dem Branch und dessen Tree
 */
function load_head(): array
{
    $branch = config('GITHUB_BRANCH', 'main');
    [$status, $ref] = github_request('GET', repo_path('/git/ref/heads/' . $branch));
    if ($status !== 200) {
        throw new GitHubException("Branch $branch nicht gefunden (HTTP $status): " . ($ref['message'] ?? ''));
    }
    $commit_sha = $ref['object']['sha'];
    [$status, $commit] = github_request('GET', repo_path('/git/commits/' . $commit_sha));
    if ($status !== 200) {
        throw new GitHubException("Commit nicht lesbar (HTTP $status): " . ($commit['message'] ?? ''));
    }
    return [$commit_sha, $commit['tree']['sha']];
}

/**
 * Schreibt mehrere Dateien in einem Commit.
 *
 * @param array<string, string> $files Pfad => Inhalt
 * @return bool false, wenn der Branch zwischenzeitlich weitergewandert ist
 */
function commit_files(string $parent, string $base_tree, array $files, string $message, string $author): bool
{
    $tree = [];
    foreach ($files as $path => $content) {
        $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'content' => $content];
    }
    [$status, $data] = github_request('POST', repo_path('/git/trees'), ['base_tree' => $base_tree, 'tree' => $tree]);
    if ($status !== 201) {
        throw new GitHubException("Speichern fehlgeschlagen (Tree, HTTP $status): " . ($data['message'] ?? ''));
    }
    [$status, $data] = github_request('POST', repo_path('/git/commits'), [
        'message' => $message,
        'tree' => $data['sha'],
        'parents' => [$parent],
        'author' => ['name' => $author, 'email' => config('AUTHOR_EMAIL', 'editor@trailampel.de')],
    ]);
    if ($status !== 201) {
        throw new GitHubException("Speichern fehlgeschlagen (Commit, HTTP $status): " . ($data['message'] ?? ''));
    }
    [$status, $data] = github_request('PATCH', repo_path('/git/refs/heads/' . config('GITHUB_BRANCH', 'main')), [
        'sha' => $data['sha'],
        'force' => false,
    ]);
    if ($status === 422 && str_contains(strtolower($data['message'] ?? ''), 'fast forward')) {
        return false;
    }
    if ($status !== 200) {
        throw new GitHubException("Speichern fehlgeschlagen (HTTP $status): " . ($data['message'] ?? ''));
    }
    return true;
}

/**
 * Lädt die aktuelle trails.json, wendet $modify an und speichert sie zusammen mit dem
 * fortgeschriebenen RSS-Feed in einem Commit. Hat jemand anderes zwischenzeitlich
 * gespeichert, wird die Änderung neu angewendet.
 *
 * @param callable(array): array{0: array, 1: ?array} $modify liefert die neuen Trails und
 *        optional einen Feed-Eintrag (siehe feed_item())
 */
function update_trails(callable $modify, string $message, string $author): void
{
    for ($i = 0; $i < SAVE_ATTEMPTS; $i++) {
        [$commit, $tree] = load_head();
        [$trails, $item] = $modify(parse_trails(load_file(trails_file(), $commit)));
        $files = [trails_file() => json_encode($trails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        if ($item !== null) {
            $files[feed_file()] = add_feed_item(load_file(feed_file(), $commit), $item);
        }
        if (commit_files($commit, $tree, $files, $message, $author)) {
            return;
        }
    }
    throw new GitHubException('Die Datei wurde gleichzeitig mehrfach geändert. Bitte erneut speichern.');
}

// ---------------------------------------------------------------------------
// RSS-Feed
// ---------------------------------------------------------------------------

function site_url(): string
{
    return rtrim(config('SITE_URL', 'https://trailampel.de'), '/') . '/';
}

function format_date(string $date): string
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed === false ? $date : $parsed->format('d.m.Y');
}

/**
 * Baut einen Feed-Eintrag aus dem alten und neuen Stand eines Trails.
 * Liefert null, wenn sich nichts geändert hat.
 *
 * @return array{title: string, description: string}|null
 */
function feed_item(string $city, string $zone, string $trail, array $old, array $new): ?array
{
    $fields = ['status', 'message', 'last_checkup'];
    $changed = array_filter($fields, fn($f) => ($old[$f] ?? '') !== ($new[$f] ?? ''));
    if (!$changed) {
        return null;
    }
    $e = fn(string $value) => htmlspecialchars($value, ENT_QUOTES | ENT_XML1);
    $old_status = $old['status'] ?? '';
    $new_status = $new['status'];

    $html = '';
    if (($new['message'] ?? '') !== '') {
        $html .= '<p>' . nl2br($e($new['message'])) . '</p>';
    }
    $html .= '<p>';
    $html .= $old_status === $new_status
        ? 'Status: ' . $e($new_status) . ' (unverändert)'
        : 'Status: ' . $e($old_status) . ' → ' . $e($new_status);
    if (($new['last_checkup'] ?? '') !== '') {
        $html .= '<br>Letzter Check: ' . $e(format_date($new['last_checkup']));
    }
    $html .= '<br>' . $e($city) . ' › ' . $e($zone) . ' › ' . $e($trail) . '</p>';

    return ['title' => "[$new_status] $trail", 'description' => $html];
}

function new_feed(): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $rss = $doc->appendChild($doc->createElement('rss'));
    $rss->setAttribute('version', '2.0');
    $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
    $channel = $rss->appendChild($doc->createElement('channel'));
    $channel->appendChild($doc->createElement('title', 'Trail Ampel'));
    $channel->appendChild($doc->createElement('link', site_url()));
    $channel->appendChild($doc->createElement('description', 'Statusänderungen der MTB Trails'));
    $channel->appendChild($doc->createElement('language', 'de'));
    $self = $channel->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'atom:link'));
    $self->setAttribute('href', site_url() . feed_file());
    $self->setAttribute('rel', 'self');
    $self->setAttribute('type', 'application/rss+xml');
    return $doc;
}

/**
 * Stellt den Eintrag an den Anfang des Feeds und kürzt ihn auf FEED_MAX_ITEMS Einträge.
 */
function add_feed_item(?string $xml, array $item): string
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    if ($xml === null || !@$doc->loadXML($xml) || $doc->getElementsByTagName('channel')->length === 0) {
        $doc = new_feed();
    }
    $doc->formatOutput = true;
    $channel = $doc->getElementsByTagName('channel')->item(0);
    $now = date(DATE_RSS);

    $last_build = $channel->getElementsByTagName('lastBuildDate')->item(0);
    if ($last_build === null) {
        $last_build = $channel->appendChild($doc->createElement('lastBuildDate'));
    }
    $last_build->textContent = $now;

    $node = $doc->createElement('item');
    $node->appendChild($doc->createElement('title'))->textContent = $item['title'];
    $node->appendChild($doc->createElement('link'))->textContent = site_url();
    $node->appendChild($doc->createElement('description'))->textContent = $item['description'];
    $node->appendChild($doc->createElement('pubDate'))->textContent = $now;
    $guid = $node->appendChild($doc->createElement('guid'));
    $guid->setAttribute('isPermaLink', 'false');
    $guid->textContent = 'trailampel-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

    $items = $channel->getElementsByTagName('item');
    $channel->insertBefore($node, $items->item(0));
    while ($items->length > FEED_MAX_ITEMS) {
        $channel->removeChild($items->item($items->length - 1));
    }
    return $doc->saveXML();
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
