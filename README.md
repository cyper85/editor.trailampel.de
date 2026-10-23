# editor.trailampel.de

Einfacher Web-Editor, mit dem auch Nicht-Programmierer den Status der Trails in der
`trails.json` von [trailampel.de](https://trailampel.de) pflegen können.

Änderungen werden über die [GitHub Contents API](https://docs.github.com/de/rest/repos/contents)
direkt als Commit in das Repository `cyper85/trailampel.de` geschrieben. Es wird weder
`git` noch ein SSH-Key benötigt. Die Nutzer des Editors brauchen keinen GitHub-Account.

## Aufruf

Der Editor wird von trailampel.de aus pro Trail verlinkt:

```
https://editor.trailampel.de/editor.php?city=<Ort>&zone=<Zone>&trail=<Trail>
```

Ort, Zone und Trail müssen in der `trails.json` existieren, sonst antwortet der Editor mit 404.
`https://editor.trailampel.de/editor.php` ohne Parameter zeigt eine Übersicht aller Trails.

Beim ersten Speichern meldet man sich mit Nutzername und Passwort an und bleibt danach
30 Tage angemeldet. Der Commit wird mit dem Nutzernamen als Autor angelegt.

## Einrichtung

### 1. GitHub-Token anlegen

Unter [GitHub → Settings → Developer settings → Fine-grained tokens](https://github.com/settings/personal-access-tokens)
einen neuen Token erstellen:

- **Repository access:** „Only select repositories“ → `cyper85/trailampel.de`
- **Repository permissions:** **Contents: Read and write**
  („Metadata: Read-only“ wird automatisch mit ausgewählt)
- Sonst keine Berechtigungen

Ist auf dem Branch eine Branch-Protection aktiv (z. B. „Pull Request erforderlich“),
wird das Speichern abgelehnt und der Editor zeigt einen Fehler an.

### 2. Konfiguration

`.env.example` nach `.env` kopieren und den Token eintragen:

```
GITHUB_TOKEN=github_pat_...
```

Optionale Einstellungen:

| Variable        | Standard                | Bedeutung                       |
|-----------------|-------------------------|---------------------------------|
| `GITHUB_REPO`   | `cyper85/trailampel.de` | Repository mit der trails.json  |
| `GITHUB_BRANCH` | `main`                  | Branch, auf den committet wird  |
| `TRAILS_FILE`   | `trails.json`           | Pfad der Datei im Repository    |
| `AUTHOR_EMAIL`  | `editor@trailampel.de`  | E-Mail-Adresse des Commit-Autors |
| `GITHUB_API`    | `https://api.github.com`| API-URL (nur für Tests)         |

### 3. Starten

```
touch user.sqlite
docker compose up -d --build
```

Der Editor lauscht auf `127.0.0.1:6180` und wird über einen Reverse Proxy veröffentlicht.

### 4. Nutzer verwalten

Nutzer anlegen oder Passwort ändern (das Passwort wird verdeckt abgefragt, mindestens 8 Zeichen):

```
docker compose exec -u root web php /var/www/create.php <username>
```

`-u root` ist nötig, weil SQLite beim Schreiben eine Journal-Datei in `/var/www` anlegt.
Der Editor selbst liest die Datenbank nur.

Nutzer löschen:

```
docker compose exec -u root web php -r '(new PDO("sqlite:/var/www/user.sqlite"))->prepare("DELETE FROM user WHERE username = ?")->execute([$argv[1]]);' <username>
```

## Sicherheit

- Die Login-Session gilt 30 Tage. Sie wird sofort ungültig, wenn der Nutzer gelöscht oder sein
  Passwort geändert wird. Die Sessions liegen in `/tmp` des Containers. Nach
  `docker compose up --build` (neuer Container) müssen sich daher alle neu anmelden.
- Alle Formulare sind mit einem CSRF-Token geschützt.
- Nach 5 fehlgeschlagenen Logins wird der Nutzername für 15 Minuten gesperrt.
  Die Zähler liegen in `/tmp` des Containers und werden bei einem Neustart zurückgesetzt.
- Status, Datum und Nachrichtenlänge (max. 1000 Zeichen) werden geprüft. Neue Trails können
  über den Editor nicht angelegt werden.
- Haben zwei Personen gleichzeitig gespeichert, lädt der Editor die aktuelle Datei neu und
  wendet die Änderung erneut an.

## Umstieg von der alten Version (git über SSH)

1. `.env` wie oben beschrieben anlegen.
2. `docker compose up -d --build`. Die bestehende `user.sqlite` funktioniert unverändert weiter.
3. Den alten Deploy-Key in den Einstellungen des Repositorys `cyper85/trailampel.de` entfernen.
4. `id_ed25519` und `passwords.csv` vom Server löschen.

Beim ersten Speichern werden in der `trails.json` einmalig die als `ä` usw. kodierten
Umlaute in echte Umlaute umgewandelt.
