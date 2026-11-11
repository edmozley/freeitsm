<#
    GH #199: runs scripts/Invoke-HyperVInventory.ps1 against MOCKED Hyper-V and Failover
    Clustering cmdlets, so the collector's own logic is exercised without a Hyper-V estate.
    A function of the same name wins over a cmdlet in PowerShell, so these stand in for the
    real ones for the collector run below. Everything is synthetic.

    The estate: cluster CL-MOCK with nodes HV-N1 and HV-N2, standalone HV-S1, and HV-DENIED
    (a standalone host that refuses every read). VM-HA1 is a clustered role on HV-N1 that may
    run on both nodes; VM-PLAIN runs on HV-N2 and is not clustered; VM-S1 is on HV-S1.

    The tables are named Mock* on purpose: PowerShell scoping is dynamic, so a mock that
    read $uuid would see the COLLECTOR's $uuid variable, not this script's.

    Usage (tests/hyperv-collector.php drives it):
      powershell -NoProfile -ExecutionPolicy Bypass -File Mock-HyperV.ps1 -Out <file> [-Redact] [-TestAccess]
#>
param([string]$Out, [switch]$Redact, [switch]$TestAccess, [string]$ReportPer = 'All', [string]$ApiUrl, [int]$ConnectionId, [string]$ApiKey, [switch]$NoGuestKvp)

$MockGuid = @{ 'CL-MOCK' = '11111111-2222-3333-4444-555555555555'; 'VM-HA1' = 'aaaaaaaa-0000-4000-8000-000000000001'; 'VM-PLAIN' = 'aaaaaaaa-0000-4000-8000-000000000002'
    'VM-S1' = 'aaaaaaaa-0000-4000-8000-000000000003'; 'GRP-HA1' = 'bbbbbbbb-0000-4000-8000-000000000001'; 'GRP-CORE' = 'bbbbbbbb-0000-4000-8000-000000000002' }
$MockUuid = @{ 'HV-N1' = '4c4c4544-0031-3510-8051-b4c04f000001'; 'HV-N2' = '4c4c4544-0031-3510-8051-b4c04f000002'; 'HV-S1' = '4c4c4544-0031-3510-8051-b4c04f000003' }
$MockVmsOn = @{ 'HV-N1' = @('VM-HA1'); 'HV-N2' = @('VM-PLAIN'); 'HV-S1' = @('VM-S1') }
$MockGuestKvpDenied = $NoGuestKvp

function Deny([string]$h) { if ($h -eq 'HV-DENIED') { throw [System.UnauthorizedAccessException]"Access is denied ($h)" } }

function Get-CimInstance { param($ClassName, $ComputerName, $Namespace)
    Deny $ComputerName
    if ($ClassName -eq 'Win32_ComputerSystemProduct') { return [pscustomobject]@{ UUID = $MockUuid[$ComputerName] } }
    if ($ClassName -eq 'Msvm_KvpExchangeComponent') {
        if ($Namespace -ne 'root/virtualization/v2') { throw 'Wrong guest KVP namespace' }
        if ($MockGuestKvpDenied -and $ComputerName -eq 'HV-N1') { throw 'Guest KVP access is denied' }
        # A different VM comes first, so reading the first component is not enough.
        [pscustomobject]@{ SystemName = 'eeeeeeee-0000-4000-8000-000000000001'; GuestIntrinsicExchangeItems = @(
            '<INSTANCE><PROPERTY NAME="Name"><VALUE>OSName</VALUE></PROPERTY><PROPERTY NAME="Data"><VALUE>ZZ unrelated guest</VALUE></PROPERTY></INSTANCE>'
        ) }
        foreach ($n in $MockVmsOn[$ComputerName]) {
            $items = @(
                '<INSTANCE><PROPERTY NAME="Name"><VALUE>FullyQualifiedDomainName</VALUE></PROPERTY><PROPERTY NAME="Data"><VALUE>ZZ-GUEST-FQDN.example.invalid</VALUE></PROPERTY></INSTANCE>',
                '<!DOCTYPE INSTANCE [<!ENTITY os "ZZ untrusted DTD">]><INSTANCE><PROPERTY NAME="Name"><VALUE>OSName</VALUE></PROPERTY><PROPERTY NAME="Data"><VALUE>&os;</VALUE></PROPERTY></INSTANCE>',
                '<INSTANCE malformed'
            )
            if ($n -eq 'VM-HA1') {
                $items += '<INSTANCE><PROPERTY NAME="Data"><VALUE>Microsoft Windows Server 2019 Standard</VALUE></PROPERTY><PROPERTY NAME="Name"><VALUE>OSName</VALUE></PROPERTY></INSTANCE>'
            } elseif ($n -eq 'VM-PLAIN') {
                $items += '<INSTANCE><PROPERTY NAME="Name"><VALUE>OSName</VALUE></PROPERTY><PROPERTY NAME="Data"><VALUE>Ubuntu 24.04 LTS</VALUE></PROPERTY></INSTANCE>'
            } else {
                $items += '<INSTANCE><PROPERTY NAME="Name"><VALUE>OSName</VALUE></PROPERTY><PROPERTY NAME="Data"><VALUE> </VALUE></PROPERTY></INSTANCE>'
            }
            [pscustomobject]@{ SystemName = $MockGuid[$n].ToUpperInvariant(); GuestIntrinsicExchangeItems = $items }
        }
        return
    }
    return [pscustomobject]@{ Caption = 'Microsoft Windows Server 2022 Datacenter' } }
function Get-VMHost { param($ComputerName) Deny $ComputerName; [pscustomobject]@{ LogicalProcessorCount = 64; MemoryCapacity = 549755813888 } }
function Get-VM { param($ComputerName)
    Deny $ComputerName
    foreach ($n in $MockVmsOn[$ComputerName]) {
        [pscustomobject]@{ Id = [guid]$MockGuid[$n]; Name = $n; State = 'Running'; IsClustered = ($n -eq 'VM-HA1'); Generation = 2
            ProcessorCount = 4; MemoryAssigned = 8589934592; MemoryStartup = 4294967296; ComputerName = $ComputerName }
    } }
function Get-VMNetworkAdapter { param($VM)
    [pscustomobject]@{ Id = "Microsoft:$($VM.Id)\NIC0"; Name = 'Network Adapter'; SwitchName = 'vSwitch-Prod'; MacAddress = '00155D010203'; IPAddresses = @(('10.0.0.' + $VM.Name.Length), 'fe80::1') } }
function Get-VMNetworkAdapterVlan { param($VMNetworkAdapter) [pscustomobject]@{ AccessVlanId = 20 } }
function Get-VMHardDiskDrive { param($VM) [pscustomobject]@{ ControllerType = 'SCSI'; ControllerNumber = 0; ControllerLocation = 0; Path = "C:\ClusterStorage\Volume1\$($VM.Name)\disk0.vhdx" } }
function Get-VHD { param($ComputerName, $Path) [pscustomobject]@{ Size = 136365211648; FileSize = 42949672960 } }
function Get-Cluster { param($Name) [pscustomobject]@{ Id = $MockGuid[$Name]; Name = $Name } }
function Get-ClusterQuorum { param($Cluster) [pscustomobject]@{ QuorumType = 'NodeAndFileShareMajority'; QuorumResource = [pscustomobject]@{ Name = 'File Share Witness' } } }
function Get-ClusterNode { param($Cluster) @([pscustomobject]@{ Name = 'HV-N1'; State = 'Up' }, [pscustomobject]@{ Name = 'HV-N2'; State = 'Up' }) }
function Get-ClusterGroup { param($Cluster)
    @([pscustomobject]@{ Id = $MockGuid['GRP-HA1']; Name = 'VM-HA1'; GroupType = 'VirtualMachine'; OwnerNode = [pscustomobject]@{ Name = 'HV-N1' }; State = 'Online' },
      [pscustomobject]@{ Id = $MockGuid['GRP-CORE']; Name = 'Cluster Group'; GroupType = 'Cluster'; OwnerNode = [pscustomobject]@{ Name = 'HV-N2' }; State = 'Online' }) }
function Get-ClusterResource { param($Cluster)
    [pscustomobject]@{ Name = 'Virtual Machine VM-HA1'; ResourceType = 'Virtual Machine'; OwnerGroup = [pscustomobject]@{ Id = $MockGuid['GRP-HA1'] } } }
function Get-ClusterParameter { param($InputObject, $Name) [pscustomobject]@{ Value = $MockGuid['VM-HA1'] } }
function Get-ClusterOwnerNode { param($InputObject)
    [pscustomobject]@{ OwnerNodes = @([pscustomobject]@{ Name = 'HV-N1' }, [pscustomobject]@{ Name = 'HV-N2' }) } }

$collector = Join-Path $PSScriptRoot '..\..\scripts\Invoke-HyperVInventory.ps1'
$splat = @{ Cluster = @('CL-MOCK'); StandaloneHost = @('HV-S1', 'HV-DENIED'); ReportPer = $ReportPer }
if ($TestAccess) { $splat['TestAccess'] = $true } elseif ($Out) { $splat['OutputFile'] = $Out }
if ($ApiUrl) { $splat['ApiUrl'] = $ApiUrl; $splat['ConnectionId'] = $ConnectionId; $splat['ApiKey'] = $ApiKey }
if ($Redact) { $splat['Redact'] = $true }
& $collector @splat
exit $LASTEXITCODE
