# How to: Unbound + Dnsmasq + ctrld/NextDNS hybrid DNS

This walks through the actual reason the `ctrld` plugin exists: routing DNS
through NextDNS with a separate profile (and per-device visibility) for each
VLAN, while keeping Unbound and Dnsmasq doing what they already do well —
without breaking DHCP-hostname resolution or reverse lookups in the
process.

**Contents**
1. [Confirm current roles](#1-confirm-current-roles)
2. [Rebind Unbound to loopback-only](#2-rebind-unbound-to-loopback-only)
3. [Install ctrld and set the local-zone resolver](#3-install-ctrld-and-set-the-local-zone-resolver)
4. [Create a NextDNS upstream profile](#4-create-a-nextdns-upstream-profile)
5. [Add a listener per VLAN](#5-add-a-listener-per-vlan)
6. [Add policy rules](#6-add-policy-rules)
7. [Enable and verify](#7-enable-and-verify)

The worked example below uses one real, previously-verified VLAN (IoT,
interface `opt1`, gateway `192.168.3.1/24`, NextDNS profile `abc123`) plus a
second, illustrative LAN VLAN — substitute your own interfaces/CIDRs/profile
IDs throughout.

## 1. Confirm current roles

Before changing anything, know what's actually running today:

- **Unbound** (Services → Unbound DNS) is your primary resolver, bound to
  your VLAN interfaces on port 53, likely forwarding externally and
  delegating `*.in-addr.arpa`/`internal` to Dnsmasq via its own forward-zone
  config.
- **Dnsmasq** (Services → Dnsmasq DNS/DHCP) provides DHCP across your VLANs,
  with its DNS component reachable on a non-default loopback port (in the
  reference setup, `127.0.0.1:53053`).

> **Note**
> If you're running Kea instead of Dnsmasq for DHCP, the local-zone
> delegation piece below still works the same way in principle, but Kea has
> its own known limitation: it doesn't natively register dynamic hostnames
> into Unbound, only static ones. That's a Kea/Unbound integration gap, not
> something this plugin tries to solve.

## 2. Rebind Unbound to loopback-only

ctrld is about to take over the client-facing listener role on your VLAN
interfaces, so Unbound can no longer also bind those same interfaces on
port 53. Unbound's interface list (Services → Unbound DNS → General →
Network Interfaces) only offers your actual assigned interfaces (LAN, WAN,
VLANs, OpenVPN instances) — there's no built-in "Localhost" entry, and
leaving the list empty doesn't mean loopback-only, it means bind to
`0.0.0.0` (every interface), which is the opposite of what you want here.

Instead:

1. **Interfaces → Other Types → Loopback**: create a new loopback device.
2. **Interfaces → Assignments**: assign that device as a new interface
   (e.g. name it "UNBOUND_LO").
3. On that new interface's settings page, give it a static IPv4 address
   that is **not** in `127.0.0.0/8` — that range is reserved for true
   loopback semantics and OPNsense won't treat it as a normal routable
   interface address. Any otherwise-unused private `/32`, e.g.
   `192.168.254.1/32`, works; it doesn't need to be reachable from
   anywhere.
4. Back in **Services → Unbound DNS → General → Network Interfaces**,
   select *only* this new interface (deselect LAN/WAN/VLANs).

Unbound always implicitly includes `127.0.0.1` alongside whatever real
interfaces you select (this is hardcoded, not a checkbox), so selecting
just the new loopback interface here means Unbound ends up listening on
`127.0.0.1` plus that interface's own address — and nothing else. Leave
the port at 53 and leave the existing forward-zone entries (the
`*.in-addr.arpa`/`internal` → `127.0.0.1@53053` delegation to Dnsmasq)
exactly as they are — that relationship doesn't change.

Apply, and confirm Unbound is now only reachable on `127.0.0.1:53` (and
the new loopback interface's address), not on any VLAN gateway IP.

## 3. Install ctrld and set the local-zone resolver

With the plugin's files deployed (see the repo README) and `ctrld` itself
already installed at `/usr/local/bin/ctrld`, go to **Services → ctrld →
General**. Set:

- **Local-zone resolver host**: `127.0.0.1`
- **Local-zone resolver port**: `53`

This is Unbound's new loopback-only address from step 2 — it's what
`*.in-addr.arpa`/`internal` queries get delegated to. Don't enable the
service yet.

## 4. Create a NextDNS upstream profile

Under the **Upstreams** tab, add one row per VLAN's NextDNS profile:

| Name | NextDNS profile ID (quick-add) | Type | Endpoint |
|---|---|---|---|
| NextDNS IoT | `abc123` | doh3 | `https://dns.nextdns.io/abc123` (auto-filled) |
| NextDNS LAN | *(your LAN profile ID)* | doh3 | *(auto-filled)* |

Pasting just the profile ID into the quick-add field fills in type and
endpoint for you.

## 5. Add a listener per VLAN

Under the **Listeners** tab, add one row per VLAN gateway:

| Description | Interface | Port |
|---|---|---|
| IoT | opt1 (192.168.3.1) | 53 |
| LAN | *(your LAN interface)* | 53 |

Each listener binds to that VLAN's specific gateway IP — never to "all
interfaces" — so it only ever picks up traffic from that VLAN.

## 6. Add policy rules

Under the **Policies** tab, add one CIDR rule per VLAN, routing that VLAN's
traffic to its NextDNS profile:

| Listener | Match type | Match value | Upstream |
|---|---|---|---|
| IoT | cidr | `192.168.3.0/24` | NextDNS IoT |
| LAN | cidr | *(your LAN CIDR)* | NextDNS LAN |

Then handle local-zone delegation: go to the **Local-Zone Delegation** tab
and click **Create local-zone delegation rules**. This creates an Upstream
row for Unbound (using the host/port from step 3) plus two Policy rows —
one for `168.192.in-addr.arpa`, one for `internal` — routing those to it
instead of NextDNS. (Equivalent by hand: an Upstream row named "Local
resolver", type `legacy`, endpoint `127.0.0.1:53`, and two `domain`-match
Policy rows pointed at it.)

> **Warning**
> Never let anything trigger ctrld's own auto-config ("yolo") flow —
> `ctrld start --cd <id>` or `ctrld start --nextdns <id>`. That mode
> generates its own config, kills Unbound and Dnsmasq system-wide, and binds
> `0.0.0.0:53`. This plugin only ever drives ctrld through custom config
> mode, writing `/etc/controld/ctrld.toml` itself.

## 7. Enable and verify

Back on the **General** tab, enable ctrld and save. Then, from a machine on
each VLAN (or via `dig @<vlan-gateway-ip>`):

```sh
dig @192.168.3.1 example.com
```

should resolve normally, and — after a few minutes — that device should
show up individually in the NextDNS dashboard for the IoT profile,
distinguishable from other devices on the same VLAN. Verify reverse lookups
and `internal` names still resolve too (these should be answered via
Unbound/Dnsmasq, not NextDNS):

```sh
dig @192.168.3.1 -x 192.168.3.1
dig @192.168.3.1 somehost.internal
```

DNSSEC is validated by NextDNS upstream, not independently by ctrld or
Unbound in this path — see the [DNSSEC section](ctrld.md#dnssec) of the
reference doc for the full tradeoff explanation.
