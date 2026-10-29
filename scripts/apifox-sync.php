<?php
/**
 * apifox-openapi-sync —— 通用 Apifox OpenAPI 同步脚本（可移植到任意 PHP 项目）
 *
 * 设计目标：仅凭 .env 中的 APIFOX_* 配置即可把 OpenAPI 规范推送到指定 Apifox 项目。
 * 与具体业务解耦：它只负责「读配置 → 定位/校验规范 → 定位 CLI → 推送」，
 * 规范的生成交给调用方（项目自带 generator 或注解/Swagger 产出）。
 *
 * 用法：
 *   php apifox-sync.php [--spec=<path>] [--dry-run] [--module-title=<t>] [--module-id=<id>] [--env=<path>]
 *
 * 依赖：PHP 7.4+；宿主机已 `npm i -g apifox-cli`（apifox 2.2.12 验证）。
 *
 * .env 配置（全部可选，缺省有兜底）：
 *   APIFOX_PROJECT_ID      目标项目 ID（必填，否则报错）
 *   APIFOX_TOKEN           CLI access-token（必填，否则报错）
 *   APIFOX_ENABLED         总开关，非 true 则跳过（默认 true）
 *   APIFOX_SKIP_UPLOAD     只生成/校验规范不上传（默认 false）
 *   APIFOX_MODULE_ID       --module-map 目标模块 ID（默认 ''，留空则落到默认模块）
 *   APIFOX_MODULE_TITLE    规范 info.title（默认读取 spec 的 info.title）
 *   APIFOX_SPEC_FILE       已生成的 openapi json 路径（默认 ./openapi.json 或 ./scripts/openapi*.json）
 *   APIFOX_IMPORT_MODE     保留兼容项（CLI import 实际行为由 --module-map 决定，本脚本忽略）
 */

// ---------- 参数解析 ----------
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $kv = substr($a, 2);
        if (str_contains($kv, '=')) {
            [$k, $v] = explode('=', $kv, 2);
            $opts[$k] = $v;
        } else {
            $opts[$kv] = true;
        }
    }
}
$dryRun = isset($opts['dry-run']) || isset($opts['dry-run']);
$envPath = $opts['env'] ?? null;
if (! $envPath) {
    // 优先探测「运行目录（项目根）」的 .env，其次脚本所在项目根的 .env
    foreach ([getcwd() . '/.env', __DIR__ . '/../../.env'] as $c) {
        if (is_file($c)) {
            $envPath = $c;
            break;
        }
    }
    $envPath = $envPath ?? (getcwd() . '/.env');
}
$specArg = $opts['spec'] ?? null;
$moduleTitleArg = $opts['module-title'] ?? null;
$moduleIdArg = $opts['module-id'] ?? null;

// ---------- 读 .env ----------
function loadEnvFile(string $path): array
{
    $vars = [];
    if (! is_file($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $vars[trim($k)] = trim($v);
        }
    }
    return $vars;
}

$env = loadEnvFile($envPath);
$projectId = $env['APIFOX_PROJECT_ID'] ?? '';
$token = $env['APIFOX_TOKEN'] ?? '';
$enabled = ($env['APIFOX_ENABLED'] ?? 'true') === 'true';
$skipUpload = ($env['APIFOX_SKIP_UPLOAD'] ?? 'false') === 'true';
$moduleId = $moduleIdArg ?? ($env['APIFOX_MODULE_ID'] ?? '');
$moduleTitle = $moduleTitleArg ?? ($env['APIFOX_MODULE_TITLE'] ?? '');
$specFromEnv = $env['APIFOX_SPEC_FILE'] ?? '';

echo "读取 .env: {$envPath}\n";
echo "  APIFOX_PROJECT_ID = " . ($projectId ?: '(空)') . "\n";
echo "  APIFOX_TOKEN      = " . ($token ? '(已配置)' : '(空)') . "\n";
echo "  APIFOX_ENABLED    = " . ($enabled ? 'true' : 'false') . "\n";
echo "  APIFOX_SKIP_UPLOAD= " . ($skipUpload ? 'true' : 'false') . "\n";

// ---------- 定位规范文件 ----------
$specFile = $specArg ?? $specFromEnv;
if (! $specFile || ! is_file($specFile)) {
    $candidates = [
        getcwd() . '/openapi.json',
        getcwd() . '/scripts/openapi.json',
    ];
    // 通配 scripts/openapi*.json
    foreach (glob(getcwd() . '/scripts/openapi*.json') as $f) {
        $candidates[] = $f;
    }
    foreach ($candidates as $c) {
        if (is_file($c)) {
            $specFile = $c;
            break;
        }
    }
}
if (! $specFile || ! is_file($specFile)) {
    fwrite(STDERR, "找不到 OpenAPI 规范文件（用 --spec 指定，或设置 APIFOX_SPEC_FILE）。\n");
    exit(1);
}
echo "规范文件: {$specFile} (" . filesize($specFile) . " bytes)\n";

// ---------- 校验 JSON ----------
$raw = file_get_contents($specFile);
$decoded = json_decode($raw, true);
if (! is_array($decoded)) {
    fwrite(STDERR, "规范不是合法 JSON：" . json_last_error_msg() . "\n");
    exit(1);
}
if (empty($decoded['openapi']) || empty($decoded['paths'])) {
    fwrite(STDERR, "规范缺少 openapi 版本或 paths，无法推送。\n");
    exit(1);
}
$pathCount = count($decoded['paths']);
echo "规范校验通过：openapi={$decoded['openapi']}, paths={$pathCount}\n";

if (! $moduleTitle && isset($decoded['info']['title'])) {
    $moduleTitle = $decoded['info']['title'];
}

// ---------- 开关 ----------
if (! $enabled) {
    echo "APIFOX_ENABLED=false -> 跳过（配置关闭）\n";
    exit(0);
}
if ($skipUpload || $dryRun) {
    echo "APIFOX_SKIP_UPLOAD=true" . ($dryRun ? " / --dry-run" : "") . " -> 仅校验，不推送\n";
}

// ---------- 校验必填 ----------
if (! $projectId || ! $token) {
    fwrite(STDERR, "缺少 APIFOX_PROJECT_ID / APIFOX_TOKEN，无法推送。\n");
    exit(1);
}

// ---------- 定位 CLI（跨平台） ----------
if (stripos(PHP_OS, 'WIN') === 0) {
    $out = trim((string) shell_exec('where apifox 2>NUL'));
    $apifoxBin = $out !== '' ? explode("\n", $out)[0] : '';
} else {
    $apifoxBin = trim((string) shell_exec('command -v apifox 2>/dev/null || command -v apifox-cli 2>/dev/null'));
}
if ($apifoxBin === '') {
    fwrite(STDERR, "未找到 apifox CLI（请先：npm i -g apifox-cli）。规范已校验，可手动推送。\n");
    exit(0);
}
echo "apifox CLI: {$apifoxBin}\n";

if ($skipUpload || $dryRun) {
    $mapNote = $moduleTitle ? "（将用 --module-map \"{$moduleTitle}={$moduleId}\" 避免重复模块）" : '';
    echo "DRY-RUN 完成：将推送 project={$projectId} file={$specFile} {$mapNote}\n";
    exit(0);
}

// ---------- 正式推送 ----------
$args = [
    'import',
    '--project', $projectId,
    '--format', 'openapi',
    '--file', $specFile,
    '--access-token', $token,
];
if ($moduleTitle) {
    $args[] = '--module-map';
    $args[] = $moduleTitle . '=' . $moduleId;
}
$cmd = $apifoxBin . ' ' . implode(' ', array_map('escapeshellarg', $args));
echo "正在推送到 Apifox (project={$projectId}" . ($moduleTitle ? ", module-map={$moduleTitle}={$moduleId}" : "") . ")...\n";
$output = (string) shell_exec($cmd . ' 2>&1');
echo "Apifox 响应:\n" . ($output ?: '(空)') . "\n";
