<?php
// ================================
// Painel Asterisk / AstDB (Mototáxi)
// ================================
// Requer: sudo sem senha para asterisk -rx (recomendado via sudoers)
// Exemplo sudoers:
// www-data ALL=(root) NOPASSWD: /usr/sbin/asterisk -rx *

date_default_timezone_set('America/Sao_Paulo');

// ---- CONFIG ----
$ASTERISK_BIN = '/usr/sbin/asterisk';

// Se quiser limitar agentes (opcional), coloque aqui:
// $AGENTS_FIXED = ['34','35','36','40','41','42','43','50','71'];
$AGENTS_FIXED = [];

// ---- HELPERS ----
function asterisk_rx($cmd) {
  global $ASTERISK_BIN;
  $full = "sudo {$ASTERISK_BIN} -rx " . escapeshellarg($cmd) . " 2>/dev/null";
  return shell_exec($full) ?? '';
}

function db_get_value($family, $key) {
  $raw = asterisk_rx("database get {$family} {$key}");
  if (preg_match('/Value:\s*(.*)\s*$/mi', $raw, $m)) return trim($m[1]);
  return '';
}

// Lê "database show <prefix>" e devolve array [key => value]
// prefix_ex: "taxi/present_ts" ou "taxi/corrida/ativa"
function db_show_prefix($prefix_ex) {
  $raw = asterisk_rx("database show {$prefix_ex}");
  $lines = preg_split("/\r?\n/", trim($raw));
  $out = [];

  foreach ($lines as $ln) {
    // formato típico:
    // /taxi/present_ts/41 : 1707061234
    if (preg_match('#^/\s*' . preg_quote($prefix_ex, '#') . '/([^ ]+)\s*:\s*(.*)$#', $ln, $m)) {
      $key = trim($m[1]);
      $val = trim($m[2]);
      $out[$key] = $val;
    }
  }
  return $out;
}

// Transforma epoch em "dd/mm HH:MM"
function fmt_dt($epoch) {
  if (!$epoch || !is_numeric($epoch)) return '-';
  return date('d/m H:i', (int)$epoch);
}

function fmt_age($seconds) {
  $seconds = max(0, (int)$seconds);
  $h = intdiv($seconds, 3600);
  $m = intdiv($seconds % 3600, 60);
  $s = $seconds % 60;
  if ($h > 0) return sprintf("%dh %02dm", $h, $m);
  return sprintf("%dm %02ds", $m, $s);
}

// ---- CARREGA DADOS DO ASTDB ----
$now = time();

$names      = db_show_prefix('taxi/nome');           // nome por agente (key = ramal)
$present_ts = db_show_prefix('taxi/present_ts');     // epoch do login
$present_base = db_show_prefix('taxi/present_base'); // 40/50 ou ramal (rua)
$present_loc  = db_show_prefix('taxi/present_loc');  // rua/base (opcional)

$corr_ativa = db_show_prefix('taxi/corrida/ativa');  // agente -> cliente
$corr_ts    = db_show_prefix('taxi/corrida/ts');     // agente -> epoch
$corr_base  = db_show_prefix('taxi/corrida/base');   // agente -> base (40/50/rua)

$taxi_line = db_get_value('taxi', 'line');           // "41|42|43"
$line_list = [];
if ($taxi_line !== '') {
  $line_list = array_values(array_filter(array_map('trim', explode('|', $taxi_line))));
}
$linePos = [];
foreach ($line_list as $i => $ag) {
  $ag = preg_replace('/\D+/', '', $ag);
  if ($ag !== '') $linePos[$ag] = $i;
}

// ---- LISTA DE AGENTES (união de tudo) ----
$agents = [];

if (!empty($AGENTS_FIXED)) {
  foreach ($AGENTS_FIXED as $a) $agents[$a] = true;
} else {
  foreach ([$names, $present_ts, $present_base, $present_loc, $corr_ativa, $corr_ts, $corr_base] as $arr) {
    foreach (array_keys($arr) as $k) {
      $k = preg_replace('/\D+/', '', $k);
      if ($k !== '') $agents[$k] = true;
    }
  }
}

$agents = array_keys($agents);
sort($agents, SORT_NUMERIC);

// ---- ORDENAÇÃO (prioridade total) ----
usort($agents, function($a, $b) use (
  $present_ts, $present_loc, $present_base,
  $corr_ativa, $corr_ts,
  $linePos
) {
  // A
  $aLogged = isset($present_ts[$a]) && is_numeric($present_ts[$a]);
  $aInRide = isset($corr_ativa[$a]) && $corr_ativa[$a] !== '';
  $aLoc    = $present_loc[$a] ?? '';
  $aBase   = $present_base[$a] ?? '';
  $aTs     = (int)($present_ts[$a] ?? 0);
  $aRideTs = (int)($corr_ts[$a] ?? 0);

  // B
  $bLogged = isset($present_ts[$b]) && is_numeric($present_ts[$b]);
  $bInRide = isset($corr_ativa[$b]) && $corr_ativa[$b] !== '';
  $bLoc    = $present_loc[$b] ?? '';
  $bBase   = $present_base[$b] ?? '';
  $bTs     = (int)($present_ts[$b] ?? 0);
  $bRideTs = (int)($corr_ts[$b] ?? 0);

  // Define "na base" (40/50 e não rua)
  $aIsBaseLogged = $aLogged && !$aInRide && $aLoc !== 'rua' && preg_match('/^(40|50)$/', (string)$aBase);
  $bIsBaseLogged = $bLogged && !$bInRide && $bLoc !== 'rua' && preg_match('/^(40|50)$/', (string)$bBase);

  // Grupo principal:
  // 0: logado sem corrida
  // 1: logado em corrida
  // 2: offline
  $aGroup = $aLogged ? ($aInRide ? 1 : 0) : 2;
  $bGroup = $bLogged ? ($bInRide ? 1 : 0) : 2;

  if ($aGroup !== $bGroup) return $aGroup <=> $bGroup;

  // Grupo 0: logado sem corrida
  if ($aGroup === 0) {
    // 0A base, 0B resto
    $aSub = $aIsBaseLogged ? 0 : 1;
    $bSub = $bIsBaseLogged ? 0 : 1;
    if ($aSub !== $bSub) return $aSub <=> $bSub;

    if ($aSub === 0) {
      // base: posição na fila taxi/line
      $pa = $linePos[$a] ?? 99999;
      $pb = $linePos[$b] ?? 99999;
      if ($pa !== $pb) return $pa <=> $pb;

      // desempate: login mais antigo
      if ($aTs !== $bTs) return $aTs <=> $bTs;
      return (int)$a <=> (int)$b;
    } else {
      // rua/outros: login mais antigo primeiro
      if ($aTs !== $bTs) return $aTs <=> $bTs;
      return (int)$a <=> (int)$b;
    }
  }

  // Grupo 1: em corrida
  if ($aGroup === 1) {
    if ($aRideTs !== $bRideTs) return $aRideTs <=> $bRideTs;
    return (int)$a <=> (int)$b;
  }

  // Offline
  return (int)$a <=> (int)$b;
});

// ---- MONTA LINHAS ----
$rows = [];
foreach ($agents as $ag) {
  $nome = $names[$ag] ?? ("Agente {$ag}");

  $logged = isset($present_ts[$ag]) && is_numeric($present_ts[$ag]);
  $loginTs = $logged ? (int)$present_ts[$ag] : 0;

  $loc  = $present_loc[$ag] ?? ($logged ? 'base' : '-');
  $base = $present_base[$ag] ?? '-';

  $inRide = isset($corr_ativa[$ag]) && $corr_ativa[$ag] !== '';
  $cliente = $inRide ? $corr_ativa[$ag] : '';
  $rideTs  = $inRide ? (int)($corr_ts[$ag] ?? 0) : 0;
  $rideBase = $inRide ? ($corr_base[$ag] ?? '-') : '-';

  $pos = isset($linePos[$ag]) ? $linePos[$ag] : null;

  $rows[] = [
    'ag' => $ag,
    'nome' => $nome,
    'logged' => $logged,
    'loc' => $loc,
    'base' => $base,
    'loginTs' => $loginTs,
    'inRide' => $inRide,
    'cliente' => $cliente,
    'rideTs' => $rideTs,
    'rideBase' => $rideBase,
    'pos' => $pos,
  ];
}

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <meta http-equiv="refresh" content="5" />
  <title>Painel Mototáxi - Asterisk</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 16px; background:#0b0d12; color:#e9eef7; }
    h1 { margin: 0 0 6px 0; font-size: 20px; }
    .sub { opacity: .8; margin-bottom: 14px; }
    .cards { display:flex; gap:10px; flex-wrap:wrap; margin-bottom: 12px; }
    .card { background:#121624; border:1px solid #20263a; border-radius:10px; padding:10px 12px; min-width: 180px;}
    .card .k { opacity:.75; font-size:12px; }
    .card .v { font-weight:bold; font-size:16px; margin-top:3px; }

    table { width: 100%; border-collapse: collapse; background:#121624; border:1px solid #20263a; border-radius:12px; overflow:hidden; }
    th, td { padding: 10px 10px; border-bottom:1px solid #20263a; font-size: 13px; vertical-align: top; }
    th { text-align:left; background:#0f1320; position: sticky; top: 0; }
    tr:last-child td { border-bottom:none; }
    .pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid #2b3350; }
    .ok { background:#0f2a17; border-color:#1f6b38; }        /* verde */
    .warn { background:#2a1a0f; border-color:#8b4c1f; }      /* laranja (corrida) */
    .bad { background:#2a0f14; border-color:#7c2432; }       /* (não vamos usar mais pro offline) */
    .gray { background:#1a1f2b; border-color:#3a425a; color:#c6cfdd; } /* ENCERRADO */

    .muted { opacity:.8; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
  </style>
</head>
<body>
  <h1>Painel Mototáxi (Asterisk / AstDB)</h1>
  <div class="sub">Atualiza a cada 5s • <?= htmlspecialchars(date('d/m/Y H:i:s')) ?></div>

  <?php
    $countLogged = 0; $countRide = 0; $countOffline = 0;
    foreach ($rows as $r) {
      if ($r['logged']) $countLogged++; else $countOffline++;
      if ($r['inRide']) $countRide++;
    }
  ?>
  <div class="cards">
    <div class="card"><div class="k">Logados</div><div class="v"><?= $countLogged ?></div></div>
    <div class="card"><div class="k">Em corrida</div><div class="v"><?= $countRide ?></div></div>
    <div class="card"><div class="k">Offline</div><div class="v"><?= $countOffline ?></div></div>
    <div class="card"><div class="k">Fila taxi/line</div><div class="v mono"><?= htmlspecialchars($taxi_line ?: '-') ?></div></div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Agente</th>
        <th>Status</th>
        <th>Local / Base</th>
        <th>Login</th>
        <th>Corrida</th>
        <th>Cliente</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          // Status único (prioridade: corrida > online > encerrado)
          if ($r['inRide']) {
            $statusPill = '<span class="pill warn">EM CORRIDA</span>';
          } elseif ($r['logged']) {
            $statusPill = '<span class="pill ok">ONLINE</span>';
          } else {
            $statusPill = '<span class="pill gray">ENCERRADO</span>';
          }

          // Não vamos mais usar pill de corrida separado
          $ridePill = '';
          
          $loginStr = $r['logged'] ? fmt_dt($r['loginTs']).' <span class="muted">('.fmt_age($now - $r['loginTs']).')</span>' : '-';

          $locBase = '-';
          if ($r['logged']) {
            $locBase = '<span class="pill">'.htmlspecialchars($r['loc'] ?: 'base').'</span> ';
            $locBase .= '<span class="mono">'.htmlspecialchars($r['base']).'</span>';
          }

          $rideStr = '-';
          if ($r['inRide']) {
            $rideStr = fmt_dt($r['rideTs']).' <span class="muted">('.fmt_age($now - $r['rideTs']).')</span>';
            $rideStr .= '<div class="muted">via: <span class="mono">'.htmlspecialchars($r['rideBase']).'</span></div>';
          }

          $clienteStr = $r['inRide'] ? '<span class="mono">'.htmlspecialchars($r['cliente']).'</span>' : '-';

        ?>
        <tr>
          <td>
            <div><strong><?= htmlspecialchars($r['nome']) ?></strong></div>
            <div class="muted mono">Ramal: <?= htmlspecialchars($r['ag']) ?></div>
          </td>
          <td><?= $statusPill ?></td>          
          <td><?= $locBase ?></td>
          <td><?= $loginStr ?></td>
          <td><?= $rideStr ?></td>
          <td><?= $clienteStr ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

</body>
</html>
