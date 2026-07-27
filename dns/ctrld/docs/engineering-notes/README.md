# Engineering notes

This folder is different from `docs/ctrld.md` and `docs/hybrid-dns-howto.md`
one level up — those are for someone *using* the plugin. This folder is for
the plugin's own author, coming back a year from now and asking "why does
this code look like this, and what did it cost to learn that?"

Three documents, each answering a different kind of question:

- **[architecture-decisions.md](architecture-decisions.md)** — "why did we
  build it this way, and what else did we consider?" One entry per real
  design decision, in the order they were made. Read this when you're about
  to change something and want to know if there was a reason it isn't the
  "obvious" way already.
- **[gotchas.md](gotchas.md)** — "I'm about to touch X, what's going to bite
  me?" A reference catalog of framework/language/tool traps discovered the
  hard way, organized by technical domain, not chronology. Read this before
  touching OPNsense's model framework, the Jinja2 template, PHP's legacy
  `.inc` bootstrap, or anything DNS/browser-related.
- **[incident-postmortems.md](incident-postmortems.md)** — "what actually
  happened during that outage, and how did we figure it out?" The big
  debugging sessions, written with the investigative path still in them, not
  just the final answer — the wrong turns are often as instructive as the
  right one.

## The short version, if you only read one paragraph

This plugin went through two genuinely different DNS architectures (Unbound
rebound to loopback, then Dnsmasq's own loopback listener) before settling
on the one `docs/ctrld.md` now describes as if it were obvious from the
start. The single most expensive lesson, twice, was that OPNsense's
`configd`-driven Jinja2 template pipeline has **zero access to live system
state** — it only ever sees what's already in `config.xml` — so anything
that depends on a live interface address (WireGuard, OpenVPN, DHCP, PPPoE)
has to be resolved *outside* that pipeline, and where exactly to do that
resolution took two real attempts to get right (see
[incident-postmortems.md](incident-postmortems.md)'s WireGuard entries).
The second most expensive lesson was that a modern browser has several
independent ways to bypass a network's local DNS entirely (DoH, ECH,
connection coalescing), and ruling one out proves nothing about the others.
