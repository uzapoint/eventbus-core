#!/bin/sh
# Restarts hung eventbus:consume processes. Each consumer touches /<dir>/eventbus-<pid>.alive
# at least every ~5s while its loop is healthy. A file that stops being touched means the
# process is stuck inside a handler or a dead socket; killing it lets supervisord (autorestart)
# start a clean one. Leftover files of processes that no longer exist are just removed.
STALE_AFTER=${STALE_AFTER:-120}
DIR=${EVENTBUS_LIVENESS_DIR:-/tmp}

is_consumer() {
  [ -r "/proc/$1/cmdline" ] && tr '\0' ' ' < "/proc/$1/cmdline" | grep -q 'eventbus:consume'
}

while true; do
  sleep 30
  now=$(date +%s)
  for f in "$DIR"/eventbus-*.alive; do
    [ -e "$f" ] || continue
    age=$(( now - $(stat -c %Y "$f") ))
    b=$(basename "$f" .alive); pid=${b#eventbus-}

    if ! is_consumer "$pid"; then
      # dead process, or the pid was reused by something else: never kill it, just tidy up
      [ "$age" -gt "$STALE_AFTER" ] && rm -f "$f"
      continue
    fi

    if [ "$age" -gt "$STALE_AFTER" ]; then
      echo "$(date -u +%FT%TZ) eventbus pid=$pid stale for ${age}s, killing"
      kill -9 "$pid" 2>/dev/null
      rm -f "$f"
    fi
  done
done
