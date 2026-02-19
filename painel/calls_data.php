<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

$ASTERISK_BIN = '/usr/sbin/asterisk';
$USE_SUDO = true;
$MAX_ROWS = 5000;

function run_cmd(string $cmd, int $maxLines = 5000): array {
    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) return ["__ERROR__" => "Falha ao executar comando (rc=$rc): $cmd"];
    if (count($out) > $maxLines) $out = array_slice($out, 0, $maxLines);
    return $out;
}

function parse_astdb_tree(array $lines): array {
    $calls = [];
    foreach ($lines as $line) {
        if (!is_string($line)) continue;
        if (stripos($line, "results found") !== false) continue;

        if (preg_match('#^/taxi/calls/([^/]+)/([^ ]+)\s*:\s*(.*)$#', $line, $m)) {
            $callid = trim($m[1]);
            $field  = trim($m[2]);
            $value  = trim($m[3]);
            if ($callid !== '') $calls[$callid][$field] = $value;
        }
    }
    return $calls;
}

function to_int($v, int $default = 0): int {
    $v = trim((string)$v);
    if ($v === '' || !preg_match('/^-?\d+$/', $v)) return $default;
    return (int)$v;
}

function derive_status(array $c): string {
    $st = strtoupper(trim((string)($c['status'] ?? '')));
    if ($st !== '') return $st;
    $answered = strtoupper(trim((string)($c['answered'] ?? '')));
    if ($answered === '1' || $answered === 'YES' || $answered === 'TRUE') return 'ATENDIDA';
    $end = to_int($c['ts_end'] ?? $c['end'] ?? '', 0);
    $start = to_int($c['ts_start'] ?? $c['start'] ?? '', 0);
    if ($start > 0 && $end > 0) return 'ENCERRADA';
    return 'RECEBIDA';
}

$cmd = ($USE_SUDO ? "sudo " : "") . $ASTERISK_BIN . " -rx " . escapeshellarg("database show taxi/calls");
$lines = run_cmd($cmd, $MAX_ROWS);

if (isset($lines["__ERROR__"])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["ok"=>false, "error"=>$lines["__ERROR__"]]);
    exit;
}

$calls = parse_astdb_tree($lines);

// normaliza
$list = [];
foreach ($calls as $callid => $c) {
    $row = $c;
    $row['callid'] = $callid;

    $row['cliente'] = $row['cliente'] ?? ($row['cid'] ?? ($row['caller'] ?? ''));
    $row['agente']  = $row['agente']  ?? ($row['agent'] ?? '');
    $row['agente_nome'] = $row['agente_nome'] ?? '';
    $row['start']   = $row['ts_start'] ?? ($row['start'] ?? ($row['ts_start'] ?? ''));
    $row['end']     = $row['ts_end'] ?? ($row['end'] ?? '');
    $row['dur']     = $row['dur'] ?? '0';
    $row['status']  = derive_status($row);
    $row['rec_file']= $row['rec_file'] ?? '';

    $row['_sort'] = to_int($row['start'], 0);
    $list[] = $row;
}

usort($list, fn($a,$b) => ($b['_sort'] ?? 0) <=> ($a['_sort'] ?? 0));

// contadores
$total = count($list);
$atendidas = 0; $abandonadas = 0; $recebidas = 0;
foreach ($list as $r) {
    $st = strtoupper((string)$r['status']);
    if (in_array($st, ['ATENDIDA','ANSWER','ANSWERED','ENCERRADA'], true)) $atendidas++;
    elseif (in_array($st, ['ABANDONADA','ABANDON','NOANSWER','TIMEOUT','NAO_ATENDIDA'], true)) $abandonadas++;
    else $recebidas++;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    "ok" => true,
    "now" => date('Y-m-d H:i:s'),
    "counts" => ["total"=>$total, "atendidas"=>$atendidas, "abandonadas"=>$abandonadas, "recebidas"=>$recebidas],
    "rows" => $list
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
