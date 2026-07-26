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

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PLUGIN_SRC="${SCRIPT_DIR}/.."

echo "==> Syncing MVC/service/widget files into /usr/local/opnsense"
rsync -a "${PLUGIN_SRC}/src/opnsense/" /usr/local/opnsense/

echo "==> Syncing plugins.inc.d hook into /usr/local/etc"
rsync -a "${PLUGIN_SRC}/src/etc/" /usr/local/etc/

echo "==> Reloading web GUI (menu/ACL/controller cache)"
configctl webgui restart

echo "==> Done. Services > ctrld should now reflect what's in this checkout."
