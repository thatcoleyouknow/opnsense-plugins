# os-ctrld — staging repo

This repo builds `dns/ctrld`, an OPNsense plugin that wraps
[ctrld](https://github.com/Control-D-Inc/ctrld) to give it a native GUI, as a
staging area for a future pull request to
[opnsense/plugins](https://github.com/opnsense/plugins).

The plugin's own description, architecture rationale, and DNSSEC-tradeoff
writeup live in [`dns/ctrld/pkg-descr`](dns/ctrld/pkg-descr) — that file is
what ships with the plugin and is the canonical explanation of *why* this
exists. See the implementation plan this repo was built from for the full
design rationale and reference-pattern research.

## Layout

`dns/ctrld/` mirrors the layout expected by an `opnsense/plugins` checkout, so
it can be copied directly into a real fork's `dns/` directory:

```
dns/ctrld/{Makefile,pkg-descr,src/...}
```

## Testing on a real OPNsense box

There's no `pkg install` path for this yet (see below) — for dev-loop
iteration, `git clone` this repo onto the OPNsense test box yourself, then
run [`dns/ctrld/tools/deploy-dev.sh`](dns/ctrld/tools/deploy-dev.sh) from
inside that checkout. It syncs `dns/ctrld/src/` into the real filesystem
locations OPNsense expects and reloads the web GUI:

```sh
git clone https://github.com/thatcoleyouknow/opnsense-plugins.git
sh opnsense-plugins/dns/ctrld/tools/deploy-dev.sh
```

Re-run the script (after your own `git pull`) whenever you want to
redeploy. The plugin has been running in production on a real home router
for a while now (see
[`dns/ctrld/docs/engineering-notes/incident-postmortems.md`](dns/ctrld/docs/engineering-notes/incident-postmortems.md)
for the full history of what that's actually surfaced), but it's still
early — if you're trying this on your own box, a spare/VM instance is
safer than one your LAN's live DNS depends on.

## Documentation

- [`dns/ctrld/docs/ctrld.md`](dns/ctrld/docs/ctrld.md) — reference doc:
  what the plugin does, why it's designed this way, the DNSSEC tradeoff,
  every settings field, known limitations, and troubleshooting.
- [`dns/ctrld/docs/hybrid-dns-howto.md`](dns/ctrld/docs/hybrid-dns-howto.md)
  — step-by-step guide to actually configuring a combined
  Dnsmasq + ctrld/NextDNS setup, the reason this plugin exists.
- [`dns/ctrld/docs/engineering-notes/`](dns/ctrld/docs/engineering-notes/)
  — for the plugin's own author/maintainer, not a user: why the code looks
  the way it does, the framework/tooling gotchas discovered building it,
  and full postmortems of the real debugging sessions (the two-act
  WireGuard listener saga, the browser-DNS-bypass investigation, and more).

## Known prerequisite before a real upstream PR

`ctrld` has no FreeBSD port today and is only distributed as a manual binary
download. `opnsense/plugins`' `CONTRIBUTING.md` requires wrapped service
binaries to already be available in FreeBSD ports and disallows shipping
precompiled binaries inside a plugin. This plugin assumes `ctrld` is already
installed on the box (as `dns/dnscrypt-proxy` assumes its own port
dependency is installed) — packaging `ctrld` as a FreeBSD port is a separate,
unstarted prerequisite for a real submission, not something this repo
attempts to solve.

`CONTRIBUTING.md` also asks that new-plugin authors open a discussion issue
before submitting, and notes that large initial codebases aren't reviewable.
This repo intentionally builds the full multi-listener/multi-profile
architecture up front rather than an MVP (per the original design brief) —
expect that a real submission would need to open with a discussion issue and
likely be split into a smaller initial PR.

## AI assistance disclosure

This plugin was built with the assistance of Claude Code (Anthropic), per
`CONTRIBUTING.md`'s disclosure requirement for AI-assisted submissions.
