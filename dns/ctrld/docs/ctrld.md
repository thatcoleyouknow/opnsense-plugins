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

### General page

| Field | Description |
|---|---|
| Enable ctrld | Starts ctrld as the client-facing DNS listener on the interfaces configured under the Listeners page. Dnsmasq keeps running unchanged. |
| Log level | Verbosity of ctrld's own service log. |
| Local-zone resolver host/port | Where `*.in-addr.arpa` and `internal` queries are delegated to — normally Dnsmasq's own loopback DNS listener. See the [hybrid DNS how-to](hybrid-dns-howto.md). |
| Enable caching | Caches resolved responses in ctrld itself for each record's own TTL. On by default. |
| Cache size | Maximum number of cached records. |
| Cache TTL override | 0 respects each record's real TTL; a positive value (seconds) overrides it. |
| Serve stale on failure | Keeps answering already-cached names from a stale cache during an upstream outage, instead of failing outright. On by default; requires caching to be enabled. |

### Listeners page

One row per client-facing bind point. Each listener is bound to a specific
interface (never "all interfaces"/`0.0.0.0`), IP version, and port. A
special "Loopback (127.0.0.1 / ::1)" interface option is also offered, for
routing the firewall's own DNS through ctrld -- see the
[hybrid DNS how-to](hybrid-dns-howto.md#7-optional-route-the-firewalls-own-dns-through-ctrld-too).

A single listener binds one address, so a VLAN that needs both IPv4 and
IPv6 client-facing DNS needs two listener rows (same interface, same port,
different IP version).

| Field | Description |
|---|---|
| Enabled | Whether this listener starts with the service. |
| Description | Label shown in the list and used as the policy name in the generated config. |
| Interface | The specific interface/VLAN to bind to, or Loopback for the firewall's own DNS. |
| IP version | IPv4 or IPv6 -- which of the interface's addresses (or which loopback address) to bind. |
| Port | Usually 53. Checked against Unbound's/Dnsmasq's own bound ports on save, as a defensive check in case either is still running on that interface. |

### Upstreams page

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

### Policies page

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
| Fallback upstream | Optional. Tried only if the primary upstream above times out or returns SERVFAIL -- not on every query. Leave blank for no fallback. |

### Local-Zone Delegation and Discovered Clients pages

The Local-Zone Delegation page is a guided shortcut: clicking **Create
local-zone delegation rules** creates an Upstream row for Dnsmasq (using
the General page's host/port) plus one pair of Policy rows *per enabled
listener* — one delegating `168.192.in-addr.arpa`, one delegating
`internal`, both to that Upstream. It reuses an existing "Local resolver"
Upstream row if one is already there, rather than creating a duplicate, so
it's safe to click again after adding a new listener (see the
[hybrid DNS how-to's optional loopback-listener step](hybrid-dns-howto.md#7-optional-route-the-firewalls-own-dns-through-ctrld-too)
for a case where that matters). `168.192.in-addr.arpa` covers the common
`192.168.0.0/16` home range specifically; add further reverse-zone Policy
rows by hand on the Policies page for other private ranges (`10.0.0.0/8`,
etc.). Equivalent by hand: an Upstream row named "Local resolver", type
`legacy`, endpoint matching the General page's local-zone resolver
host/port, and one `domain`-match Policy row per zone per listener.

Discovered Clients mirrors `ctrld clients list` output so you don't need
SSH to see what ctrld has seen.

## Log

**Services → ctrld → Log File**. Shows ctrld's own service log (`/var/log/ctrld.log`, set via
`log_path` in the rendered config -- not user-configurable), newest line
first, as a searchable/sortable grid -- the same search box, row-count
selector, pagination, and refresh icon every other grid in this plugin
(and other OPNsense plugins' own Log File pages) already has, rather than
a static block of text. This is the first place to look when something
doesn't seem to be working: an empty log after enabling the service
usually means ctrld never actually started.

## Known limitations

- **No FreeBSD port for `ctrld` yet.** It's distributed only as a manual
  binary download from Control-D's GitHub releases. This plugin assumes
  `ctrld` is already installed, the same posture `dns/dnscrypt-proxy` takes
  toward its own port dependency.
- **`ctrld clients list` output parsing.** ctrld has no JSON/API mode for
  client discovery, so `ClientsController::searchAction()` parses its
  human-readable table: a box-drawn ASCII table (`+---+` border lines,
  `|`-delimited cells), with columns named IP/Hostname/Mac/Discovered
  (verified against a live instance's real output -- an earlier version of
  this parser assumed a plain whitespace-aligned table with a "Source"
  column instead, which matched nothing and left the page empty). If a
  future `ctrld` version changes this table format again, the parser will
  need updating the same way -- run `ctrld clients list` directly (SSH) to
  compare its real output against the parser's assumptions.
- **The generated `ctrld.toml` matches ctrld's documented config shape**
  (`networks`/`rules` arrays, multi-upstream fallback lists,
  `failover_rcodes`) verified against ctrld's own `docs/config.md` and by
  rendering the template against sample data and parsing the result as
  TOML -- but no live `ctrld` instance has actually consumed this file yet.
  Spot-check the rendered config (`/etc/controld/ctrld.toml`) the first
  time you enable the service.
- **No DNS Rebinding Protection.** Unbound's `private-address`/
  `private-domain` options have no equivalent in this plugin. If you relied
  on Unbound for this, NextDNS has its own rebinding-protection toggle in
  its dashboard's Security settings.
- **IPv6 listeners are unverified against a live `ctrld` instance.**
  Selecting "IPv6" for a listener's IP version resolves the interface's
  `ipaddrv6` (or `::1` for Loopback) and passes it straight through as
  ctrld's listener `ip` value -- this is standard for Go network code and
  should work, but ctrld's own docs don't show an explicit IPv6 listener
  example to confirm against. Spot-check `/etc/controld/ctrld.toml` and
  that the service actually starts, the first time you use it.

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
[how-to](hybrid-dns-howto.md#8-disable-unbound-optional-cleanup)) rather
than working around it.

**The firewall itself still seems to be using another DNS server after
pointing System DNS servers at ctrld's loopback listener.** Check
**System → Settings → General → "Allow DNS server list to be overridden by
DHCP/PPP on WAN"** -- it's on by default, and when it is, your ISP's
DHCP-assigned DNS servers get written into `/etc/resolv.conf` ahead of
whatever's manually configured. Uncheck it. See the how-to's
[loopback-listener step](hybrid-dns-howto.md#7-optional-route-the-firewalls-own-dns-through-ctrld-too).

**The service-status widget (top of the page) goes blank/hidden after
clicking Apply, and doesn't come back without a page refresh.** Check
**Services → ctrld → Log** first -- if it's empty, ctrld likely never started
(`/usr/local/bin/ctrld` missing, a malformed `ctrld.toml`, or a port
conflict) and `/api/ctrld/service/status` is getting back something the
widget-refresh code doesn't know how to render, leaving it stuck in a
half-updated state. If Apply itself fails outright, you'll now get an
error dialog naming the actual problem; the widget going blank with *no*
dialog specifically points at the status check itself, not the reconfigure
step. SSH and check `service ctrld status` / `ps aux | grep ctrld`
directly to confirm whether it's actually running.

**A newly-added action (e.g. the Log page) returns "Action not allowed or
missing."** configd (`processhandler.py`'s `ActionHandler.load_config()`)
reads every `actions.d/actions_*.conf` file exactly once, at its own
process startup -- not per-request, and not on file change. Syncing a
changed `actions_ctrld.conf` to disk (e.g. via `deploy-dev.sh`) has no
effect on an already-running configd until it's restarted: `service
configd restart`. `deploy-dev.sh` does this automatically as of the
version in this repo; if you're seeing this on an older checkout, restart
configd by hand.

**Discovered Clients stays empty despite the service running.** Check in
this order: (1) has any client on a configured VLAN actually sent a DNS
query through a ctrld listener yet -- if nothing's queried through it,
there's nothing to discover, which is correct behavior, not a bug; (2)
ctrld's own DHCP-lease-based client discovery (`discover_dhcp`, on by
default) relies on finding a lease file -- its own "common file
locations" auto-discovery has no reason to know OPNsense's specific
paths, which is why this plugin's template explicitly sets
`dhcp_lease_file_path`/`dhcp_lease_file_format` to
`/var/db/dnsmasq.leases` (confirmed as dnsmasq's actual FreeBSD-default
lease file on a live box -- `ps auxww | grep dnsmasq` showed no
`--dhcp-leasefile` override, and that file's mtime tracks real DHCP
activity while OPNsense's other two lease-adjacent paths sat stale);
(3) even once found, `discover_refresh_interval` defaults to 120 seconds,
so allow a couple of minutes after traffic flows before expecting to see
anything; (4) if `ctrld clients list` (SSH) shows real rows but the UI
still doesn't, that's a parser mismatch -- see the known limitation above
for the exact table format the parser expects, and compare against what
your version of `ctrld` actually prints.

**`service ctrld stop` appears to succeed but a new ctrld process is
running again moments later, and/or `service ctrld start` fails with
"process already running, pid: -1".** `rc.d/ctrld` runs ctrld under
`daemon(8)` with `-r` (auto-restart on exit) for resilience. Confirmed
against `daemon(8)`'s own man page: the `-p`/`--child-pidfile` flag records
the *child's* PID, and rc.subr's stop action only knows how to signal
whatever PID is in the tracked pidfile -- so with only `-p` set, `stop`
kills the child, but the still-running daemon(8) *supervisor* process
(separate from the child, visible in `ps` as `daemon: ...(daemon)`)
immediately respawns it per `-r`, since nothing ever told the supervisor
itself to stop. Fixed by also passing `-P`/`--supervisor-pidfile` and
pointing rc.subr's own `pidfile`/`procname` at the *supervisor*, not the
child -- confirmed via the same man page that signaling the supervisor
correctly forwards the kill to the child first, with no restart. If
you're on an older checkout, this may explain the earlier `/etc/controld/
ctrld.toml` mystery too: with `stop` not actually working, a stale ctrld
process could have kept running on whatever config it loaded at its own
last real start, regardless of how many times the file was correctly
regenerated afterward, or how many "reload" calls followed. Redeploy and
retry the stop/reload/start sequence from scratch.
