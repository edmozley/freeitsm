<#
.SYNOPSIS
    Collects hardware, software, and system inventory from the local machine and
    posts it to FreeITSM.

.DESCRIPTION
    Gathers hostname, manufacturer, model, CPU, memory, OS, BIOS, disk, network,
    GPU, TPM, BitLocker, and installed software information then sends the data as
    a JSON payload to the FreeITSM asset inventory API.

    Run as Administrator for full results (BitLocker, TPM, and some disk details
    require elevation).

.PARAMETER ApiUrl
    The base URL of your FreeITSM instance (e.g. https://itsm.yourcompany.com).

.PARAMETER ApiKey
    API key for authentication.

.PARAMETER OutputFile
    Optional path to save the JSON output to a file instead of (or in addition to)
    posting to the API.

.EXAMPLE
    .\Invoke-AssetInventory.ps1 -ApiUrl "https://itsm.yourcompany.com" -ApiKey "abc123"

.EXAMPLE
    .\Invoke-AssetInventory.ps1 -OutputFile "C:\Temp\asset.json"
#>

[CmdletBinding()]
param(
    [string]$ApiUrl,
    [string]$ApiKey,
    [string]$OutputFile
)

# Require at least one output destination
if (-not $ApiUrl -and -not $OutputFile) {
    Write-Host ""
    Write-Host "FreeITSM Asset Inventory Collector" -ForegroundColor Cyan
    Write-Host "-----------------------------------" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Usage:" -ForegroundColor Yellow
    Write-Host "  .\Invoke-AssetInventory.ps1 -ApiUrl `"https://itsm.yourcompany.com`" -ApiKey `"your-key`""
    Write-Host "  .\Invoke-AssetInventory.ps1 -OutputFile `"C:\Temp\asset.json`""
    Write-Host "  .\Invoke-AssetInventory.ps1 -ApiUrl `"https://itsm.yourcompany.com`" -ApiKey `"your-key`" -OutputFile `"C:\Temp\asset.json`""
    Write-Host ""
    Write-Host "Run as Administrator for full results (BitLocker, TPM)." -ForegroundColor Gray
    exit 1
}

$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

Write-Host "Collecting inventory data..." -ForegroundColor Cyan
if (-not $isAdmin) {
    Write-Host "  Note: Not running as Administrator. BitLocker and TPM data may be limited." -ForegroundColor Yellow
}

# ─── Core system info ───────────────────────────────────────────────────────────

$cs   = Get-CimInstance Win32_ComputerSystem
$os   = Get-CimInstance Win32_OperatingSystem
$bios = Get-CimInstance Win32_BIOS
$cpu  = Get-CimInstance Win32_Processor | Select-Object -First 1

# Feature release from registry (e.g. "23H2", "24H2")
$ntKey          = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion'
$featureRelease = (Get-ItemProperty -Path $ntKey -Name DisplayVersion -ErrorAction SilentlyContinue).DisplayVersion
$ubr            = (Get-ItemProperty -Path $ntKey -Name UBR -ErrorAction SilentlyContinue).UBR
$buildNumber    = if ($ubr) { "$($os.BuildNumber).$ubr" } else { "$($os.BuildNumber)" }

Write-Host "  System info collected" -ForegroundColor Green

# ─── Disks ───────────────────────────────────────────────────────────────────────

$logicalDisks = @(Get-CimInstance Win32_LogicalDisk -Filter "DriveType=3" | ForEach-Object {
    @{
        drive        = $_.DeviceID
        label        = $_.VolumeName
        file_system  = $_.FileSystem
        size_bytes   = $_.Size
        free_bytes   = $_.FreeSpace
        used_percent = if ($_.Size -and $_.Size -gt 0) {
            [math]::Round((($_.Size - $_.FreeSpace) / $_.Size) * 100, 1)
        } else { 0 }
    }
})

$physicalDisks = @(Get-CimInstance Win32_DiskDrive | ForEach-Object {
    @{
        model      = $_.Model
        serial     = if ($_.SerialNumber) { $_.SerialNumber.Trim() } else { $null }
        size_bytes = $_.Size
        media_type = $_.MediaType
        interface  = $_.InterfaceType
    }
})

Write-Host "  Disk info collected ($($logicalDisks.Count) logical, $($physicalDisks.Count) physical)" -ForegroundColor Green

# ─── Network adapters ────────────────────────────────────────────────────────────

$networkAdapters = @(Get-CimInstance Win32_NetworkAdapterConfiguration -Filter "IPEnabled=True" | ForEach-Object {
    @{
        name         = $_.Description
        mac_address  = $_.MACAddress
        ip_addresses = @($_.IPAddress)
        subnet_masks = @($_.IPSubnet)
        gateway      = @($_.DefaultIPGateway | Where-Object { $_ })
        dhcp_enabled = $_.DHCPEnabled
        dns_servers  = @($_.DNSServerSearchOrder | Where-Object { $_ })
    }
})

Write-Host "  Network info collected ($($networkAdapters.Count) adapters)" -ForegroundColor Green

# ─── GPU ─────────────────────────────────────────────────────────────────────────

$gpus = @(Get-CimInstance Win32_VideoController | ForEach-Object {
    @{
        name            = $_.Name
        driver_version  = $_.DriverVersion
        vram_bytes      = $_.AdapterRAM
        resolution      = "$($_.CurrentHorizontalResolution)x$($_.CurrentVerticalResolution)"
    }
})

Write-Host "  GPU info collected ($($gpus.Count) adapters)" -ForegroundColor Green

# ─── TPM ─────────────────────────────────────────────────────────────────────────

$tpm = $null
try {
    $tpmData = Get-CimInstance -Namespace "root\cimv2\Security\MicrosoftTpm" -ClassName Win32_Tpm -ErrorAction Stop
    if ($tpmData) {
        $tpm = @{
            version        = $tpmData.SpecVersion
            manufacturer   = $tpmData.ManufacturerIdTxt
            is_enabled     = $tpmData.IsEnabled_InitialValue
            is_activated   = $tpmData.IsActivated_InitialValue
        }
        Write-Host "  TPM info collected (v$($tpmData.SpecVersion))" -ForegroundColor Green
    }
} catch {
    Write-Host "  TPM info skipped (requires elevation or not present)" -ForegroundColor Gray
}

# ─── BitLocker ───────────────────────────────────────────────────────────────────

$bitlocker = @()
if ($isAdmin) {
    try {
        $bitlocker = @(Get-BitLockerVolume -ErrorAction Stop | ForEach-Object {
            @{
                drive             = $_.MountPoint
                protection_status = $_.ProtectionStatus.ToString()
                encryption_method = $_.EncryptionMethod.ToString()
                volume_status     = $_.VolumeStatus.ToString()
                lock_status       = $_.LockStatus.ToString()
            }
        })
        Write-Host "  BitLocker info collected ($($bitlocker.Count) volumes)" -ForegroundColor Green
    } catch {
        Write-Host "  BitLocker info skipped (not available)" -ForegroundColor Gray
    }
} else {
    Write-Host "  BitLocker info skipped (requires elevation)" -ForegroundColor Gray
}

# ─── Device Manager ─────────────────────────────────────────────────────────────

$devices = @()
try {
    # Get present PnP devices (matches what Device Manager shows)
    $pnpDevices = Get-PnpDevice -PresentOnly -Status OK, Error, Degraded -ErrorAction Stop |
        Where-Object { $_.Class -and $_.FriendlyName }

    # Build a lookup of driver info from Win32_PnPSignedDriver
    $driverLookup = @{}
    Get-CimInstance Win32_PnPSignedDriver -ErrorAction SilentlyContinue | ForEach-Object {
        if ($_.DeviceID) {
            $driverLookup[$_.DeviceID] = $_
        }
    }

    foreach ($dev in $pnpDevices) {
        $driver = $driverLookup[$dev.InstanceId]
        $devices += @{
            device_class   = $dev.Class
            device_name    = $dev.FriendlyName
            status         = $dev.Status
            manufacturer   = if ($driver) { $driver.DriverProviderName } else { $null }
            driver_version = if ($driver) { $driver.DriverVersion } else { $null }
            driver_date    = if ($driver -and $driver.DriverDate) {
                $driver.DriverDate.ToString("yyyy-MM-dd")
            } else { $null }
        }
    }
    Write-Host "  Device Manager info collected ($($devices.Count) devices)" -ForegroundColor Green
} catch {
    Write-Host "  Device Manager info skipped: $_" -ForegroundColor Yellow
}

# ─── Installed software ─────────────────────────────────────────────────────────

$regPaths = @(
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\*"
    "HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*"
)

$software = @(
    $regPaths | ForEach-Object {
        Get-ItemProperty -Path $_ -ErrorAction SilentlyContinue
    } | Where-Object {
        $_.DisplayName -and $_.DisplayName.Trim() -ne ''
    } | Sort-Object DisplayName -Unique | ForEach-Object {
        # SystemComponent=1 or ParentKeyName set = hidden from Add/Remove Programs
        $isComponent = ($_.SystemComponent -eq 1) -or ($_.ParentKeyName -and $_.ParentKeyName -ne '')

        @{
            display_name      = $_.DisplayName
            publisher         = $_.Publisher
            display_version   = $_.DisplayVersion
            install_date      = $_.InstallDate
            install_location  = $_.InstallLocation
            uninstall_string  = $_.UninstallString
            estimated_size    = if ($_.EstimatedSize) { "$($_.EstimatedSize)" } else { $null }
            system_component  = $isComponent
        }
    }
)

$appCount = ($software | Where-Object { -not $_.system_component }).Count
$componentCount = ($software | Where-Object { $_.system_component }).Count
Write-Host "  Software inventory collected ($appCount applications, $componentCount system components)" -ForegroundColor Green

# ─── Logged-in user ──────────────────────────────────────────────────────────────

$loggedInUser = $null
try {
    $explorerProc = Get-CimInstance Win32_Process -Filter "Name='explorer.exe'" -ErrorAction Stop | Select-Object -First 1
    if ($explorerProc) {
        $owner = Invoke-CimMethod -InputObject $explorerProc -MethodName GetOwner -ErrorAction Stop
        $loggedInUser = if ($owner.Domain) { "$($owner.Domain)\$($owner.User)" } else { $owner.User }
    }
} catch {
    $loggedInUser = $env:USERNAME
}

# ─── Last boot time ─────────────────────────────────────────────────────────────

$lastBoot = $os.LastBootUpTime.ToUniversalTime().ToString("yyyy-MM-dd HH:mm:ss")
$uptimeDays = [math]::Round(((Get-Date) - $os.LastBootUpTime).TotalDays, 1)

Write-Host "  Last boot: $lastBoot UTC (uptime: $uptimeDays days)" -ForegroundColor Green

# ─── Build the payload ───────────────────────────────────────────────────────────

$payload = [ordered]@{
    # Core asset fields (match assets table schema)
    hostname         = $env:COMPUTERNAME
    manufacturer     = $cs.Manufacturer
    model            = $cs.Model
    memory           = [long]$cs.TotalPhysicalMemory
    service_tag      = $bios.SerialNumber
    operating_system = $os.Caption -replace "Microsoft ", ""
    feature_release  = $featureRelease
    build_number     = $buildNumber
    cpu_name         = $cpu.Name
    speed            = [long]($cpu.MaxClockSpeed * 1000000)
    bios_version     = $bios.SMBIOSBIOSVersion
    domain           = $cs.Domain

    # Extended info
    logged_in_user   = $loggedInUser
    last_boot_utc    = $lastBoot
    uptime_days      = $uptimeDays

    # Disks
    disks = [ordered]@{
        logical  = $logicalDisks
        physical = $physicalDisks
    }

    # Network
    network_adapters = $networkAdapters

    # GPU
    gpus = $gpus

    # Security
    tpm              = $tpm
    bitlocker        = $bitlocker

    # Device Manager
    devices          = $devices

    # Software inventory
    software         = $software
}

$json = $payload | ConvertTo-Json -Depth 5 -Compress:$false

# ─── Output ──────────────────────────────────────────────────────────────────────

if ($OutputFile) {
    $json | Out-File -FilePath $OutputFile -Encoding UTF8 -Force
    Write-Host ""
    Write-Host "JSON saved to: $OutputFile" -ForegroundColor Green
}

if ($ApiUrl) {
    $url = "$($ApiUrl.TrimEnd('/'))/api/external/system-info/submit/"
    $headers = @{ 'Content-Type' = 'application/json'; 'Authorization' = '' }
    if ($ApiKey) { $headers['Authorization'] = $ApiKey }

    Write-Host ""
    Write-Host "Posting to $url ..." -ForegroundColor Cyan

    try {
        $response = Invoke-RestMethod -Uri $url -Method POST -Headers $headers -Body ([System.Text.Encoding]::UTF8.GetBytes($json)) -ContentType 'application/json; charset=utf-8'
        Write-Host "Success!" -ForegroundColor Green
        Write-Host ($response | ConvertTo-Json -Compress) -ForegroundColor Gray
    } catch {
        Write-Host "Error posting to API: $_" -ForegroundColor Red
        if ($_.Exception.Response) {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            Write-Host "Response: $($reader.ReadToEnd())" -ForegroundColor Red
        }
        exit 1
    }

    # Post device manager data separately
    if ($devices.Count -gt 0) {
        $dmUrl = "$($ApiUrl.TrimEnd('/'))/api/external/device-manager/submit/"
        $dmPayload = [ordered]@{
            hostname = $env:COMPUTERNAME
            devices  = $devices
        }
        $dmJson = $dmPayload | ConvertTo-Json -Depth 5 -Compress:$false

        Write-Host ""
        Write-Host "Posting device manager data to $dmUrl ..." -ForegroundColor Cyan
        try {
            $dmResponse = Invoke-RestMethod -Uri $dmUrl -Method POST -Headers $headers -Body ([System.Text.Encoding]::UTF8.GetBytes($dmJson)) -ContentType 'application/json; charset=utf-8'
            Write-Host "  Devices synced: $($dmResponse.devices_synced)" -ForegroundColor Green
        } catch {
            Write-Host "  Warning: Device manager POST failed: $_" -ForegroundColor Yellow
        }
    }
}

Write-Host ""
Write-Host "Done. Collected: $($software.Count) apps, $($logicalDisks.Count) drives, $($networkAdapters.Count) NICs, $($gpus.Count) GPUs, $($devices.Count) devices" -ForegroundColor Cyan
