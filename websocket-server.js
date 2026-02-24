const WebSocket = require('ws');
const http      = require('http');

const WS_PORT = 8080;

// All connected browser clients
const clients = new Set();

// Latest sensor states (in memory)
let sensorState = {
  S1: { sensor:'S1', side:'LEFT',   motion:0, duration:0, count:0, rssi:0, time:'' },
  S2: { sensor:'S2', side:'RIGHT',  motion:0, duration:0, count:0, rssi:0, time:'' },
  S3: { sensor:'S3', side:'CENTER', motion:0, duration:0, count:0, rssi:0, time:'' },
};

// Create HTTP + WebSocket on same port
const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://localhost:${WS_PORT}`);

  // PHP calls /notify after saving to MySQL
  if (url.pathname === '/notify') {
    const data = {
      type:     'motion',
      sensor:   url.searchParams.get('sensor')  || '',
      side:     url.searchParams.get('side')    || '',
      motion:   parseInt(url.searchParams.get('motion')   || 0),
      pos_x:    parseFloat(url.searchParams.get('pos_x')  || 0),
      pos_y:    parseFloat(url.searchParams.get('pos_y')  || 0),
      duration: parseInt(url.searchParams.get('duration') || 0),
      count:    parseInt(url.searchParams.get('count')    || 0),
      rssi:     parseInt(url.searchParams.get('rssi')     || 0),
      time:     new Date().toISOString()
    };

    // Update in-memory state
    if (sensorState[data.sensor]) {
      sensorState[data.sensor] = { ...sensorState[data.sensor], ...data };
    }

    // Broadcast to all browsers
    const msg  = JSON.stringify(data);
    let   sent = 0;
    clients.forEach(client => {
      if (client.readyState === WebSocket.OPEN) {
        client.send(msg);
        sent++;
      }
    });

    console.log(`[${new Date().toLocaleTimeString()}] ${data.sensor} motion=${data.motion} dur=${data.duration}ms → ${sent} clients`);
    res.writeHead(200, {'Content-Type':'text/plain','Access-Control-Allow-Origin':'*'});
    res.end('OK');

  // ESP32 direct WebSocket notify (alternative path)
  } else if (url.pathname === '/esp') {
    const data = {
      type:   'motion',
      sensor: url.searchParams.get('sensor') || '',
      side:   url.searchParams.get('side')   || '',
      motion: parseInt(url.searchParams.get('motion') || 0),
      rssi:   parseInt(url.searchParams.get('rssi')   || 0),
      time:   new Date().toISOString()
    };
    const msg = JSON.stringify(data);
    clients.forEach(c => { if(c.readyState===WebSocket.OPEN) c.send(msg); });
    res.writeHead(200); res.end('OK');

  // Status page
  } else if (url.pathname === '/status') {
    const status = {
      clients:  clients.size,
      sensors:  sensorState,
      uptime:   process.uptime(),
      time:     new Date().toISOString()
    };
    res.writeHead(200, {'Content-Type':'application/json','Access-Control-Allow-Origin':'*'});
    res.end(JSON.stringify(status, null, 2));

  } else {
    res.writeHead(404); res.end('Not found');
  }
});

// Attach WebSocket to same HTTP server
const wss = new WebSocket.Server({ server });

wss.on('connection', (ws, req) => {
  clients.add(ws);
  const ip = req.socket.remoteAddress;
  console.log(`[${new Date().toLocaleTimeString()}] Browser connected from ${ip} — Total: ${clients.size}`);

  // Send current state immediately on connect
  ws.send(JSON.stringify({
    type:  'init',
    state: sensorState,
    time:  new Date().toISOString()
  }));

  ws.on('close', () => {
    clients.delete(ws);
    console.log(`[${new Date().toLocaleTimeString()}] Browser disconnected — Total: ${clients.size}`);
  });

  ws.on('error', () => clients.delete(ws));

  // Heartbeat
  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });
});

// Ping clients every 10s to detect dead connections
setInterval(() => {
  wss.clients.forEach(ws => {
    if (!ws.isAlive) { clients.delete(ws); return ws.terminate(); }
    ws.isAlive = false;
    ws.ping();
  });
}, 10000);

server.listen(WS_PORT, () => {
  console.log('╔════════════════════════════════════╗');
  console.log('║   ROOM TRACKER WebSocket Server    ║');
  console.log(`║   Port: ${WS_PORT}                        ║`);
  console.log('║   PHP  → http://localhost:8080/notify ║');
  console.log('║   Status→ http://localhost:8080/status║');
  console.log('╚════════════════════════════════════╝');
});
