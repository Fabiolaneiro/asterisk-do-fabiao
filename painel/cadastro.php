<?php
// /painel/cadastro.php
// Cadastro de agentes no AstDB + geração automática de /etc/asterisk/pjsip.generated.conf

date_default_timezone_set('America/Sao_Paulo');

// ====== OPCIONAL: token simples na URL ======
// $TOKEN = "SEU_TOKEN"; // acesse /painel/cadastro.php?k=SEU_TOKEN
$TOKEN = "";
if ($TOKEN !== "") {
  $k = $_GET['k'] ?? '';
  if (!hash_equals($TOKEN, $k)) { http_response_code(403); echo "Acesso negado."; exit; }
}

function sh($s){ return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8"); }

function asterisk_rx($cmd){
  $full = "sudo /usr/sbin/asterisk -rx " . escapeshellarg($cmd);
  $out=[]; $rc=0;
  exec($full . " 2>&1", $out, $rc);
  return ["rc"=>$rc, "out"=>implode("\n",$out)];
}

function normalize_ramal($r){ return preg_replace('/\D+/', '', (string)$r); }

function normalize_nome($n){
  $n = trim((string)$n);
  $n = preg_replace('/[\"\']/', '', $n);
  if (strlen($n) > 40) $n = substr($n, 0, 40);
  return $n;
}

function normalize_ctx($c){
  $c = trim((string)$c);
  $c = preg_replace('/[^a-zA-Z0-9\-\_]/','', $c);
  return $c;
}

function normalize_pass($p){
  $p = preg_replace('/\s+/', '', (string)$p);
  // deixa só dígitos pra manter simples (se quiser permitir forte, me avisa)
  $p = preg_replace('/\D+/', '', $p);
  if ($p === "") $p = "102030";
  return $p;
}

function db_show_prefix($prefix){
  $res = asterisk_rx("database show {$prefix}");
  $map = [];
  foreach (explode("\n", $res["out"]) as $line) {
    // /taxi/nome/71 : Joao
    if (preg_match('#^/'.preg_quote($prefix,'#').'/([0-9]+)\s*:\s*(.*)$#', trim($line), $m)) {
      $map[$m[1]] = trim($m[2]);
    }
  }
  ksort($map, SORT_NUMERIC);
  return $map;
}

function db_get($family, $key){
  // asterisk -rx "database get taxi nome/71"
  $res = asterisk_rx("database get {$family} {$key}");
  // retorna: "Value: X" ou "Database entry not found."
  if (preg_match('/Value:\s*(.*)\s*$/m', $res["out"], $m)) return trim($m[1]);
  return "";
}

function regenerate_pjsip_generated(){
  // lê nomes, senhas e contextos do AstDB
  $names = db_show_prefix("taxi/nome");

  $lines = [];
  $lines[] = "; =====================================================";
  $lines[] = "; AUTO-GERADO PELO PAINEL - NAO EDITAR MANUALMENTE";
  $lines[] = "; Arquivo: /etc/asterisk/pjsip.generated.conf";
  $lines[] = "; Gerado em: ".date("Y-m-d H:i:s");
  $lines[] = "; =====================================================";
  $lines[] = "";

  foreach ($names as $ramal => $nome) {
    $pass = db_get("taxi", "pass/{$ramal}");
    if ($pass === "") $pass = "102030";

    $ctx = db_get("taxi", "ctx/{$ramal}");
    if ($ctx === "") $ctx = "rua-agent-codes";

    $callerid = $nome;
    $lines[] = "[{$ramal}]";
    $lines[] = "type=endpoint";
    $lines[] = "context={$ctx}";
    $lines[] = "disallow=all";
    $lines[] = "allow=ulaw,alaw";
    $lines[] = "aors={$ramal}";
    $lines[] = "auth={$ramal}-auth";
    $lines[] = "direct_media=no";
    $lines[] = "force_rport=yes";
    $lines[] = "rewrite_contact=yes";
    $lines[] = "rtp_symmetric=yes";
    $lines[] = "callerid=\"{$callerid}\" <{$ramal}>";
    $lines[] = "";
    $lines[] = "[{$ramal}-auth]";
    $lines[] = "type=auth";
    $lines[] = "auth_type=userpass";
    $lines[] = "username={$ramal}";
    $lines[] = "password={$pass}";
    $lines[] = "";
    $lines[] = "[{$ramal}]";
    $lines[] = "type=aor";
    $lines[] = "max_contacts=1";
    $lines[] = "";
  }

  $content = implode("\n", $lines)."\n";

  // grava com lock pra evitar escrita concorrente
  $path = "/etc/asterisk/pjsip.generated.conf";
  $fp = @fopen($path, "c");
  if (!$fp) return ["ok"=>false, "err"=>"Não consegui abrir {$path} para escrita (permissão)."];

  if (!flock($fp, LOCK_EX)) { fclose($fp); return ["ok"=>false, "err"=>"Falha ao travar arquivo (lock)."]; }

  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, $content);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);

  // reload do pjsip
  $r = asterisk_rx("pjsip reload");
  if ($r["rc"] !== 0) return ["ok"=>false, "err"=>"Gerou arquivo, mas pjsip reload falhou: ".$r["out"]];

  return ["ok"=>true, "err"=>""];
}

// ======= AÇÕES =======
$msg = "";
$err = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";

  if ($action === "save") {
    $ramal = normalize_ramal($_POST["ramal"] ?? "");
    $nome  = normalize_nome($_POST["nome"] ?? "");
    $pass  = normalize_pass($_POST["senha"] ?? "102030");
    $ctx   = normalize_ctx($_POST["contexto"] ?? "rua-agent-codes");
    if ($ctx === "") $ctx = "rua-agent-codes";

    if ($ramal === "" || $nome === "") {
      $err = "Preencha ramal e nome.";
    } else {
      // AstDB: nome / senha / contexto
      $r1 = asterisk_rx("database put taxi nome/{$ramal} {$nome}");
      $r2 = asterisk_rx("database put taxi pass/{$ramal} {$pass}");
      $r3 = asterisk_rx("database put taxi ctx/{$ramal} {$ctx}");

      if ($r1["rc"]!==0 || $r2["rc"]!==0 || $r3["rc"]!==0) {
        $err = "Erro ao salvar no AstDB.\n".$r1["out"]."\n".$r2["out"]."\n".$r3["out"];
      } else {
        $gen = regenerate_pjsip_generated();
        if (!$gen["ok"]) $err = $gen["err"];
        else $msg = "Agente {$ramal} salvo (nome={$nome}, ctx={$ctx}) e PJSIP gerado + reload OK.";
      }
    }
  }

  if ($action === "delete") {
    $ramal = normalize_ramal($_POST["ramal"] ?? "");
    if ($ramal === "") {
      $err = "Ramal inválido.";
    } else {
      // remove dados de cadastro
      $r1 = asterisk_rx("database del taxi nome/{$ramal}");
      $r2 = asterisk_rx("database del taxi pass/{$ramal}");
      $r3 = asterisk_rx("database del taxi ctx/{$ramal}");

      // opcional: você pode também limpar presença/corrida aqui se quiser

      $gen = regenerate_pjsip_generated();
      if (!$gen["ok"]) $err = $gen["err"];
      else $msg = "Agente {$ramal} removido do cadastro e PJSIP regenerado + reload OK.";
    }
  }

  if ($action === "regen") {
    $gen = regenerate_pjsip_generated();
    if (!$gen["ok"]) $err = $gen["err"];
    else $msg = "Regenerado pjsip.generated.conf + pjsip reload OK.";
  }
}

$agentes = db_show_prefix("taxi/nome");
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Painel - Cadastro de Agentes</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial; background:#0b0f14; color:#e7eef7; margin:0;}
    .wrap{max-width:1040px; margin:0 auto; padding:22px;}
    .top{display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;}
    .btn{display:inline-block; padding:10px 12px; border-radius:10px; background:#1b2430; color:#e7eef7; text-decoration:none; border:1px solid #2a3a4c; cursor:pointer;}
    .btn:hover{background:#223041;}
    .danger{background:#2a1111; border:1px solid #6a2222;}
    .danger:hover{background:#381616;}
    .card{background:#0f1621; border:1px solid #1d2a3a; border-radius:14px; padding:14px; margin-top:14px;}
    h1{font-size:18px; margin:0;}
    h2{font-size:14px; margin:0 0 10px 0; opacity:.9;}
    .grid{display:grid; grid-template-columns: 160px 1fr 160px 220px auto; gap:10px; align-items:end;}
    input{width:100%; padding:10px; border-radius:10px; border:1px solid #2a3a4c; background:#0b0f14; color:#e7eef7;}
    .ok{background:#0f2a1b; border:1px solid #1f5a3a; color:#b6ffd0; padding:10px; border-radius:10px; margin-top:12px; white-space:pre-wrap;}
    .bad{background:#2a1111; border:1px solid #6a2222; color:#ffd0d0; padding:10px; border-radius:10px; margin-top:12px; white-space:pre-wrap;}
    table{width:100%; border-collapse:collapse; margin-top:10px;}
    th,td{padding:10px; border-bottom:1px solid #1d2a3a; text-align:left;}
    th{opacity:.85; font-size:12px; text-transform:uppercase; letter-spacing:.06em;}
    .actions{display:flex; gap:8px;}
    .muted{opacity:.75; font-size:12px;}
    code{background:#0b0f14; border:1px solid #1d2a3a; padding:2px 6px; border-radius:8px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <h1>Cadastro de Agentes (AstDB + PJSIP auto)</h1>
      <div class="actions">
        <a class="btn" href="/painel/">← Voltar ao Painel</a>
        <form method="post" style="margin:0;">
          <input type="hidden" name="action" value="regen">
          <button class="btn" type="submit">Regenerar PJSIP</button>
        </form>
      </div>
    </div>

    <?php if ($msg): ?><div class="ok"><?=sh($msg)?></div><?php endif; ?>
    <?php if ($err): ?><div class="bad"><?=sh($err)?></div><?php endif; ?>

    <div class="card">
      <h2>Novo cadastro / Atualizar (gera PJSIP automaticamente)</h2>
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="save">
        <div class="grid">
          <div>
            <label class="muted">Ramal</label>
            <input name="ramal" inputmode="numeric" placeholder="Ex: 71" required>
          </div>
          <div>
            <label class="muted">Nome (CallerID)</label>
            <input name="nome" placeholder="Ex: Joao" required>
          </div>
          <div>
            <label class="muted">Senha SIP</label>
            <input name="senha" inputmode="numeric" placeholder="102030" value="102030">
          </div>
          <div>
            <label class="muted">Contexto</label>
            <input name="contexto" placeholder="rua-agent-codes" value="rua-agent-codes">
          </div>
          <div>
            <button class="btn" type="submit">Salvar</button>
          </div>
        </div>
      </form>

      <div class="muted" style="margin-top:10px;">
        O painel grava em AstDB:
        <code>taxi/nome/&lt;ramal&gt;</code>, <code>taxi/pass/&lt;ramal&gt;</code>, <code>taxi/ctx/&lt;ramal&gt;</code>
        e regenera <code>/etc/asterisk/pjsip.generated.conf</code> seguindo sua template.
      </div>
    </div>

    <div class="card">
      <h2>Agentes cadastrados</h2>
      <table>
        <thead>
          <tr>
            <th style="width:120px;">Ramal</th>
            <th>Nome</th>
            <th style="width:220px;">Contexto</th>
            <th style="width:160px;">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (count($agentes) === 0): ?>
          <tr><td colspan="4" class="muted">Nenhum agente cadastrado ainda.</td></tr>
        <?php else: ?>
          <?php foreach ($agentes as $ramal => $nome): ?>
            <?php $ctx = db_get("taxi", "ctx/{$ramal}"); if ($ctx==="") $ctx="rua-agent-codes"; ?>
            <tr>
              <td><b><?=sh($ramal)?></b></td>
              <td><?=sh($nome)?></td>
              <td><code><?=sh($ctx)?></code></td>
              <td>
                <form method="post" class="actions" onsubmit="return confirm('Remover o agente <?=sh($ramal)?> do cadastro?');" style="margin:0;">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="ramal" value="<?=sh($ramal)?>">
                  <button class="btn danger" type="submit">Remover</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
