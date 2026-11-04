$ErrorActionPreference = 'Stop'
$scriptPath = Join-Path $PSScriptRoot 'bootstrap_admin.php'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if (-not (Test-Path -LiteralPath $scriptPath -PathType Leaf)) { throw 'Bootstrap PHP entrypoint is missing.' }

$phpPath = $env:COREAXIS_PHP_CLI
if ([string]::IsNullOrWhiteSpace($phpPath)) {
    $phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($null -eq $phpCommand) { throw 'Set COREAXIS_PHP_CLI to the PHP CLI executable or add php.exe to PATH.' }
    $phpPath = $phpCommand.Source
}
if (-not (Test-Path -LiteralPath $phpPath -PathType Leaf)) { throw 'Configured PHP CLI executable was not found.' }
if (-not [string]::IsNullOrEmpty($env:COREAXIS_DB_MODE)) { throw 'COREAXIS_DB_MODE must be empty for first-time setup.' }

$secureOne = $null
$secureTwo = $null
$plainOne = $null
$plainTwo = $null
$ptrOne = [IntPtr]::Zero
$ptrTwo = [IntPtr]::Zero
$json = $null
$bytes = $null
$payload = $null
$stdout = $null
$stderr = $null
$diagnostic = $null
$process = $null
$processStarted = $false
$exitCode = 0
try {
    $database = Read-Host 'Type the exact empty database name from the external CoreAxis DB configuration'
    $databasePort = Read-Host 'Type the configured MySQL server port'
    $employeeId = Read-Host 'Initial Admin Employee ID (EMP followed by digits)'
    $fullName = Read-Host 'Admin full name'
    $department = Read-Host 'Admin department'
    $designation = Read-Host 'Admin designation'
    $joiningDate = Read-Host 'Joining date (YYYY-MM-DD)'
    $phone = Read-Host 'Phone number'
    $email = Read-Host 'Email address'
    $secureOne = Read-Host 'New Admin password (hidden)' -AsSecureString
    $secureTwo = Read-Host 'Confirm Admin password (hidden)' -AsSecureString
    $ptrOne = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureOne)
    $ptrTwo = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureTwo)
    $plainOne = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptrOne)
    $plainTwo = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptrTwo)
    if ($plainOne -cne $plainTwo) { throw 'Password confirmation did not match.' }

    $payload = [ordered]@{
        database = $database
        database_port = $databasePort
        employee_id = $employeeId
        full_name = $fullName
        department = $department
        designation = $designation
        joining_date = $joiningDate
        phone = $phone
        email = $email
        password = $plainOne
    }
    $json = ConvertTo-Json -InputObject $payload -Compress
    $utf8 = New-Object -TypeName System.Text.UTF8Encoding -ArgumentList $false
    $bytes = $utf8.GetBytes($json + "`n")

    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $phpPath
    $startInfo.Arguments = '"' + $scriptPath + '"'
    $startInfo.WorkingDirectory = $repoRoot
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $startInfo
    if (-not $process.Start()) { throw 'PHP CLI could not start.' }
    $processStarted = $true
    $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
    $process.StandardInput.BaseStream.Flush()
    $process.StandardInput.Close()
    [Array]::Clear($bytes, 0, $bytes.Length)
    $stdout = $process.StandardOutput.ReadToEnd()
    $stderr = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    if ($process.ExitCode -eq 0 -and $stdout -match '^PASS \[admin_bootstrap\] one_active_admin_created\s*$') {
        Write-Output 'PASS [admin_bootstrap] completed'
    } else {
        $diagnostic = $stdout + "`n" + $stderr
        $safeFailure = [regex]::Match($diagnostic, '(?m)^FAIL \[admin_bootstrap\] [a-z_]+$')
        if ($safeFailure.Success) { Write-Output $safeFailure.Value }
        else { Write-Output 'FAIL [admin_bootstrap] not_completed' }
        $exitCode = 1
    }
} finally {
    if ($processStarted -and $process -and -not $process.HasExited) { $process.Kill() }
    if ($null -ne $ptrOne -and $ptrOne -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptrOne) }
    if ($null -ne $ptrTwo -and $ptrTwo -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptrTwo) }
    if ($bytes -is [byte[]]) { [Array]::Clear($bytes, 0, $bytes.Length) }
    if ($secureOne) { $secureOne.Dispose() }
    if ($secureTwo) { $secureTwo.Dispose() }
    $plainOne = $null
    $plainTwo = $null
    $json = $null
    $payload = $null
    $stdout = $null
    $stderr = $null
    $diagnostic = $null
    $safeFailure = $null
    $bytes = $null
    $database = $null
    $databasePort = $null
    $employeeId = $null
    $fullName = $null
    $department = $null
    $designation = $null
    $joiningDate = $null
    $phone = $null
    $email = $null
    if ($process) { $process.Dispose() }
}
if ($exitCode -ne 0) { exit $exitCode }
