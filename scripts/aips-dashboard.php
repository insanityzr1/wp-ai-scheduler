<?php
/**
 * AI Post Scheduler — Live Log Dashboard
 *
 * For local web use, drop a small bootstrap stub inside your WordPress
 * install (e.g. wp-content/aips-dashboard/aips-dashboard.php) that defines
 * AIPS_DASHBOARD_LOG_PATH and requires this file — see the "Usage" section
 * of the PR/README for a copy-pasteable example. That keeps this script
 * version-controlled here while staying reachable over HTTP. Every page
 * load re-reads debug.log fresh and re-renders the report — there is no
 * cache and no separate "refresh" endpoint. Clicking "Refresh Data" just
 * reloads this page.
 *
 * Log path resolution (first match wins):
 *   1. AIPS_DASHBOARD_LOG_PATH constant, if defined (set by the bootstrap
 *      stub — this is how the local XAMPP setup points at its debug.log).
 *   2. First CLI argument, when run via `php scripts/aips-dashboard.php
 *      /path/to/debug.log` (writes the rendered HTML to stdout).
 *   3. A short list of relative-path guesses from this file's own
 *      location, for convenience when copied somewhere ad hoc.
 *
 * Query params (web mode only):
 *   ?all=1   also parse & report on non-AIPS log lines (PHP Fatal/Warning/
 *            Notice, WordPress DB errors, other tagged plugin logs)
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$TAG = '[AI Post Scheduler]';
$isCli = PHP_SAPI === 'cli';

$logPath = false;
if (defined('AIPS_DASHBOARD_LOG_PATH')) {
    $logPath = realpath(AIPS_DASHBOARD_LOG_PATH);
} elseif ($isCli && isset($argv[1])) {
    $logPath = realpath($argv[1]);
} else {
    foreach (['/../debug.log', '/../wp-content/debug.log', '/../../wp-content/debug.log', '/../../../wp-content/debug.log'] as $candidate) {
        $try = realpath(__DIR__ . $candidate);
        if ($try !== false) { $logPath = $try; break; }
    }
}

$allMode = !$isCli && isset($_GET['all']) && $_GET['all'] === '1';
$topN = 25;
$recentN = 25;

if (!$logPath || !is_readable($logPath)) {
    $msg = "debug.log not found.\n\n"
        . ($isCli
            ? "Usage: php " . basename(__FILE__) . " /path/to/debug.log\n"
            : "Define AIPS_DASHBOARD_LOG_PATH before requiring this file, e.g.:\n"
              . "  define('AIPS_DASHBOARD_LOG_PATH', __DIR__ . '/../debug.log');\n"
              . "  require '/absolute/path/to/scripts/aips-dashboard.php';\n");
    if ($isCli) {
        fwrite(STDERR, $msg);
        exit(1);
    }
    http_response_code(500);
    echo '<pre>' . htmlspecialchars($msg) . '</pre>';
    exit;
}

// ---------------------------------------------------------------------------
// Parsing helpers
// ---------------------------------------------------------------------------

function normalize_sig(string $msg): string {
    $sig = preg_replace('/:?\s*\{.*$/s', '', $msg) ?? $msg;
    $sig = preg_replace("/'[^']*'/", 'X', $sig) ?? $sig;
    $sig = preg_replace('/\d{4}-\d{2}-\d{2} [\d:]+/', 'TIMESTAMP', $sig) ?? $sig;
    $sig = preg_replace('/\d+/', 'N', $sig) ?? $sig;
    $sig = trim($sig);
    if (mb_strlen($sig) > 110) {
        $sig = mb_substr($sig, 0, 110) . '...';
    }
    return $sig;
}

/**
 * @return array{0: array<int,array{ts:string,level:string,msg:string,raw:string}>,
 *               1: array<int,array{ts:string,level:string,source:string,msg:string,raw:string}>,
 *               2: int, 3: int}
 */
function parse_log(string $logPath, string $tag, bool $allMode): array {
    $aips = [];
    $other = [];
    $totalLines = 0;

    $aipsRe = '/^\[[^\]]*\]\s+\[AI Post Scheduler\]\s+\[([^\]]*)\]\s+\[([A-Z]+)\]\s+(.*)$/';

    $fh = fopen($logPath, 'r');
    if ($fh === false) return [$aips, $other, 0, 0];

    while (($line = fgets($fh)) !== false) {
        $totalLines++;
        $trim = rtrim($line, "\r\n");
        if ($trim === '') continue;

        $isAips = strpos($trim, $tag) !== false;

        if ($isAips) {
            if (preg_match($aipsRe, $trim, $m)) {
                $aips[] = ['ts' => $m[1], 'level' => $m[2], 'msg' => $m[3], 'raw' => $trim];
            }
            continue;
        }

        if (!$allMode) continue;

        if (!preg_match('/^\[([^\]]*)\]\s*(.*)$/', $trim, $m)) continue;
        $wpTs = $m[1];
        $rest = $m[2];
        $level = 'OTHER';
        $source = 'core/unknown';
        $msg = $rest;

        if (strpos($rest, 'PHP Fatal error') !== false) {
            $level = 'FATAL';
        } elseif (strpos($rest, 'PHP Warning') !== false) {
            $level = 'WARNING';
        } elseif (strpos($rest, 'PHP Notice') !== false || strpos($rest, 'PHP Deprecated') !== false) {
            $level = 'NOTICE';
        } elseif (strpos($rest, 'WordPress database error') !== false) {
            $level = 'DB_ERROR';
        } elseif (preg_match('/^\[([^\]]*)\]\s*(.*)$/', $rest, $m2)) {
            $source = $m2[1];
            $msg = $m2[2];
            $level = 'PLUGIN';
        }

        $other[] = ['ts' => $wpTs, 'level' => $level, 'source' => $source, 'msg' => $msg, 'raw' => $trim];
    }
    fclose($fh);

    $otherWpDbErrors = 0;
    foreach ($other as $e) {
        if ($e['level'] === 'DB_ERROR') $otherWpDbErrors++;
    }
    // when not in all-mode we still want the DB error count for the header stat
    if (!$allMode) {
        $otherWpDbErrors = substr_count(file_get_contents($logPath) ?: '', 'WordPress database error');
    }

    return [$aips, $other, $totalLines, $otherWpDbErrors];
}

/** @param array<int,array<string,mixed>> $entries */
function level_counts(array $entries): array {
    $c = [];
    foreach ($entries as $e) { $c[$e['level']] = ($c[$e['level']] ?? 0) + 1; }
    arsort($c);
    return $c;
}

/** @param array<int,array<string,mixed>> $entries */
function day_counts(array $entries): array {
    $c = [];
    foreach ($entries as $e) { $d = substr($e['ts'], 0, 10); $c[$d] = ($c[$d] ?? 0) + 1; }
    ksort($c);
    return $c;
}

/** @param array<int,array<string,mixed>> $entries */
function hour_counts(array $entries): array {
    $c = [];
    foreach ($entries as $e) {
        if (strlen($e['ts']) >= 13) { $h = substr($e['ts'], 11, 2); $c[$h] = ($c[$h] ?? 0) + 1; }
    }
    ksort($c);
    return $c;
}

/** @param array<int,array<string,mixed>> $entries */
function group_by_sig(array $entries, ?string $levelFilter = null): array {
    $groups = [];
    foreach ($entries as $e) {
        if ($levelFilter !== null && $e['level'] !== $levelFilter) continue;
        $sig = normalize_sig($e['msg']);
        if (!isset($groups[$sig])) $groups[$sig] = ['count' => 0, 'first' => $e['ts'], 'last' => $e['ts'], 'raw' => $e['raw']];
        $groups[$sig]['count']++;
        if ($e['ts'] < $groups[$sig]['first']) $groups[$sig]['first'] = $e['ts'];
        if ($e['ts'] > $groups[$sig]['last']) $groups[$sig]['last'] = $e['ts'];
    }
    uasort($groups, fn($a, $b) => $b['count'] <=> $a['count']);
    return $groups;
}

/** @param array<int,array<string,mixed>> $entries */
function sig_type_counts(array $entries, bool $withSource = false): array {
    $groups = [];
    foreach ($entries as $e) {
        $sig = normalize_sig($e['msg']);
        $source = $withSource ? ($e['source'] ?? '') : '';
        $key = $e['level'] . "\t" . $source . "\t" . $sig;
        if (!isset($groups[$key])) {
            $groups[$key] = ['level' => $e['level'], 'source' => $source, 'sig' => $sig, 'count' => 0, 'raw' => $e['raw']];
        }
        $groups[$key]['count']++;
    }
    usort($groups, fn($a, $b) => $b['count'] <=> $a['count']);
    return $groups;
}

function count_msg(array $entries, string $needle): int {
    $n = 0;
    foreach ($entries as $e) { if (strpos($e['msg'], $needle) !== false) $n++; }
    return $n;
}

// ---------------------------------------------------------------------------
// Parse + compute
// ---------------------------------------------------------------------------

[$aipsEntries, $otherEntries, $totalLines, $otherWpDbErrors] = parse_log($logPath, $TAG, $allMode);
$pluginCount = count($aipsEntries);

$levelCounts = level_counts($aipsEntries);
$dayCounts = day_counts($aipsEntries);
$hourCounts = hour_counts($aipsEntries);
$msgTypes = sig_type_counts($aipsEntries);
$errorGroups = group_by_sig($aipsEntries, 'ERROR');
$warnGroups = group_by_sig($aipsEntries, 'WARNING');

$reqs = count_msg($aipsEntries, 'New AI Text Generation Request');
$txtOk = count_msg($aipsEntries, 'AI text generation successful');
$txtFail = count_msg($aipsEntries, 'AI text generation failed');
$jsonOk = count_msg($aipsEntries, 'AI json generation successful');
$jsonFail = count_msg($aipsEntries, 'AI json generation failed');
$postOk = count_msg($aipsEntries, 'Post generated successfully');
$postWarn = count_msg($aipsEntries, 'Post generated with missing components');

$firstTs = $aipsEntries ? $aipsEntries[0]['ts'] : 'n/a';
$lastTs = $aipsEntries ? $aipsEntries[count($aipsEntries) - 1]['ts'] : 'n/a';
$errRate = $pluginCount ? round((($levelCounts['ERROR'] ?? 0) / $pluginCount) * 100, 1) : 0;
$warnRate = $pluginCount ? round((($levelCounts['WARNING'] ?? 0) / $pluginCount) * 100, 1) : 0;

$otherLevelCounts = $allMode ? level_counts($otherEntries) : [];
$otherTop = $allMode ? array_slice(sig_type_counts($otherEntries, true), 0, $topN) : [];
$otherRecent = $allMode ? array_slice($otherEntries, -15) : [];

$vscodeHref = 'vscode://file/' . str_replace('\\', '/', $logPath);

// ---------------------------------------------------------------------------
// Render helpers
// ---------------------------------------------------------------------------

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function copy_btn(string $text): string {
    return '<button type="button" class="copy-btn" data-copy="' . e($text) . '" onclick="aipsCopy(this)" title="Copy">⧉</button>';
}

function bar_row(string $label, int $count, int $max, string $color = '#5b8def', int $indent = 0, string $copyText = ''): string {
    $pct = $max > 0 ? round(($count / $max) * 100, 1) : 0;
    $style = $indent > 0 ? ' style="padding-left:' . ($indent * 16) . 'px"' : '';
    $ct = $copyText !== '' ? $copyText : ($label . ': ' . $count);
    return '<div class="bar-row">'
        . '<div class="bar-label"' . $style . '>' . e($label) . '</div>'
        . '<div class="bar-track"><div class="bar-fill" style="width:' . $pct . '%;background:' . $color . '"></div></div>'
        . '<div class="bar-count">' . $count . '</div>'
        . '<div class="bar-copy">' . copy_btn($ct) . '</div>'
        . '</div>';
}

$maxLevel = $levelCounts ? max($levelCounts) : 1;
$levelOrder = ['INFO', 'DEBUG', 'WARNING', 'ERROR'];
$levelColors = ['INFO' => '#5b8def', 'DEBUG' => '#8a8fa3', 'WARNING' => '#e0a83e', 'ERROR' => '#d9534f'];
$levelBars = '';
foreach ($levelOrder as $lvl) {
    if (!isset($levelCounts[$lvl])) continue;
    $pct = $pluginCount ? round(($levelCounts[$lvl] / $pluginCount) * 100, 1) : 0;
    $levelBars .= bar_row($lvl, $levelCounts[$lvl], $maxLevel, $levelColors[$lvl], 0, "$lvl: {$levelCounts[$lvl]} ($pct%)");
}

$maxDay = $dayCounts ? max($dayCounts) : 1;
$dayBars = '';
foreach ($dayCounts as $d => $c) { $dayBars .= bar_row($d, $c, $maxDay, '#5b8def', 0, "$d: $c entries"); }

$maxHour = $hourCounts ? max($hourCounts) : 1;
$hourBars = '';
foreach ($hourCounts as $h => $c) { $hourBars .= bar_row("$h:00", $c, $maxHour, '#7c5cff', 0, "$h:00 — $c entries"); }

$funnelMax = max($reqs, 1);
$funnelBars = ''
    . bar_row('New AI text generation requests', $reqs, $funnelMax, '#5b8def')
    . bar_row('→ text generation succeeded', $txtOk, $funnelMax, '#4caf50', 1)
    . bar_row('→ text generation failed', $txtFail, $funnelMax, '#d9534f', 1)
    . bar_row('AI JSON generation succeeded', $jsonOk, $funnelMax, '#4caf50')
    . bar_row('AI JSON generation failed', $jsonFail, $funnelMax, '#d9534f')
    . bar_row('Post generated successfully', $postOk, $funnelMax, '#4caf50')
    . bar_row('Post generated with missing components', $postWarn, $funnelMax, '#e0a83e');

$msgRows = '';
foreach (array_slice($msgTypes, 0, $topN) as $row) {
    $lvl = strtolower($row['level']);
    $msgRows .= '<tr><td><span class="pill pill-' . e($lvl) . '">' . e($row['level']) . '</span></td>'
        . '<td>' . $row['count'] . '</td>'
        . '<td class="mono">' . e($row['sig']) . '</td>'
        . '<td class="copy-cell">' . copy_btn($row['raw']) . '</td></tr>';
}

function group_rows(array $groups, int $topN): string {
    $rows = '';
    $i = 0;
    foreach ($groups as $sig => $g) {
        if ($i++ >= $topN) break;
        $rows .= '<tr><td>' . $g['count'] . '</td>'
            . '<td class="mono small">' . e($g['first']) . '</td>'
            . '<td class="mono small">' . e($g['last']) . '</td>'
            . '<td class="mono">' . e(mb_substr($sig, 0, 160)) . '</td>'
            . '<td class="copy-cell">' . copy_btn($g['raw']) . '</td></tr>';
    }
    return $rows;
}

$errorRows = group_rows($errorGroups, $topN);
$warnRows = group_rows($warnGroups, $topN);

$recent = array_slice($aipsEntries, -$recentN);
$recent = array_reverse($recent);
$recentRows = '';
foreach ($recent as $r) {
    $lvl = strtolower($r['level']);
    $recentRows .= '<tr><td class="mono small">' . e($r['ts']) . '</td>'
        . '<td><span class="pill pill-' . e($lvl) . '">' . e($r['level']) . '</span></td>'
        . '<td class="mono">' . e(mb_substr($r['msg'], 0, 160)) . '</td>'
        . '<td class="copy-cell">' . copy_btn($r['raw']) . '</td></tr>';
}

$otherLevelBars = '';
if ($allMode && $otherLevelCounts) {
    $maxOther = max($otherLevelCounts);
    $otherColors = ['FATAL' => '#d9534f', 'WARNING' => '#e0a83e', 'NOTICE' => '#8a8fa3', 'DB_ERROR' => '#c9556f', 'PLUGIN' => '#5b8def', 'OTHER' => '#6b7290'];
    foreach ($otherLevelCounts as $lvl => $c) {
        $otherLevelBars .= bar_row($lvl, $c, $maxOther, $otherColors[$lvl] ?? '#6b7290', 0, "$lvl: $c");
    }
}

$otherTopRows = '';
foreach ($otherTop as $row) {
    $lvl = strtolower($row['level']);
    $otherTopRows .= '<tr><td><span class="pill pill-' . e($lvl) . '">' . e($row['level']) . '</span></td>'
        . '<td class="mono">' . e($row['source']) . '</td>'
        . '<td>' . $row['count'] . '</td>'
        . '<td class="mono">' . e($row['sig']) . '</td>'
        . '<td class="copy-cell">' . copy_btn($row['raw']) . '</td></tr>';
}

$otherRecentRows = '';
foreach (array_reverse($otherRecent) as $r) {
    $lvl = strtolower($r['level']);
    $otherRecentRows .= '<tr><td class="mono small">' . e($r['ts']) . '</td>'
        . '<td><span class="pill pill-' . e($lvl) . '">' . e($r['level']) . '</span></td>'
        . '<td class="mono small">' . e($r['source']) . '</td>'
        . '<td class="mono">' . e(mb_substr($r['msg'], 0, 140)) . '</td>'
        . '<td class="copy-cell">' . copy_btn($r['raw']) . '</td></tr>';
}

$genAt = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>AI Post Scheduler — Log Dashboard</title>
<style>
  :root {
    --bg: #0f1220; --panel: #171b2e; --panel2: #1e2338; --text: #e7e9f5;
    --muted: #9aa0b8; --border: #2a2f4a; --accent: #5b8def;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 32px; background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .subtitle { color: var(--muted); font-size: 13px; margin-bottom: 14px; }
  .subtitle a { color: #8fb2f5; text-decoration: none; }
  .subtitle a:hover { text-decoration: underline; }
  .controls { display: flex; align-items: center; gap: 18px; margin-bottom: 24px; flex-wrap: wrap; }
  .controls button#refreshBtn {
    background: var(--accent); color: #fff; border: none; border-radius: 8px;
    padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .controls button#refreshBtn:hover { background: #4a78d8; }
  .controls label.toggle { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 6px; cursor: pointer; }
  .stamp { font-size: 12px; color: var(--muted); }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 12px; margin-bottom: 28px; }
  .kpi { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 16px; position: relative; }
  .kpi .val { font-size: 26px; font-weight: 700; }
  .kpi .lbl { font-size: 12px; color: var(--muted); margin-top: 4px; }
  .kpi.warn .val { color: #e0a83e; }
  .kpi.err .val { color: #d9534f; }
  .kpi .copy-btn { position: absolute; top: 10px; right: 10px; }
  section { background: var(--panel); border: 1px solid var(--border); border-radius: 10px;
             padding: 18px 20px; margin-bottom: 20px; }
  section h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted);
                margin: 0 0 14px; }
  .bar-row { display: grid; grid-template-columns: 240px 1fr 50px 28px; align-items: center; gap: 10px; margin: 6px 0; font-size: 13px; }
  .bar-track { background: var(--panel2); border-radius: 4px; height: 10px; overflow: hidden; }
  .bar-fill { height: 100%; border-radius: 4px; }
  .bar-count { text-align: right; color: var(--muted); }
  .bar-copy { display: flex; justify-content: flex-end; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
  th { color: var(--muted); font-weight: 600; font-size: 11px; text-transform: uppercase; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  .small { font-size: 12px; color: var(--muted); white-space: nowrap; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
  .pill-info { background: rgba(91,141,239,.18); color: #8fb2f5; }
  .pill-debug { background: rgba(138,143,163,.18); color: #b5bacb; }
  .pill-warning { background: rgba(224,168,62,.18); color: #f0c163; }
  .pill-error { background: rgba(217,83,79,.18); color: #ef8b88; }
  .pill-fatal { background: rgba(217,83,79,.28); color: #ff9b98; }
  .pill-notice { background: rgba(138,143,163,.18); color: #b5bacb; }
  .pill-db_error { background: rgba(201,85,111,.2); color: #f0a0b4; }
  .pill-plugin { background: rgba(91,141,239,.18); color: #8fb2f5; }
  .pill-other { background: rgba(107,114,144,.2); color: #b8bccb; }
  .copy-cell { width: 32px; text-align: right; }
  .copy-btn {
    background: var(--panel2); border: 1px solid var(--border); color: var(--muted);
    border-radius: 6px; width: 26px; height: 26px; cursor: pointer; font-size: 13px; line-height: 1;
  }
  .copy-btn:hover { color: var(--text); border-color: var(--accent); }
  .copy-btn.copied { color: #4caf50; border-color: #4caf50; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  @media (max-width: 900px) { .grid2 { grid-template-columns: 1fr; } .bar-row { grid-template-columns: 140px 1fr 40px 24px; } }
  footer { color: var(--muted); font-size: 12px; margin-top: 24px; }
</style>
</head>
<body>
  <h1>AI Post Scheduler — Log Dashboard</h1>
  <div class="subtitle">
    Source: <a class="mono" href="<?php echo e($vscodeHref); ?>" title="Open in VS Code"><?php echo e($logPath); ?></a>
    &nbsp;•&nbsp; Log span: <?php echo e($firstTs); ?> → <?php echo e($lastTs); ?>
  </div>

  <div class="controls">
    <button id="refreshBtn" onclick="location.reload()">⟳ Refresh Data</button>
    <label class="toggle">
      <input type="checkbox" id="allToggle" <?php echo $allMode ? 'checked' : ''; ?> onchange="toggleAll(this)">
      Parse all log entries (not just AI Post Scheduler)
    </label>
    <span class="stamp">Generated <?php echo e($genAt); ?> — regenerated fresh on every load/refresh</span>
  </div>

  <div class="kpis">
    <div class="kpi"><?php echo copy_btn("Plugin log entries: $pluginCount"); ?><div class="val"><?php echo $pluginCount; ?></div><div class="lbl">Plugin log entries</div></div>
    <div class="kpi"><?php echo copy_btn("Total lines in file: $totalLines"); ?><div class="val"><?php echo $totalLines; ?></div><div class="lbl">Total lines in file</div></div>
    <div class="kpi err"><?php echo copy_btn("Errors: " . ($levelCounts['ERROR'] ?? 0) . " ($errRate%)"); ?><div class="val"><?php echo $levelCounts['ERROR'] ?? 0; ?></div><div class="lbl">Errors (<?php echo $errRate; ?>%)</div></div>
    <div class="kpi warn"><?php echo copy_btn("Warnings: " . ($levelCounts['WARNING'] ?? 0) . " ($warnRate%)"); ?><div class="val"><?php echo $levelCounts['WARNING'] ?? 0; ?></div><div class="lbl">Warnings (<?php echo $warnRate; ?>%)</div></div>
    <div class="kpi"><?php echo copy_btn("AI generation requests: $reqs"); ?><div class="val"><?php echo $reqs; ?></div><div class="lbl">AI generation requests</div></div>
    <div class="kpi"><?php echo copy_btn("Non-plugin WP DB errors: $otherWpDbErrors"); ?><div class="val"><?php echo $otherWpDbErrors; ?></div><div class="lbl">Non-plugin WP DB errors</div></div>
  </div>

  <div class="grid2">
    <section>
      <h2>Log level breakdown</h2>
      <?php echo $levelBars; ?>
    </section>
    <section>
      <h2>AI generation funnel</h2>
      <?php echo $funnelBars; ?>
    </section>
  </div>

  <div class="grid2">
    <section>
      <h2>Activity by day</h2>
      <?php echo $dayBars; ?>
    </section>
    <section>
      <h2>Activity by hour of day</h2>
      <?php echo $hourBars; ?>
    </section>
  </div>

  <section>
    <h2>Message type inventory (top <?php echo $topN; ?>)</h2>
    <table>
      <thead><tr><th>Level</th><th>Count</th><th>Template</th><th></th></tr></thead>
      <tbody><?php echo $msgRows; ?></tbody>
    </table>
  </section>

  <section>
    <h2>Repeat errors (grouped, most frequent first)</h2>
    <table>
      <thead><tr><th>Count</th><th>First seen</th><th>Last seen</th><th>Message</th><th></th></tr></thead>
      <tbody><?php echo $errorRows !== '' ? $errorRows : '<tr><td colspan="5">No errors logged.</td></tr>'; ?></tbody>
    </table>
  </section>

  <section>
    <h2>Repeat warnings (grouped, most frequent first)</h2>
    <table>
      <thead><tr><th>Count</th><th>First seen</th><th>Last seen</th><th>Message</th><th></th></tr></thead>
      <tbody><?php echo $warnRows !== '' ? $warnRows : '<tr><td colspan="5">No warnings logged.</td></tr>'; ?></tbody>
    </table>
  </section>

  <section>
    <h2>Most recent <?php echo $recentN; ?> entries</h2>
    <table>
      <thead><tr><th>Timestamp</th><th>Level</th><th>Message</th><th></th></tr></thead>
      <tbody><?php echo $recentRows; ?></tbody>
    </table>
  </section>

  <?php if ($allMode): ?>
  <section>
    <h2>All other log activity (non-AIPS lines)</h2>
    <p class="small" style="margin-top:-6px;margin-bottom:14px;">
      <?php echo count($otherEntries); ?> non-AIPS lines parsed and categorized
      (PHP Fatal errors, PHP Warnings/Notices, WordPress DB errors, and other tagged plugin log lines).
    </p>
    <?php echo $otherLevelBars; ?>
  </section>

  <section>
    <h2>Repeat non-AIPS entries (top <?php echo $topN; ?> by frequency)</h2>
    <table>
      <thead><tr><th>Level</th><th>Source</th><th>Count</th><th>Template</th><th></th></tr></thead>
      <tbody><?php echo $otherTopRows !== '' ? $otherTopRows : '<tr><td colspan="5">None found.</td></tr>'; ?></tbody>
    </table>
  </section>

  <section>
    <h2>Most recent 15 non-AIPS entries</h2>
    <table>
      <thead><tr><th>Timestamp</th><th>Level</th><th>Source</th><th>Message</th><th></th></tr></thead>
      <tbody><?php echo $otherRecentRows !== '' ? $otherRecentRows : '<tr><td colspan="5">None found.</td></tr>'; ?></tbody>
    </table>
  </section>
  <?php endif; ?>

  <footer>
    This page re-parses <span class="mono"><?php echo e($logPath); ?></span> on every load — "Refresh Data" simply reloads it.
    Click the file path above to open the log in VS Code (requires VS Code installed with the vscode:// URI handler registered).
  </footer>

<script>
function aipsCopy(btn) {
  const text = btn.getAttribute('data-copy');
  const done = () => {
    const orig = btn.textContent;
    btn.textContent = '✓';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 1100);
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    // Race against a short timeout: some browser/permission configurations
    // leave the Clipboard API promise pending indefinitely (e.g. a stalled
    // permission prompt) instead of rejecting, which would otherwise hang
    // the button forever. Fall back to the execCommand path if it's slow.
    const timeout = new Promise((_, reject) => setTimeout(() => reject(new Error('clipboard-timeout')), 800));
    Promise.race([navigator.clipboard.writeText(text), timeout]).then(done).catch(() => fallbackCopy(text, done));
  } else {
    fallbackCopy(text, done);
  }
}
function fallbackCopy(text, cb) {
  const ta = document.createElement('textarea');
  ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
  document.body.appendChild(ta); ta.select();
  try { document.execCommand('copy'); } catch (e) {}
  document.body.removeChild(ta);
  cb();
}
function toggleAll(cb) {
  const url = new URL(window.location.href);
  if (cb.checked) url.searchParams.set('all', '1'); else url.searchParams.delete('all');
  window.location.href = url.toString();
}
</script>
</body>
</html>
