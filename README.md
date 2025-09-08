# Dateibasierte-Umfrage-in-PHP
Hier ist ein kompaktes, dateibasiertes Umfragescript in PHP, das Antworten sicher in einer Textdatei (NDJSON) speichert, beim Schreiben sperrt (flock), einfache Duplikate verhindert (Cookie + gehashte IP) und die Ergebnisse ausliest und aggregiert.

Speichert unter data/ im gleichen Ordner. Bitte stelle sicher, dass der Webserver Schreibrechte hat (z. B. chmod 755 für den Ordner, im Zweifel chmod 775/777 testweise).

Code zum Einbinden in eine bestehende Webseite:

Variante A: Direktes Einbinden im PHP-Template

<?php
// In deiner Seite (z. B. index.php) an passender Stelle:
require __DIR__ . '/survey.php';

// Umfrage anzeigen:
render_survey();

// Oder die Ergebnisse:
# render_survey_results();
?>

Variante B: Per Query-Parameter zwischen Ansicht wechseln

<?php
require __DIR__ . '/survey.php';

if (isset($_GET['results'])) {
    render_survey_results();
} else {
    render_survey();
}

Variante C: Per iFrame (wenn Seite statisch ist)

<!-- Formular -->
<iframe src="/pfad/zu/survey.php" style="width:100%;max-width:900px;height:520px;border:0;"></iframe>

<!-- Ergebnisse -->
<iframe src="/pfad/zu/survey.php?results=1" style="width:100%;max-width:900px;height:520px;border:0;"></iframe>


Im survey.php-Header befindet sich der Konfigurationsblock:

$SURVEY = [
  'id'       => 'beispiel_tech2025',
  'title'    => 'Welche Technologie findest du am spannendsten?',
  'question' => 'Bitte wähle eine Option:',
  'options'  => ['Solarenergie','Windenergie','Wasserkraft','Biomasse','Geothermie','Andere'],
  'enable_comment' => true, // Freitextfeld an/aus
];

id: eindeutige Kennung (nur Buchstaben/Zahlen/Unterstrich).
options: beliebig anpassen.
enable_comment: Kommentarbox ein-/ausschalten.
Wichtig: Ändere $IP_SALT auf einen eigenen zufälligen Wert.


Hinweise:

Datenformat: Jede Stimmabgabe ist eine einzelne JSON-Zeile in data/survey_<id>.ndjson. Dadurch lassen sich Daten später leicht weiterverarbeiten (z. B. per Python, jq, etc.).

Mehrere Umfragen: Lege einfach mehrere Kopien von survey.php mit unterschiedlichen $SURVEY['id']/Optionen an oder verwalte die $SURVEY-Konfiguration dynamisch über include.

Sicherheit: Das Script nutzt ein einfaches CSRF-Token, Cookie + gehashte IP als Basisschutz. Für öffentliche, stark frequentierte Umfragen ggf. zusätzlich Rate-Limiting, Captcha o. Ä. ergänzen.

