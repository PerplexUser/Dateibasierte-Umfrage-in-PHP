# Dateibasierte-Umfrage-in-PHP
Hier ist ein kompaktes, dateibasiertes Umfragescript in PHP, das Antworten sicher in einer Textdatei (NDJSON) speichert, beim Schreiben sperrt (flock), einfache Duplikate verhindert (Cookie + gehashte IP) und die Ergebnisse ausliest und aggregiert.
