# ctrld

## Introduction

[ctrld](https://github.com/Control-D-Inc/ctrld) is a DNS forwarding proxy
from Control-D. This plugin gives it a native OPNsense GUI and runs it
alongside the existing Dnsmasq service, rather than replacing it: ctrld
takes over the client-facing DNS listener role, and Dnsmasq keeps handling
DHCP unchanged while also answering reverse (`*.in-addr.arpa`) and local
`internal` zone lookups on its own loopback DNS port. Unbound has no role
in this architecture and can be disabled entirely — see the
[hybrid DNS how-to](hybrid-dns-howto.md).

Unlike Unbound, ctrld's policy engine can route DNS queries to different
upstream profiles based on the source network, and can tag outgoing
DNS-over-HTTPS requests with per-client metadata (IP/MAC/hostname). This
plugin exists to make that possible with NextDNS specifically: a separate
NextDNS profile per VLAN, with individual devices distinguishable in
NextDNS's own dashboard and logs. Unbound's forward-zone model cannot do
this — forward-zone selection in Unbound can't vary by source client or
network, full stop.

## Why this design

**Why not just run multiple independent validating resolvers** (Unbound or
PowerDNS Recursor instances), each statically bound to one interface with a
single fixed forward-zone to one NextDNS profile, instead of adding a new
plugin? That alternative was considered and deliberately not chosen. It
preserves full end-to-end DNSSEC validation and achieves per-VLAN profile
separation, but it structurally cannot achieve per-device metadata tagging
inside NextDNS's own dashboard, since that capability inherently requires
the dynamic per-query routing this alternative avoids by design. It's also
not a "just use what's already there" shortcut: OPNsense's native Unbound
plugin has no multi-instance support today (it manages exactly one merged
configuration via `.opnsense.d` includes), so this alternative would itself
require comparable new plugin infrastructure to become GUI-manageable.

## DNSSEC

ctrld is a forwarding proxy and does not independently validate DNSSEC
locally. NextDNS does validate DNSSEC on its own infrastructure and blocks
responses with invalid signatures or on zones that aren't properly signed,
so validation isn't absent from the pipeline — it happens upstream instead
of locally.

This makes the trust model **hop-by-hop** (trusting NextDNS's validation and
infrastructure integrity) rather than **end-to-end** (independently
verifiable locally regardless of any intermediate party). That's a real
difference, but not an unusual posture: most consumer encrypted-DNS tooling,
including AdGuard Home's typical configuration, works the same way — its
DNSSEC option sets the DO bit and surfaces the upstream's AD bit rather than
validating against a local trust anchor.

> **Note**
> Don't try to "fix" this by inserting Unbound as a hop between ctrld and
> NextDNS. That would strip the per-client metadata ctrld attaches to
> outgoing DoH requests and defeat the entire reason this plugin exists.

## Installation

Once the plugin's files are in place (see the repo
[README](../../../README.md) for the current dev-loop deployment process —
there's no `pkg install os-ctrld` yet), a new **Services → ctrld** menu
entry appears. It requires that `ctrld` itself is already installed and
runnable at `/usr/local/bin/ctrld` — this plugin manages an already-present
binary, it does not install one (see **Known limitations** below).

## Settings

### General tab

| Field | Description |
|---|---|
| Enable ctrld | Starts ctrld as the client-facing DNS listener on the interfaces configured under the Listeners tab. Dnsmasq keeps running unchanged. |
| Log level | Verbosity of ctrld's own service log. |
| Local-zone resolver host/port | Where `*.in-addr.arpa` and `internal` queries are delegated to — normally Dnsmasq's own loopback DNS listener. See the [hybrid DNS how-to](hybrid-dns-howto.md). |

### Listeners tab

One row per client-facing bind point. Each listener is bound to a specific
interface (never "all interfaces"/`0.0.0.0`) and port.

| Field | Description |
|---|---|
| Enabled | Whether this listener starts with the service. |
| Description | Label shown in the list and used as the policy name in the generated config. |
| Interface | The specific interface/VLAN to bind to. |
| Port | Usually 53. Checked against Unbound's/Dnsmasq's own bound ports on save, as a defensive check in case either is still running on that interface. |

### Upstreams tab

One row per upstream resolver profile — typically one NextDNS profile per
VLAN, plus one entry representing Dnsmasq for local-zone delegation.

| Field | Description |
|---|---|
| Enabled | Whether this profile is available as a routing target. |
| Name | Label used in policy rules and the generated config. |
| NextDNS profile ID (quick-add) | Paste a bare profile ID to auto-fill Type/Endpoint for a NextDNS DoH3 upstream. |
| Type | `doh`, `doh3`, `dot`, or `legacy`. |
| Endpoint | URL for `doh`/`doh3`, `host[:port]` for `dot`/`legacy`. |
| Timeout | Upstream response timeout, in milliseconds. |

### Policies tab

Maps a listener + match (CIDR / domain / MAC) to an upstream profile. The
primary use case is one row per VLAN, routing that VLAN's CIDR to a
dedicated NextDNS profile; domain-match rows handle local-zone delegation.

| Field | Description |
|---|---|
| Enabled | Whether this rule is applied. |
| Description | Label shown in the list. |
| Listener | Which listener this rule applies to. |
| Match type | `cidr` for VLAN/network routing, `domain` for split-horizon delegation (e.g. `*.in-addr.arpa`, `internal`), `mac` for per-device rules. |
| Match value | The CIDR, domain, or MAC address to match, depending on match type. |
| Upstream profile | Where matching queries are routed. |

### Local-Zone Delegation and Discovered Clients tabs

The Local-Zone Delegation tab is a guided shortcut that creates an Upstream
row for Dnsmasq (using the General tab's host/port) plus two Policy rows
delegating `168.192.in-addr.arpa` and `internal` to it — the same result as
adding those rows by hand. Discovered Clients mirrors `ctrld clients list`
output so you don't need SSH to see what ctrld has seen.

## Known limitations

- **No FreeBSD port for `ctrld` yet.** It's distributed only as a manual
  binary download from Control-D's GitHub releases. This plugin assumes
  `ctrld` is already installed, the same posture `dns/dnscrypt-proxy` takes
  toward its own port dependency.
- **`ctrld clients list` output parsing is a best-effort table parse.**
  ctrld has no JSON/API mode for client discovery; the Discovered Clients
  tab assumes a specific column layout that should be spot-checked against
  a real `ctrld clients list` run.
- **The generated `ctrld.toml` policy syntax (`networks`/`rules` arrays)**
  hasn't been verified against a live ctrld instance — cross-check the
  rendered config (`/etc/controld/ctrld.toml`) against ctrld's own
  `docs/config.md` the first time you enable the service.

## Troubleshooting

**A new/changed menu entry doesn't appear after redeploying files.**
OPNsense caches the merged menu/ACL XML to disk
(`/var/lib/php/tmp/opnsense_menu_cache.xml`) with a 1-hour TTL. A real
`pkg install` flushes this automatically via `rc.configure_plugins`; a
manual file sync doesn't. Run `/usr/local/etc/rc.configure_plugins` (or wait
out the hour) after copying files — `dns/ctrld/tools/deploy-dev.sh` already
does this for you.

**Config validation flags a listener as conflicting with Unbound/Dnsmasq.**
This is a live check against those services' own config models — resolve
the actual conflict (usually: Unbound is still enabled and bound to the
same interface/port a listener wants; disable it per the
[how-to](hybrid-dns-howto.md#7-disable-unbound-optional-cleanup)) rather
than working around it.
