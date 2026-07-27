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
7. [Optional: route the firewall's own DNS through ctrld too](#7-optional-route-the-firewalls-own-dns-through-ctrld-too)
8. [Disable Unbound (optional cleanup)](#8-disable-unbound-optional-cleanup)

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

Under the **Upstreams** page, add one row per VLAN's NextDNS profile:

| Name | NextDNS profile ID (quick-add) | Type | Endpoint |
|---|---|---|---|
| NextDNS IoT | `abc123` | doh3 | `https://dns.nextdns.io/abc123` (auto-filled) |
| NextDNS LAN | *(your LAN profile ID)* | doh3 | *(auto-filled)* |

Pasting just the profile ID into the quick-add field fills in type and
endpoint for you.

## 4. Add a listener per VLAN

Under the **Listeners** page, add one row per VLAN gateway:

| Description | Interface | Port |
|---|---|---|
| IoT | opt1 (192.168.3.1) | 53 |
| LAN | *(your LAN interface)* | 53 |

Each listener binds to that VLAN's specific gateway IP — never to "all
interfaces" — so it only ever picks up traffic from that VLAN. Each row
also has an **IP version** field, IPv4 by default; leave it as-is unless
you also want to serve DNS over a VLAN's IPv6 address, in which case add a
second row for that VLAN with the same interface/port and IPv6 selected.
This worked example is IPv4-only throughout — see
[ctrld.md's Known limitations](ctrld.md#known-limitations) if you use
IPv6.

## 5. Add policy rules

Under the **Policies** page, add one CIDR rule per VLAN, routing that VLAN's
traffic to its NextDNS profile:

| Listener | Match type | Match value | Upstream |
|---|---|---|---|
| IoT | cidr | `192.168.3.0/24` | NextDNS IoT |
| LAN | cidr | *(your LAN CIDR)* | NextDNS LAN |

Then handle local-zone delegation: on that same **Policies** page, click
**Create local-zone delegation policies**. This creates an Upstream row
for Dnsmasq (using the host/port from step 2) plus two Policy rows — one
for `168.192.in-addr.arpa`, one for `internal` — routing those to it
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

Back on the **General** page, enable ctrld and save. Then, from a machine on
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

## 7. Optional: route the firewall's own DNS through ctrld too

By default, OPNsense itself — not just your VLAN clients — resolves DNS
through `127.0.0.1:53`, which today is Unbound. If you don't want anything
on your network, including the firewall itself, bypassing NextDNS, route
this through ctrld too instead of pointing it at an unrelated external
resolver in the next step:

1. **General page**: leave **Enable caching** and **Serve stale on failure**
   turned on (the default). `ctrld` then keeps answering already-resolved
   names from its own cache during a brief NextDNS/WAN hiccup, instead of
   failing outright — this alone covers most short blips.
2. **Upstreams page**: reuse an existing NextDNS profile, or add a new one
   dedicated to the firewall itself (recommended — gives it its own
   distinguishable device row in the NextDNS dashboard). Also add a second,
   non-NextDNS upstream to use as a fallback — e.g. Quad9 (`type: dot`,
   endpoint `9.9.9.9`) — for step 4 below.
3. **Listeners page**: add a row with **Interface: Loopback (127.0.0.1 / ::1)**,
   **Port: 53**. This is a special option this plugin adds specifically for
   this case — it isn't a real assigned interface, so it doesn't show up
   anywhere else in OPNsense.
4. **Policies page**: add a `cidr` rule matching `0.0.0.0/0` on that
   listener, routed to the NextDNS upstream from step 2. Use the catch-all
   `0.0.0.0/0`, not `127.0.0.1/32`, even though loopback-bound traffic
   might seem like it could only ever come from `127.0.0.1` itself — on a
   real box the source address ctrld actually sees for these queries
   didn't reliably match a `/32` (see the loopback-listener postmortem in
   [`docs/engineering-notes/incident-postmortems.md`](engineering-notes/incident-postmortems.md)
   for the full investigation); the loopback *bind* itself is already what
   restricts which traffic can reach this listener at all, so the policy
   match can safely be wide open. Optionally set **Fallback upstream** to
   the Quad9 (or similar) upstream from step 2: `ctrld` then only falls
   back to it if NextDNS specifically times out or errors, not on every
   query — NextDNS still handles the normal case.
5. **Policies page**: click **Create local-zone delegation policies**
   again (or by hand, for just this listener). It creates rows for
   *every* enabled listener, so re-running it after adding the
   loopback listener also delegates the firewall's own
   `*.in-addr.arpa`/`internal` lookups to Dnsmasq instead of NextDNS,
   matching what VLAN listeners already get — otherwise the catch-all rule
   in step 4 would route those to NextDNS too, which can't answer them.
6. Apply, then verify before changing anything else:

```sh
dig @127.0.0.1 example.com
```

7. Go to **System → Settings → General** and set **DNS servers** to
   `127.0.0.1`, **and uncheck "Allow DNS server list to be overridden by
   DHCP/PPP on WAN"** if it's checked (it's OPNsense's default). This isn't
   optional: with it checked, your ISP's own DHCP-assigned DNS servers get
   written into `/etc/resolv.conf` *ahead of* the `127.0.0.1` entry you just
   added, and the firewall ends up using those directly — silently
   bypassing everything above.

> **Warning**
> This still makes the firewall's own DNS resolution depend on `ctrld` and
> on at least one of its configured upstreams being reachable over the WAN.
> Caching plus a fallback upstream (steps 1 and 4) closes most of the gap —
> a brief NextDNS outage no longer breaks already-seen names, and a longer
> one fails over to your fallback upstream instead of failing outright —
> but a total WAN outage still breaks resolution for anything not already
> cached, same as it would for any DNS setup that isn't doing fully local
> recursive resolution. That's a real difference from how Unbound behaved
> (it could resolve plenty locally, WAN or not), and it also means OPNsense's
> FQDN-based firewall aliases (Firewall → Aliases) stop refreshing during
> that window. Decide if that residual risk is acceptable before doing this.
>
> Two smaller things Unbound provided that this setup doesn't replicate:
> **DNS Rebinding Protection** (Unbound's `private-address`/`private-domain`
> options) has no equivalent here — if you relied on it, NextDNS has its own
> rebinding-protection toggle in its dashboard's Security settings. And this
> loopback listener only covers IPv4 (`127.0.0.1`); Unbound also
> auto-bound `::1`, which this setup doesn't replicate — not expected to
> matter unless something on the box specifically queries `::1`.

## 8. Disable Unbound (optional cleanup)

Once ctrld is verified working — for VLAN clients (step 6), and for the
firewall itself if you did step 7 — Unbound has no remaining job in this
architecture and can be disabled (Services → Unbound DNS → General →
uncheck **Enable Unbound**).

> **Warning**
> Do this last, and make sure something is already answering `127.0.0.1:53`
> for the box's own DNS before you do:
> - If you did step 7, ctrld already is — confirm with the `dig @127.0.0.1`
>   check above first.
> - If you skipped step 7, go to **System → Settings → General** and set
>   **DNS servers** to one or more resolvers not managed by this plugin
>   (e.g. Quad9 `9.9.9.9`, Cloudflare `1.1.1.1`) instead — understanding
>   that those specific lookups then bypass NextDNS, which is the tradeoff
>   step 7 exists to avoid.
>
> Either way, also uncheck **"Allow DNS server list to be overridden by
> DHCP/PPP on WAN"** on that same System → Settings → General page if it's
> checked (it's on by default). Otherwise your ISP's DHCP-assigned DNS
> servers get written ahead of whatever you just configured, and get used
> instead of it.
>
> Disabling Unbound without first confirming *something* answers
> `127.0.0.1:53` -- and that nothing else can preempt it -- breaks the
> firewall's own DNS entirely (package installs, firmware checks, NTP
> hostname lookups, etc.).
