# Architecture decisions

One entry per real design decision: the problem, what was chosen, what else
was considered and why it lost, and what that decision costs going forward.
Roughly chronological — later decisions sometimes build on or reverse
earlier ones, and that's noted where it happened.

## Why ctrld at all, instead of multiple Unbound instances

**Problem:** route DNS per-VLAN to different NextDNS profiles, with
individual devices distinguishable in NextDNS's own dashboard.

**Decision:** wrap `ctrld` (Control-D's Go DNS forwarding proxy) in a native
OPNsense plugin, rather than running several independent, statically-bound
Unbound (or PowerDNS Recursor) instances, one per VLAN, each with its own
fixed forward-zone to one NextDNS profile.

**Why not the alternative:** the multi-Unbound approach preserves full
end-to-end DNSSEC validation and does achieve per-VLAN profile separation —
but it structurally cannot achieve per-*device* metadata tagging inside
NextDNS's dashboard, because that requires ctrld's dynamic per-query
routing and per-client metadata headers, which a static forward-zone can't
express. It's also not a "just use what's already there" shortcut: OPNsense's
native Unbound plugin has no multi-instance support (it manages exactly one
merged configuration), so this alternative would have needed comparable new
plugin infrastructure anyway.

**Cost:** DNSSEC validation moves from end-to-end (locally verifiable) to
hop-by-hop (trusting NextDNS's own validation) — see `docs/ctrld.md`'s
DNSSEC section for the full tradeoff. Also no independent-of-Anthropic
FreeBSD port for `ctrld` exists yet, so the plugin manages an
already-manually-installed binary rather than a package dependency.

## Why Dnsmasq for local-zone delegation, not Unbound

**Problem:** `*.in-addr.arpa` reverse lookups and the `internal` domain need
to keep resolving locally (via DHCP lease hostnames) instead of being routed
to NextDNS, which knows nothing about them.

**Original decision, later reversed:** the plugin originally delegated these
to Unbound, rebound to listen on loopback only (freeing the VLAN-facing
interfaces for ctrld), keeping Unbound's own existing forward-zone
configuration to Dnsmasq untouched underneath it.

**Reversed to:** delegate directly to Dnsmasq's own loopback DNS listener,
removing Unbound from the architecture entirely.

**Why the reversal:** Unbound had no remaining job once ctrld took over
client-facing listening — it was purely a delegation hop to Dnsmasq, adding
one more moving part (and one more thing that could be misconfigured, as it
briefly was — see the "Local-zone resolver defaults pointed at the wrong
port" note below) for no benefit. Removing it simplified the whole stack to
two services (ctrld + Dnsmasq) instead of three.

**Cost:** lost two things Unbound provided that this design doesn't
replicate: DNS Rebinding Protection (`private-address`/`private-domain` —
NextDNS has its own equivalent toggle) and automatic `::1` binding for the
firewall's own IPv6 loopback resolution (see the Loopback pseudo-interface
decision below, which is IPv4-only). Both are documented as known
limitations in `docs/ctrld.md`.

**A defaults bug this reversal surfaced:** partway through, the plugin's
"Local-zone resolver" default port was still `53053` (Dnsmasq's loopback
port) while the *documented* architecture at that exact moment still said
Unbound (port `53`) — an inconsistency caught before it shipped broadly, but
a good reminder that a default value and the docs describing it can drift
independently during a mid-flight architecture change. Fix the default and
the prose in the same commit.

## Why a "Loopback (127.0.0.1 / ::1)" pseudo-interface option

**Problem:** by default, OPNsense itself (not just VLAN clients) resolves
DNS through `127.0.0.1`. With Unbound gone, nothing was listening there
unless a listener could bind to it — but OPNsense's stock `InterfaceField`
(what every other interface-picking dropdown in OPNsense uses) only lists
*assigned* interfaces, and loopback isn't one.

**Decision:** `ListenerInterfaceField` extends the stock field and adds one
synthetic `lo0` option, special-cased in the Jinja2 template to resolve to
the literal address `127.0.0.1` or `::1` (picked by the listener's own
`ipVersion` field) instead of doing a real interface lookup.

**Consequence that took a while to notice:** because this is a genuinely
fake, non-interface option, `patch_listener_ips.php` (see the listener IP
resolution decision, and its postmortem) explicitly skips `lo0` — there's no
real interface to call `get_interface_ip()` against, and trying to would be
meaningless. Any future pseudo-interface option added the same way needs
the same explicit skip, in both the template *and* the patch script.

## Why blade-split pages instead of tabs

**Problem:** the plugin originally had one "General" page with everything —
service settings, listeners, upstreams, policies, local-zone delegation, log
viewer — as tabs on a single Volt view.

**Decision:** split into separate `Services → ctrld → *` pages (General,
Listeners, Upstreams, Policies, Discovered Clients, Log File), matching
Unbound's own real menu structure (`Menu.xml`: one `<Tag>` per blade under a
shared parent).

**Why:** this is simply how other OPNsense plugins are organized, confirmed
against a real deployed example rather than assumed, and it's what the
plugin's own author explicitly asked for ("that's the pattern other plugins
seem to use and I find that easier to navigate").

**What it broke, briefly, and had to be fixed alongside the split:** two
things silently depended on being on the same page as General's own form
fields — the local-zone delegation JS (read host/port directly out of DOM
fields that no longer existed on that page; switched to an `ajaxGet` call
against `/api/ctrld/general/get`) and the dashboard widget's per-device link
(pointed at `/ui/ctrld/general#clients`, a URL fragment that stopped
existing; repointed at the new `/ui/ctrld/clients` page). Splitting a
single-page UI into multiple pages needs an explicit audit for exactly this
class of cross-page assumption, not just moving the markup.

## Why the local-zone delegation button lives on the Policies page, not its own blade

**Original decision:** local-zone delegation got its own "Local-Zone
Delegation" blade with one button on it, as part of the tab-split above.

**Reversed to:** folded into the Policies page itself, as a button below the
grid it populates.

**Why:** the button's *only* effect is creating rows in the Policies grid —
having it live on a separate page meant clicking it visibly mutated a
*different* page's data with no on-page feedback beyond a popup dialog,
which read as "weird" to actually use. Moving it onto the page it affects,
and having it call `$("#grid-policies").bootgrid('reload')` on completion,
means the new rows show up immediately in the same view that triggered them.

**Generalizable rule this established:** a "quick-add" or bulk-action button
belongs on the page whose grid it mutates, not wherever seems thematically
tidy. This shaped the later arbitrary-domain quick-add feature (below) too
— it went straight onto the Policies page from the start, no detour through
its own blade first.

## Why the CIDR auto-suggest force-overwrites on listener change (but not match-type change)

**Problem:** for a `cidr`-type Policy rule, the Match Value field can be
auto-filled from the selected Listener's own interface CIDR, as a
convenience. First version: only filled the field when it was empty.

**Bug this caused:** the Listener field is `Required="Y"`, so per
`BaseListField`'s own option-generation logic, its dropdown never gets a
blank placeholder — it defaults to selecting the *first* listener in the
list. That default selection is itself a "change" event, which fired the
auto-suggest once, filling the field. When the user then picked the listener
they *actually* wanted, the "only fill if empty" guard now blocked the
update, since the field was no longer empty — so the suggestion silently
stopped updating after the very first (usually wrong) listener.

**Decision:** force-overwrite the match value on every Listener *change*
event specifically, but keep the original "only fill if empty" behavior for
Match Type changes (switching between cidr/domain/mac is more often
exploratory, and clobbering a value the user already typed for a different
match type would be more surprising there than useful).

**Why this specific split, not "always force" or "never force":** always
forcing on *every* trigger (including match-type changes) risks destroying
a value someone deliberately typed while just glancing at another match
type. Never forcing reproduces the original bug. The asymmetry — listener
changes are (almost always) a deliberate "I want this VLAN" signal;
match-type changes are more often just browsing — is what actually matches
how a user interacts with the form.

## Why domain-match quick-adds create both the exact domain and a `*.` wildcard rule

**Problem:** the arbitrary-domain quick-add on the Policies page needs to
decide what rule(s) to actually create for "delegate example.com to my
internal resolver."

**Finding that shaped the decision:** ctrld's own domain-rule matching is
**exact string match only** — confirmed by reading ctrld's real Go source
(`cmd/cli/dns_proxy.go`'s `wildcardMatches()`): a plain `"example.com"` rule
never falls back to a suffix check, so it does not also match
`foo.example.com`. A `"*.example.com"` rule does a suffix match instead
(covering subdomains at any depth), but by the same logic does *not* also
match the bare `example.com` itself.

**Decision:** the quick-add's "Include subdomains" checkbox (checked by
default) creates *both* rules together, since "delegate this domain" almost
always means "and everything under it" in practice, and getting that
requires two rules, not one clever pattern.

**Where this same fact bit again later:** the exact-match-only behavior is
also why local-zone delegation only ever creates rules for the two fixed
zones it needs (`168.192.in-addr.arpa`, `internal`) rather than trying to
be clever with a broader pattern — and it's called out explicitly in the
Match Value field's own help text and in `docs/ctrld.md`, specifically so a
future reader doesn't have to rediscover it by testing.

## Why the loopback listener's CIDR policy is `0.0.0.0/0`, not `127.0.0.1/32`

**Problem:** the loopback listener (see above) needs a policy rule routing
its traffic somewhere, matched by source CIDR. The obviously "correct" match
is `127.0.0.1/32` — nothing else should ever be able to reach a socket
bound to loopback.

**What actually happened:** on the real deployed box, queries genuinely
arriving at the loopback listener showed a source address of the box's LAN
gateway IP, not `127.0.0.1` — and a `127.0.0.1/32` policy match
consequently never fired, silently falling through to a default-routing
fallback instead (see
[incident-postmortems.md](incident-postmortems.md#the-loopback-listener-source-address-mystery)
for the full investigation, which did not reach a definitive root cause).

**Decision:** widen the match to `0.0.0.0/0` (or the IPv6 equivalent) for
loopback-listener policies specifically.

**Why this is safe despite being "too broad" on paper:** the real security
boundary here isn't the CIDR match at all — it's the loopback *bind*
itself. No packet from anywhere on the network can physically land on a
socket bound to `127.0.0.1`, regardless of what CIDR a policy rule checks
against it. The CIDR match was always redundant defense-in-depth for this
one listener, not the actual gate, so widening it costs nothing and fixes a
real, reproducible failure whose exact mechanism was never fully explained.

## Why listener IP resolution ended up as an rc.d precmd script, not a persisted model field

This is the single most expensive architectural lesson in the whole
project, and it took two real, deployed, tested-in-production attempts to
land correctly. The full blow-by-blow (including the outage each attempt's
first version caused) is in
[incident-postmortems.md](incident-postmortems.md#the-wireguard-listener-saga-two-acts).
The short version of *why* the final design looks the way it does:

**The core constraint, discovered the hard way:** OPNsense's `configd`
Jinja2 template pipeline (`service/modules/addons/template_helpers.py` +
`service/modules/template.py`, read directly from real core source) has
**no access to live system/interface state at all** — its only data source
is `config.xml`, fed in once via `Template.set_config()`. There is no hook
for a plugin to inject additional computed data into that pipeline. A
WireGuard/OpenVPN/DHCP/PPPoE-assigned interface's address is *only* live
kernel state (`get_interface_ip()`), never a static config.xml field — so
the template genuinely cannot resolve it on its own, no matter how the
Jinja2 is written.

**Attempt 1 (persist a resolved value into config.xml via a PHP model
field):** technically resolves the constraint above — PHP *can* call
`get_interface_ip()` — but introduces two new problems: the PHP resolution
step only ran via the plugin's `dns` boot/WAN-event hook, which the GUI's
own Apply button never actually calls (it only does `template reload` +
service start/stop), so Apply-triggered changes used stale/empty resolved
data; and even with that routing fixed, persisting derived, non-user-intent
data into config.xml on every reconfigure risks a lost-update race against
a concurrent GUI save, with no clean way to eliminate it from inside a
config.xml-writing reconfigure hook.

**Final design (rc.d precmd + direct TOML patch, zero config.xml writes):**
resolve the interface's address in a script (`patch_listener_ips.php`) run
via `rc.d/ctrld`'s `start_precmd`/`reload_precmd` — the one place *every*
real path to actually starting ctrld converges (GUI Apply, boot/WAN-event
hook, and a bare `service ctrld restart` from the console, which neither of
the PHP-level hooks would ever catch). It patches the already-rendered
`/etc/controld/ctrld.toml` directly, in place, and never touches
config.xml at all — so there's no persisted state to race against, and
nothing to correctly-route-to in the first place, since it isn't reached
through OPNsense's normal PHP action-routing at all.

**Also decided:** a listener whose interface still can't resolve an address
gets its entire block *removed* from the rendered config, not left with an
invalid placeholder — ctrld is a single process managing every listener, so
one bad listener writing an invalid bind address crashes the whole service
and takes every *other*, perfectly good listener down with it. Dropping the
one listener for that cycle is a strictly smaller failure.

## Why the "Local resolver" upstream reuse checks the endpoint, not just the name

**Problem:** both the local-zone delegation button and the domain quick-add
need a "Local resolver" Upstream row to route domain-match rules to, and
reuse an existing one (matched by name) rather than creating duplicates.

**Bug this had:** reuse was name-only — if the General page's
`localZoneResolverHost`/`Port` ever changed *after* the upstream was first
created, re-running either button found the existing row by name, reused it
unchanged, and reported success, while silently continuing to route to the
old, now-wrong endpoint.

**Decision:** on reuse, compare the existing row's `endpoint` against the
*current* General-page host:port, and update it via `setItem` first if
they've drifted, before proceeding. Found and fixed as part of the Opus
code review response, not by a live incident — noted here specifically
because it's the kind of "quiet correctness bug" that a code review catches
and live testing usually doesn't (nothing crashes; it just silently routes
to the wrong place).

## Why `Policy.matchValue` needed both a `<Mask>` and fixed regex anchors

**Problem:** every user-supplied string that flows into the generated
`ctrld.toml` needs to be safe against breaking out of the TOML
`'{{ value }}'` string it gets wrapped in. `matchValue` was the one field of
this kind with no `<Mask>` field-level guard at all, relying solely on
`Policy::performValidation()`'s own hand-rolled `preg_match()` checks.

**The actual gap:** those hand-rolled patterns used `^`/`$` anchors instead
of `\A`/`\z`. PCRE's `$` (without the `/D` modifier) matches either the true
string end *or* immediately before a single trailing newline — so
`"internal\n"` still satisfied `/^...domain-pattern...$/`, passed
validation, and would have rendered as a raw embedded newline inside a TOML
literal string: a parse error that crash-loops ctrld for the whole network.
`preg_match()` also only reports *whether* a match occurred, not whether it
consumed the entire string, unlike Phalcon's own Mask validator (which
additionally checks the matched substring equals the full input).

**Decision:** add the same `<Mask>` its sibling fields already carry
(`/^[^'\r\n]*$/` — this one's safe despite using `$` too, because the
character class itself excludes `\r`/`\n` outright, so there's nothing for
the trailing-newline ambiguity to exploit), *and* fix the hand-rolled
patterns to use `\A`/`\z`. Belt and suspenders deliberately: the Mask alone
would have closed this specific gap, but the anchor fix is the more general
correct practice, and having both means a future field that copies
`performValidation()`'s pattern style without also getting a Mask isn't
automatically vulnerable to the same class of bug.

**Never found a live incident from this one** — it was caught by code
review before any real newline ever reached production. Worth remembering
that the review-response phases weren't just cleanup: this specific finding
was a genuine, previously-unnoticed way to take down the whole network from
the Policy dialog's own text field.
