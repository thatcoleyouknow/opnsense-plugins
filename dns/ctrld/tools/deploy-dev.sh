#!/bin/sh
#
# Dev-loop install script for os-ctrld -- run this ON the OPNsense test box,
# not on your workstation, from inside a checkout of this repo (i.e. you've
# already `git clone`d it yourself). It syncs dns/ctrld/src/ into the real
# filesystem locations OPNsense expects, then reloads the web GUI so any new
# menu/ACL/controller entries are picked up.
#
# Usage: after cloning this repo onto the box, run:
#   sh dns/ctrld/tools/deploy-dev.sh
# and re-run it (after your own `git pull`) whenever you want to redeploy.
#
# Explicitly invoked via /bin/sh -- OPNsense's default interactive shell is
# csh, not sh, so this must not be run as `./deploy-dev.sh` from a csh
# prompt without going through sh first.

set -e

if [ "$(id -u)" -ne 0 ]; then
    echo "Must run as root (writes into /usr/local/opnsense and /usr/local/etc)." >&2
    exit 1
fi

if ! command -v rsync >/dev/null 2>&1; then
    echo "==> rsync not found, installing (pkg install -y rsync)"
    pkg install -y rsync
fi

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PLUGIN_SRC="${SCRIPT_DIR}/.."

# NEVER add --delete to this rsync. /usr/local/opnsense/ is not owned
# exclusively by this plugin -- it's the shared root for OPNsense's entire
# MVC framework (Phalcon runtime, core libraries, every other installed
# plugin). An earlier version of this script used --delete here and it
# mirrored our tiny plugin subtree onto that shared directory, deleting
# Phalcon itself and breaking the whole web GUI (recovered via
# `opnsense-bootstrap -y` from the local console). If stale files from a
# renamed/removed plugin file ever need cleaning up, do it by hand.
echo "==> Syncing MVC/service/widget files into /usr/local/opnsense"
rsync -a "${PLUGIN_SRC}/src/opnsense/" /usr/local/opnsense/

echo "==> Syncing plugins.inc.d hook and rc.d script into /usr/local/etc"
rsync -a "${PLUGIN_SRC}/src/etc/" /usr/local/etc/
chmod +x /usr/local/etc/rc.d/ctrld

# The ACL and Menu systems each cache their merged XML to disk with a 1hr
# TTL (see OPNsense\Base\Menu\MenuSystem::persist(), core's system.inc
# system_cache_flush()). A real `pkg install` triggers this flush via
# rc.configure_plugins automatically; a manual file sync like this one
# doesn't, so without this step a new/changed Menu.xml or ACL.xml silently
# won't show up until the cache expires on its own.
echo "==> Flushing ACL/menu caches (rc.configure_plugins)"
/usr/local/etc/rc.configure_plugins

# configd (core/opnsense/service/modules/processhandler.py) reads every
# actions.d/actions_*.conf file exactly once, at its own process startup
# (ActionHandler.load_config(), called from __init__ -- confirmed against
# the real source, not guessed). Editing/adding a section to an
# *already-loaded* actions_ctrld.conf -- e.g. a brand new action -- has no
# effect until configd itself restarts; syncing the file to disk isn't
# enough. This restarts the system-wide configd daemon (a few seconds,
# used by every plugin's Apply/service-control buttons, not just this
# one) -- low risk and self-healing, but worth knowing what it is before
# running this repeatedly.
echo "==> Restarting configd (picks up new/changed actions.d entries)"
service configd restart

echo "==> Reloading web GUI"
configctl webgui restart

# Syncing a changed ctrld.toml *template* above doesn't regenerate the
# rendered /etc/controld/ctrld.toml *output* -- that only happens when this
# actually runs, same as clicking Apply/Save in the GUI.
echo "==> Re-rendering ctrld.toml from the (possibly updated) template"
configctl template reload OPNsense/Ctrld

echo "==> Done. Services > ctrld should now reflect what's in this checkout."
