<?php

const STATUS = ['ACTIVE', 'WARNING', 'INACTIVE', 'CLOSED', 'DESTROYED', 'NOT READY'];
const DB_FILENAME = 'sqlite:/var/www/user.sqlite';
$zone = "";
$trail = "";
$city = "";
$status = "";
$message = "";
$last_checkup = "";
if (array_key_exists('zone', $_REQUEST)) {
    $zone = $_REQUEST['zone'];
}
if (array_key_exists('trail', $_REQUEST)) {
    $trail = $_REQUEST['trail'];
}
if (array_key_exists('city', $_REQUEST)) {
    $city = $_REQUEST['city'];
}

// get data
$json = file_get_contents('https://trailampel.de/trails.json');
$trails = json_decode($json, associative: true);

if (array_key_exists($city, $trails) && array_key_exists($zone, $trails[$city]) && array_key_exists($trail, $trails[$city][$zone])) {
    if (array_key_exists('status', $trails[$city][$zone][$trail])) {
        $status = $trails[$city][$zone][$trail]['status'];
    }
    if (array_key_exists('message', $trails[$city][$zone][$trail])) {
        $message = $trails[$city][$zone][$trail]['message'];
    }
    if (array_key_exists('last_checkup', $trails[$city][$zone][$trail])) {
        $last_checkup = $trails[$city][$zone][$trail]['last_checkup'];
    }
}

if (array_key_exists('username', $_POST) && array_key_exists('password', $_POST)) {
    $db = new PDO(DB_FILENAME);
    $statement = $db->prepare('SELECT password FROM user WHERE username = :username');
    $statement->bindValue(':username', $_POST['username'], PDO::PARAM_STR);
    $statement->execute();
    $result = $statement->fetch(PDO::FETCH_ASSOC);
    #print_r($result['password']);
    #print_r(array_key_exists('password', $result));
    if ($result === false) {
        exit('Unbekannter Nutzername');
    } else if (!array_key_exists('password', $result)) {
        exit('Unbekannter Nutzername');
    } else if (!password_verify($_POST['password'], $result['password'])) {
        exit('Falsches Passwort');
    }

    shell_exec('bash /var/www/checkout.sh 2>&1');

    $trails_file = '/tmp/editorgit/trails.json';
    $trails = json_decode(file_get_contents($trails_file), associative: true);
    $trails[$city][$zone][$trail]['status'] = $_POST['status'];
    if (array_key_exists('message', $_POST)) {
        $trails[$city][$zone][$trail]['message'] = $_POST['message'];
    } else if (array_key_exists('message', $trails[$city][$zone][$trail])) {
        unset($trails[$city][$zone][$trail]['message']);
    }
    if (array_key_exists('last_checkup', $_POST)) {
        $trails[$city][$zone][$trail]['last_checkup'] = $_POST['last_checkup'];
    } else if (array_key_exists('last_checkup', $trails[$city][$zone][$trail])) {
        unset($trails[$city][$zone][$trail]['last_checkup']);
    }

    file_put_contents($trails_file, json_encode($trails, JSON_PRETTY_PRINT));
    shell_exec('bash /var/www/commit.sh "' . escapeshellarg($_POST['username']) . '" "' . escapeshellarg($trail) . '" 2>&1');

    exit('<html><body><h1>Speichern erfolgreich. Bis die Website aktualisiert wird, dauert es wenige Minuten.</h1><br/><br/><br/><a href="https://trailampel.de">zur&uuml;ck zu trailampel.de</a></body></html>');
}

?>
<html>
<head>

</head>
<body>
<form action="editor.php" method="post">
    <table>
        <tr>
            <th><label for="city">City:</label></th>
            <td><input type="hidden" id="city" name="city" value="<?= $city ?>"/><?= $city ?>"</td>
        </tr>
        <tr>
            <th><label for="zone">Zone:</label></th>
            <td><input type="hidden" id="zone" name="zone" value="<?= $zone ?>"/><?= $zone ?></td>
        </tr>
        <tr>
            <th><label for="trail">Trail:</label></th>
            <td><input type="hidden" id="trail" name="trail" value="<?= $trail ?>"/><?= $trail ?></td>
        </tr>
        <tr>
            <th><label for="status">Status:</label></th>
            <td><select id="status" name="status">
                    <?php foreach (STATUS as $s) { ?>
                        <option value="<?= $s ?>" <?= $s == $status ? 'selected="selected"' : '' ?>><?= $s ?></option>
                    <?php } ?>
                </select></td>
        </tr>
        <tr>
            <th><label for="message">Message:</label></th>
            <td><textarea type="text" name="message" id="message" cols="40" rows="5"><?= $message ?></textarea></td>
        </tr>
        <tr>
            <th><label for="last_checkup">Last Checkup:</label></th>
            <td><input type="date" name="last_checkup" id="last_checkup" value="<?= $last_checkup ?>"/></td>
        </tr>
        <tr>
            <td colspan="2">&nbsp;</td>
        </tr>
        <tr>
            <th><label for="username">Username:</label></th>
            <td><input type="text" name="username" id="username"/></td>
        </tr>
        <tr>
            <th><label for="password">Password:</label></th>
            <td><input type="password" name="password" id="password"/></td>
        </tr>
        <tr>
            <td style="text-align:center" colspan="2"><input type="submit" value="Speichern"/></td>
        </tr>
    </table>


</form>
</body>
</html>