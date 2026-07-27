# Gotchas

A reference catalog, not a narrative — organized by technical domain so you
can jump to the part relevant to whatever you're about to touch. Each entry
says what the trap is, how it was actually discovered (verified against real
source, not assumed), and what to do instead. See
[incident-postmortems.md](incident-postmortems.md) for the full stories
behind the ones that caused real outages.

## OPNsense's model/field framework

**List-type fields serialize as `{key: {value, selected}}`, not a plain
string.** Anything built on `BaseListField` — `OptionField`,
`ModelRelationField`, this plugin's own `ListenerInterfaceField` — comes
back from `getBase()`/`searchItem` as its *full option map*, not the
selected value as a bare string. Confirmed by reading `BaseField::getNodes()`
in real core source: it explicitly checks `is_string($result[$key])` before
treating a value as a plain string, which only makes sense if it's
sometimes *not* one. This bit twice in this project: once in
`ListenerController::cidrAction()` (reading a Policy's selected listener
field, fixed with a `selectedOption()` helper that finds the key with
`selected == 1`), and again in the delegation quick-add's duplicate-detection
JS (comparing `row.matchType === 'domain'` directly against what was
actually an object, so the comparison was always false and dedup silently
never worked — fixed with a `selectedKey()` JS helper doing the same thing).
**Rule of thumb:** never compare a value read back from `searchItem`/`getBase()`
against a plain string unless you've confirmed that specific field's type
isn't list-based.

**`ArrayField`-backed models double-nest in config.xml and template paths.**
A model like `Listener.xml` (`<mount>//OPNsense/ctrld/listeners</mount>`,
then `<items><listeners><listener type="ArrayField">`) has a real config.xml
path of mount-segment + items-wrapper-segment + the ArrayField's own
repeated tag — **three** segments, not two. `BaseField::addToXMLNode()`
adds one XML layer per non-ArrayField container and specifically *skips*
the ArrayField's own tag; `ArrayField`'s row-tagging names each row after
that same tag. Confirmed against a real, deployed plugin with the identical
shape (dnscrypt-proxy's `Forward.xml` + `forwarding-rules.txt`, which reads
`...forward.forwards.forward`, not `...forward.forward`). An earlier draft
of this plugin's own `ctrld.toml` template used single-nesting, which
happened to make an early version of the local render-test *pass* while the
real template was broken — the test's mock matched the wrong assumption
instead of catching it. If you write a new template test fixture, build it
by checking a real rendered/exported structure, not by copying the template
author's own mental model of the path.

**Every defined model field is always a key in `getNodes()`'s output, even
when its value is an empty string.** Useful to know when deciding whether
`upstream.nextdnsProfileId` (say) is safe to dot-access directly in a
Jinja2 template without risking an `UndefinedError` — it is, as long as
`upstream` itself is a real, populated row (see the Jinja2 section below
for when a *parent* object being entirely missing is a different, more
dangerous case).

**`performValidation()` cannot express a non-blocking warning.** Every
`Message` it returns is treated as a hard save failure by
`ApiMutableModelControllerBase::validate()` — confirmed by reading the
real, current core source, which unconditionally sets
`$result["result"] = "failed"` the moment any validation message exists at
all; the `Message` class's own `$type` constructor argument isn't consulted
anywhere in that path. There's no core-supported "warn but still let Save
through" outcome from this specific mechanism. When a check is genuinely
advisory (e.g. Policy.php flagging an enabled Policy that references a
disabled Listener/Upstream — a real footgun, but not something that should
block Save the way an invalid CIDR should), it has to be surfaced somewhere
else entirely — this plugin does it by post-processing rows in the
`searchItem` API action instead (see `PolicyController::searchItemAction()`),
prepending a warning marker into an existing visible grid column rather than
adding a validation message.

## `daemon(8)` and `rc.d`

**`-p` (child pidfile) vs `-P` (supervisor pidfile) matters when combined
with `-r` (restart-on-exit).** `daemon(8)`'s own man page spells this out
directly: `-p` gives you the *child's* PID, and killing only the child with
`-r` set just makes the still-running supervisor immediately respawn a new
one. `rc.d/ctrld` tracks the supervisor's pidfile (`-P`) as its real
`pidfile`/`procname`, specifically so `service ctrld stop` correctly forwards
SIGTERM to the child first and then exits, with no restart. `-p` is kept
too, purely so the actual worker PID is recorded somewhere for debugging.

**`-T` (syslog tag) implicitly enables `-S` (syslog output), and that
changes what `-f` actually does.** The man page says outright: "`-S`...
This is implicitly applied if other syslog parameters are provided." Once
syslog is (even implicitly) enabled, `-f`'s "redirect to /dev/null"
behavior changes to "redirect to /dev/null, *then* to the file/syslog
destination" — meaning stdout/stderr end up going to syslog instead of
purely disappearing, contradicting an intuitive reading of `-f` alone. This
plugin dropped `-T` entirely, since ctrld already writes its full
structured log straight to `log_path` and has nothing that should go to
syslog at all.

**`rc.subr`'s `start_precmd`/`reload_precmd` is the one place every real
path to (re)starting a service actually converges.** GUI Apply buttons
(`ApiMutableServiceControllerBase::reconfigureAction()`, inherited, not
overridden here), this plugin's own boot/WAN-event `dns` hook
(`ctrld_configure_do()`), and a bare `service ctrld restart` from the
console all eventually call into the *same* rc.d script's start/restart/
reload logic — but none of them share a common PHP-level call site with
each other. If something must run on every real start, hooking it at the
rc.d layer is more reliable than trying to find and patch every PHP-level
caller (see the WireGuard postmortem for what happened trying the latter).

**`daemon -r` (restart-on-exit) respawns the child directly — it does not
go back through the rc.d script.** So a crash caused by a bad config
doesn't get a fresh chance to self-heal on each auto-respawn; every
respawn reuses the exact same broken file from the original failed start,
until something explicitly re-triggers the *full* rc.d start sequence
(a genuine `service ctrld restart`, not just daemon's own internal
retry). A precmd hook only fires on that full sequence, not on individual
crash-respawns.

## AJAX / OPNsense's own frontend JS helpers

**`ajaxCall()` always POSTs. `ajaxGet()` is the GET-only sibling.**
Confirmed by reading OPNsense core's real `opnsense.js`: `ajaxCall()`
hardcodes `type: 'POST'`; `ajaxGet()` hardcodes `type: 'GET'`. The generic
model `ApiMutableModelControllerBase::getAction()` (used by any
`.../general/get`-style endpoint that doesn't define its own `getItemAction`)
only populates its response `if ($this->request->isGet())` — call it via
`ajaxCall()` by mistake and you get back a bare, empty `[]`/`{}`, which
reads exactly like "this setting genuinely isn't configured" rather than
"you used the wrong HTTP verb." This caused a real bug in the local-zone
delegation button (see
[incident-postmortems.md](incident-postmortems.md#the-ajaxcall-vs-ajaxget-bug)).
**Rule of thumb:** any endpoint whose action name is a bare `get` (not
`getItem`) needs `ajaxGet()`, not `ajaxCall()`.

**`$.when.apply($, deferreds).always()`'s callback argument shape depends
on how many deferreds you passed.** A genuine jQuery quirk: with exactly
one deferred, `.always()`'s callback gets `(data, textStatus, jqXHR)`
directly; with more than one, it gets N arguments, each wrapped as its own
`[data, textStatus, jqXHR]` array. Code that doesn't account for this
either breaks for the single-item case or the multi-item case, silently,
depending on which one you happened to test with. Safer to track
completion manually (a counter decremented in each call's own callback)
than to rely on `$.when`'s aggregate resolution for anything beyond
"wait until N async things are done."

**OPNsense's `addItem`/`setItem` API endpoints return HTTP 200 even on
validation failure.** The response body is `{"result": "saved", "uuid":
...}` on success or `{"result": "failed", ...}` on failure (confirmed via
real `ApiMutableModelControllerBase::addBase()`/`setBase()` source) — but
the HTTP status is 200 either way. A raw jQuery Deferred/Promise's own
resolved-vs-rejected state can't distinguish the two; you have to inspect
`response.result === 'saved'` in the actual body. Code that used
`$.when(...).always(...)` and assumed "it resolved, so it worked" reported
false success on every validation failure until this was caught.

## Jinja2 / OPNsense's `configd` template pipeline

**The Jinja2 template pipeline has zero access to live system state.**
Confirmed by reading real core source (`service/modules/addons/
template_helpers.py`, `service/modules/template.py`): `Template.set_config()`
is the pipeline's *only* data-entry point, fed once from `config.xml`, and
every helper method available inside a template (`getNodeByTag`, `toList`,
`physical_interface`, etc.) only ever reads from that same static,
config.xml-derived dict. There is no `ifconfig`-equivalent, no live
interface query, and no hook for a plugin to inject additional computed
data into the pipeline from PHP. If a value only exists as live kernel/OS
state (a DHCP-leased or WireGuard-tunnel address, for instance), the
template genuinely cannot resolve it — that resolution has to happen
somewhere else entirely (see the listener-IP-resolution architecture
decision, and its postmortem).

**Dot-chain attribute access throws immediately on a missing
*intermediate* level — a trailing `|default(...)` never gets a chance to
run.** `{{ OPNsense.ctrld.general.cacheSize | default(4096) }}` looks like
it should safely fall back to `4096` if `general` doesn't exist yet (e.g.
right after plugin install, before the General page has ever been saved).
It doesn't: Jinja2's own attribute-access resolution raises
`UndefinedError` the moment it tries `.general` on `OPNsense.ctrld` and
finds nothing there, well before the outer `.cacheSize` or the `default`
filter are ever reached. Confirmed by testing this exact scenario against
this plugin's own render harness — a first-pass fix that *looked* correct
against the normal case threw on this one. `helpers.getNodeByTag('OPNsense.
ctrld.general.cacheSize')` is the safe alternative already established
elsewhere in this template: it's a plain Python dict-walk that returns
`None` cleanly at *any* missing depth, with no exception.

**`default(X)`'s single-argument form only substitutes for Jinja2's own
`Undefined` sentinel — not a genuine Python `None`.** `getNodeByTag()`
(above) returns real Python `None` when a key is missing, not `Undefined`
— so `helpers.getNodeByTag(...) | default(4096)` (single argument) leaves
that `None` completely unchanged, which then interpolates as the literal
4-character string `"None"`. This is the *exact same failure class* as the
`ip = 'None'` bug this whole project spent so long chasing, just
rediscovered independently in a different part of the same template. The
fix is the second, positional `true` argument:
`| default(4096, true)` — this makes Jinja2's `default` filter also treat
any falsy value (including `None`) as needing the fallback, not just its
own internal sentinel. Confirmed by testing both the naive and the fixed
version against the render harness before shipping either.

## PHP's legacy `.inc` bootstrap

**Individual legacy `/etc/inc/*.inc` files do not self-include their own
transitive dependencies.** `interfaces.inc`'s `get_interface_ip()` calls
`is_ipaddrv4()` internally — a function actually defined in `util.inc`, not
`interfaces.inc` itself — and `interfaces.inc` never requires `util.inc` on
its own. In OPNsense's normal web/GUI request bootstrap, every legacy
`.inc` file is already loaded together, so this gap is invisible; a
standalone CLI script that only requires what it *thinks* it needs will
fatal with something like `Call to undefined function is_ipaddrv4()` —
confirmed the hard way, live, on a deployed box (see
[incident-postmortems.md](incident-postmortems.md#the-wireguard-listener-saga-two-acts)'s
second act). **The fix that actually held up:** don't try to hand-derive
the full transitive dependency graph — find a real, confirmed OPNsense
core CLI script that calls the *same* function standalone, and match its
exact require list. (`get_interface_ip()` specifically: `config.inc`,
`interfaces.inc`, `util.inc`, `filter.inc`, `system.inc` — five files,
confirmed against core's own `scripts/shell/setaddr.php`, which needed all
five to call this one function outside the full bootstrap.)

**This exact class of risk was already suspected once, earlier, and
correctly avoided.** `gen_subnet()` (also `util.inc`) was deliberately
*not* used inside an Api controller's request-lifecycle code early in this
project, specifically because its availability inside the Phalcon MVC/API
request path (as opposed to a legacy `/www`-style page that explicitly
includes `util.inc`) was never confirmed — inline PHP network math was
used instead, to remove the dependency entirely rather than gamble on it.
That earlier caution turned out to be exactly the right instinct; the
`get_interface_ip()` bug above is the same risk materializing in a
*different* code path (a standalone rc.d-invoked CLI script) where the
caution wasn't applied up front, because the require pattern for a
*script* looked different enough from the require pattern for an *Api
controller* that the connection wasn't obviously the same problem.

**`$this->sessionClose()` does not exist anywhere in OPNsense core.**
Despite being an extremely plausible-sounding method name (and despite
being explicitly suggested this way by a prior automated code review), a
full source search across `ApiControllerBase`, `ControllerBase`, and every
`searchBase()`-adjacent method found no such method. Shipping it as
written would have been a fatal "call to undefined method" the first time
either affected endpoint was hit. The real, correct mechanism for
releasing PHP's file-based session lock mid-request is the plain built-in
`session_write_close()` — no OPNsense-specific wrapper needed or, it turns
out, available. **General lesson:** a plausible-sounding API surface
suggested by any source (a review, documentation, your own memory of a
similar framework) is a hypothesis, not a fact, until you've actually seen
it defined somewhere real.

## ctrld itself

**Domain-match policy rules are exact string match only — no automatic
subdomain fallback.** Confirmed by reading ctrld's own real Go source
(`cmd/cli/dns_proxy.go`'s `wildcardMatches()`): a plain `"example.com"`
rule matches only `example.com`, never `foo.example.com`. A
`"*.example.com"` rule does a suffix check instead (covering subdomains at
any depth via a simple `strings.HasSuffix`), but by the same logic does
*not* also match the bare `example.com`. Full coverage of "this domain and
everything under it" always needs both rules, never one clever pattern —
this shaped the domain quick-add feature directly (see architecture
decisions).

**ctrld's own bootstrap DNS resolution is a separate, internal mechanism —
easy to mistake for a client-facing DNS problem.** Before ctrld can reach
an upstream whose endpoint is a hostname (`https://dns.nextdns.io/...`), it
has to resolve that hostname itself, via its own internal "OS resolver"/
bootstrap logic — visible in `ctrld.log` as `"os resolver query for
dns.nextdns.io. with nameservers: ..."` lines. This is *not* forwarding a
client's own query; it's ctrld resolving its own upstream's address before
it can even start serving. Seeing repeated failures/retries here at
service startup, alongside a `warn no default route IP found` in the same
window, is consistent with a benign WAN-not-fully-up race at boot, not
necessarily a real problem — but persistent, ongoing failures on this path
(not just at startup) would indicate the specific bootstrap nameserver
ctrld fell back to trying first genuinely isn't answering.

**A single misbehaving listener can take the entire service down.** ctrld
is one process managing every configured listener; an invalid bind address
on any one of them is fatal to the whole process, not just that listener.
This is why `patch_listener_ips.php` *removes* an unresolvable listener's
block entirely rather than leaving any kind of placeholder in it — see the
listener-IP-resolution architecture decision.

**The DHCP lease file ctrld should watch isn't discoverable automatically
on OPNsense.** ctrld's own "common file locations" client-discovery
auto-detection has no OPNsense-specific knowledge, so `dhcp_lease_file_path`/
`_format` must be set explicitly in the generated config. The *correct*
path depends on which DHCP service is actually active (this project uses
Dnsmasq, whose FreeBSD-compiled-in default lease path is
`/var/db/dnsmasq.leases` — confirmed on a live box via `ps auxww` showing
no `--dhcp-leasefile` override, plus comparing file mtimes against two
other candidate paths that turned out to be stale leftovers from a
previous ISC-dhcp-based setup).

## Browser DNS resolution (client-side)

**A browser has several genuinely independent ways to bypass a network's
local DNS entirely — ruling one out proves nothing about the others.**
DNS-over-HTTPS (Secure DNS), Encrypted Client Hello (ECH, delivered via the
`HTTPS`/`SVCB` DNS record type), HTTP/2–HTTP/3 connection coalescing
(silently reusing an already-open connection to a *different* hostname
that happens to share certificate coverage), and platform-level features
like iCloud Private Relay are all separate mechanisms with separate
on/off switches. This project spent a long debugging session
methodically disabling each one in turn — DoH confirmed off, ECH
disabled via `network.dns.echconfig.enabled`, a full browser restart to
rule out connection pooling — and *still* had the underlying problem,
because the actual cause (see the postmortem) was one level further out:
a public DNS record existing at all for a domain meant to be
internal-only, which gave every one of those mechanisms *something* to
find regardless of which specific one a browser happened to use that day.
**The fix that actually worked was removing the public exposure, not
disabling any client-side mechanism.**

**Firefox's Secure-DNS auto-enable heuristic checks a specific canary
domain.** On startup, Firefox queries `use-application-dns.net` through
whatever DNS the network provides; if that domain resolves *normally*,
Firefox takes it as a signal the network doesn't object to DoH and may
enable it automatically. Some DNS providers (NextDNS included) offer a
dedicated toggle to make that domain resolve to `NXDOMAIN` specifically,
as a way for a network operator to tell Firefox "please respect my local
DNS instead."

**An old `dig` binary can misinterpret an unrecognized record-type
argument as a second query name entirely.** macOS ships a genuinely old
`dig` (BIND 9.10.6) that doesn't recognize the `HTTPS` record-type
mnemonic — `dig example.com HTTPS` on that binary doesn't error, it
silently treats `HTTPS` as a *second hostname* to also look up, producing
two separate, confusingly-formatted answer blocks in one invocation. This
led to a real, if short-lived, red herring during the browser-DNS-bypass
investigation (a reserved `192.0.2.0/24` "SERVER:" value that looked like
evidence of network interception, but was actually just this dig quirk's
own display artifact). The fix for actually querying an unsupported record
type on an old `dig`: use the raw numeric form (`TYPE65` for `HTTPS`),
which even old binaries understand.

## Diagnostic tooling / sandboxing

**An agentic coding tool's own sandbox can block LAN reachability at more
than one layer simultaneously, and a single "disable sandbox" flag might
only address one of them.** During live debugging on this project, a
coding assistant's Bash tool initially could not reach a LAN host at all
(`No route to host`, `errno=65`/`EHOSTUNREACH`) even with the tool's own
per-call sandbox-disable flag set — because a *separate*, OS-level gate
(macOS's per-application "Local Network" privacy permission) was also in
effect, and required the human operator to explicitly approve a system
popup before genuine LAN access worked. Confirmed conclusively once
both layers were addressed: a direct TCP connection to the router
(`192.168.x.1`) succeeded while the actual target host still failed, then
succeeded too once the OS-level permission was granted — ruling out "the
whole LAN is unreachable" in favor of the correct, narrower explanation.
