# Incident postmortems

The big debugging sessions, written with the investigative path still in
them — including the theories that turned out wrong, and why they seemed
plausible at the time. If you only want the "what to avoid" takeaway, see
[gotchas.md](gotchas.md) instead; this file is for when you want to
understand *how* a root cause like that actually gets found.

## Discovered Clients tab staying empty

**Symptom:** real DNS traffic was flowing correctly through every VLAN, but
the Discovered Clients page never showed a single device.

**Investigation:** ctrld's `discover_dhcp` feature (on by default) needs an
explicit `dhcp_lease_file_path`/`_format` in its config — its own "common
file locations" auto-discovery has no idea where OPNsense keeps DHCP lease
data. Three candidate paths existed on the box: `/var/dhcpd/var/db/
dhcpd.leases` (ISC dhcpd format), `/var/etc/dnsmasq-leases` (an addn-hosts
style file Dnsmasq's own `.inc` clears on start, for a watcher script this
setup doesn't use), and `/var/db/dnsmasq.leases`. Checking `ps auxww | grep
dnsmasq` showed the live Dnsmasq process running with no
`--dhcp-leasefile` override, meaning it was using its FreeBSD-compiled-in
default — and comparing file `mtime`s across the three candidates showed
only `/var/db/dnsmasq.leases` being actively, recently updated, with the
other two sitting stale/empty.

**Root cause:** the generated `ctrld.toml` never set
`dhcp_lease_file_path`/`_format` at all, so ctrld's discovery silently
found nothing to watch.

**Fix:** added both fields to the template, fixed to
`/var/db/dnsmasq.leases` / `dnsmasq` format — not derived from anything the
Dnsmasq plugin exposes, since nothing does; hardcoded with a comment
explaining exactly how that path was confirmed live, so a future reader
doesn't have to re-derive it from scratch if Dnsmasq's own defaults ever
change.

**Lesson:** "confirm which file is actually being written to, right now, on
the real box" beat "read the docs for what the default *should* be" —
there were legitimate-looking candidate paths that were simply leftovers
from an earlier setup, and only live mtime comparison distinguished them.

## `ctrld clients list` showing real data, but the UI grid staying empty

**Symptom:** SSH'ing in and running `ctrld clients list` directly showed
plenty of discovered devices — but the exact same data never appeared in
the plugin's own Discovered Clients grid.

**Investigation:** the controller parsing that command's output was built
against an *assumed* table format (plain whitespace-aligned columns, with a
"Source" column) that had never actually been verified against real
output. The real box's output turned out to be a box-drawn ASCII table
instead:

```
+---------------+-----------------------+-------------------+----------------+
|      IP       |       Hostname        |        Mac        |   Discovered   |
+---------------+-----------------------+-------------------+----------------+
| 70.178.43.1   |                       | 00:1c:73:00:09:99 | arp            |
```

— pipe-delimited cells, `+---+` border lines to skip, and column names
"Mac"/"Discovered" (not "MAC"/"Source" as originally assumed). "Discovered"
is itself a comma-separated list of discovery methods (arp, dhcp, ptr,
hosts), not a single source value.

**Root cause:** the parser was matching nothing in the real output at all,
so every "row" silently failed to parse and the grid stayed empty with no
visible error.

**Fix:** rewrote the parser against the real sample output, verified with a
standalone PHP test script run against that exact real data before
shipping — not just eyeballed against the new understanding of the format.

**Lesson:** an assumption about a third-party tool's output format needs to
be verified against *that tool's real output*, not just plausible-sounding
docs or a first guess — and a parser that silently produces zero rows on a
format mismatch, rather than erroring, hides exactly this class of bug
until someone happens to compare it against known-real data.

## The `ajaxCall` vs `ajaxGet` bug

**Symptom:** clicking the local-zone delegation button always failed with
"Set the Local-zone resolver host/port on the General page first" —
regardless of whether those fields were actually set.

**Investigation:** the button's JS fetched General's settings via
`ajaxCall("/api/ctrld/general/get", {}, ...)`, and the response consistently
came back as an empty `[]`, which the code correctly (from its own
perspective) interpreted as "the setting isn't configured." Confirming
whether the *setting* was really missing meant reading the real OPNsense
core source for what `.../general/get` (backed by the framework's own
generic `getAction()`, since this controller defines no custom `getItemAction`)
actually requires: `if ($this->request->isGet()) { ...populate response... }`
— gating its entire response on the HTTP method being GET.

**Root cause:** `ajaxCall()` (OPNsense's own core JS helper) always sends a
POST — confirmed by reading the real `opnsense.js` source, not assumed.
Since this endpoint only populates its response for a genuine GET request,
every call via `ajaxCall()` silently got back the bare, empty default
(`$result = []`), which looked exactly like "host/port not set" from the
JS side, with no error, no wrong-status-code, nothing to hint at the real
cause.

**Fix:** switched that one call to `ajaxGet()` (the GET-only sibling).
Checked the rest of the plugin's own JS for the same pattern afterward —
every other `ajaxCall()` in the codebase targets a custom `searchItem`/
`addItem`-style action that doesn't gate on HTTP method, so this was the
only affected call site.

**Lesson:** a "setting appears unset" symptom with no error and no obvious
misconfiguration is worth checking one layer down, at the actual HTTP
request being made — the framework's own generic endpoints have real,
undocumented-in-the-UI method requirements that a plausible-looking helper
function name (`ajaxCall`, which sounds generic) doesn't hint at.

## The loopback listener source-address mystery

**Symptom:** the loopback listener's Policy rule, matching source CIDR
`127.0.0.1/32`, never fired for genuine locally-originated queries — they
fell through to a default-routing fallback instead, even though the query
was, by every reasonable expectation, coming from the box itself.

**Investigation:** direct testing (`drill @127.0.0.1 example.com`, and
separately capturing ctrld's own debug logs for the exact query) showed the
query's logged source address as the box's *LAN gateway IP*, not
`127.0.0.1` — for traffic that should, by ordinary BSD networking
semantics, never leave loopback at all. Checked and ruled out several
plausible explanations in turn: a CARP/VIP alias bound to `lo0` (`ifconfig
lo0` showed only the genuine `127.0.0.1`/`::1`, nothing else); a NAT/
redirect rule intercepting port 53 traffic (`pfctl -sn`'s full ruleset
showed only standard outbound-NAT entries for WAN egress, none of which
structurally apply to traffic that never transits the WAN interface).

**Root cause:** never definitively established. The most likely remaining
explanation — OPNsense's automatic outbound NAT enumerating `lo0`/
`127.0.0.0/8` among the networks it's willing to translate for WAN-bound
traffic — doesn't cleanly explain a packet that never actually left
loopback, and no further diagnostic (e.g. an actual packet capture on
`lo0` during a live reproduction) was run to close the gap.

**Fix:** rather than keep chasing the exact mechanism, widened the
loopback listener's policy match from `127.0.0.1/32` to a catch-all
(`0.0.0.0/0`), reasoning that the loopback *bind* itself — not the CIDR
match — is the real security boundary here: nothing outside the box can
ever physically reach a socket bound to `127.0.0.1`, so a broader match
condition on that one listener costs nothing.

**Lesson:** not every root cause is worth chasing to full ground truth,
especially on a live production system — once a fix is available whose
*safety* doesn't depend on understanding the exact mechanism, it's
reasonable to ship it and move on, as long as the reasoning for *why* it's
safe (not just "it worked") is written down for whoever revisits this
later and wonders why the match looks unusually permissive.

## The `SSL_ERROR_NO_CYPHER_OVERLAP` / browser DNS bypass saga

The longest single investigation in this project. Told roughly in the order
theories were tested, because the *elimination* is most of the value here.

**Symptom:** a self-hosted internal app (`homepage.apps.colereynolds.io`,
correctly resolving via the LAN's own DNS to its real internal IP) worked
fine from `curl`/`openssl` and from a phone on a VPN with NextDNS's own app
handling DNS directly — but failed in both Firefox (desktop) and Safari
(iPhone), with a TLS handshake error, when using the network's normal local
DNS.

**Theory 1 — plain DNS misconfiguration.** Ruled out immediately: `dig`
against the LAN's own resolver returned the correct internal IP every
time, exactly as configured.

**Theory 2 — the origin server's TLS itself was broken.** Ruled out: a
direct `openssl s_client` connection to the real IP:port completed a clean
TLS 1.3 handshake with a valid, unexpired Let's Encrypt certificate. A
server that just cleanly negotiated TLS 1.3 with `openssl` failing
specifically against a browser meant the browser almost certainly wasn't
even talking to that same server.

**Theory 3 — Firefox's Secure DNS (DoH) silently overriding local DNS.**
Checked directly in Firefox's own settings: DoH was confirmed *off*.
Ruled out on the most direct evidence available.

**Theory 4 — Encrypted Client Hello (ECH) mismatch.** The domain's *real
public* DNS (queried directly against `1.1.1.1`, bypassing the LAN
resolver entirely) turned out to have a genuine `HTTPS`/`SVCB` record with
real Cloudflare ECH configuration in it — advertising `h3`/`h2` ALPN and an
ECH key pointing at `cloudflare-ech.com`. The LAN's own DNS correctly
returned nothing for that record type (a legitimate "no ECH info, connect
normally" signal). Theory: a browser using a cached or differently-sourced
copy of the *real* Cloudflare ECH config while connecting to the *local*
IP would encrypt its ClientHello with a key the local server has no way to
decrypt. Tested directly by disabling ECH in Firefox
(`network.dns.echconfig.enabled`) — **the failure persisted unchanged**,
ruling this out too, despite being a strong-looking match for the symptom.

**Theory 5 — HTTP/2/HTTP/3 connection coalescing.** A captured packet trace
(`tshark`, filtered to the target IP:port during a live reproduction)
showed **zero packets reaching the local server at all** during Firefox's
failed attempt, alongside a `cf_clearance` cookie present on the request —
a cookie only ever set by Cloudflare's own edge. Theory: Firefox was
reusing an already-open, pooled connection to a *different* hostname whose
certificate happened to also cover this one, entirely skipping a fresh DNS
lookup for this specific request. Tested directly: fully quit and relaunch
Firefox (`Cmd+Q`, confirmed no lingering process in Activity Monitor),
forcing every connection pool to be torn down — **the failure persisted
unchanged again**, ruling this out too.

**Root cause, finally:** a wildcard CNAME for `*.colereynolds.io`, proxied
(orange-cloud) through Cloudflare, existed in the domain's public DNS —
with no explicit record for the `apps.colereynolds.io` subdomain
specifically, meaning that wildcard was the thing actually answering for
it publicly. This gave Cloudflare's edge *something* to answer with for
this exact hostname, over the real public internet, regardless of which
specific client-side mechanism (DoH, ECH, coalescing, or something never
individually tested) a given browser used to reach it that day. Every
theory above was independently plausible and individually disproven — the
actual answer was one layer further out than any single client-side
mechanism.

**Fix:** deleted the wildcard CNAME entirely from Cloudflare's DNS.
Confirmed working (after a few minutes for DNS/negative-caching to settle)
on both Firefox and Safari, from a completely cold state — no client-side
setting changed on either browser was actually load-bearing for the fix.

**A related, second incident found and fixed in the same session:** while
walling off the `apps.colereynolds.io` subdomain from any public exposure,
the firewall itself briefly lost the ability to resolve DNS entirely.
That turned out to be a *separate*, simpler problem — Unbound had been
disabled without first setting up ctrld's own loopback listener (see the
architecture decision above), so nothing at all was listening on
`127.0.0.1:53` for the firewall's own resolution — fixed by completing that
setup properly (see `docs/hybrid-dns-howto.md`'s loopback-listener step),
after a temporary point-System-DNS-at-a-public-resolver stopgap to restore
connectivity while diagnosing.

**Lesson:** when a symptom has several individually-plausible client-side
explanations, disproving each one directly (not just "it's probably fine
now") is worth the time — it's exactly what narrowed this down to "the
actual problem is one layer further out than any of these," which no
amount of browser-setting tweaking would ever have found. A `cf_clearance`
cookie and a zero-packet capture were the two pieces of hard evidence that
actually moved the investigation forward; everything before that was
elimination.

## The WireGuard listener saga, two acts

The most expensive lesson in this project, told in two acts because it
took two real, separately-deployed, separately-tested fixes to actually
land.

### Act 1: the routing gap that broke every listener, not just WireGuard's

**Symptom:** WireGuard's listener failed to start with
`listen udp: lookup None on 127.0.0.1:53: ... connection refused` — ctrld
was trying to resolve the literal string `"None"` as a hostname, because a
config.xml lookup for the interface's IP had found nothing (WireGuard/
OpenVPN/DHCP/PPPoE-assigned interfaces don't store an `<ipaddr>` field the
way a static VLAN does — see [gotchas.md](gotchas.md)). First fix: resolve
each listener's live address in a PHP reconfigure hook
(`ctrld_resolve_listener_ips()`), persist it into a new `Listener.resolvedIp`
model field, and have the Jinja2 template read that instead of doing its
own (broken-for-dynamic-interfaces) config.xml lookup — with a template-side
guard added at the same time to skip (not crash on) any listener whose
resolved IP was still empty.

**What actually happened after deploying that fix:** WireGuard's listener
started working — but shortly after, on the very next routine "Apply from
the Listeners page" click, **every VLAN listener stopped resolving DNS at
all**, not just WireGuard's.

**Investigation:** the rendered `ctrld.toml` showed only the loopback
listener present — every other listener's block was missing entirely.
Since the new template-side guard skips any listener with an empty
resolved IP, this meant `resolvedIp` had gone empty for *every* listener,
not just WireGuard's — which pointed away from "WireGuard specifically
still can't resolve" and toward "the PHP resolution step didn't run at
all" for this particular request.

**Root cause:** reading `ApiMutableServiceControllerBase::reconfigureAction()`'s
real core source directly confirmed it: the GUI's own Apply button calls
*only* `template reload` and service start/stop — it never calls
`plugins_configure()` or any plugin hook at all. The PHP resolution step
only ever ran via the plugin's separate `dns` boot/WAN-event hook, which
Apply-button clicks never triggered. The very first successful WireGuard
test happened to work because *some* earlier boot/WAN-event cycle had
already populated `resolvedIp` correctly for every listener at that point
— but the very next Apply click re-rendered the template from whatever was
currently in config.xml, without ever refreshing it, and (for reasons not
further chased once the deeper design problem was found) the values it
found there were empty across the board.

**Fix (this act):** reverted the entire commit, restoring the
pre-WireGuard-fix behavior — VLAN DNS working again, WireGuard broken
again — while a proper redesign was worked out. See the "Why listener IP
resolution ended up as an rc.d precmd script" architecture decision for
what replaced it: a second, independent finding from the code review that
prompted the redesign (persisting resolved data into config.xml at all
risks a lost-update race against a concurrent GUI save) meant the fix
wasn't just "call the resolve function from one more place" — it was "stop
persisting this into config.xml at all."

**Lesson:** a fix that's tested by triggering it once, successfully, isn't
the same as a fix that's tested against *every real path* that could
trigger it. The GUI's Apply button and the boot/WAN-event hook look, from
a user's perspective, like two ways of doing "the same thing" — reconfigure
the service — but they turned out to be two structurally different code
paths that happened to overlap in outcome exactly once.

### Act 2: the missing PHP require that survived every local test

**Symptom:** after the full redesign (rc.d precmd hook + direct TOML patch,
no config.xml writes), re-enabling the WireGuard listener produced the
*exact same* `lookup None` fatal error as Act 1 — despite the new design
being verified, locally, against a standalone Jinja2 render harness *and* a
standalone PHP test of the patch script's own block-detection/removal
logic, both passing cleanly, including a case specifically simulating an
unresolvable listener.

**Investigation:** the patch script's own `try`/`catch` wraps every real
step and logs failures to syslog rather than letting them propagate — which
is exactly why "never let this crash the whole service" was the design
goal, but it also meant a failure inside the resolution step would be
silently swallowed with no visible symptom beyond "the fix didn't seem to
do anything." Running the patch script by hand, directly, exactly as
`rc.d`'s precmd hook invokes it, produced no output and no obvious error —
but the rendered config still showed `ip = 'None'` for WireGuard's
listener afterward. Calling `get_interface_ip()` *directly*, standalone,
with the same require list the script used
(`config.inc`, `interfaces.inc`) produced a real, visible fatal error:
`Call to undefined function is_ipaddrv4()`.

**Root cause:** `interfaces.inc`'s own `get_interface_ip()` internally
calls `is_ipaddrv4()`, which is defined in `util.inc` — a file
`interfaces.inc` never requires on its own. The script's require list
(`config.inc` + `interfaces.inc`) was exactly what a *plausible-sounding*
reading of "what does this function need" would produce, and was
sufficient for every earlier local test (which only exercised the
regex/parsing logic in isolation, never the real PHP legacy bootstrap,
since none of `config.inc`/`interfaces.inc`/`util.inc` exist on a
non-OPNsense machine to test against at all).

**Fix:** rather than add just the one missing `util.inc` and risk finding
a *third* missing dependency on the next test, found a real, confirmed
OPNsense core script that calls `get_interface_ip()` the same way
(`scripts/shell/setaddr.php`) and matched its full require list exactly:
`config.inc`, `interfaces.inc`, `util.inc`, `filter.inc`, `system.inc`.
Verified directly on the live box, standalone, before considering it fixed
— `get_interface_ip('opt3')` correctly returned WireGuard's real address —
then redeployed and confirmed all six configured listeners started
successfully via `ctrld.log`'s own `"starting DNS server on listener.N"`
lines, not just an absence of fatal errors.

**Lesson:** local testing on a machine that doesn't have the real
production environment available (this plugin's legacy PHP bootstrap
requires a real OPNsense box; nothing about it can be faithfully simulated
on a Mac) has a real, structural blind spot — the regex/rendering logic
tested cleanly precisely *because* it was the part that could be tested
locally, while the part that actually broke (a PHP include dependency) was
exactly the part that couldn't be. When a class of bug is known to be
untestable locally, the mitigation isn't "test harder" — it's "verify
against a *real, confirmed* example doing the exact same thing," which is
what actually worked here, twice: once as a near-miss (the earlier caution
around `gen_subnet()`), and once for real, live, after the fact.

## Phantom clients from a stale ISC dhcpd lease file, immune to restarts

**Symptom:** the Discovered Clients page showed devices that "shouldn't
have come from anywhere" — hostnames/MACs not recognized as anything on
the network. Restarting `ctrld` about a dozen times over an hour didn't
clear them, which ruled out the obvious first theory (accumulated
in-memory client-table cruft — see the `gotchas.md` entry on ctrld never
expiring a discovered client) up front: an in-memory-only problem would
have been reset by any one of those restarts.

**Investigation:** reading ctrld's real Go source
(`internal/clientinfo/dhcp_lease_files.go`) showed `discover_dhcp`
doesn't only watch the single `dhcp_lease_file_path` this plugin's
`ctrld.toml` template sets — its `init()` unconditionally tries roughly a
dozen more hardcoded, platform-specific lease-file paths too (OpenWrt,
Merlin, UDM/UDR, Synology, Tomato, EdgeOS, Firewalla, and two OPNsense/
pfSense-family ones: ISC `dhcpd`'s and Kea's), silently ignoring whichever
don't exist. Checking all of them on the live box turned up
`/var/dhcpd/var/db/dhcpd.leases` — real content, 1829 bytes, owned by
`dhcpd:dhcpd`, `mtime` over a month old. `cat`ing it confirmed its
MAC/hostname entries matched the phantom clients exactly.

**Root cause:** this box's DHCP has been Dnsmasq for this whole project
(see the `dhcp_lease_file_path` postmortem above), but OPNsense's legacy
built-in ISC `dhcpd` had apparently run at some earlier point and left a
real lease file behind — note that same earlier postmortem's own
investigation found this exact path "stale/empty" at the time, so
whatever wrote real content into it did so sometime *after* that
investigation, not before. `service dhcpd status` confirmed the `dhcpd`
binary doesn't even exist on this box anymore ("does not exist in
`/etc/rc.d`... or is not executable") — fully dead, not a currently
misconfigured second DHCP server. ctrld's own client tables are
append-only per source and never diffed against a shrinking/disappearing
file (see the `gotchas.md` entry), so every restart re-ingested the exact
same stale entries fresh, explaining why restarting a dozen times did
nothing.

**Fix:** `mv`d the stale lease file aside (not deleted, in case the
diagnosis was somehow wrong) and restarted `ctrld` once more — phantom
clients gone.

**Lesson:** "discovery" features that auto-scan a list of well-known file
paths are a real, config-independent attack surface for stale data on any
box with a history — this plugin's own `dhcp_lease_file_path` setting
only adds one *more* path to watch, it doesn't make ctrld watch
*exclusively* that one. Worth remembering for any future "ctrld is
showing something wrong" report: check `internal/clientinfo/
dhcp_lease_files.go`'s current hardcoded list against what actually exists
on the box before assuming the bug is in this plugin's own generated
config.
