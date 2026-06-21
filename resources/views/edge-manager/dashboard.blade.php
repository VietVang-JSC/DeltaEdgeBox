<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DeltaPOS Edge Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }
        .container { max-width: 900px; margin: 0 auto; padding: 1.5rem; }
        h1 { font-size: 1.25rem; font-weight: 700; color: #38bdf8; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; }
        h1 small { font-size: 0.75rem; color: #64748b; font-weight: 400; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem; }
        .card { background: #1e293b; border-radius: 8px; padding: 1rem; }
        .card .label { font-size: 0.7rem; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
        .card .value { font-size: 1.5rem; font-weight: 700; }
        .card .sub { font-size: 0.75rem; color: #94a3b8; margin-top: 0.15rem; }
        .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
        .dot.green { background: #22c55e; box-shadow: 0 0 8px #22c55e66; }
        .dot.red { background: #ef4444; box-shadow: 0 0 8px #ef444466; }
        .dot.yellow { background: #eab308; box-shadow: 0 0 8px #eab30866; }
        .section { background: #1e293b; border-radius: 8px; padding: 1.25rem; margin-bottom: 1rem; }
        .section h2 { font-size: 0.85rem; font-weight: 600; color: #94a3b8; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .btn-row { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .btn { padding: 0.45rem 1rem; border: none; border-radius: 6px; font-size: 0.825rem; font-weight: 500; cursor: pointer; transition: .15s; white-space: nowrap; }
        .btn:active { transform: scale(0.97); }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-success { background: #16a34a; color: #fff; }
        .btn-success:hover { background: #15803d; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-warning { background: #d97706; color: #fff; }
        .btn-warning:hover { background: #b45309; }
        .btn-secondary { background: #475569; color: #fff; }
        .btn-secondary:hover { background: #334155; }
        .btn-outline { background: transparent; color: #94a3b8; border: 1px solid #334155; }
        .btn-outline:hover { background: #1e293b; border-color: #475569; }
        .btn-sm { padding: 0.3rem 0.7rem; font-size: 0.75rem; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .form-row { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.6rem; flex-wrap: wrap; }
        .form-row label { font-size: 0.8rem; min-width: 80px; color: #94a3b8; }
        .form-row input, .form-row select { flex: 1; min-width: 120px; padding: 0.4rem 0.6rem; border: 1px solid #334155; border-radius: 4px; background: #0f172a; color: #e2e8f0; font-size: 0.825rem; outline: none; }
        .form-row input:focus, .form-row select:focus { border-color: #2563eb; }
        .alert { padding: 0.6rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; animation: fadeIn .2s; }
        .alert-success { background: #14532d; color: #86efac; border: 1px solid #22c55e44; }
        .alert-error { background: #450a0a; color: #fca5a5; border: 1px solid #ef444444; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
        .svc-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.4rem 0; border-bottom: 1px solid #0f172a; }
        .svc-row:last-child { border: none; }
        .svc-row .name { flex: 1; font-size: 0.825rem; }
        .svc-row .status { font-size: 0.8rem; font-weight: 500; }
        .log-box { background: #0f172a; border-radius: 4px; padding: 0.5rem; max-height: 180px; overflow-y: auto; font-family: 'Cascadia Code', 'Fira Code', monospace; font-size: 0.7rem; line-height: 1.6; }
        .log-box .time { color: #475569; }
        .log-box .lvl-error { color: #ef4444; }
        .log-box .lvl-warning { color: #eab308; }
        .log-entry { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 2px; }
        .ip-list { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .ip-badge { background: #334155; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-family: monospace; }
        .copy-msg { font-size: 0.7rem; color: #64748b; cursor: pointer; }
        .copy-msg:hover { color: #94a3b8; }
        .toast { position: fixed; bottom: 1rem; right: 1rem; background: #1e293b; border: 1px solid #334155; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.75rem; display: none; z-index: 999; }
    </style>
</head>
<body>
<div class="container">
    @php use Illuminate\Support\Str; @endphp
    <h1>⚙ DeltaPOS Edge Box Manager</h1>

    @if(session('success'))
        <div class="alert alert-success">✓ {{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-error">✗ {{ session('error') }}</div>
    @endif

    <div class="grid">
        <div class="card">
            <div class="label">Status</div>
            <div class="value" style="font-size:1rem">
                <span class="dot {{ $status['all_running'] ? 'green' : 'red' }}"></span>
                {{ $status['all_running'] ? 'All Running' : 'Partial' }}
            </div>
            <div class="sub">{{ $status['all_running'] ? 'All 4 services active' : 'Some services stopped' }}</div>
        </div>
        <div class="card">
            <div class="label">Cloud</div>
            <div class="value" style="font-size:1rem">
                <span class="dot {{ $config['has_api_key'] ? 'green' : 'red' }}"></span>
                {{ $config['has_api_key'] ? 'Connected' : 'Disconnected' }}
            </div>
            <div class="sub">{{ $config['cloud_url'] ?? 'No URL set' }}</div>
        </div>
        <div class="card">
            <div class="label">Store</div>
            <div class="value" style="font-size:1rem">{{ $config['store_id'] ?? 'N/A' }}</div>
            <div class="sub">PHP {{ $status['php_version'] }}</div>
        </div>
        <div class="card">
            <div class="label">Sync Queue</div>
            <div class="value" style="font-size:1rem">{{ $status['sync_queue'] }}</div>
            <div class="sub">{{ $status['db_size'] }} DB</div>
        </div>
    </div>

    @if(!empty($network['ips']))
    <div class="section" style="padding:0.5rem 1rem">
        <div style="display:flex;flex-direction:column;gap:0.3rem">
            <div>
                <span style="font-size:0.75rem;color:#64748b">Computer name:</span>
                <span class="ip-badge" style="background:#1d4ed8" onclick="copy(this)" title="Click to copy">{{ $network['hostname'] }}</span>
                <span style="font-size:0.7rem;color:#64748b;margin-left:0.3rem">(Windows only)</span>
            </div>
            <div style="font-size:0.8rem">
                <span style="color:#94a3b8">POS URL: </span>
                <code style="background:#0f172a;padding:0.1rem 0.4rem;border-radius:3px;font-size:0.9rem">http://{{ $network['hostname'] }}:8000</code>
                <span class="copy-msg" onclick='copy("http://{{ $network['hostname'] }}:8000")'>[copy]</span>
            </div>
            <div style="font-size:0.7rem;color:#475569;margin-top:0.2rem">
                ⚠ Mobile (iOS/Android) ko dùng được computer name. Dùng IP bên dưới hoặc set static IP.
            </div>
            <div style="margin-top:0.3rem">
                <span style="font-size:0.75rem;color:#64748b">Current IPs:</span>
                @foreach($network['ips'] as $ip)
                    <span class="ip-badge" onclick="copy(this)" title="Click to copy">{{ $ip }}:8000</span>
                @endforeach
                <span class="copy-msg" onclick="copyAll()">[copy all]</span>
            </div>
        </div>

        @if(!empty($network['adapters']))
        <details style="margin-top:0.7rem;font-size:0.8rem">
            <summary style="cursor:pointer;color:#64748b">Network settings ⚙</summary>
            <div style="margin-top:0.5rem;padding:0.5rem;background:#0f172a;border-radius:4px">
                @foreach($network['adapters'] as $adapter)
                <div style="margin-bottom:0.7rem;padding-bottom:0.5rem;border-bottom:1px solid #1e293b">
                    <div style="font-weight:600;margin-bottom:0.3rem">{{ $adapter['name'] }}</div>
                    <div style="font-size:0.75rem;color:#94a3b8">
                        IP: {{ $adapter['ip'] ?: 'N/A' }} / {{ $adapter['subnet'] }} &nbsp;|&nbsp; Gateway: {{ $adapter['gateway'] ?: 'N/A' }}
                    </div>
                    @if(!empty($adapter['ip']))
                    <form method="POST" action="/edge-manager/action" style="margin-top:0.3rem" onsubmit="return confirm('Set static IP {{ $adapter['ip'] }} on {{ $adapter['name'] }}? Connection may drop.') && submitForm(this)">
                        @csrf
                        <input type="hidden" name="action" value="set-static-ip">
                        <input type="hidden" name="adapter" value="{{ $adapter['name'] }}">
                        <input type="hidden" name="ip" value="{{ $adapter['ip'] }}">
                        <input type="hidden" name="gateway" value="{{ $adapter['gateway'] }}">
                        <button class="btn btn-outline btn-sm">Set Static IP ({{ $adapter['ip'] }})</button>
                    </form>
                    @endif
                </div>
                @endforeach
                <div style="font-size:0.7rem;color:#475569">
                    * Static IP do router DHCP Reservation quản lý là an toàn hơn. Chỉ set static here nếu bạn biết rõ.
                </div>
            </div>
        </details>
        @endif
    </div>
    @endif

    <div class="section">
        <h2>Services</h2>
        <div>
            @foreach($status['services'] as $name => $svc)
            <div class="svc-row">
                <span class="dot {{ $svc['running'] ? 'green' : 'red' }}"></span>
                <span class="name">{{ $name }}</span>
                <span class="status" style="color:{{ $svc['running'] ? '#22c55e' : '#ef4444' }}">{{ $svc['running'] ? 'Running' : 'Stopped' }}</span>
            </div>
            @endforeach
        </div>
        <div class="btn-row" style="margin-top:0.75rem">
            <button class="btn btn-success btn-sm" onclick="confirmAction('start', 'Start all services?')">▶ Start All</button>
            <button class="btn btn-danger btn-sm" onclick="confirmAction('stop', 'Stop all services?')">■ Stop All</button>
            <button class="btn btn-warning btn-sm" onclick="confirmAction('restart', 'Restart all services?')">↻ Restart</button>
        </div>
    </div>

    <div class="section">
        <h2>Cloud Connection</h2>
        <form method="POST" action="/edge-manager/action" onsubmit="return submitForm(this)">
            @csrf
            <input type="hidden" name="action" value="connect-cloud">
            <div class="form-row">
                <label>Store ID</label>
                <input type="text" name="store_id" value="{{ $config['store_id'] }}" placeholder="STORE_001" style="max-width:200px">
            </div>
            <div class="form-row">
                <label>Cloud URL</label>
                <input type="url" name="cloud_url" value="{{ $config['cloud_url'] }}" placeholder="https://api.deltapos.cloud">
            </div>
            <div class="form-row">
                <label>API Key</label>
                <input type="text" name="api_key" value="" placeholder="Paste API key">
            </div>
            <div class="btn-row">
                <button class="btn btn-primary btn-sm">Connect & Save</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="genKey()">Generate New Key</button>
            </div>
        </form>
        <div id="key-result" style="margin-top:0.5rem;font-size:0.75rem;color:#22c55e;display:none"></div>
    </div>

    <div class="section">
        <h2>Sync <span style="font-weight:400;color:#475569;font-size:0.7rem">Box → Cloud</span></h2>
        <form method="POST" action="/edge-manager/action" style="margin-bottom:0.75rem">
            @csrf
            <input type="hidden" name="action" value="set-interval">
            <div class="form-row">
                <label>Upload every</label>
                <input type="text" name="sync_interval" value="{{ $config['sync_interval'] }}" placeholder="1m" style="max-width:120px;font-family:monospace">
                <span style="font-size:0.75rem;color:#64748b">e.g. 30s, 1m, 5m, 10m, 1h, 2h, 12h</span>
                <button class="btn btn-primary btn-sm">Save</button>
            </div>
        </form>
        <div class="btn-row">
            <form method="POST" action="/edge-manager/action" style="display:inline" onsubmit="return submitForm(this)">
                @csrf
                <input type="hidden" name="action" value="sync-now">
                <button class="btn btn-success btn-sm">↑ Sync Now (Box → Cloud)</button>
            </form>
            <form method="POST" action="/edge-manager/action" style="display:inline" onsubmit="return submitForm(this)">
                @csrf
                <input type="hidden" name="action" value="sync-master">
                <button class="btn btn-warning btn-sm">↓ Sync Data (Cloud → Box)</button>
            </form>
            <div style="font-size:0.75rem;color:#475569;width:100%;margin-top:0.3rem">
                Cloud → Box is manual only. Click button when you want to pull data.
            </div>
            <form method="POST" action="/edge-manager/action" style="display:inline" onsubmit="return submitForm(this)">
                @csrf
                <input type="hidden" name="action" value="backup-db">
                <button class="btn btn-outline btn-sm">💾 Backup Database</button>
            </form>
            <form method="POST" action="/edge-manager/action" style="display:inline" onsubmit="return confirm('⚠️ Reset database? ALL orders, products, settings will be lost. Auto-backup will be created.') && submitForm(this)">
                @csrf
                <input type="hidden" name="action" value="reset-db">
                <button class="btn btn-danger btn-sm" style="background:#7f1d1d">⚡ Reset Database</button>
            </form>
        </div>
    </div>

    <div class="section">
        <h2>Recent Logs <span style="font-weight:400;color:#475569;font-size:0.7rem">(last 30 lines)</span></h2>
        <div class="log-box" id="logBox">
            @php
                $logFile = storage_path('logs/laravel.log');
                $lines = ['No logs yet'];
                if (file_exists($logFile) && filesize($logFile) > 0) {
                    $f = fopen($logFile, 'r');
                    fseek($f, 0, SEEK_END);
                    $pos = ftell($f);
                    $buf = '';
                    $lineCount = 0;
                    $chunkSize = 4096;
                    while ($pos > 0 && $lineCount < 30) {
                        $readSize = min($chunkSize, $pos);
                        $pos -= $readSize;
                        fseek($f, $pos);
                        $buf = fread($f, $readSize) . $buf;
                        $lineCount = substr_count($buf, "\n");
                    }
                    fclose($f);
                    $allLines = array_filter(explode("\n", $buf));
                    $lines = array_slice($allLines, -30);
                }
            @endphp
            @foreach(array_reverse($lines) as $line)
                @php
                    preg_match('/^\[(.*?)\]\s*(production\.\w+\.)?(.*)/', $line, $m);
                    $time = $m[1] ?? '';
                    $level = $m[2] ?? '';
                    $msg = $m[3] ?? $line;
                    $cls = '';
                    if (str_contains($level, 'ERROR')) $cls = 'lvl-error';
                    elseif (str_contains($level, 'WARNING')) $cls = 'lvl-warning';
                @endphp
                <div class="log-entry"><span class="time">[{{ $time }}]</span> <span class="{{ $cls }}">{{ Str::limit(e(trim($msg)), 200) }}</span></div>
            @endforeach
        </div>
        <div style="margin-top:0.4rem;font-size:0.7rem;color:#475569">
            {{ storage_path('logs/laravel.log') }}
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<form id="actionForm" method="POST" action="/edge-manager/action" style="display:none">@csrf<input type="hidden" name="action" id="actionInput"></form>

<script>
function submitForm(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = '...'; }
    return true;
}

function confirmAction(action, msg) {
    if (!confirm(msg)) return;
    document.getElementById('actionInput').value = action;
    document.getElementById('actionForm').submit();
}

async function genKey() {
    const form = document.querySelector('form input[name="action"][value="gen-key"]')?.closest('form');
    if (!form) {
        const f = document.getElementById('actionForm');
        document.getElementById('actionInput').value = 'gen-key';
        f.submit();
    }
}

function copy(el) {
    const text = typeof el === 'string' ? el : el.textContent.trim();
    navigator.clipboard?.writeText(text).then(() => showToast('Copied: ' + text)).catch(() => {});
}

function copyAll() {
    const ips = [...document.querySelectorAll('.ip-badge')].map(el => el.textContent.trim()).join('\n');
    navigator.clipboard?.writeText(ips);
    showToast('Copied all IPs');
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 2000);
}

// Auto-dismiss alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => { a.style.opacity = '0'; a.style.transition = 'opacity .3s'; setTimeout(() => a.remove(), 300); });
}, 6000);
</script>
</body>
</html>
