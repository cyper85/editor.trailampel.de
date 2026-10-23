<?php

require '/var/www/lib.php';

date_default_timezone_set('Europe/Berlin');

const STATUS = ['ACTIVE', 'WARNING', 'INACTIVE', 'CLOSED', 'DESTROYED', 'NOT READY'];
const MESSAGE_MAX_LENGTH = 1000;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
}

function param(array $source, string $key): string
{
    return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : '';
}

function is_valid_date(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function trail_url(string $city, string $zone, string $trail): string
{
    return 'editor.php?' . http_build_query(['city' => $city, 'zone' => $zone, 'trail' => $trail]);
}

function render_page(string $title, string $content, ?string $user): never
{
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> – Trailampel Editor</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 0 auto; padding: 1rem; line-height: 1.4; }
        h1 { font-size: 1.4rem; }
        h2 { font-size: 1.2rem; margin-top: 2rem; }
        h3 { font-size: 1rem; margin-bottom: .25rem; }
        label { display: block; font-weight: bold; margin-top: 1rem; }
        input, select, textarea { box-sizing: border-box; width: 100%; padding: .6rem; font-size: 1rem; margin-top: .25rem; }
        button { width: 100%; padding: .8rem; margin-top: 1.5rem; font-size: 1.1rem; font-weight: bold; cursor: pointer; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: .25rem 1rem; }
        dt { font-weight: bold; }
        dd { margin: 0; }
        nav { display: flex; justify-content: space-between; align-items: center; gap: 1rem; font-size: .9rem; }
        nav form { display: inline; }
        nav button { width: auto; padding: .2rem .6rem; margin: 0; font-size: .9rem; font-weight: normal; }
        ul.trails { list-style: none; padding: 0; margin: 0; }
        ul.trails li { display: flex; justify-content: space-between; gap: 1rem; padding: .5rem 0; border-bottom: 1px solid #eee; }
        .status { font-size: .8rem; padding: .1rem .4rem; border-radius: .25rem; white-space: nowrap; }
        .status-ACTIVE { background: #cfc; }
        .status-WARNING { background: #ffc; }
        .status-INACTIVE { background: #ddd; }
        .status-CLOSED { background: #fcc; }
        .status-DESTROYED { background: #333; color: #fff; }
        .status-NOT-READY { background: #cdf; }
        .errors { background: #fdd; border: 1px solid #c00; padding: .5rem 1rem; }
        .success { background: #dfd; border: 1px solid #080; padding: .5rem 1rem; }
        fieldset { border: 1px solid #ccc; margin-top: 1.5rem; }
    </style>
</head>
<body>
<nav>
    <a href="editor.php">Übersicht</a>
    <?php if ($user !== null) { ?>
        <span>
            Angemeldet als <?= h($user) ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Abmelden</button>
            </form>
        </span>
    <?php } ?>
</nav>
<h1><?= h($title) ?></h1>
<?= $content ?>
<p><a href="https://trailampel.de">zurück zu trailampel.de</a></p>
</body>
</html>
<?php
    exit;
}

function render_overview(array $trails, ?string $user): never
{
    ob_start();
    foreach ($trails as $city => $zones) {
        if (!is_array($zones)) {
            continue;
        }
        ?>
        <h2><?= h($city) ?></h2>
        <?php foreach ($zones as $zone => $entries) {
            if (!is_array($entries)) {
                continue;
            }
            ?>
            <h3><?= h($zone) ?></h3>
            <ul class="trails">
                <?php foreach ($entries as $trail => $entry) {
                    $status = is_array($entry) && is_string($entry['status'] ?? null) ? $entry['status'] : '';
                    ?>
                    <li>
                        <a href="<?= h(trail_url($city, $zone, $trail)) ?>"><?= h($trail) ?></a>
                        <span class="status status-<?= h(str_replace(' ', '-', $status)) ?>"><?= h($status) ?></span>
                    </li>
                <?php } ?>
            </ul>
        <?php } ?>
    <?php }
    render_page('Alle Trails', ob_get_clean(), $user);
}

start_session();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && param($_POST, 'action') === 'logout') {
    if (csrf_valid()) {
        logout();
    }
    $query = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: editor.php' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

$city = param($_REQUEST, 'city');
$zone = param($_REQUEST, 'zone');
$trail = param($_REQUEST, 'trail');

try {
    [$trails] = load_trails();
} catch (GitHubException $e) {
    http_response_code(502);
    render_page('Fehler', '<p class="errors">' . h($e->getMessage()) . '</p>', $user);
}

if ($city === '' && $zone === '' && $trail === '') {
    render_overview($trails, $user);
}

if (!isset($trails[$city][$zone][$trail]) || !is_array($trails[$city][$zone][$trail])) {
    http_response_code(404);
    render_page('Trail nicht gefunden', '<p>Bitte einen Trail aus der <a href="editor.php">Übersicht</a> wählen.</p>', $user);
}

$entry = $trails[$city][$zone][$trail];
$status = $entry['status'] ?? '';
$message = $entry['message'] ?? '';
$last_checkup = date('Y-m-d');
$username = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = param($_POST, 'status');
    $message = param($_POST, 'message');
    $last_checkup = param($_POST, 'last_checkup');

    if (!csrf_valid()) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte erneut speichern.';
    }
    if (!in_array($status, STATUS, true)) {
        $errors[] = 'Ungültiger Status.';
    }
    if (mb_strlen($message) > MESSAGE_MAX_LENGTH) {
        $errors[] = 'Die Nachricht darf höchstens ' . MESSAGE_MAX_LENGTH . ' Zeichen lang sein.';
    }
    if ($last_checkup !== '' && !is_valid_date($last_checkup)) {
        $errors[] = 'Ungültiges Datum.';
    }

    if (!$errors && $user === null) {
        $username = param($_POST, 'username');
        $password = $_POST['password'] ?? '';
        if (login_locked($username)) {
            $errors[] = 'Zu viele Fehlversuche. Bitte in ' . (LOGIN_LOCK_SECONDS / 60) . ' Minuten erneut versuchen.';
        } elseif (!is_string($password) || !authenticate($username, $password)) {
            login_failed($username);
            $errors[] = 'Nutzername oder Passwort falsch.';
        } else {
            login_succeeded($username);
            login($username);
            $user = $username;
        }
    }

    if (!$errors) {
        try {
            update_trails(function (array $trails) use ($city, $zone, $trail, $status, $message, $last_checkup) {
                if (!isset($trails[$city][$zone][$trail]) || !is_array($trails[$city][$zone][$trail])) {
                    throw new GitHubException('Der Trail wurde zwischenzeitlich entfernt.');
                }
                $entry = &$trails[$city][$zone][$trail];
                $entry['status'] = $status;
                // leere Felder entfernen statt als "" zu speichern
                foreach (['message' => $message, 'last_checkup' => $last_checkup] as $key => $value) {
                    if ($value === '') {
                        unset($entry[$key]);
                    } else {
                        $entry[$key] = $value;
                    }
                }
                return $trails;
            }, "User: $user Trail: $trail", $user);
        } catch (GitHubException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        render_page('Gespeichert', '<p class="success">Speichern erfolgreich. Bis die Website aktualisiert wird, dauert es wenige Minuten.</p>'
            . '<p><a href="editor.php">Weiteren Trail bearbeiten</a></p>', $user);
    }
}

ob_start();
?>
<dl>
    <dt>Ort</dt><dd><?= h($city) ?></dd>
    <dt>Zone</dt><dd><?= h($zone) ?></dd>
    <dt>Trail</dt><dd><?= h($trail) ?></dd>
</dl>

<?php if ($errors) { ?>
    <div class="errors">
        <?php foreach ($errors as $error) { ?>
            <p><?= h($error) ?></p>
        <?php } ?>
    </div>
<?php } ?>

<form action="editor.php" method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="city" value="<?= h($city) ?>">
    <input type="hidden" name="zone" value="<?= h($zone) ?>">
    <input type="hidden" name="trail" value="<?= h($trail) ?>">

    <label for="status">Status</label>
    <select id="status" name="status">
        <?php foreach (STATUS as $s) { ?>
            <option value="<?= h($s) ?>" <?= $s === $status ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php } ?>
    </select>

    <label for="message">Nachricht</label>
    <textarea id="message" name="message" rows="5" maxlength="<?= MESSAGE_MAX_LENGTH ?>"><?= h($message) ?></textarea>

    <label for="last_checkup">Zuletzt kontrolliert</label>
    <input type="date" id="last_checkup" name="last_checkup" value="<?= h($last_checkup) ?>" max="<?= date('Y-m-d') ?>">

    <?php if ($user === null) { ?>
        <fieldset>
            <legend>Anmeldung (bleibt 30 Tage gespeichert)</legend>
            <label for="username">Nutzername</label>
            <input type="text" id="username" name="username" value="<?= h($username) ?>" autocomplete="username" autocapitalize="none" required>

            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </fieldset>
    <?php } ?>

    <button type="submit">Speichern</button>
</form>
<?php
render_page($trail, ob_get_clean(), $user);
