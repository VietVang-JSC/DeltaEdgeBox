<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class EdgeManagerController extends Controller
{
    private const SERVICES = ['EdgeBoxWeb', 'EdgeBoxSync', 'EdgeBoxPrint', 'EdgeBoxSchedule'];

    public function dashboard()
    {
        return view('edge-manager.dashboard', [
            'status' => $this->getServiceStatus(),
            'config' => $this->getConfig(),
            'network' => $this->getNetworkInfo(),
        ]);
    }

    public function action(Request $request)
    {
        $action = $request->input('action');
        $handlers = [
            'start' => fn() => $this->controlServices('start'),
            'stop' => fn() => $this->controlServices('stop'),
            'restart' => fn() => $this->controlServices('restart'),
            'connect-cloud' => fn() => $this->connectCloud($request),
            'set-store-id' => fn() => $this->setStoreId($request),
            'gen-key' => fn() => $this->generateKey(),
            'sync-now' => fn() => $this->runCommand('sync:worker', ['--batch' => 50]),
            'sync-master' => fn() => $this->runCommand('edge:sync-master', ['--force' => true]),
            'set-interval' => fn() => $this->setInterval($request),
            'backup-db' => fn() => $this->backupDatabase(),
            'reset-db' => fn() => $this->resetDatabase(),
            'set-static-ip' => fn() => $this->setStaticIp($request),
        ];
        $handler = $handlers[$action] ?? fn() => back()->with('error', 'Unknown action');
        try {
            return $handler();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function controlServices(string $cmd): \Illuminate\Http\RedirectResponse
    {
        $results = [];
        $allOk = true;
        foreach (self::SERVICES as $svc) {
            exec("nssm $cmd $svc 2>&1", $output, $code);
            $results[] = "$svc: " . ($code === 0 ? 'OK' : implode(' ', $output));
            if ($code !== 0) $allOk = false;
        }
        $msg = implode("\n", $results);
        return back()->with($allOk ? 'success' : 'error', $msg);
    }

    private function getServiceStatus(): array
    {
        $services = [];
        foreach (self::SERVICES as $name) {
            exec("nssm status $name 2>&1", $output, $code);
            $running = $code === 0 && str_contains(implode(' ', $output), 'RUNNING');
            $services[$name] = ['running' => $running, 'raw' => implode(' ', $output)];
        }
        $allRunning = collect($services)->every(fn($s) => $s['running']);

        return [
            'all_running' => $allRunning,
            'services' => $services,
            'php_version' => PHP_VERSION,
            'db_size' => $this->getDbSize(),
            'sync_queue' => \App\Models\SyncQueue::where('status', 'pending')->count(),
        ];
    }

    private function getConfig(): array
    {
        return [
            'cloud_url' => config('edge_box.cloud_api_url'),
            'sync_interval' => config('edge_box.sync_interval', '1m'),
            'has_api_key' => !empty(config('edge_box.api_key')),
            'store_id' => config('edge_box.store_id'),
        ];
    }

    private function getNetworkInfo(): array
    {
        $ips = [];
        exec("powershell -Command \"Get-NetIPAddress -AddressFamily IPv4 | Where-Object { \$_.InterfaceAlias -notlike '*Loopback*' } | Select-Object -ExpandProperty IPAddress\" 2>&1", $output);
        foreach ($output as $line) {
            $line = trim($line);
            if (!empty($line) && filter_var($line, FILTER_VALIDATE_IP)) {
                $ips[] = $line;
            }
        }
        $hostname = trim(exec('hostname') ?: 'DELTAPOS-EDGE');

        // Get network adapters for static IP config
        $adapters = [];
        exec("powershell -Command \"Get-NetAdapter -Physical | Where-Object Status -eq 'Up' | ForEach-Object { \$adapter = \$_.Name; \$ip = Get-NetIPAddress -InterfaceAlias \$adapter -AddressFamily IPv4 -ErrorAction SilentlyContinue; \$gw = Get-NetRoute -InterfaceAlias \$adapter -DestinationPrefix '0.0.0.0/0' -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty NextHop; [PSCustomObject]@{ Name = \$adapter; IP = \$ip.IPAddress; Subnet = \$ip.PrefixLength; Gateway = \$gw } } | ConvertTo-Json\" 2>&1", $jsonOut);
        $json = implode('', $jsonOut);
        $parsed = json_decode($json, true);
        if ($parsed) {
            $items = isset($parsed[0]) ? $parsed : [$parsed];
            foreach ($items as $a) {
                if (!empty($a['Name'])) {
                    $adapters[] = [
                        'name' => $a['Name'],
                        'ip' => $a['IP'] ?? '',
                        'subnet' => $a['Subnet'] ?? '',
                        'gateway' => $a['Gateway'] ?? '',
                    ];
                }
            }
        }
        return ['ips' => $ips, 'hostname' => $hostname, 'adapters' => $adapters];
    }

    private function setStaticIp(Request $request): \Illuminate\Http\RedirectResponse
    {
        $adapter = trim($request->input('adapter', ''));
        $ip = trim($request->input('ip', ''));
        $gateway = trim($request->input('gateway', ''));

        if (empty($adapter) || empty($ip)) {
            return back()->with('error', 'Adapter name and IP are required');
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return back()->with('error', 'Invalid IP address');
        }
        if (!empty($gateway) && !filter_var($gateway, FILTER_VALIDATE_IP)) {
            return back()->with('error', 'Invalid gateway address');
        }

        $escAdapter = escapeshellarg($adapter);
        $escIp = escapeshellarg($ip);
        $escGw = escapeshellarg($gateway);

        try {
            exec("netsh interface ip show config $escAdapter 2>&1", $configOut);
            $configStr = implode("\n", $configOut);

            $subnet = '255.255.255.0';
            if (preg_match('/Subnet Prefix:\s+\S+\/(\d+)/', $configStr, $m)) {
                $prefix = max(1, min(30, (int)$m[1]));
                $subnet = long2ip(-1 << (32 - $prefix));
            }

            $dnsServers = [];
            if (preg_match_all('/DNS Servers[^:]*:\s+(\S+)/', $configStr, $m)) {
                $dnsServers = array_filter($m[1], fn($d) => filter_var($d, FILTER_VALIDATE_IP));
            }
            if (empty($dnsServers) && !empty($gateway)) {
                $dnsServers = [$gateway];
            }
            if (empty($dnsServers)) {
                $dnsServers = ['8.8.8.8', '1.1.1.1'];
            }

            $setCmd = "netsh interface ip set address $escAdapter static $escIp $subnet";
            if (!empty($gateway)) $setCmd .= " $escGw 1";
            exec("$setCmd 2>&1", $out, $code);

            if ($code !== 0 && str_contains(implode(' ', $out), 'elevation')) {
                $psCmd = "Start-Process -FilePath netsh -ArgumentList 'interface ip set address $escAdapter static $escIp $subnet";
                if (!empty($gateway)) $psCmd .= " $escGw 1";
                $psCmd .= "' -Verb RunAs -Wait";
                exec("powershell -Command \"$psCmd\" 2>&1", $out, $code);
            }

            if ($code !== 0) {
                $errMsg = implode("\n", $out);
                return back()->with('error', "Set IP failed: $errMsg");
            }

            $escDns1 = escapeshellarg($dnsServers[0]);
            exec("netsh interface ip set dns $escAdapter static $escDns1 2>&1");

            if (count($dnsServers) > 1) {
                $escDns2 = escapeshellarg($dnsServers[1]);
                exec("netsh interface ip add dns $escAdapter $escDns2 index=2 2>&1");
            }

            return back()->with('success', "Static IP $ip set on $adapter. DNS preserved. Re-connect using $ip:8000.");

        } catch (\Throwable $e) {
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    private function setStoreId(Request $request): \Illuminate\Http\RedirectResponse
    {
        $id = trim($request->input('store_id', ''));
        if (empty($id)) return back()->with('error', 'Store ID is required');
        $this->updateEnv('STORE_ID', $id);
        return back()->with('success', "Store ID set to $id");
    }

    private function connectCloud(Request $request): \Illuminate\Http\RedirectResponse
    {
        $url = rtrim($request->input('cloud_url', ''), '/');
        $key = $request->input('api_key', '');
        $storeId = trim($request->input('store_id', ''));

        if (empty($url)) return back()->with('error', 'Cloud URL is required');
        if (empty($key)) return back()->with('error', 'API Key is required');

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'X-Edge-Api-Key' => $key,
        ])->timeout(10)->get("$url/api/health");

        if (!$response->successful()) {
            return back()->with('error', "Cloud returned HTTP {$response->status()}. Check URL and key.");
        }
        $this->updateEnv('CLOUD_API_URL', $url);
        $this->updateEnv('API_KEY', $key);
        $this->updateEnv('EDGE_BOX_API_KEY', $key);
        if (!empty($storeId)) $this->updateEnv('STORE_ID', $storeId);
        return back()->with('success', "Connected to $url");
    }

    private function generateKey(): \Illuminate\Http\RedirectResponse
    {
        $key = Str::random(32);
        $this->updateEnv('API_KEY', $key);
        $this->updateEnv('EDGE_BOX_API_KEY', $key);
        return back()->with('success', "New API key generated: $key");
    }

    private function runCommand(string $command, array $params): \Illuminate\Http\RedirectResponse
    {
        try {
            Artisan::call($command, $params);
            $output = Artisan::output();
            $summary = !empty(trim($output)) ? trim($output) : "$command completed";
            return back()->with('success', $summary);
        } catch (\Throwable $e) {
            return back()->with('error', "$command failed: " . $e->getMessage());
        }
    }

    private function setInterval(Request $request): \Illuminate\Http\RedirectResponse
    {
        $raw = trim($request->input('sync_interval', '1m'));
        if (!preg_match('/^\d+\s*(s|sec|m|min|h|d)$/i', $raw)) {
            return back()->with('error', 'Invalid format. Use e.g. 1m, 5m, 10m, 1h, 2h, 12h, 30s');
        }
        $this->updateEnv('SYNC_INTERVAL', $raw);
        exec('nssm restart EdgeBoxSchedule 2>&1', $out, $code);
        $svcMsg = $code === 0 ? ' Scheduler restarted.' : '';
        return back()->with('success', "Sync every $raw.$svcMsg");
    }

    private function backupDatabase(): \Illuminate\Http\RedirectResponse
    {
        $dbPath = database_path('database.sqlite');
        if (!file_exists($dbPath)) return back()->with('error', 'Database not found');
        $backupDir = storage_path('app/backup');
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
        $backupFile = "$backupDir/delta-pos-" . date('Y-m-d-His') . '.sqlite';
        if (!copy($dbPath, $backupFile)) return back()->with('error', 'Backup failed');
        $size = round(filesize($backupFile) / 1048576, 2);
        return back()->with('success', "Backup created ($size MB)");
    }

    private function resetDatabase(): \Illuminate\Http\RedirectResponse
    {
        $dbPath = database_path('database.sqlite');
        $backupDir = storage_path('app/backup');

        // Auto-backup before reset
        if (file_exists($dbPath)) {
            if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
            $backupFile = "$backupDir/pre-reset-" . date('Y-m-d-His') . '.sqlite';
            copy($dbPath, $backupFile);
        }

        try {
            Artisan::call('db:reset', ['--force' => true]);
            return back()->with('success', 'Database reset complete. Migrations re-run.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Reset failed: ' . $e->getMessage());
        }
    }

    private function getDbSize(): string
    {
        $dbPath = database_path('database.sqlite');
        return file_exists($dbPath) ? round(filesize($dbPath) / 1048576, 2) . ' MB' : 'N/A';
    }

    private function updateEnv(string $key, string $value): void
    {
        $envFile = base_path('.env');
        if (!file_exists($envFile)) {
            file_put_contents($envFile, "# DeltaPOS Edge Box\n");
        }
        $content = file_get_contents($envFile);
        $escaped = str_replace('"', '\\"', $value);
        $escaped = str_replace('$', '\\$', $escaped);
        if (preg_match("/^$key=/m", $content)) {
            $content = preg_replace("/^$key=.*/m", "$key=\"$escaped\"", $content);
        } else {
            $content .= "$key=\"$escaped\"\n";
        }
        file_put_contents($envFile, $content);
    }
}
