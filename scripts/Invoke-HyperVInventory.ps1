<#
.SYNOPSIS
    Reads Hyper-V hosts, Failover Clusters, cluster roles and VMs, and sends them to
    FreeITSM (GH #199). READ-ONLY: it only ever calls Get-* cmdlets and CIM reads.

.DESCRIPTION
    Runs on a Windows management server as a scheduled task - nothing is installed on the
    Hyper-V hosts, and FreeITSM never connects to them. Each run reads the clusters and
    standalone hosts you name and posts one report per scope to
    /api/v1/virtual-estate/{ConnectionId}/reports, with the key the connection was given
    in Assets -> Settings -> Hyper-V.

    What it reads:
      Get-Cluster, Get-ClusterQuorum, Get-ClusterNode, Get-ClusterGroup,
      Get-ClusterResource, Get-ClusterParameter, Get-ClusterOwnerNode   (FailoverClusters)
      Get-VMHost, Get-VM, Get-VMHardDiskDrive, Get-VHD,
      Get-VMNetworkAdapter, Get-VMNetworkAdapterVlan                     (Hyper-V)
      Win32_ComputerSystemProduct (the hardware UUID), Win32_OperatingSystem   (CIM)
      Msvm_KvpExchangeComponent (guest OS names, root/virtualization/v2)       (CIM)

    VM operating systems come from the guest's OSName integration-service KVP item,
    matched by VM Id. Missing or unreadable guest data stays unknown; the host's
    operating system is never used as a guest OS fallback.

    A host or cluster it cannot read is NAMED in the report as unreadable - FreeITSM then
    keeps what it knew about it, marked "not confirmed", instead of treating its VMs as gone.
    Anything it could not read about a VM is sent as unknown (null), never as 0 or empty.

    SUPPORTED: Windows PowerShell 5.1 on Windows Server 2016 or later. PowerShell 7.4+ is
    expected to work (Hyper-V loads natively, FailoverClusters through Windows PowerShell
    compatibility) but has not been proven on a real estate. NOT YET PROVEN ON A REAL
    HYPER-V ESTATE AT ALL: it was written against Microsoft's documentation and tested with
    mocked cmdlets. Run -TestAccess and -OutputFile first.

    PERMISSIONS (read only):
      Each cluster:  Grant-ClusterAccess -Cluster <name> -User <account> -ReadOnly
      Each host:     the local Hyper-V Administrators group works, but also allows changes
                     this script never makes. Enable Account + Remote Enable on the
                     root\virtualization\v2 and root\cimv2 WMI namespaces, plus Remote
                     Management Users, may be enough. -TestAccess shows what works.
    A group-managed service account is the natural choice.

.PARAMETER ApiUrl
    FreeITSM's address, e.g. https://itsm.example.com/freeitsm-app

.PARAMETER ConnectionId
    The connection's number, shown in Assets -> Settings -> Hyper-V.

.PARAMETER ApiKey
    The connection's key (fitsm_...). Only that key is accepted for this connection.

.PARAMETER Cluster
    Failover Cluster names to read.

.PARAMETER StandaloneHost
    Hyper-V hosts that are not in a cluster.

.PARAMETER ReportPer
    All (default): one report for everything. Cluster: one report per cluster and one for
    the standalone hosts - better for a large estate (an 8,000-VM report is about 7.5 MB,
    close to PHP's default post_max_size of 8 MB), and one unreachable cluster cannot hold
    the others back.

.PARAMETER OutputFile
    Write the report(s) to this file instead of, or as well as, sending. With -ReportPer
    Cluster each scope gets its own file (name-<scope>.json).

.PARAMETER Redact
    Replace names, addresses, MAC addresses, paths, identifiers and error text with stable
    placeholders, keyed on a secret kept in %ProgramData%\FreeITSM\redact.key - so the same
    VM gets the same placeholder in every report. A REDACTED REPORT STILL NEEDS A PERSON TO
    READ IT before it is shared: no filter catches everything.

.PARAMETER TestAccess
    Try every read against every cluster and host and print what worked and what was
    refused. Sends nothing, writes nothing.

.PARAMETER CertificateThumbprint
    Accept only the server certificate with this SHA-1 fingerprint (an internal FreeITSM
    with a self-signed certificate). Same as the inventory agent.

.PARAMETER SkipCertificateCheck
    Accept any certificate. Lab use only - it exposes the key.

.EXAMPLE
    .\Invoke-HyperVInventory.ps1 -Cluster CL-PROD,CL-DR -StandaloneHost HV-LAB01 -TestAccess

.EXAMPLE
    .\Invoke-HyperVInventory.ps1 -Cluster CL-PROD -OutputFile C:\Temp\hyperv.json -Redact

.EXAMPLE
    .\Invoke-HyperVInventory.ps1 -ApiUrl "https://itsm.example.com" -ConnectionId 3 -ApiKey "fitsm_..." -Cluster CL-PROD,CL-DR -StandaloneHost HV-LAB01 -ReportPer Cluster
#>

[CmdletBinding()]
param(
    [string]$ApiUrl,
    [int]$ConnectionId,
    [string]$ApiKey,
    [string[]]$Cluster = @(),
    [string[]]$StandaloneHost = @(),
    [ValidateSet('All', 'Cluster')]
    [string]$ReportPer = 'All',
    [string]$OutputFile,
    [switch]$Redact,
    [switch]$TestAccess,
    [string]$CertificateThumbprint,
    [switch]$SkipCertificateCheck
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'
$CollectorVersion = '1.0.1'

if (-not $Cluster -and -not $StandaloneHost) {
    Write-Host "Name at least one -Cluster or -StandaloneHost." -ForegroundColor Red
    exit 1
}
if (-not $TestAccess -and -not $OutputFile -and -not ($ApiUrl -and $ConnectionId -and $ApiKey)) {
    Write-Host "To send, give -ApiUrl, -ConnectionId and -ApiKey. Or use -OutputFile to write the report, or -TestAccess to check access." -ForegroundColor Red
    exit 1
}
foreach ($cmd in @('Get-VM', 'Get-VMHost')) {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
        Write-Host "The Hyper-V PowerShell module is not installed here: Install-WindowsFeature Hyper-V-PowerShell" -ForegroundColor Red
        exit 1
    }
}
if ($Cluster -and -not (Get-Command 'Get-Cluster' -ErrorAction SilentlyContinue)) {
    Write-Host "The Failover Clustering PowerShell module is not installed here: Install-WindowsFeature RSAT-Clustering-PowerShell" -ForegroundColor Red
    exit 1
}
$pinnedThumbprint = $null
if ($CertificateThumbprint) {
    $pinnedThumbprint = ($CertificateThumbprint -replace '[^0-9A-Fa-f]', '').ToUpperInvariant()
    if ($pinnedThumbprint.Length -ne 40) {
        Write-Host "-CertificateThumbprint should be a 40-character SHA-1 fingerprint." -ForegroundColor Red
        exit 1
    }
}

# ─── Redaction ──────────────────────────────────────────────────────────────────
# Keyed (HMAC) so a placeholder cannot be reversed by guessing names, and stable across
# runs so a sequence of redacted reports still tells one VM from another.
$script:RedactKey = $null
if ($Redact) {
    $dir = Join-Path $env:ProgramData 'FreeITSM'
    $keyFile = Join-Path $dir 'redact.key'
    if (-not (Test-Path $keyFile)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        $bytes = New-Object byte[] 32
        [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
        [IO.File]::WriteAllText($keyFile, [Convert]::ToBase64String($bytes))
    }
    $script:RedactKey = [Convert]::FromBase64String([IO.File]::ReadAllText($keyFile).Trim())
}

function Get-Hash([string]$Value) {
    $h = New-Object Security.Cryptography.HMACSHA256 (, $script:RedactKey)
    return (($h.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value.ToLowerInvariant())) | ForEach-Object { $_.ToString('x2') }) -join '')
}
# TRAP: not called R - "r" is a built-in alias for Invoke-History, and an alias beats a
# function, so R 'host' $name silently ran Invoke-History instead of redacting.
function Mask([string]$Kind, $Value) {
    if (-not $Redact -or $null -eq $Value -or "$Value" -eq '') { return $Value }
    return ($Kind + '-' + (Get-Hash "$Kind|$Value").Substring(0, 8))
}
function RGuid($Value) {
    if (-not $Redact -or $null -eq $Value -or "$Value" -eq '') { return $Value }
    $x = Get-Hash "guid|$Value"
    return ($x.Substring(0, 8) + '-' + $x.Substring(8, 4) + '-' + $x.Substring(12, 4) + '-' + $x.Substring(16, 4) + '-' + $x.Substring(20, 12))
}
function RError([string]$Message) {
    if ($Redact) { return 'redacted - see the collector''s own output on the management server' }
    return $Message
}

function Get-Str($Value) { if ($null -eq $Value) { return $null }; return "$Value" }

# ─── Reading ────────────────────────────────────────────────────────────────────

$script:Access = New-Object System.Collections.ArrayList
function Try-Read([string]$Target, [string]$What, [scriptblock]$Read) {
    try {
        $r = & $Read
        [void]$script:Access.Add([pscustomobject]@{ Target = $Target; Read = $What; Result = 'OK' })
        return $r
    } catch {
        [void]$script:Access.Add([pscustomobject]@{ Target = $Target; Read = $What; Result = "REFUSED: $($_.Exception.Message)" })
        throw
    }
}

# One host: its hardware UUID, its VMs with their disks and adapters. Throws if the host
# cannot be read at all; a VM detail that cannot be read is sent as null.
function Read-HyperVHost([string]$HostName, $ClusterId, [hashtable]$ClusterVmInfo) {
    $uuid = $null
    $osName = $null
    try {
        $p = Try-Read $HostName 'hardware UUID (Win32_ComputerSystemProduct)' { Get-CimInstance -ClassName Win32_ComputerSystemProduct -ComputerName $HostName }
        $uuid = Get-Str $p.UUID
    } catch { }
    try {
        $o = Try-Read $HostName 'operating system (Win32_OperatingSystem)' { Get-CimInstance -ClassName Win32_OperatingSystem -ComputerName $HostName }
        $osName = Get-Str $o.Caption
    } catch { }
    $vmHost = Try-Read $HostName 'Get-VMHost' { Get-VMHost -ComputerName $HostName }
    $vms = @(Try-Read $HostName 'Get-VM' { Get-VM -ComputerName $HostName })

    # One optional read per host, not one remote call per VM. SystemName is the
    # Msvm_ComputerSystem Name (the VM Id), not the guest or host's display name.
    $guestOsById = @{}
    $guestKvp = @()
    try {
        $guestKvp = @(Try-Read $HostName 'guest operating systems (Msvm_KvpExchangeComponent)' {
            Get-CimInstance -Namespace 'root/virtualization/v2' -ClassName Msvm_KvpExchangeComponent -ComputerName $HostName
        })
    } catch { } # KVP being unavailable does not make the host or its VMs unreadable.
    $xmlSettings = New-Object System.Xml.XmlReaderSettings
    $xmlSettings.DtdProcessing = [System.Xml.DtdProcessing]::Prohibit
    $xmlSettings.XmlResolver = $null
    $xmlSettings.CloseInput = $true
    foreach ($component in $guestKvp) {
        $guestId = Get-Str $component.SystemName
        if ([string]::IsNullOrWhiteSpace($guestId)) { continue }
        foreach ($item in @($component.GuestIntrinsicExchangeItems)) {
            $reader = $null
            try {
                # Guest-provided XML must not resolve external entities or a DTD.
                $reader = [System.Xml.XmlReader]::Create((New-Object System.IO.StringReader ([string]$item)), $xmlSettings)
                $xml = New-Object System.Xml.XmlDocument
                $xml.XmlResolver = $null
                $xml.Load($reader)
                $value = $xml.SelectSingleNode('/INSTANCE[PROPERTY[@NAME="Name"]/VALUE="OSName"]/PROPERTY[@NAME="Data"]/VALUE')
                if ($value -and -not [string]::IsNullOrWhiteSpace($value.InnerText)) {
                    $guestOsById[$guestId.ToLowerInvariant()] = $value.InnerText.Trim()
                    break
                }
            } catch { } # A bad optional item must not hide other guest data or VMs.
            finally {
                if ($null -ne $reader) { $reader.Dispose() }
            }
        }
    }

    $hostItem = [ordered]@{
        id = (RGuid $uuid); name = (Mask 'host' $HostName); cluster_id = (RGuid $ClusterId); node_state = $null
        os_version = $osName; cpu_count = $vmHost.LogicalProcessorCount; memory_bytes = $vmHost.MemoryCapacity
    }
    $vmItems = @(); $disks = @(); $nets = @()
    foreach ($vm in $vms) {
        $id = (Get-Str $vm.Id).ToLowerInvariant()
        $info = $null
        if ($ClusterVmInfo -and $ClusterVmInfo.ContainsKey($id)) { $info = $ClusterVmInfo[$id] }
        $addresses = @()
        $adapters = @()
        try { $adapters = @(Get-VMNetworkAdapter -VM $vm) } catch { }
        foreach ($a in $adapters) {
            foreach ($ip in @($a.IPAddresses)) { if ($ip) { $addresses += (Mask 'ip' $ip) } }
            $vlan = $null
            try { $vlan = (Get-VMNetworkAdapterVlan -VMNetworkAdapter $a).AccessVlanId } catch { }
            $nets += [ordered]@{
                vm_id = (RGuid $id); id = (RGuid (Get-Str $a.Id)); name = (Mask 'nic' $a.Name); switch_name = (Mask 'switch' $a.SwitchName)
                mac_address = (Mask 'mac' $a.MacAddress); ip_addresses = @($a.IPAddresses | Where-Object { $_ } | ForEach-Object { Mask 'ip' $_ }); vlan_id = $vlan
            }
        }
        $drives = @()
        try { $drives = @(Get-VMHardDiskDrive -VM $vm) } catch { }
        foreach ($d in $drives) {
            $size = $null; $used = $null
            if ($d.Path) {
                try { $vhd = Get-VHD -ComputerName $HostName -Path $d.Path; $size = $vhd.Size; $used = $vhd.FileSize } catch { }
            }
            $loc = "$($d.ControllerType) $($d.ControllerNumber):$($d.ControllerLocation)"
            $disks += [ordered]@{
                vm_id = (RGuid $id); id = ($loc.ToLowerInvariant() -replace '[^a-z0-9:]+', '-'); path = (Mask 'path' $d.Path)
                size_bytes = $size; used_bytes = $used; controller = $loc
            }
        }
        $memory = $vm.MemoryAssigned
        if (-not $memory) { $memory = $vm.MemoryStartup }
        $vmItems += [ordered]@{
            id = (RGuid $id); name = (Mask 'vm' $vm.Name); host = (Mask 'host' $HostName); state = (Get-Str $vm.State)
            is_clustered = $vm.IsClustered
            cluster_role_id = $(if ($info) { RGuid $info.GroupId } else { $null })
            possible_owners = $(if ($info) { @($info.Owners | ForEach-Object { Mask 'host' $_ }) } else { @() })
            generation = $vm.Generation; cpu_count = $vm.ProcessorCount; memory_bytes = $memory
            guest_addresses = $addresses
            os_name = $guestOsById[$id]
        }
    }
    return @{ Host = $hostItem; Vms = $vmItems; Disks = $disks; Nets = $nets }
}

function New-Section([array]$Items) { return [ordered]@{ complete = $true; items = @($Items) } }

# One scope's report: the clusters and standalone hosts given.
function Read-Scope([string[]]$Clusters, [string[]]$Hosts) {
    $scope = @(); $unreadable = @()
    $clusterItems = @(); $hostItems = @(); $roleItems = @(); $vmItems = @(); $diskItems = @(); $netItems = @()
    foreach ($c in $Clusters) {
        try {
            $cl = Try-Read $c 'Get-Cluster' { Get-Cluster -Name $c }
            $cid = (Get-Str $cl.Id)
            $quorum = $null; $witness = $null
            try { $q = Try-Read $c 'Get-ClusterQuorum' { Get-ClusterQuorum -Cluster $c }; $quorum = Get-Str $q.QuorumType; if ($q.QuorumResource) { $witness = Get-Str $q.QuorumResource.Name } } catch { }
            $nodes = @(Try-Read $c 'Get-ClusterNode' { Get-ClusterNode -Cluster $c })
            $groups = @(Try-Read $c 'Get-ClusterGroup' { Get-ClusterGroup -Cluster $c })
            # Which cluster role each VM is, and the nodes it may run on.
            $vmInfo = @{}
            try {
                foreach ($res in @(Try-Read $c 'Get-ClusterResource' { Get-ClusterResource -Cluster $c | Where-Object { $_.ResourceType -like 'Virtual Machine' } })) {
                    $vmId = $null
                    try { $vmId = (Get-Str (Get-ClusterParameter -InputObject $res -Name VmID).Value).ToLowerInvariant() } catch { }
                    if (-not $vmId) { continue }
                    $owners = @()
                    try { $owners = @((Get-ClusterOwnerNode -InputObject $res).OwnerNodes | ForEach-Object { Get-Str $_.Name }) } catch { }
                    $vmInfo[$vmId] = @{ GroupId = (Get-Str $res.OwnerGroup.Id); Owners = $owners }
                }
            } catch { }
            $scope += [ordered]@{ kind = 'cluster'; id = (RGuid $cid); name = (Mask 'cluster' $c) }
            $clusterItems += [ordered]@{ id = (RGuid $cid); name = (Mask 'cluster' (Get-Str $cl.Name)); quorum_type = $quorum; witness = (Mask 'witness' $witness) }
            foreach ($g in $groups) {
                $preferred = @()
                try { $preferred = @((Get-ClusterOwnerNode -InputObject $g).OwnerNodes | ForEach-Object { Mask 'host' (Get-Str $_.Name) }) } catch { }
                $roleItems += [ordered]@{ id = (RGuid (Get-Str $g.Id)); name = (Mask 'role' $g.Name); cluster_id = (RGuid $cid); role_type = (Get-Str $g.GroupType)
                    owner_host = (Mask 'host' (Get-Str $g.OwnerNode.Name)); state = (Get-Str $g.State); preferred_owners = $preferred }
            }
            foreach ($n in $nodes) {
                $name = Get-Str $n.Name
                try {
                    $r = Read-HyperVHost $name $cid $vmInfo
                    $r.Host.node_state = Get-Str $n.State
                    $hostItems += $r.Host; $vmItems += $r.Vms; $diskItems += $r.Disks; $netItems += $r.Nets
                } catch {
                    $unreadable += [ordered]@{ kind = 'host'; name = (Mask 'host' $name); error = (RError $_.Exception.Message) }
                }
            }
        } catch {
            $scope += [ordered]@{ kind = 'cluster'; name = (Mask 'cluster' $c) }
            $unreadable += [ordered]@{ kind = 'cluster'; name = (Mask 'cluster' $c); error = (RError $_.Exception.Message) }
        }
    }
    foreach ($h in $Hosts) {
        $scope += [ordered]@{ kind = 'host'; name = (Mask 'host' $h) }
        try {
            $r = Read-HyperVHost $h $null $null
            $hostItems += $r.Host; $vmItems += $r.Vms; $diskItems += $r.Disks; $netItems += $r.Nets
        } catch {
            $unreadable += [ordered]@{ kind = 'host'; name = (Mask 'host' $h); error = (RError $_.Exception.Message) }
        }
    }
    $sections = [ordered]@{ hosts = (New-Section $hostItems); vms = (New-Section $vmItems); vm_disks = (New-Section $diskItems); vm_networks = (New-Section $netItems) }
    if ($Clusters) {
        $sections.Insert(0, 'clusters', (New-Section $clusterItems))
        $sections.Insert(2, 'cluster_roles', (New-Section $roleItems))
    }
    return [ordered]@{
        schema_version = 1
        observed_at = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        scope = $scope
        collector = [ordered]@{ name = 'Invoke-HyperVInventory'; version = $CollectorVersion; powershell = $PSVersionTable.PSVersion.ToString(); host = (Mask 'host' $env:COMPUTERNAME) }
        unreadable = $unreadable
        sections = $sections
    }
}

# ─── -TestAccess ────────────────────────────────────────────────────────────────

if ($TestAccess) {
    Write-Host "Testing what $([Security.Principal.WindowsIdentity]::GetCurrent().Name) can read. Nothing is sent." -ForegroundColor Cyan
    [void](Read-Scope $Cluster $StandaloneHost)
    $script:Access | Format-Table -AutoSize -Wrap
    $refused = @($script:Access | Where-Object { $_.Result -ne 'OK' })
    if ($refused) { Write-Host "$($refused.Count) read(s) refused - see above." -ForegroundColor Yellow; exit 2 }
    Write-Host "Every read worked." -ForegroundColor Green
    exit 0
}

# ─── Collect ───────────────────────────────────────────────────────────────────

$reports = @()
if ($ReportPer -eq 'Cluster') {
    foreach ($c in $Cluster) { $reports += , @($c, (Read-Scope @($c) @())) }
    if ($StandaloneHost) { $reports += , @('standalone', (Read-Scope @() $StandaloneHost)) }
} else {
    $reports += , @('all', (Read-Scope $Cluster $StandaloneHost))
}

# ─── Send ──────────────────────────────────────────────────────────────────────

$requestArgs = @{}
$restoreCallback = $false
$originalCallback = $null
if ($ApiUrl) {
    if ($PSVersionTable.PSVersion.Major -lt 6) { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 }
    $isCore = $PSVersionTable.PSVersion.Major -ge 6
    if ($pinnedThumbprint) {
        if ($isCore) {
            $uri = [Uri]$ApiUrl
            $port = $uri.Port; if ($port -lt 0) { $port = 443 }
            $tcp = New-Object System.Net.Sockets.TcpClient($uri.Host, $port)
            $ssl = New-Object System.Net.Security.SslStream($tcp.GetStream(), $false, { $true })
            $ssl.AuthenticateAsClient($uri.Host)
            $presented = (New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($ssl.RemoteCertificate)).Thumbprint.ToUpperInvariant()
            $ssl.Dispose(); $tcp.Close()
            if ($presented -ne $pinnedThumbprint) { Write-Host "Certificate mismatch - refusing to send. Expected $pinnedThumbprint, got $presented." -ForegroundColor Red; exit 1 }
            $requestArgs['SkipCertificateCheck'] = $true
        } else {
            $originalCallback = [Net.ServicePointManager]::ServerCertificateValidationCallback
            [Net.ServicePointManager]::ServerCertificateValidationCallback = {
                param($s, $certificate, $chain, $errors)
                ([System.Security.Cryptography.X509Certificates.X509Certificate2]$certificate).Thumbprint.ToUpperInvariant() -eq $pinnedThumbprint
            }.GetNewClosure()
            $restoreCallback = $true
        }
    } elseif ($SkipCertificateCheck) {
        Write-Host "WARNING: certificate validation is off for this run - lab use only." -ForegroundColor Yellow
        if ($isCore) { $requestArgs['SkipCertificateCheck'] = $true } else {
            $originalCallback = [Net.ServicePointManager]::ServerCertificateValidationCallback
            [Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
            $restoreCallback = $true
        }
    }
}

$exit = 0
try {
    foreach ($pair in $reports) {
        $label = $pair[0]; $report = $pair[1]
        $json = $report | ConvertTo-Json -Depth 8
        $vmCount = @($report.sections.vms.items).Count
        if ($OutputFile) {
            $file = $OutputFile
            if ($reports.Count -gt 1) { $file = [IO.Path]::Combine([IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($OutputFile)), [IO.Path]::GetFileNameWithoutExtension($OutputFile) + "-$label.json") }
            [IO.File]::WriteAllText($file, $json, (New-Object Text.UTF8Encoding $false))
            Write-Host "Wrote $file ($vmCount VMs, $(@($report.unreadable).Count) unreadable)" -ForegroundColor Green
        }
        if ($ApiUrl -and $ConnectionId -and $ApiKey) {
            $url = "$($ApiUrl.TrimEnd('/'))/api/v1/index.php?path=" + [Uri]::EscapeDataString("/virtual-estate/$ConnectionId/reports")
            try {
                $resp = Invoke-RestMethod -Uri $url -Method POST -Headers @{ Authorization = "Bearer $ApiKey" } -ContentType 'application/json; charset=utf-8' `
                    -Body ([Text.Encoding]::UTF8.GetBytes($json)) @requestArgs
                Write-Host "Sent $label ($vmCount VMs): $($resp.data.outcome)" -ForegroundColor Green
            } catch {
                # 207 (some sections failed) arrives here on Windows PowerShell; the body says which.
                $body = $null
                if ($_.Exception.Response) { try { $body = (New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())).ReadToEnd() } catch { } }
                Write-Host "Sending $label failed: $($_.Exception.Message) $body" -ForegroundColor Red
                $exit = 1
            }
        }
    }
} finally {
    if ($restoreCallback) { [Net.ServicePointManager]::ServerCertificateValidationCallback = $originalCallback }
}
exit $exit
