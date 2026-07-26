# How to: Dnsmasq + ctrld/NextDNS hybrid DNS

This walks through the actual reason the `ctrld` plugin exists: routing DNS
through NextDNS with a separate profile (and per-device visibility) for each
VLAN, while keeping Dnsmasq doing what it already does well — DHCP,
DHCP-hostname resolution, and reverse lookups — without breaking any of that
in the process. Unbound is not part of this architecture; if you're not
using it for anything else, it can be disabled entirely (see the last step).

**Contents**
1. [Confirm current roles](#1-confirm-current-roles)
2. [Install ctrld and set the local-zone resolver](#2-install-ctrld-and-set-the-local-zone-resolver)
3. [Create a NextDNS upstream profile](#3-create-a-nextdns-upstream-profile)
4. [Add a listener per VLAN](#4-add-a-listener-per-vlan)
5. [Add policy rules](#5-add-policy-rules)
6. [Enable and verify](#6-enable-and-verify)
7. [Disable Unbound (optional cleanup)](#7-disable-unbound-optional-cleanup)

The worked example below uses one real, previously-verified VLAN (IoT,
interface `opt1`, gateway `192.168.3.1/24`, NextDNS profile `abc123`) plus a
second, illustrative LAN VLAN — substitute your own interfaces/CIDRs/profile
IDs throughout.

## 1. Confirm current roles

Before changing anything, know what's actually running today:

- **Dnsmasq** (Services → Dnsmasq DNS/DHCP) provides DHCP across your VLANs,
  with its DNS component reachable on a non-default loopback port (in the
  reference setup, `127.0.0.1:53053`). This is the resolver ctrld will
  delegate reverse/internal lookups to — it's what actually knows DHCP lease
  hostnames.

> **Note**
> This guide assumes Dnsmasq for DHCP. If you're running Kea instead, this
> design doesn't work as documented: Kea has no DNS component of its own, so
> there's nothing at loopback to delegate to, and you'd need to keep Unbound
> (or another resolver) in the loop specifically for local-zone delegation
> rather than removing it.

## 2. Install ctrld and set the local-zone resolver

With the plugin's files deployed (see the repo README) and `ctrld` itself
already installed at `/usr/local/bin/ctrld`, go to **Services → ctrld →
General**. Set:

- **Local-zone resolver host**: `127.0.0.1`
- **Local-zone resolver port**: `53053` (or whatever port Dnsmasq's DNS
  component is actually listening on — Services → Dnsmasq DNS/DHCP →
  General)

Don't enable the service yet.

## 3. Create a NextDNS upstream profile

Under the **Upstreams** tab, add one row per VLAN's NextDNS profile:

| Name | NextDNS profile ID (quick-add) | Type | Endpoint |
|---|---|---|---|
| NextDNS IoT | `abc123` | doh3 | `https://dns.nextdns.io/abc123` (auto-filled) |
| NextDNS LAN | *(your LAN profile ID)* | doh3 | *(auto-filled)* |

Pasting just the profile ID into the quick-add field fills in type and
endpoint for you.

## 4. Add a listener per VLAN

Under the **Listeners** tab, add one row per VLAN gateway:

| Description | Interface | Port |
|---|---|---|
| IoT | opt1 (192.168.3.1) | 53 |
| LAN | *(your LAN interface)* | 53 |

Each listener binds to that VLAN's specific gateway IP — never to "all
interfaces" — so it only ever picks up traffic from that VLAN.

## 5. Add policy rules

Under the **Policies** tab, add one CIDR rule per VLAN, routing that VLAN's
traffic to its NextDNS profile:

| Listener | Match type | Match value | Upstream |
|---|---|---|---|
| IoT | cidr | `192.168.3.0/24` | NextDNS IoT |
| LAN | cidr | *(your LAN CIDR)* | NextDNS LAN |

Then handle local-zone delegation: go to the **Local-Zone Delegation** tab
and click **Create local-zone delegation rules**. This creates an Upstream
row for Dnsmasq (using the host/port from step 2) plus two Policy rows —
one for `168.192.in-addr.arpa`, one for `internal` — routing those to it
instead of NextDNS. (Equivalent by hand: an Upstream row named "Local
resolver", type `legacy`, endpoint `127.0.0.1:53053`, and two `domain`-match
Policy rows pointed at it.)

> **Warning**
> Never let anything trigger ctrld's own auto-config ("yolo") flow —
> `ctrld start --cd <id>` or `ctrld start --nextdns <id>`. That mode
> generates its own config, kills Dnsmasq system-wide, and binds
> `0.0.0.0:53`. This plugin only ever drives ctrld through custom config
> mode, writing `/etc/controld/ctrld.toml` itself.

## 6. Enable and verify

Back on the **General** tab, enable ctrld and save. Then, from a machine on
each VLAN (or via `dig @<vlan-gateway-ip>`):

```sh
dig @192.168.3.1 example.com
```

should resolve normally, and — after a few minutes — that device should
show up individually in the NextDNS dashboard for the IoT profile,
distinguishable from other devices on the same VLAN. Verify reverse lookups
and `internal` names still resolve too (these should be answered by
Dnsmasq, not NextDNS):

```sh
dig @192.168.3.1 -x 192.168.3.1
dig @192.168.3.1 somehost.internal
```

DNSSEC is validated by NextDNS upstream, not independently by ctrld — see
the [DNSSEC section](ctrld.md#dnssec) of the reference doc for the full
tradeoff explanation.

## 7. Disable Unbound (optional cleanup)

Once step 6 is verified working, Unbound has no remaining job in this
architecture and can be disabled (Services → Unbound DNS → General →
uncheck **Enable Unbound**).

> **Warning**
> Do this last, and do the step below first. OPNsense itself — not just
> your VLAN clients — resolves DNS through `127.0.0.1:53`, which today is
> Unbound. ctrld only binds VLAN gateway IPs (not loopback), and Dnsmasq is
> on port `53053`, not `53` — so disabling Unbound without doing anything
> else leaves nothing answering the firewall's own DNS queries (package
> installs, firmware checks, NTP hostname lookups, etc. would all break).
>
> Before disabling Unbound, go to **System → Settings → General** and set
> **DNS servers** to one or more external resolvers (e.g. NextDNS's own
> anycast IPs, or any resolver you trust) so the box keeps resolving its own
> queries independently of the services this plugin manages.
