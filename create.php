<?php
// Legt einen Nutzer an oder setzt sein Passwort neu:
//   docker compose exec web php /var/www/create.php <username>

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if ($argc !== 2) {
    fwrite(STDERR, "Aufruf: php create.php <username>\n");
    exit(1);
}

const DB_FILENAME = 'sqlite:/var/www/user.sqlite';
$username = $argv[1];

fwrite(STDOUT, "Passwort für $username: ");
shell_exec('stty -echo 2>/dev/null');
$password = rtrim(fgets(STDIN), "\r\n");
shell_exec('stty echo 2>/dev/null');
fwrite(STDOUT, "\n");

if (strlen($password) < 8) {
    fwrite(STDERR, "Das Passwort muss mindestens 8 Zeichen lang sein.\n");
    exit(1);
}

$db = new PDO(DB_FILENAME);
$db->exec('CREATE TABLE IF NOT EXISTS user(username TEXT PRIMARY KEY, password TEXT NOT NULL)');
$statement = $db->prepare('INSERT INTO user (username, password) VALUES(:username, :password)
    ON CONFLICT(username) DO UPDATE SET password = excluded.password');
$statement->bindValue(':username', $username);
$statement->bindValue(':password', password_hash($password, PASSWORD_DEFAULT));
$statement->execute();

fwrite(STDOUT, "Nutzer $username gespeichert.\n");
