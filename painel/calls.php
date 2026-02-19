<?php
declare(strict_types=1);

/**
 * Calls.php - Painel de chamadas (AstDB taxi/calls)
 * - Lê: asterisk -rx "database show taxi/calls"
 * - Agrupa por CALLID e exibe contadores e lista.
 */

// ======= CONFIG =======
$ASTERISK_BIN = '/usr/sbin/asterisk'; // ajuste se necessário
$REFRESH_SECONDS = 5;

// Se você usa sudoers pro www-data:
//   www-data ALL=(root) NOPASSWD: /usr/sbin/asterisk
// então deixe $USE_SUDO=true
$USE_SUDO = true;

// Limite de linhas do "database show" pra não explodir página em produção
$MAX_ROWS = 5000;

// ======= HELPERS =======
function run_cmd(string $cmd, int $maxLines = 5000): array {
    $out = [];
    $rc = 0;
    @exec($cmd, $out, $rc);
    if ($rc !== 0) {
        return ["__ERROR__" => "Falha ao executar comando (rc=$rc): $cmd"];
    }
    if (count($out) > $maxLines) {
        $out = array_slice($out, 0, $maxLines);
    }
    return $out;
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parse_astdb_tree(array $lines): array {
    // Formato típico do Asterisk:
    // /taxi/calls/<CALLID>/<field> : value
    $calls = [];
    foreach ($lines as $line) {
        if (!is_string($line)) continue;

        // ignora ruído
        if (stripos($line, "results found") !== false) continue;

        if (preg_match('#^/taxi/calls/([^/]+)/([^ ]+)\s*:\s*(.*)$#', $line, $m)) {
            $callid = trim($m[1]);
            $field  = trim($m[2]);
            $value  = trim($m[3]);

            if ($callid !== '') {
                $calls[$callid][$field] = $value;
            }
        }
    }
    return $calls;
}

function to_int(?string $v, int $default = 0): int {
    if ($v === null) return $default;
    $v = trim($v);
    if ($v === '') return $default;
    if (!preg_match('/^-?\d+$/', $v)) return $default;
    return (int)$v;
}

function fmt_epoch(?string $epoch): string {
    $ts = to_int($epoch, 0);
    if ($ts <= 0) return '-';
    // Usa timezone do servidor; se quiser fixar: date_default_timezone_set('America/Sao_Paulo');
    return date('Y-m-d H:i:s', $ts);
}

function derive_status(array $c): string {
    // Prioridade: status explícito > inferido
    $st = strtoupper(trim((string)($c['status'] ?? '')));
    if ($st !== '') return $st;

    // inferências comuns
    $answered = strtoupper(trim((string)($c['answered'] ?? '')));
    if ($answered === '1' || $answered === 'YES' || $answered === 'TRUE') return 'ATENDIDA';

    $end = to_int($c['end'] ?? '', 0);
    $start = to_int($c['start'] ?? '', 0);
    if ($start > 0 && $end > 0) return 'ENCERRADA';

    return 'RECEBIDA';
}

// ======= MAIN =======
date_default_timezone_set('America/Sao_Paulo');

$cmd = ($USE_SUDO ? "sudo " : "") . $ASTERISK_BIN . " -rx " . escapeshellarg("database show taxi/calls");
$lines = run_cmd($cmd, $MAX_ROWS);

$error = null;
if (isset($lines["__ERROR__"])) {
    $error = $lines["__ERROR__"];
    $calls = [];
} else {
    $calls = parse_astdb_tree($lines);
}

// Normaliza/deriva campos e cria lista
$list = [];
foreach ($calls as $callid => $c) {
    $row = $c;
    $row['callid'] = $callid;

    // Campos esperados (mas opcionais)
    $row['cliente'] = $row['cliente'] ?? ($row['caller'] ?? ($row['cid'] ?? ''));
    $row['agente']  = $row['agente']  ?? ($row['agent'] ?? '');
    $row['base']    = $row['base']    ?? ($row['origem'] ?? '');
    $row['start']   = $row['start']   ?? ($row['ts_start'] ?? ($row['inicio'] ?? ''));
    $row['end']     = $row['end']     ?? ($row['ts_end'] ?? ($row['fim'] ?? ''));

    $row['status']  = derive_status($row);

    // duração (se não tiver, calcula quando possível)
    $dur = to_int($row['dur'] ?? '', -1);
    if ($dur < 0) {
        $s = to_int($row['start'] ?? '', 0);
        $e = to_int($row['end'] ?? '', 0);
        $dur = ($s > 0 && $e > 0 && $e >= $s) ? ($e - $s) : 0;
    }
    $row['dur_calc'] = $dur;

    // timestamp pra ordenar
    $row['_sort'] = to_int($row['start'] ?? '', 0);

    $list[] = $row;
}

// Ordena por mais recente primeiro
usort($list, function($a, $b){
    return ($b['_sort'] ?? 0) <=> ($a['_sort'] ?? 0);
});

// Contadores
$total = count($list);
$atendidas = 0;
$abandonadas = 0;
$recebidas = 0;

foreach ($list as $r) {
    $st = strtoupper((string)$r['status']);
    if (in_array($st, ['ATENDIDA','ANSWER','ANSWERED'], true)) {
        $atendidas++;
    } elseif (in_array($st, ['ABANDONADA','ABANDON','NOANSWER','TIMEOUT'], true)) {
        $abandonadas++;
    } else {
        $recebidas++;
    }
}

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Painel - Chamadas</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;background:#0b0f17;color:#e8eefc;margin:0}
    header{padding:18px 22px;background:#0f172a;border-bottom:1px solid rgba(255,255,255,.08)}
    h1{margin:0;font-size:18px}
    .sub{opacity:.75;margin-top:6px;font-size:13px}
    .wrap{padding:18px 22px}
    .cards{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:12px;margin-bottom:16px}
    .card{background:#111b33;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px}
    .card .k{opacity:.7;font-size:12px}
    .card .v{font-size:22px;font-weight:700;margin-top:6px}
    table{width:100%;border-collapse:separate;border-spacing:0 8px}
    th{font-size:12px;opacity:.75;text-align:left;padding:0 10px}
    td{background:#0f172a;border:1px solid rgba(255,255,255,.08);padding:10px;border-left:none;border-right:none}
    tr td:first-child{border-left:1px solid rgba(255,255,255,.08);border-radius:10px 0 0 10px}
    tr td:last-child{border-right:1px solid rgba(255,255,255,.08);border-radius:0 10px 10px 0}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}
    .st-atendida{background:rgba(16,185,129,.18);color:#34d399;border:1px solid rgba(52,211,153,.35)}
    .st-abandonada{background:rgba(239,68,68,.18);color:#f87171;border:1px solid rgba(248,113,113,.35)}
    .st-recebida{background:rgba(59,130,246,.18);color:#93c5fd;border:1px solid rgba(147,197,253,.35)}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
    .err{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.35);padding:12px;border-radius:10px}
    a{color:#93c5fd}
    .topbar{display:flex;gap:10px;align-items:center;justify-content:space-between}
    .btn{display:inline-block;text-decoration:none;background:#111b33;border:1px solid rgba(255,255,255,.12);padding:8px 12px;border-radius:10px;color:#e8eefc}
    .btn:hover{border-color:rgba(147,197,253,.5)}
  </style>
</head>
<body>
<header>
  <div class="topbar">
    <div>
      <h1>Painel de Chamadas</h1>
	<div class="sub">Atualiza a cada <?= (int)$REFRESH_SECONDS ?>s • <span id="now"><?= h(date('Y-m-d H:i:s')) ?></span></div>
    </div>
    <div>
      <a class="btn" href="./index.php">← Voltar ao Painel</a>
    </div>
  </div>
</header>

<div class="wrap">
  <?php if ($error): ?>
    <div class="err">
      <b>Erro executando Asterisk:</b><br>
      <span class="mono"><?= h($error) ?></span><br><br>
      Dica: se o Apache não consegue rodar <span class="mono">asterisk -rx</span>, configure sudoers pro <span class="mono">www-data</span>.
    </div>
  <?php endif; ?>

<div class="cards">
    <div class="card">
        <div class="k">Recebidas (total)</div>
        <div class="v" id="cnt-total"><?= (int)$total ?></div>
    </div>

    <div class="card">
        <div class="k">Atendidas</div>
        <div class="v" id="cnt-atendidas"><?= (int)$atendidas ?></div>
    </div>

    <div class="card">
        <div class="k">Abandonadas/NoAnswer</div>
        <div class="v" id="cnt-abandonadas"><?= (int)$abandonadas ?></div>
    </div>
</div>


  <table>
    <thead>
      <tr>
        <th>Horário início</th>
        <th>Cliente</th>
        <th>Agente</th>
        <th>Base</th>
        <th>Status</th>
        <th>Duração</th>
        <th>CALLID</th>
      </tr>
    </thead>
    <tbody id="calls-body">
      <?php if (!$list): ?>
        <tr>
          <td colspan="7" style="background:#0f172a;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px;opacity:.8">
            Nenhuma chamada encontrada em <span class="mono">taxi/calls</span>.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($list as $r): ?>
          <?php
            $st = strtoupper((string)$r['status']);
            $class = 'st-recebida';
            if (in_array($st, ['ATENDIDA','ANSWER','ANSWERED'], true)) $class = 'st-atendida';
            if (in_array($st, ['ABANDONADA','ABANDON','NOANSWER','TIMEOUT'], true)) $class = 'st-abandonada';
          ?>
          <tr>
            <td class="mono"><?= h(fmt_epoch((string)($r['start'] ?? ''))) ?></td>
            <td class="mono"><?= h((string)($r['cliente'] ?? '-')) ?></td>
            <td class="mono"><?= h((string)($r['agente'] ?? '-')) ?></td>
            <td class="mono"><?= h((string)($r['base'] ?? '-')) ?></td>
            <td><span class="pill <?= h($class) ?>"><?= h($st ?: 'RECEBIDA') ?></span></td>
	    <td class="mono"><?= h((string)($r['dur_calc'] ?? 0)) ?>s</td>

<td>
  <?php
    $rec = (string)($r['rec_file'] ?? '');
    if ($rec !== '') {
      $file = basename($rec);
      $url  = "/monitor/" . rawurlencode($file);

      // Play embutido + Download
      echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
      echo '<audio controls preload="none" style="height:28px;width:220px" src="' . h($url) . '"></audio>';
      echo '<a class="btn" href="' . h($url) . '" download>⬇️ WAV</a>';
      echo '</div>';
    } else {
      echo '<span style="opacity:.6">-</span>';
    }
  ?>
</td>

<td class="mono"><?= h((string)($r['callid'] ?? '')) ?></td>

          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="sub" style="margin-top:14px">
    Fonte: <span class="mono">asterisk -rx "database show taxi/calls"</span>
  </div>
</div>

<script>
(function(){
  const REFRESH_MS = <?= (int)$REFRESH_SECONDS ?> * 1000;

  function anyAudioPlaying(){
    return Array.from(document.querySelectorAll('audio')).some(a => !a.paused && !a.ended);
  }

  function pillClass(st){
    st = (st || '').toUpperCase();
    if (['ATENDIDA','ANSWER','ANSWERED','ENCERRADA'].includes(st)) return 'st-atendida';
    if (['ABANDONADA','ABANDON','NOANSWER','TIMEOUT','NAO_ATENDIDA'].includes(st)) return 'st-abandonada';
    return 'st-recebida';
  }

  function esc(s){
    return (s ?? '').toString()
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }

  function fmtEpoch(epoch){
    const n = parseInt(epoch, 10);
    if (!n) return '-';
    const d = new Date(n * 1000);
    const pad = (x)=> String(x).padStart(2,'0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  }

  async function refresh(){
    if (anyAudioPlaying()) return; // não atrapalha o playback
    try {
      const r = await fetch('./calls_data.php', {cache:'no-store'});
      const j = await r.json();
      if (!j.ok) return;

      document.getElementById('now').textContent = j.now;
      document.getElementById('cnt-total').textContent = j.counts.total;
      document.getElementById('cnt-atendidas').textContent = j.counts.atendidas;
      document.getElementById('cnt-abandonadas').textContent = j.counts.abandonadas;

      const body = document.getElementById('calls-body');
      body.innerHTML = j.rows.map(row => {
        const st = (row.status || '').toUpperCase();
        const cls = pillClass(st);
        let audioHtml = '-';
        if (row.rec_file) {
          const file = row.rec_file.split('/').pop();
          const url = '/monitor/' + encodeURIComponent(file);
          audioHtml = `
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <audio controls preload="none" style="height:28px;width:220px" src="${esc(url)}"></audio>
              <a class="btn" style="padding:6px 10px;border-radius:10px" href="${esc(url)}" download>⬇️ WAV</a>
            </div>`;
        }
        const agente = row.agente_nome ? `${row.agente} (${row.agente_nome})` : (row.agente || '-');
        return `
          <tr>
            <td class="mono">${esc(fmtEpoch(row.ts_start || row.start))}</td>
            <td class="mono">${esc(row.cliente || row.cid || '-')}</td>
            <td class="mono">${esc(agente)}</td>
            <td class="mono">${esc(row.base || '-')}</td>
            <td><span class="pill ${esc(cls)}">${esc(st || 'RECEBIDA')}</span></td>
            <td class="mono">${esc(row.dur || '0')}s</td>
            <td>${audioHtml}</td>
            <td class="mono">${esc(row.callid || '')}</td>
          </tr>`;
      }).join('');
    } catch(e) {
      // silencioso
    }
  }

  setInterval(refresh, REFRESH_MS);
})();
</script>


</body>
</html>
