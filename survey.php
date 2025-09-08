<?php
/**
 * survey.php — Dateibasierte Umfrage (NDJSON) mit Auswertung
 * - Speicherformat: data/survey_<ID>.ndjson (eine JSON-Zeile pro Stimmabgabe)
 * - Duplikat-Schutz: Cookie + gehashte IP (mit Salt)
 * - Direktaufruf:     /survey.php            -> Formular
 *                     /survey.php?results=1  -> Ergebnisse
 * - Einbindung:       siehe Snippets weiter unten
 *
 * www.perplex.click
 *
 */

/* =========================
   KONFIGURATION
   ========================= */
$SURVEY = [
    'id'       => 'beispiel_tech2025',
    'title'    => 'Welche Technologie findest du am spannendsten?',
    'question' => 'Bitte wähle eine Option:',
    'options'  => [
        'Solarenergie',
        'Windenergie',
        'Wasserkraft',
        'Biomasse',
        'Geothermie',
        'Andere'
    ],
    // Freitextfeld optional anzeigen (wird als "comment" gespeichert)
    'enable_comment' => true,
];

$DATA_DIR   = __DIR__ . '/data';
$DATA_FILE  = $DATA_DIR . '/survey_' . preg_replace('/[^a-z0-9_]+/i','',$SURVEY['id']) . '.ndjson';
$COOKIE_KEY = 'survey_voted_' . $SURVEY['id'];
$IP_SALT    = 'change_this_random_salt_492f1b3d'; // ← bitte für eure Instanz ändern!
$CSRF_KEY   = 'survey_csrf_' . $SURVEY['id'];

/* =========================
   HILFSFUNKTIONEN
   ========================= */

/** Erzeugt CSRF-Token (pro Session/Pageview) */
function survey_csrf_get($key) {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(16));
    }
    return $_SESSION[$key];
}
function survey_csrf_check($key, $token) {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    return isset($_SESSION[$key]) && hash_equals($_SESSION[$key], $token ?? '');
}

/** Stellt sicher, dass der Datenordner existiert */
function survey_ensure_data_dir($dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Datenordner ist nicht beschreibbar: ' . $dir);
    }
}

/** Hash der IP (mit Salt), um einfache Doppelabgaben zu erschweren */
function survey_hash_ip($salt) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash('sha256', $salt . '|' . $ip);
}

/** Eine Antwortzeile (JSON) anhängen – mit flock() */
function survey_append_vote($file, array $row) {
    $fh = fopen($file, 'ab');
    if (!$fh) {
        throw new RuntimeException('Kann Datei nicht öffnen: ' . $file);
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new RuntimeException('Konnte Datei nicht sperren: ' . $file);
    }
    fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Alle Antworten lesen (Iterator) */
function survey_read_all($file) {
    if (!is_file($file)) return [];
    $rows = [];
    $fh = fopen($file, 'rb');
    if (!$fh) return $rows;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $obj = json_decode($line, true);
        if (is_array($obj)) $rows[] = $obj;
    }
    fclose($fh);
    return $rows;
}

/** Aggregation: zählt pro Option und berechnet Prozentwerte */
function survey_aggregate(array $options, array $rows) {
    $counts = array_fill_keys($options, 0);
    $total  = 0;
    foreach ($rows as $r) {
        if (!empty($r['option']) && isset($counts[$r['option']])) {
            $counts[$r['option']]++;
            $total++;
        }
    }
    $percents = [];
    foreach ($counts as $opt => $cnt) {
        $percents[$opt] = $total > 0 ? round(100 * $cnt / $total, 1) : 0.0;
    }
    return [$counts, $percents, $total];
}

/* =========================
   RENDER-FUNKTIONEN (zum Einbinden)
   ========================= */

/** Formular rendern */
function render_survey(array $config = null) {
    global $SURVEY, $COOKIE_KEY, $CSRF_KEY;
    $cfg = $config ?? $SURVEY;
    $voted = isset($_COOKIE[$COOKIE_KEY]);
    $csrf  = htmlspecialchars(survey_csrf_get($CSRF_KEY), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="survey-widget" style="max-width:600px;margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:12px;font-family:system-ui,Arial,sans-serif">
        <h3 style="margin:0 0 .5rem 0"><?= htmlspecialchars($cfg['title']) ?></h3>
        <p style="margin:.25rem 0 1rem 0"><?= htmlspecialchars($cfg['question']) ?></p>

        <?php if ($voted): ?>
            <div style="padding:.75rem;background:#f6ffed;border:1px solid #b7eb8f;border-radius:8px;margin-bottom:.75rem">
                Danke, deine Stimme wurde gezählt. <a href="?results=1">Ergebnisse anzeigen</a>
            </div>
        <?php endif; ?>

        <form method="post" action="" style="display:flex;flex-direction:column;gap:.5rem">
            <input type="hidden" name="survey_action" value="vote">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <?php foreach ($cfg['options'] as $opt): ?>
                <label style="display:flex;gap:.5rem;align-items:center">
                    <input type="radio" name="option" value="<?= htmlspecialchars($opt) ?>" required <?= $voted?'disabled':'' ?>>
                    <span><?= htmlspecialchars($opt) ?></span>
                </label>
            <?php endforeach; ?>

            <?php if (!empty($cfg['enable_comment'])): ?>
                <label style="margin-top:.5rem;display:block;font-size:.95rem;color:#444">Optionaler Kommentar:
                    <textarea name="comment" rows="3" style="width:100%;margin-top:.25rem;padding:.5rem;border:1px solid #ccc;border-radius:8px" <?= $voted?'disabled':'' ?>></textarea>
                </label>
            <?php endif; ?>

            <button type="submit" <?= $voted?'disabled':'' ?>
                style="margin-top:.5rem;padding:.6rem .9rem;border:0;border-radius:10px;background:#1a73e8;color:#fff;font-weight:600;cursor:pointer;<?= $voted?'opacity:.6;cursor:not-allowed':'' ?>">
                Abstimmen
            </button>
            <div style="font-size:.9rem;color:#666">
                <a href="?results=1">Ergebnisse anzeigen</a>
            </div>
        </form>
    </div>
    <?php
}

/** Ergebnisse rendern */
function render_survey_results(array $config = null) {
    global $SURVEY, $DATA_FILE;
    $cfg  = $config ?? $SURVEY;
    $rows = survey_read_all($DATA_FILE);
    [$counts, $percents, $total] = survey_aggregate($cfg['options'], $rows);

    ?>
    <div class="survey-results" style="max-width:700px;margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:12px;font-family:system-ui,Arial,sans-serif">
        <h3 style="margin:0 0 .5rem 0">Ergebnisse: <?= htmlspecialchars($cfg['title']) ?></h3>
        <p style="margin:.25rem 0 1rem 0;color:#444">Gesamtstimmen: <strong><?= (int)$total ?></strong></p>

        <?php foreach ($cfg['options'] as $opt): 
            $cnt = $counts[$opt] ?? 0;
            $pc  = $percents[$opt] ?? 0;
            $bar = max(2, (int)$pc);
        ?>
            <div style="margin:.5rem 0">
                <div style="display:flex;justify-content:space-between;font-size:.95rem;color:#333">
                    <span><?= htmlspecialchars($opt) ?></span>
                    <span><?= $cnt ?> (<?= number_format($pc,1,',','.') ?>%)</span>
                </div>
                <div style="height:10px;background:#f0f0f0;border-radius:999px;overflow:hidden;margin-top:.25rem">
                    <div style="height:100%;width:<?= $bar ?>%;background:#1a73e8"></div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
        // Optional: letzte 5 Kommentare (falls aktiviert)
        if (!empty($cfg['enable_comment'])) {
            $comments = [];
            foreach (array_reverse($rows) as $r) {
                if (!empty($r['comment'])) {
                    $comments[] = [
                        'option'  => $r['option'] ?? '',
                        'comment' => $r['comment'],
                        'time'    => $r['time'] ?? ''
                    ];
                    if (count($comments) >= 5) break;
                }
            }
            if ($comments) {
                echo '<h4 style="margin-top:1rem">Neueste Kommentare</h4>';
                echo '<ul style="list-style:none;padding-left:0;margin:.5rem 0">';
                foreach ($comments as $c) {
                    echo '<li style="padding:.5rem .6rem;border:1px solid #eee;border-radius:10px;margin:.4rem 0;background:#fafafa">';
                    echo '<div style="font-size:.9rem;color:#666;margin-bottom:.25rem">'.htmlspecialchars($c['time']).' – Option: <em>'.htmlspecialchars($c['option']).'</em></div>';
                    echo '<div>'.nl2br(htmlspecialchars($c['comment'])).'</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
        }
        ?>

        <div style="font-size:.9rem;color:#666;margin-top:.5rem"><a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">Zurück zur Umfrage</a></div>
    </div>
    <?php
}

/* =========================
   POST-LOGIK (nur wenn direkt aufgerufen oder eingebunden)
   ========================= */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && ($_POST['survey_action'] ?? '') === 'vote') {
    try {
        if (!survey_csrf_check($CSRF_KEY, $_POST['csrf'] ?? '')) {
            throw new RuntimeException('Ungültiges CSRF-Token.');
        }

        $option = trim((string)($_POST['option'] ?? ''));
        $comment = trim((string)($_POST['comment'] ?? ''));
        if ($option === '') {
            throw new RuntimeException('Bitte eine Option auswählen.');
        }
        // Validierung Option
        if (!in_array($option, $SURVEY['options'], true)) {
            throw new RuntimeException('Ungültige Option.');
        }

        // Duplikat-Schutz
        if (!isset($_COOKIE[$COOKIE_KEY])) {
            // persistentes Cookie (180 Tage)
            setcookie($COOKIE_KEY, '1', [
                'expires'  => time() + 60*60*24*180,
                'path'     => '/',
                'samesite' => 'Lax',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => false
            ]);
        }

        survey_ensure_data_dir($DATA_DIR);

        $row = [
            'survey_id' => $SURVEY['id'],
            'time'      => date('Y-m-d H:i:s'),
            'option'    => $option,
            // Kommentar begrenzen (z. B. 2000 Zeichen)
            'comment'   => $comment !== '' ? mb_substr($comment, 0, 2000) : null,
            'ip_hash'   => survey_hash_ip($IP_SALT),
            'ua'        => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)
        ];
        survey_append_vote($DATA_FILE, $row);

        // Nach POST: Redirect (PRG-Pattern), um Doppelklick/Reload zu vermeiden
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } catch (Throwable $e) {
        // Minimaler Fehlerhinweis (kein Abbruch)
        $GLOBALS['survey_last_error'] = $e->getMessage();
    }
}

/* =========================
   DIREKTAUSGABE, wenn Datei direkt aufgerufen wird
   ========================= */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    ?><!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Umfrage</title>
    </head>
    <body style="margin:0;padding:1rem;background:#fff">
        <div style="max-width:900px;margin:0 auto">
            <?php
            if (!empty($GLOBALS['survey_last_error'])) {
                echo '<div style="padding:.75rem;background:#fff1f0;border:1px solid #ffa39e;border-radius:8px;margin-bottom:.75rem;color:#a8071a">';
                echo 'Fehler: ' . htmlspecialchars($GLOBALS['survey_last_error']);
                echo '</div>';
            }
            if (isset($_GET['results'])) {
                render_survey_results();
            } else {
                render_survey();
            }
            ?>
        </div>
    </body>
    </html><?php
    exit;
}
