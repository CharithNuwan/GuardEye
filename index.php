<?php
$host='localhost';$dbname='ghost_sensor';$user='root';$pass='';
$pdo=null;
try{
  $pdo=new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4",$user,$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}catch(PDOException $e){}

function getLatest($pdo,$id){
  if(!$pdo)return['motion'=>0,'recorded_at'=>null,'duration'=>0,'count'=>0,'rssi_router'=>null,'pos_x'=>null,'pos_y'=>null];
  $stmt=$pdo->prepare("SELECT * FROM motion_tracking WHERE sensor_id=? ORDER BY recorded_at DESC LIMIT 1");
  $stmt->execute([$id]);
  $row=$stmt->fetch(PDO::FETCH_ASSOC);
  return $row?:['motion'=>0,'recorded_at'=>null,'duration'=>0,'count'=>0,'rssi_router'=>null,'pos_x'=>null,'pos_y'=>null];
}
$s1=getLatest($pdo,'S1');$s2=getLatest($pdo,'S2');$s3=getLatest($pdo,'S3');

$todayStats=['s1_count'=>0,'s2_count'=>0,'s3_count'=>0,'total'=>0,'s1_dur'=>0,'s2_dur'=>0,'s3_dur'=>0];
if($pdo){
  $stmt=$pdo->query("SELECT
    SUM(CASE WHEN sensor_id='S1' AND motion=1 THEN 1 ELSE 0 END) as s1_count,
    SUM(CASE WHEN sensor_id='S2' AND motion=1 THEN 1 ELSE 0 END) as s2_count,
    SUM(CASE WHEN sensor_id='S3' AND motion=1 THEN 1 ELSE 0 END) as s3_count,
    SUM(motion) as total,
    SUM(CASE WHEN sensor_id='S1' THEN duration ELSE 0 END) as s1_dur,
    SUM(CASE WHEN sensor_id='S2' THEN duration ELSE 0 END) as s2_dur,
    SUM(CASE WHEN sensor_id='S3' THEN duration ELSE 0 END) as s3_dur
    FROM motion_tracking WHERE DATE(recorded_at)=CURDATE()");
  $row=$stmt->fetch(PDO::FETCH_ASSOC);
  if($row)$todayStats=$row;
}

$recentEvents=[];
if($pdo){
  $stmt=$pdo->query("SELECT * FROM motion_tracking WHERE motion=1 ORDER BY recorded_at DESC LIMIT 40");
  $recentEvents=$stmt->fetchAll(PDO::FETCH_ASSOC);
}

$dirHistory=[];
if($pdo){
  $stmt=$pdo->query("SELECT sensor_id,side,motion,recorded_at FROM motion_tracking WHERE motion=1 ORDER BY recorded_at DESC LIMIT 60");
  $dirHistory=array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

$longestDur=0;
if($pdo){
  $stmt=$pdo->query("SELECT MAX(duration) as mx FROM motion_tracking WHERE DATE(recorded_at)=CURDATE()");
  $row=$stmt->fetch(PDO::FETCH_ASSOC);
  $longestDur=$row['mx']??0;
}

$s1Active=$s1['motion']==1;$s2Active=$s2['motion']==1;$s3Active=$s3['motion']==1;
$activeCount=($s1Active?1:0)+($s2Active?1:0)+($s3Active?1:0);
$s1Time=$s1['recorded_at']?strtotime($s1['recorded_at']):0;
$s2Time=$s2['recorded_at']?strtotime($s2['recorded_at']):0;
$s3Time=$s3['recorded_at']?strtotime($s3['recorded_at']):0;
$lastActiveTime=max($s1Time,$s2Time,$s3Time);
$secAgo=$lastActiveTime>0?(time()-$lastActiveTime):999;

$posX=50;$posY=50;
$location='NO MOTION';$confidence=95;$direction='—';$speed='—';$personType='—';
if($activeCount==0){$location='NO MOTION';$confidence=95;$posX=50;$posY=50;}
elseif($s1Active&&$s2Active&&$s3Active){$location='FULL ROOM';$confidence=80;$posX=50;$posY=55;}
elseif($s1Active&&$s2Active&&!$s3Active){$location='FRONT CENTER';$confidence=85;$posX=50;$posY=30;}
elseif($s1Active&&$s3Active&&!$s2Active){$location='LEFT CENTER';$confidence=85;$posX=30;$posY=60;}
elseif($s2Active&&$s3Active&&!$s1Active){$location='RIGHT CENTER';$confidence=85;$posX=70;$posY=60;}
elseif($s1Active&&!$s2Active&&!$s3Active){$location='LEFT SIDE';$confidence=90;$posX=15;$posY=45;}
elseif($s2Active&&!$s1Active&&!$s3Active){$location='RIGHT SIDE';$confidence=90;$posX=85;$posY=45;}
elseif($s3Active&&!$s1Active&&!$s2Active){$location='CENTER';$confidence=90;$posX=50;$posY=78;}

if(count($dirHistory)>=2){
  $last2=array_slice($dirHistory,-2);
  $prev=$last2[0]['sensor_id'];$curr=$last2[1]['sensor_id'];
  $dm=['S1_S2'=>'LEFT → RIGHT','S2_S1'=>'RIGHT → LEFT','S1_S3'=>'LEFT → CENTER',
       'S3_S1'=>'CENTER → LEFT','S2_S3'=>'RIGHT → CENTER','S3_S2'=>'CENTER → RIGHT'];
  $direction=$dm[$prev.'_'.$curr]??("$prev → $curr");
  $t1=strtotime($last2[0]['recorded_at']);$t2=strtotime($last2[1]['recorded_at']);
  $diff=abs($t2-$t1);
  if($diff<1){$speed='VERY FAST';$personType='RUNNING';}
  elseif($diff<3){$speed='FAST';$personType='WALKING FAST';}
  elseif($diff<6){$speed='NORMAL';$personType='WALKING';}
  elseif($diff<10){$speed='SLOW';$personType='SLOW / ANIMAL';}
  else{$speed='VERY SLOW';$personType='ANIMAL / OBJECT';}
}

$locColors=['NO MOTION'=>'#2a5070','LEFT SIDE'=>'#0088ff','RIGHT SIDE'=>'#ff8800',
            'CENTER'=>'#00dd77','FRONT CENTER'=>'#00e5cc','LEFT CENTER'=>'#aa44ff',
            'RIGHT CENTER'=>'#f0a500','FULL ROOM'=>'#ff3355'];
$locColor=$locColors[$location]??'#00e5cc';

function wifiQ($r){
  if($r===null||$r==0)return['l'=>'N/A','p'=>0,'c'=>'#2a5070'];
  if($r>-60)return['l'=>'EXCELLENT','p'=>100,'c'=>'#00dd77'];
  if($r>-70)return['l'=>'GOOD','p'=>75,'c'=>'#00e5cc'];
  if($r>-80)return['l'=>'FAIR','p'=>50,'c'=>'#f0a500'];
  return['l'=>'POOR','p'=>25,'c'=>'#ff3355'];
}
function fmtD($ms){
  if(!$ms)return'0s';
  $s=round($ms/1000,1);
  if($s<60)return$s.'s';
  return floor($s/60).'m '.($s%60).'s';
}
$w1=wifiQ($s1['rssi_router']??null);
$w2=wifiQ($s2['rssi_router']??null);
$w3=wifiQ($s3['rssi_router']??null);

// JSON for JS initial state
$initState=json_encode([
  'S1'=>['motion'=>$s1Active?1:0,'side'=>'LEFT','duration'=>$s1['duration']??0,'count'=>$s1['count']??0,'rssi'=>$s1['rssi_router']??0,'time'=>$s1['recorded_at']??''],
  'S2'=>['motion'=>$s2Active?1:0,'side'=>'RIGHT','duration'=>$s2['duration']??0,'count'=>$s2['count']??0,'rssi'=>$s2['rssi_router']??0,'time'=>$s2['recorded_at']??''],
  'S3'=>['motion'=>$s3Active?1:0,'side'=>'CENTER','duration'=>$s3['duration']??0,'count'=>$s3['count']??0,'rssi'=>$s3['rssi_router']??0,'time'=>$s3['recorded_at']??''],
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Room Tracker — Live</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Exo+2:wght@300;400;600&display=swap');
:root{
  --bg:#060a0e;--panel:#0c1520;--border:#152535;
  --teal:#00e5cc;--blue:#0088ff;--orange:#ff8800;
  --green:#00dd77;--red:#ff3355;--gold:#f0a500;
  --purple:#aa44ff;--dim:#2a5070;--text:#90b8d0;--white:#d8eeff;
}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:'Exo 2',sans-serif;min-height:100vh;padding:12px}
body::before{content:'';position:fixed;inset:0;
  background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,229,204,.008) 2px,rgba(0,229,204,.008) 4px);
  pointer-events:none;z-index:999}
.hdr{text-align:center;padding:10px 0 14px}
.hdr h1{font-family:'Orbitron',monospace;font-size:1.5rem;font-weight:900;
  color:var(--white);letter-spacing:5px;text-shadow:0 0 30px rgba(0,229,204,.4)}
.hdr h1 span{color:var(--teal)}
.hdr-sub{font-size:.6rem;color:var(--dim);letter-spacing:3px;margin-top:4px;display:flex;align-items:center;justify-content:center;gap:12px}
.ws-badge{font-family:'Orbitron',monospace;font-size:.58rem;padding:2px 8px;border-radius:2px;border:1px solid currentColor}
.hline{height:1px;background:linear-gradient(90deg,transparent,var(--teal),transparent);margin:10px 0;opacity:.35}
.layout{display:grid;grid-template-columns:235px 1fr 235px;gap:10px;max-width:1400px;margin:0 auto}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:4px;padding:12px;position:relative;overflow:hidden}
.panel::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--teal),transparent);opacity:.4}
.ptitle{font-family:'Orbitron',monospace;font-size:.52rem;letter-spacing:3px;color:var(--teal);margin-bottom:10px;opacity:.8}
.sc{background:rgba(0,0,0,.25);border:1px solid var(--border);border-radius:3px;padding:10px;margin-bottom:8px;transition:all .4s}
.sc.on-s1{border-color:var(--blue);box-shadow:0 0 15px rgba(0,136,255,.15)}
.sc.on-s2{border-color:var(--orange);box-shadow:0 0 15px rgba(255,136,0,.15)}
.sc.on-s3{border-color:var(--green);box-shadow:0 0 15px rgba(0,221,119,.15)}
.sc-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:7px}
.sc-name{font-family:'Orbitron',monospace;font-size:.75rem;font-weight:700;transition:color .4s}
.dot{width:9px;height:9px;border-radius:50%;background:var(--dim);transition:all .4s}
.dot.on{animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.5)}}
.sc-badge{display:inline-block;font-family:'Orbitron',monospace;font-size:.56rem;padding:2px 8px;border-radius:2px;letter-spacing:1px;transition:all .3s}
.badge-on{background:rgba(0,229,204,.12);color:var(--teal)}
.badge-off{background:rgba(255,255,255,.04);color:var(--dim)}
.sdr{display:flex;justify-content:space-between;align-items:center;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:.65rem}
.sdr:last-child{border-bottom:none}
.sdl{color:var(--dim)}.sdv{font-family:'Orbitron',monospace;font-size:.6rem;font-weight:600;transition:all .3s}
.wifi-bars{display:flex;align-items:flex-end;gap:2px;height:14px}
.wb{width:4px;border-radius:1px;background:rgba(255,255,255,.1);transition:background .4s}
.wb.lit{background:currentColor}
.locbox{text-align:center;padding:12px 8px;background:rgba(0,0,0,.3);border:1px solid var(--border);border-radius:3px;margin-top:8px}
.loc-icon{font-size:1.6rem;margin-bottom:3px}
.loc-name{font-family:'Orbitron',monospace;font-size:.9rem;font-weight:900;letter-spacing:2px;transition:color .4s}
.loc-conf{font-size:.58rem;color:var(--dim);margin-top:3px;letter-spacing:2px}
.cbar{height:3px;background:rgba(255,255,255,.05);border-radius:2px;overflow:hidden;margin-top:5px}
.cfill{height:100%;border-radius:2px;transition:width .5s,background .4s}
.lastseen{text-align:center;padding:5px;background:rgba(0,0,0,.2);border-radius:3px;margin-top:7px;font-family:'Orbitron',monospace;font-size:.55rem;color:var(--dim)}
.tstats{display:flex;gap:6px;margin-top:6px}
.tstat{flex:1;text-align:center;padding:7px 3px;background:rgba(0,0,0,.3);border-radius:3px}
.tstat-val{font-family:'Orbitron',monospace;font-size:1.1rem;font-weight:700;transition:all .3s}
.tstat-lbl{font-size:.5rem;color:var(--dim);letter-spacing:1px;margin-top:2px}
.dur-bar-wrap{margin-top:8px}
.dur-row{display:flex;align-items:center;gap:6px;margin-bottom:5px}
.dur-lbl{font-size:.6rem;color:var(--dim);width:28px}
.dur-track{flex:1;height:6px;background:rgba(255,255,255,.05);border-radius:3px;overflow:hidden}
.dur-fill{height:100%;border-radius:3px;transition:width .6s}
.dur-val{font-family:'Orbitron',monospace;font-size:.58rem;width:35px;text-align:right;transition:all .3s}
.map-wrap{position:relative;width:100%;padding-bottom:62%;background:rgba(0,0,0,.5);border:1px solid var(--border);border-radius:3px;overflow:hidden}
.map-inner{position:absolute;inset:0}
.map-grid{position:absolute;inset:0;
  background-image:linear-gradient(rgba(0,229,204,.035) 1px,transparent 1px),
    linear-gradient(90deg,rgba(0,229,204,.035) 1px,transparent 1px);
  background-size:10% 10%}
.room-border{position:absolute;top:8%;left:5%;right:5%;bottom:8%;border:2px solid rgba(0,229,204,.2);border-radius:2px}
.room-border::before{content:'ROOM';position:absolute;top:-10px;left:50%;transform:translateX(-50%);
  font-family:'Orbitron',monospace;font-size:.42rem;color:rgba(0,229,204,.25);letter-spacing:3px}
.zone{position:absolute;transition:background .5s}
.zone-left{top:8%;left:5%;width:30%;bottom:8%}
.zone-right{top:8%;right:5%;width:30%;bottom:8%}
.zone-center{top:40%;left:30%;right:30%;bottom:8%}
.zone-left.on{background:rgba(0,136,255,.07)}
.zone-right.on{background:rgba(255,136,0,.07)}
.zone-center.on{background:rgba(0,221,119,.07)}
.smark{position:absolute;transform:translate(-50%,-50%);text-align:center;z-index:5}
.sring{width:32px;height:32px;border-radius:50%;border:2px solid var(--dim);display:flex;align-items:center;justify-content:center;
  margin:0 auto;font-family:'Orbitron',monospace;font-size:.52rem;color:var(--dim);transition:all .4s;position:relative}
.smark.on .sring{box-shadow:0 0 15px currentColor}
.pulse{position:absolute;inset:-2px;border-radius:50%;border:2px solid currentColor;opacity:0}
.smark.on .pulse{animation:sonar 1.5s infinite}
@keyframes sonar{0%{transform:scale(1);opacity:.6}100%{transform:scale(2.8);opacity:0}}
.slbl{font-family:'Orbitron',monospace;font-size:.42rem;margin-top:3px;letter-spacing:1px;transition:color .4s}
.pdot{position:absolute;transform:translate(-50%,-50%);transition:left .6s cubic-bezier(.4,0,.2,1),top .6s cubic-bezier(.4,0,.2,1);z-index:10}
.pdot-core{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;transition:all .5s}
.pdot.active .pdot-core{background:var(--teal);box-shadow:0 0 20px var(--teal),0 0 40px rgba(0,229,204,.3);animation:ppulse 2s infinite}
.pdot.inactive .pdot-core{background:var(--dim);box-shadow:none;animation:none}
@keyframes ppulse{0%,100%{transform:scale(1)}50%{transform:scale(1.2)}}
.pdot-label{position:absolute;top:-17px;left:50%;transform:translateX(-50%);
  font-family:'Orbitron',monospace;font-size:.38rem;white-space:nowrap;letter-spacing:1px;transition:color .4s}
.irow{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.irow:last-child{border-bottom:none}
.ilbl{color:var(--dim);font-size:.62rem;letter-spacing:1px}
.ival{font-family:'Orbitron',monospace;font-size:.65rem;font-weight:700;transition:all .3s}
.path-scroll{max-height:195px;overflow-y:auto}
.path-scroll::-webkit-scrollbar{width:3px}
.path-scroll::-webkit-scrollbar-thumb{background:var(--border)}
.pitem{display:flex;align-items:center;gap:6px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:.68rem}
.pitem:last-child{border-bottom:none}
.pdotsmall{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.ptime{color:var(--dim);font-size:.58rem;margin-left:auto}
.pdur{font-family:'Orbitron',monospace;font-size:.55rem;color:var(--gold)}
.navlink{display:block;text-align:center;padding:6px;background:rgba(0,229,204,.06);
  border:1px solid rgba(0,229,204,.15);color:var(--teal);text-decoration:none;
  font-family:'Orbitron',monospace;font-size:.52rem;letter-spacing:2px;border-radius:2px;margin-top:5px;transition:background .2s}
.navlink:hover{background:rgba(0,229,204,.12)}
/* Flash animation for new events */
@keyframes flash{0%{opacity:1}50%{opacity:.3}100%{opacity:1}}
.flash{animation:flash .4s ease}
@keyframes slideIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.slide-in{animation:slideIn .3s ease}
/* Live indicator pulse */
@keyframes livePulse{0%,100%{opacity:1}50%{opacity:.4}}
.live-pulse{animation:livePulse 1.5s infinite}
</style>
</head>
<body>

<div class="hdr">
  <h1>ROOM <span>MOTION</span> TRACKER</h1>
  <div class="hdr-sub">
    <span>S1:LEFT · S2:RIGHT · S3:CENTER</span>
    <span id="ws-status" class="ws-badge" style="color:var(--orange);border-color:var(--orange)">CONNECTING...</span>
    <span id="ws-latency" style="font-size:.58rem;color:var(--dim)"></span>
  </div>
  <div class="hline"></div>
</div>

<div class="layout">

<!-- LEFT -->
<div>
  <div class="panel">
    <div class="ptitle">▸ SENSOR STATUS</div>

    <!-- S1 -->
    <div class="sc" id="sc-S1">
      <div class="sc-head">
        <span class="sc-name" id="name-S1" style="color:var(--dim)">S1 — LEFT</span>
        <div class="dot" id="dot-S1"></div>
      </div>
      <span class="sc-badge badge-off" id="badge-S1">CLEAR</span>
      <div style="margin-top:7px">
        <div class="sdr"><span class="sdl">WiFi</span>
          <div style="display:flex;align-items:center;gap:5px">
            <div class="wifi-bars" id="wifi-S1" style="color:var(--dim)">
              <div class="wb" style="height:4px"></div><div class="wb" style="height:7px"></div>
              <div class="wb" style="height:10px"></div><div class="wb" style="height:13px"></div>
            </div>
            <span class="sdv" id="wlbl-S1" style="color:var(--dim);font-size:.55rem">N/A</span>
          </div>
        </div>
        <div class="sdr"><span class="sdl">RSSI</span><span class="sdv" id="rssi-S1" style="color:var(--dim)">—</span></div>
        <div class="sdr"><span class="sdl">LAST DUR</span><span class="sdv" id="dur-S1" style="color:var(--gold)">—</span></div>
        <div class="sdr" style="border-bottom:none"><span class="sdl">TODAY #</span><span class="sdv" id="cnt-S1" style="color:var(--blue)"><?= $s1['count']??0 ?></span></div>
      </div>
      <div class="sc-time" id="time-S1"><?= $s1['recorded_at']?date('H:i:s',strtotime($s1['recorded_at'])):'No data' ?></div>
    </div>

    <!-- S2 -->
    <div class="sc" id="sc-S2">
      <div class="sc-head">
        <span class="sc-name" id="name-S2" style="color:var(--dim)">S2 — RIGHT</span>
        <div class="dot" id="dot-S2"></div>
      </div>
      <span class="sc-badge badge-off" id="badge-S2">CLEAR</span>
      <div style="margin-top:7px">
        <div class="sdr"><span class="sdl">WiFi</span>
          <div style="display:flex;align-items:center;gap:5px">
            <div class="wifi-bars" id="wifi-S2" style="color:var(--dim)">
              <div class="wb" style="height:4px"></div><div class="wb" style="height:7px"></div>
              <div class="wb" style="height:10px"></div><div class="wb" style="height:13px"></div>
            </div>
            <span class="sdv" id="wlbl-S2" style="color:var(--dim);font-size:.55rem">N/A</span>
          </div>
        </div>
        <div class="sdr"><span class="sdl">RSSI</span><span class="sdv" id="rssi-S2" style="color:var(--dim)">—</span></div>
        <div class="sdr"><span class="sdl">LAST DUR</span><span class="sdv" id="dur-S2" style="color:var(--gold)">—</span></div>
        <div class="sdr" style="border-bottom:none"><span class="sdl">TODAY #</span><span class="sdv" id="cnt-S2" style="color:var(--orange)"><?= $s2['count']??0 ?></span></div>
      </div>
      <div class="sc-time" id="time-S2"><?= $s2['recorded_at']?date('H:i:s',strtotime($s2['recorded_at'])):'No data' ?></div>
    </div>

    <!-- S3 -->
    <div class="sc" id="sc-S3">
      <div class="sc-head">
        <span class="sc-name" id="name-S3" style="color:var(--dim)">S3 — CENTER</span>
        <div class="dot" id="dot-S3"></div>
      </div>
      <span class="sc-badge badge-off" id="badge-S3">CLEAR</span>
      <div style="margin-top:7px">
        <div class="sdr"><span class="sdl">WiFi</span>
          <div style="display:flex;align-items:center;gap:5px">
            <div class="wifi-bars" id="wifi-S3" style="color:var(--dim)">
              <div class="wb" style="height:4px"></div><div class="wb" style="height:7px"></div>
              <div class="wb" style="height:10px"></div><div class="wb" style="height:13px"></div>
            </div>
            <span class="sdv" id="wlbl-S3" style="color:var(--dim);font-size:.55rem">N/A</span>
          </div>
        </div>
        <div class="sdr"><span class="sdl">RSSI</span><span class="sdv" id="rssi-S3" style="color:var(--dim)">—</span></div>
        <div class="sdr"><span class="sdl">LAST DUR</span><span class="sdv" id="dur-S3" style="color:var(--gold)">—</span></div>
        <div class="sdr" style="border-bottom:none"><span class="sdl">TODAY #</span><span class="sdv" id="cnt-S3" style="color:var(--green)"><?= $s3['count']??0 ?></span></div>
      </div>
      <div class="sc-time" id="time-S3"><?= $s3['recorded_at']?date('H:i:s',strtotime($s3['recorded_at'])):'No data' ?></div>
    </div>

    <div class="locbox">
      <div class="loc-icon" id="loc-icon">⬜</div>
      <div class="loc-name" id="loc-name" style="color:var(--dim)"><?= $location ?></div>
      <div class="loc-conf" id="loc-conf">CONFIDENCE <?= $confidence ?>%</div>
      <div class="cbar"><div class="cfill" id="loc-bar" style="width:<?= $confidence ?>%;background:<?= $locColor ?>"></div></div>
    </div>
    <div class="lastseen" id="lastseen"><?= $secAgo<3600?"LAST: {$secAgo}s AGO":"LAST: LONG AGO" ?></div>
  </div>

  <div class="panel" style="margin-top:10px">
    <div class="ptitle">▸ TODAY'S EVENTS</div>
    <div class="tstats">
      <div class="tstat"><div class="tstat-val" id="ts1" style="color:var(--blue)"><?= $todayStats['s1_count']??0 ?></div><div class="tstat-lbl">LEFT</div></div>
      <div class="tstat"><div class="tstat-val" id="ts2" style="color:var(--orange)"><?= $todayStats['s2_count']??0 ?></div><div class="tstat-lbl">RIGHT</div></div>
      <div class="tstat"><div class="tstat-val" id="ts3" style="color:var(--green)"><?= $todayStats['s3_count']??0 ?></div><div class="tstat-lbl">CENTER</div></div>
    </div>
    <div class="dur-bar-wrap" id="dur-bars">
      <?php $maxD=max(($todayStats['s1_dur']??1),($todayStats['s2_dur']??1),($todayStats['s3_dur']??1),1);
      foreach([['S1',$todayStats['s1_dur']??0,'var(--blue)'],['S2',$todayStats['s2_dur']??0,'var(--orange)'],['S3',$todayStats['s3_dur']??0,'var(--green)']] as [$l,$d,$c]):
        $p=min(100,round($d/$maxD*100)); ?>
      <div class="dur-row">
        <span class="dur-lbl"><?= $l ?></span>
        <div class="dur-track"><div class="dur-fill" id="dbar-<?= $l ?>" style="width:<?= $p ?>%;background:<?= $c ?>"></div></div>
        <span class="dur-val" id="dval-<?= $l ?>" style="color:<?= $c ?>"><?= fmtD($d) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:8px">
      <div class="irow"><span class="ilbl">TOTAL EVENTS</span><span class="ival" id="total-ev" style="color:var(--teal)"><?= $todayStats['total']??0 ?></span></div>
      <div class="irow"><span class="ilbl">LONGEST</span><span class="ival" id="longest" style="color:var(--gold)"><?= fmtD($longestDur) ?></span></div>
      <div class="irow"><span class="ilbl">ACTIVE NOW</span><span class="ival" id="active-now" style="color:var(--dim)"><?= $activeCount ?>/3</span></div>
    </div>
  </div>
</div>

<!-- CENTER MAP -->
<div class="panel">
  <div class="ptitle">▸ LIVE ROOM MAP · <span id="map-time"><?= date('H:i:s') ?></span> · <span class="live-pulse" style="color:var(--teal)">● LIVE</span></div>
  <div class="map-wrap">
    <div class="map-inner">
      <div class="map-grid"></div>
      <div class="zone zone-left"  id="zone-S1"></div>
      <div class="zone zone-right" id="zone-S2"></div>
      <div class="zone zone-center"id="zone-S3"></div>
      <div class="room-border"></div>

      <?php $marks=[['S1',15,45,'var(--blue)','LEFT'],['S2',85,45,'var(--orange)','RIGHT'],['S3',50,78,'var(--green)','CENTER']];
      foreach($marks as [$sid,$lx,$ly,$col,$lbl]): ?>
      <div class="smark" id="sm-<?= $sid ?>" style="left:<?= $lx ?>%;top:<?= $ly ?>%;color:<?= $col ?>">
        <div style="position:relative;width:32px;height:32px;margin:0 auto">
          <div style="width:120px;height:120px;border-radius:50%;border:1px dashed rgba(0,229,204,.05);
               position:absolute;top:50%;left:50%;transform:translate(-50%,-50%)" id="range-<?= $sid ?>"></div>
          <div class="sring" id="sring-<?= $sid ?>" style="border-color:<?= $col ?>;color:<?= $col ?>">
            <?= $sid ?><div class="pulse"></div>
          </div>
        </div>
        <div class="slbl" style="color:<?= $col ?>"><?= $lbl ?></div>
      </div>
      <?php endforeach; ?>

      <div class="pdot inactive" id="person-dot" style="left:<?= $posX ?>%;top:<?= $posY ?>%">
        <div class="pdot-label" id="pdot-label" style="color:var(--dim)"><?= $location ?></div>
        <div class="pdot-core" id="pdot-core">·</div>
      </div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:10px">
    <div class="panel" style="padding:10px">
      <div class="ptitle">▸ MOVEMENT</div>
      <div class="irow"><span class="ilbl">DIRECTION</span><span class="ival" id="mv-dir" style="color:var(--teal);font-size:.56rem"><?= $direction ?></span></div>
      <div class="irow"><span class="ilbl">SPEED</span><span class="ival" id="mv-spd" style="color:var(--gold)"><?= $speed ?></span></div>
      <div class="irow"><span class="ilbl">TYPE</span><span class="ival" id="mv-type" style="color:var(--green);font-size:.56rem"><?= $personType ?></span></div>
    </div>
    <div class="panel" style="padding:10px">
      <div class="ptitle">▸ SIGNAL QUALITY</div>
      <?php foreach([['S1',$w1,'var(--blue)'],['S2',$w2,'var(--orange)'],['S3',$w3,'var(--green)']] as [$sid,$wq,$col]): ?>
      <div class="irow">
        <span class="ilbl" style="color:<?= $col ?>"><?= $sid ?></span>
        <div style="display:flex;align-items:center;gap:4px">
          <div class="wifi-bars" id="wifi2-<?= $sid ?>" style="color:<?= $wq['c'] ?>">
            <div class="wb <?= $wq['p']>=25?'lit':'' ?>" style="height:3px"></div>
            <div class="wb <?= $wq['p']>=50?'lit':'' ?>" style="height:5px"></div>
            <div class="wb <?= $wq['p']>=75?'lit':'' ?>" style="height:8px"></div>
            <div class="wb <?= $wq['p']>=100?'lit':'' ?>" style="height:11px"></div>
          </div>
          <span class="ival" id="wlbl2-<?= $sid ?>" style="color:<?= $wq['c'] ?>;font-size:.55rem"><?= $wq['l'] ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="panel" style="padding:10px">
      <div class="ptitle">▸ ZONE LEGEND</div>
      <?php $zones=['LEFT SIDE'=>['S1','var(--blue)'],'RIGHT SIDE'=>['S2','var(--orange)'],'CENTER'=>['S3','var(--green)'],'FRONT CENTER'=>['S1+S2','var(--teal)'],'LEFT CENTER'=>['S1+S3','var(--purple)'],'RIGHT CENTER'=>['S2+S3','var(--gold)'],'FULL ROOM'=>['ALL','var(--red)']];
      foreach($zones as $z=>[$s,$col]):$act=$z==$location; ?>
      <div style="display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid rgba(255,255,255,.03);font-size:.56rem">
        <span id="zone-lbl-<?= str_replace(' ','-',$z) ?>" style="color:<?= $act?$col:'var(--dim)' ?>;font-weight:<?= $act?700:400 ?>"><?= $act?'▶ ':'' ?><?= $z ?></span>
        <span style="color:var(--dim);font-size:.5rem"><?= $s ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- RIGHT -->
<div>
  <div class="panel">
    <div class="ptitle">▸ MOTION HISTORY <span id="hist-count" style="color:var(--dim);font-size:.5rem"></span></div>
    <div class="path-scroll" id="history-list">
      <?php if(count($recentEvents)>0):
        $sC=['S1'=>'#0088ff','S2'=>'#ff8800','S3'=>'#00dd77'];
        foreach($recentEvents as $ev):
          $col=$sC[$ev['sensor_id']]??'#00e5cc';$dur=$ev['duration']??0;
      ?>
      <div class="pitem">
        <div class="pdotsmall" style="background:<?= $col ?>"></div>
        <span style="color:<?= $col ?>;font-family:'Orbitron',monospace;font-size:.58rem"><?= $ev['sensor_id'] ?></span>
        <span style="color:var(--text);font-size:.65rem"><?= $ev['side'] ?></span>
        <?php if($dur>0): ?><span class="pdur"><?= fmtD($dur) ?></span><?php endif; ?>
        <span class="ptime"><?= date('H:i:s',strtotime($ev['recorded_at'])) ?></span>
      </div>
      <?php endforeach;else: ?>
      <div style="color:var(--dim);text-align:center;padding:20px;font-size:.72rem">No events yet</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel" style="margin-top:10px">
    <div class="ptitle">▸ DIRECTION ANALYSIS</div>
    <?php
    $dirCount=[];
    for($i=1;$i<count($dirHistory);$i++){
      $k=$dirHistory[$i-1]['sensor_id'].'→'.$dirHistory[$i]['sensor_id'];
      $dirCount[$k]=($dirCount[$k]??0)+1;
    }
    arsort($dirCount);$total2=array_sum($dirCount);
    $dl=['S1→S2'=>'LEFT→RIGHT','S2→S1'=>'RIGHT→LEFT','S1→S3'=>'LEFT→CENTER',
         'S3→S1'=>'CENTER→LEFT','S2→S3'=>'RIGHT→CENTER','S3→S2'=>'CENTER→RIGHT'];
    $sC2=['S1'=>'#0088ff','S2'=>'#ff8800','S3'=>'#00dd77'];
    if(count($dirCount)>0):foreach(array_slice($dirCount,0,5,true) as $k=>$cnt):
      $pct=$total2>0?round($cnt/$total2*100):0;
      $parts=explode('→',$k);
      $c1=$sC2[$parts[0]]??'var(--teal)';$c2=$sC2[$parts[1]??'']??'var(--teal)';
      $lbl=$dl[$k]??$k;
    ?>
    <div style="margin-bottom:7px">
      <div style="display:flex;justify-content:space-between;font-size:.62rem;margin-bottom:2px">
        <span style="color:var(--text)"><?= $lbl ?></span>
        <span style="font-family:'Orbitron',monospace;color:var(--teal)"><?= $cnt ?>x</span>
      </div>
      <div style="height:4px;background:rgba(255,255,255,.05);border-radius:3px;overflow:hidden">
        <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,<?= $c1 ?>,<?= $c2 ?>);border-radius:3px"></div>
      </div>
    </div>
    <?php endforeach;else: ?>
    <div style="color:var(--dim);font-size:.72rem;text-align:center;padding:12px">Not enough data</div>
    <?php endif; ?>
  </div>

  <div class="panel" style="margin-top:10px">
    <div class="ptitle">▸ NAVIGATION</div>
    <a href="predictions.php" class="navlink">⚡ PHASE 2 — PREDICTIONS</a>
    <a href="patterns.php"    class="navlink">📊 PHASE 3 — PATTERNS</a>
    <div style="margin-top:8px">
      <div class="irow"><span class="ilbl">WS STATUS</span><span class="ival" id="nav-ws" style="color:var(--orange)">CONNECTING</span></div>
      <div class="irow"><span class="ilbl">WS LATENCY</span><span class="ival" id="nav-lat" style="color:var(--dim)">—</span></div>
      <div class="irow"><span class="ilbl">MESSAGES</span><span class="ival" id="nav-msg" style="color:var(--teal)">0</span></div>
      <div class="irow"><span class="ilbl">TIME</span><span class="ival" id="nav-time" style="font-size:.58rem"><?= date('H:i:s') ?></span></div>
    </div>
  </div>
</div>

</div><!-- end layout -->

<script>
// ============================================
// WEBSOCKET CLIENT
// ============================================
const WS_URL    = 'ws://192.168.1.197:8080';
const sColors   = {S1:'#0088ff',S2:'#ff8800',S3:'#00dd77'};
const sOnClass  = {S1:'on-s1',S2:'on-s2',S3:'on-s3'};

// Initial state from PHP
let state = <?= $initState ?>;
let msgCount    = 0;
let wsLatency   = 0;
let pingTime    = 0;
let ws;
let reconnTimer;

// Direction history for analysis
let dirHistory = [];

// ---- WEBSOCKET ----
function connect() {
  ws = new WebSocket(WS_URL);

  ws.onopen = () => {
    clearTimeout(reconnTimer);
    setWsStatus('LIVE ●', '#00dd77');
    console.log('WebSocket connected!');
  };

  ws.onmessage = (e) => {
    const data = JSON.parse(e.data);
    msgCount++;
    el('nav-msg').textContent = msgCount;

    if (data.type === 'init') {
      // Server sent full state on connect
      Object.keys(data.state).forEach(sid => {
        const s = data.state[sid];
        state[sid] = s;
        updateSensorUI(sid, s.motion==1, s.rssi, s.duration, s.count, s.time);
      });
      updateLocation();
      return;
    }

    if (data.type === 'motion') {
      const sid = data.sensor;

      // Latency from server timestamp
      const serverTime = new Date(data.time).getTime();
      wsLatency = Date.now() - serverTime;
      el('nav-lat').textContent = wsLatency + 'ms';
      el('ws-latency').textContent = wsLatency + 'ms';

      // Update state
      state[sid] = { ...state[sid], ...data };

      // Update sensor card UI
      updateSensorUI(sid, data.motion==1, data.rssi, data.duration, data.count, data.time);

      // Update location dot
      updateLocation();

      // Add to history
      if (data.motion==1) addHistory(data);

      // Update time
      el('map-time').textContent = new Date().toLocaleTimeString();
      el('nav-time').textContent = new Date().toLocaleTimeString();

      // Flash sensor card
      el('sc-'+sid).classList.add('flash');
      setTimeout(() => el('sc-'+sid).classList.remove('flash'), 400);
    }
  };

  ws.onclose = () => {
    setWsStatus('RECONNECTING...', '#ff8800');
    reconnTimer = setTimeout(connect, 2000);
  };

  ws.onerror = () => ws.close();
}

// ---- UPDATE SENSOR CARD ----
function updateSensorUI(sid, active, rssi, duration, count, time) {
  const sc    = el('sc-'+sid);
  const dot   = el('dot-'+sid);
  const badge = el('badge-'+sid);
  const name  = el('name-'+sid);
  const col   = sColors[sid];

  // Zone overlay
  const zone = el('zone-'+sid);
  if (zone) zone.className = 'zone zone-' + sid.toLowerCase().replace('s1','left').replace('s2','right').replace('s3','center') + (active?' on':'');

  // Sensor marker
  const sm = el('sm-'+sid);
  if (sm) {
    if (active) sm.classList.add('on');
    else sm.classList.remove('on');
  }

  if (active) {
    sc.className = 'sc ' + sOnClass[sid];
    dot.style.background = col;
    dot.style.boxShadow  = '0 0 8px ' + col;
    dot.classList.add('on');
    name.style.color = col;
    badge.textContent  = 'MOTION';
    badge.className    = 'sc-badge badge-on';
  } else {
    sc.className = 'sc';
    dot.style.background = 'var(--dim)';
    dot.style.boxShadow  = 'none';
    dot.classList.remove('on');
    name.style.color = 'var(--dim)';
    badge.textContent  = 'CLEAR';
    badge.className    = 'sc-badge badge-off';
  }

  if (duration > 0) {
    el('dur-'+sid).textContent = fmtDur(duration);
  }
  if (count > 0) {
    el('cnt-'+sid).textContent = count;
    // Update today stat counters
    const tsMap = {S1:'ts1',S2:'ts2',S3:'ts3'};
    if (tsMap[sid]) el(tsMap[sid]).textContent = count;
  }
  if (time) {
    const t = new Date(time).toLocaleTimeString();
    el('time-'+sid).textContent = isNaN(new Date(time)) ? time : t;
  }

  // WiFi quality
  updateWifi(sid, rssi);
}

// ---- WIFI BARS ----
function updateWifi(sid, rssi) {
  const q = wifiQuality(rssi);
  const bars1 = el('wifi-'+sid);
  const bars2 = el('wifi2-'+sid);
  [bars1, bars2].forEach(bars => {
    if (!bars) return;
    bars.style.color = q.color;
    const wbs = bars.querySelectorAll('.wb');
    wbs.forEach((wb, i) => {
      if ((i+1)*25 <= q.pct) wb.classList.add('lit');
      else wb.classList.remove('lit');
    });
  });
  if (el('wlbl-'+sid))  el('wlbl-'+sid).textContent  = q.label;
  if (el('wlbl2-'+sid)) el('wlbl2-'+sid).textContent = q.label;
  if (el('rssi-'+sid))  {
    el('rssi-'+sid).textContent = rssi ? rssi+'dBm' : '—';
    el('rssi-'+sid).style.color = q.color;
  }
}

// ---- LOCATION ----
function updateLocation() {
  const s1 = state.S1?.motion==1;
  const s2 = state.S2?.motion==1;
  const s3 = state.S3?.motion==1;
  const activeCount = (s1?1:0)+(s2?1:0)+(s3?1:0);

  let location='NO MOTION',posX=50,posY=50,confidence=95,color='#2a5070';
  const icons={
    'NO MOTION':'⬜','LEFT SIDE':'⬅️','RIGHT SIDE':'➡️','CENTER':'🎯',
    'FRONT CENTER':'⬆️','LEFT CENTER':'↖️','RIGHT CENTER':'↗️','FULL ROOM':'🚨'
  };
  const colors={
    'NO MOTION':'#2a5070','LEFT SIDE':'#0088ff','RIGHT SIDE':'#ff8800',
    'CENTER':'#00dd77','FRONT CENTER':'#00e5cc','LEFT CENTER':'#aa44ff',
    'RIGHT CENTER':'#f0a500','FULL ROOM':'#ff3355'
  };

  if      (s1&&s2&&s3)  { location='FULL ROOM';    posX=50;posY=55;confidence=80; }
  else if (s1&&s2)       { location='FRONT CENTER'; posX=50;posY=30;confidence=85; }
  else if (s1&&s3)       { location='LEFT CENTER';  posX=30;posY=60;confidence=85; }
  else if (s2&&s3)       { location='RIGHT CENTER'; posX=70;posY=60;confidence=85; }
  else if (s1)           { location='LEFT SIDE';    posX=15;posY=45;confidence=90; }
  else if (s2)           { location='RIGHT SIDE';   posX=85;posY=45;confidence=90; }
  else if (s3)           { location='CENTER';       posX=50;posY=78;confidence=90; }

  color = colors[location] || '#2a5070';

  // Person dot
  const dot = el('person-dot');
  if (dot) {
    dot.style.left = posX + '%';
    dot.style.top  = posY + '%';
    dot.className  = 'pdot ' + (activeCount>0?'active':'inactive');
    el('pdot-label').textContent = location;
    el('pdot-label').style.color = activeCount>0 ? color : 'var(--dim)';
    el('pdot-core').textContent  = activeCount>0 ? '👤' : '·';
  }

  // Location box
  el('loc-icon').textContent = icons[location] || '❓';
  el('loc-name').textContent = location;
  el('loc-name').style.color = color;
  el('loc-conf').textContent = 'CONFIDENCE ' + confidence + '%';
  el('loc-bar').style.width  = confidence + '%';
  el('loc-bar').style.background = color;

  // Active count
  el('active-now').textContent = activeCount + '/3';
  el('active-now').style.color = activeCount>0?'var(--green)':'var(--dim)';

  // Zone legend
  const zones = ['LEFT SIDE','RIGHT SIDE','CENTER','FRONT CENTER','LEFT CENTER','RIGHT CENTER','FULL ROOM'];
  zones.forEach(z => {
    const lblEl = el('zone-lbl-' + z.replace(/ /g,'-'));
    if (!lblEl) return;
    if (z === location) {
      lblEl.style.color = colors[z];
      lblEl.style.fontWeight = '700';
      lblEl.textContent = '▶ ' + z;
    } else {
      lblEl.style.color = 'var(--dim)';
      lblEl.style.fontWeight = '400';
      lblEl.textContent = z;
    }
  });
}

// ---- ADD HISTORY ITEM ----
function addHistory(data) {
  const hist = el('history-list');
  if (!hist) return;

  const col  = sColors[data.sensor] || '#00e5cc';
  const time = new Date(data.time).toLocaleTimeString();

  const item = document.createElement('div');
  item.className = 'pitem slide-in';
  item.innerHTML =
    `<div class="pdotsmall" style="background:${col}"></div>` +
    `<span style="color:${col};font-family:'Orbitron',monospace;font-size:.58rem">${data.sensor}</span>` +
    `<span style="color:var(--text);font-size:.65rem">${data.side}</span>` +
    (data.duration>0 ? `<span class="pdur">${fmtDur(data.duration)}</span>` : '') +
    `<span class="ptime">${time}</span>`;

  hist.insertBefore(item, hist.firstChild);
  while (hist.children.length > 40) hist.removeChild(hist.lastChild);
}

// ---- HELPERS ----
function el(id) { return document.getElementById(id); }

function fmtDur(ms) {
  if (!ms || ms==0) return '0s';
  const s = (ms/1000).toFixed(1);
  if (s < 60) return s + 's';
  return Math.floor(s/60) + 'm ' + (s%60).toFixed(0) + 's';
}

function wifiQuality(rssi) {
  if (!rssi || rssi==0) return {label:'N/A',pct:0,color:'#2a5070'};
  if (rssi>-60) return {label:'EXCELLENT',pct:100,color:'#00dd77'};
  if (rssi>-70) return {label:'GOOD',     pct:75, color:'#00e5cc'};
  if (rssi>-80) return {label:'FAIR',     pct:50, color:'#f0a500'};
  return             {label:'POOR',     pct:25, color:'#ff3355'};
}

function setWsStatus(txt, col) {
  const el1 = el('ws-status');
  const el2 = el('nav-ws');
  if (el1) { el1.textContent=txt; el1.style.color=col; el1.style.borderColor=col; }
  if (el2) { el2.textContent=txt.replace(' ●',''); el2.style.color=col; }
}

// Update clock every second
setInterval(() => {
  const t = new Date().toLocaleTimeString();
  el('map-time').textContent = t;
  el('nav-time').textContent = t;
}, 1000);

// Apply initial state from PHP data
document.addEventListener('DOMContentLoaded', () => {
  Object.keys(state).forEach(sid => {
    const s = state[sid];
    updateSensorUI(sid, s.motion==1, s.rssi, s.duration, s.count, s.time);
  });
  updateLocation();
  connect(); // Start WebSocket!
});
</script>
</body>
</html>
