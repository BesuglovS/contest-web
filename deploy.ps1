<#
.SYNOPSIS
  Deploy PHP site to remote server via SSH.
.DESCRIPTION
  Reads config from .env, packs project source via tar and syncs
  to remote server over SSH. Excludes dev files (DB, sandbox temps,
  .git, logs, IDE files). Sets write permissions on data/ and sandbox/
  after deploy.
.PARAMETER DryRun
  Show commands without executing.
.EXAMPLE
  .\deploy.ps1
  .\deploy.ps1 -DryRun
#>

param(
  [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

# ─── 1. Load .env ───
$envFile = Join-Path $PSScriptRoot '.env'
if (Test-Path $envFile) {
  Get-Content $envFile | ForEach-Object {
    if ($_ -match '^\s*([^#=]+?)\s*=\s*(.+?)\s*$') {
      [Environment]::SetEnvironmentVariable($matches[1], $matches[2])
    }
  }
}

$sshHost    = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_HOST')
$sshPort    = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_PORT')
if (-not $sshPort) { $sshPort = '22' }
$sshUser    = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_USER')
$remotePath = [Environment]::GetEnvironmentVariable('DEPLOY_REMOTE_PATH')
if ($remotePath) { $remotePath = $remotePath.TrimEnd('/') }

if (-not $sshHost -or -not $sshUser -or -not $remotePath) {
  Write-Host "ERROR: Set DEPLOY_SSH_HOST, DEPLOY_SSH_USER and DEPLOY_REMOTE_PATH in .env" -ForegroundColor Red
  exit 1
}

$webUser    = [Environment]::GetEnvironmentVariable('DEPLOY_WEB_USER')
if (-not $webUser) { $webUser = 'www-data' }

$identityFile = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_KEY')
$identityArg  = if ($identityFile) { "-i `"$identityFile`"" } else { '' }

$remote  = "${sshUser}@${sshHost}"
$portArg = if ($sshPort -ne '22') { "-P $sshPort" } else { '' }

# ─── Fix SSH key permissions (Windows OpenSSH requires restrictive ACLs) ───
if ($identityFile -and (Test-Path $identityFile)) {
  $identityFullPath = (Resolve-Path $identityFile).Path
  icacls $identityFullPath /reset           2>$null
  icacls $identityFullPath /inheritance:r    2>$null
  icacls $identityFullPath /grant "${env:USERDOMAIN}\${env:USERNAME}:(R)" 2>$null
}

# ─── 2. Pack & deploy via tar + ssh ───
$srcPath = $PSScriptRoot

# Exclusions matching .gitignore + dev artifacts
$excludeArgs = @(
  '--exclude=.git',
  '--exclude=.gitignore',
  '--exclude=.gitattributes',
  '--exclude=.vscode',
  '--exclude=.idea',
  '--exclude=*.swp',
  '--exclude=*.swo',
  '--exclude=*~',
  '--exclude=Thumbs.db',
  '--exclude=.DS_Store',
  '--exclude=Desktop.ini',
  '--exclude=Data',
  '--exclude=data',
  '--exclude=sandbox/lint_*',
  '--exclude=sandbox/run_*',
  '--exclude=sandbox/sbx_*',
  '--exclude=sandbox/*_wrapper.py',
  '--exclude=sandbox/*.txt',
  # Dev-скрипты ручного тестирования песочницы на проде не нужны
  '--exclude=sandbox/test_*.php',
  # tasks/*.json содержат скрытые тесты задач и не должны попадать в веб-корень
  '--exclude=tasks',
  '--exclude=*.log',
  '--exclude=php_errors.log',
  '--exclude=.env',
  '--exclude=deploy.ps1',
  '--exclude=contest.nayanovaacademy.ru',
  '--exclude=node_modules',
  '--exclude=.editorconfig',
  '--exclude=.prettierrc',
  '--exclude=.prettierignore'
) -join ' '

$tarCmd = "tar czf - $excludeArgs -C `"$srcPath`" ."

# ─── Раскладка как в python-web (сентябрь 2026): data/ не стирается и не
# восстанавливается — каталог и SQLite переживают деплой под www-data.
# Webroot очищается и распаковывается от deploy-пользователя (его основная
# группа — www-data, файлы каталогов tar читаются other-битами).
# Предыдущая схема (sqlite3 .backup + mv-своп + rm .old) опасна: слив
# нечитаемого для deploy contest.db (600 www-data после hardening) оставлял
# Предыдущая схема (sqlite3 .backup + mv-своп + rm -rf .old) была опасна:
# sqlite3 не читал contest.db под правами 600 www-data, бэкап молча
# пропускался, после чего своп и удаление .old уничтожали оригинал БД.
$remoteScript = "find \`"$remotePath\`" -mindepth 1 -maxdepth 1 ! -name 'data' -exec rm -rf {} + 2>/dev/null; " +
  "mkdir -p \`"$remotePath/data\`" 2>/dev/null; " +
  "tar -xzf - --skip-old-files -C \`"$remotePath\`""
$sshCmd = "ssh $portArg $identityArg $remote `"$remoteScript`""

Write-Host "`n==> Deploying to ${remote}:${remotePath} ..." -ForegroundColor Cyan

if ($DryRun) {
  Write-Host "  [DryRun] $tarCmd | $sshCmd" -ForegroundColor Yellow
} else {
  Write-Host "  Archiving and transferring..." -ForegroundColor Gray
  $pipe = "$tarCmd | $sshCmd"
  cmd /c $pipe
  if ($LASTEXITCODE -ne 0) {
    Write-Host "Deploy failed (exit code: $LASTEXITCODE)" -ForegroundColor Red
    exit 1
  }
  Write-Host "  Done." -ForegroundColor Green
}

# ─── 3. Deploy nginx config ───
$nginxSite = 'contest.nayanovaacademy.ru'
$nginxLocal = Join-Path $PSScriptRoot $nginxSite
$nginxRemote = '/etc/nginx/sites-available/' + $nginxSite

if ($DryRun) {
  Write-Host "  [DryRun] Deploy nginx config: $nginxSite" -ForegroundColor Yellow
} elseif (Test-Path $nginxLocal) {
  Write-Host "`n==> Deploying nginx config ($nginxSite) ..." -ForegroundColor Cyan
  $scpCmd = "scp $portArg $identityArg `"$nginxLocal`" ${remote}:/tmp/nginx-$nginxSite"
  $sshNginxCmd = "ssh $portArg $identityArg $remote `"sudo -n /usr/local/sbin/deploy-nginx.sh $nginxSite`""
  cmd /c $scpCmd
  if ($LASTEXITCODE -ne 0) { Write-Host "  Nginx config scp failed" -ForegroundColor Red; exit 1 }
  cmd /c $sshNginxCmd
  if ($LASTEXITCODE -ne 0) { Write-Host "  Nginx config install/reload failed" -ForegroundColor Red; exit 1 }
  Write-Host "  Done." -ForegroundColor Green
}

Write-Host "`n==> Deploy complete" -ForegroundColor Green