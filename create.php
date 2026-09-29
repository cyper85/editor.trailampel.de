<?php
const DB_FILENAME = 'sqlite:/var/www/user.sqlite';
$db = new PDO(DB_FILENAME);
$statement = $db->prepare('CREATE TABLE IF NOT EXISTS user(username TEST PRIMARY KEY,password TEXT NOT NULL)');
$statement->execute();

// init
$statement = $db->prepare('INSERT OR IGNORE INTO user (username, password) VALUES(:username, :password)');
$file = fopen('/var/www/passwords.csv', 'r');
while (($line = fgetcsv($file, separator: ';', enclosure: '"', escape: "\\")) !== FALSE) {
    $statement->bindValue(':username', $line[0]);
    $statement->bindValue(':password', password_hash($line[1], PASSWORD_DEFAULT));
    $statement->execute();
}
fclose($file);
