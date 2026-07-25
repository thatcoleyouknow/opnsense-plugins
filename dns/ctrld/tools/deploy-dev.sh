#!/bin/sh
#
# Dev-loop deploy script for os-ctrld -- run this ON the OPNsense test box,
# not on your workstation. It clones (first run) or pulls (later runs) the
# plugin source repo and syncs dns/ctrld/src/ into the real filesystem
# locations OPNsense expects, then reloads the web GUI so any new
# menu/ACL/controller entries are picked up.
#
# Prerequisite (one-time): pkg install git
#
# Usage: fetch this script once, e.g.
#   fetch -o /root/deploy-dev.sh \
#     https://raw.githubusercontent.com/thatcoleyouknow/opnsense-plugins/main/dns/ctrld/tools/deploy-dev.sh
#   sh /root/deploy-dev.sh
# then just re-run `sh /root/deploy-dev.sh` after every push to redeploy.
#
# Explicitly invoked via /bin/sh -- OPNsense's default interactive shell is
# csh, not sh, so this must not be run as `./deploy-dev.sh` from a csh
# prompt without going through sh first.

set -e

REPO_URL="https://github.com/thatcoleyouknow/opnsense-plugins.git"
CHECKOUT_DIR="/root/opnsense-plugins"
PLUGIN_SRC="${CHECKOUT_DIR}/dns/ctrld/src"

if [ ! -d "${CHECKOUT_DIR}/.git" ]; then
    echo "==> Cloning ${REPO_URL} into ${CHECKOUT_DIR}"
    git clone "${REPO_URL}" "${CHECKOUT_DIR}"
else
    echo "==> Pulling latest into ${CHECKOUT_DIR}"
    git -C "${CHECKOUT_DIR}" pull --ff-only
fi

echo "==> Syncing MVC/service/widget files into /usr/local/opnsense"
rsync -a "${PLUGIN_SRC}/opnsense/" /usr/local/opnsense/

echo "==> Syncing plugins.inc.d hook into /usr/local/etc"
rsync -a "${PLUGIN_SRC}/etc/" /usr/local/etc/

echo "==> Reloading web GUI (menu/ACL/controller cache)"
configctl webgui restart

echo "==> Done. Services > ctrld should now reflect the latest push."
