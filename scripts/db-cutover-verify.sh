#!/usr/bin/env bash
# db-cutover-verify.sh — post-cutover confirmation for a YOLO Fargate app
# (read-only companion to db-cutover.sh).
#
# For every running task of the app, proves four independent layers:
#   1. .env carries the expected DB_HOST
#   2. the CACHED config (what the booted app actually reads) carries it too
#   3. a live query answers, and reports which server did (@@server_uuid)
#   4. maintenance mode is OFF (and queue workers are running, on queue tasks)
# Then asserts every container saw the SAME server identity — the split-brain
# detector: one straggler container still talking to the old database fails
# this even when every hostname reads clean. Optionally probes the public
# site. Exits non-zero on any failure, so it can gate follow-up steps.
#
# Uses your ambient AWS credentials/region (AWS_PROFILE / AWS_REGION).
#
# usage: ./db-cutover-verify.sh <environment> <app> <expected-db-host> [site-url]
set -uo pipefail

ENVIRONMENT="${1:?usage: db-cutover-verify.sh <environment> <app> <expected-db-host> [site-url]}"
APP="${2:?usage: db-cutover-verify.sh <environment> <app> <expected-db-host> [site-url]}"
EXPECTED_HOST="${3:?usage: db-cutover-verify.sh <environment> <app> <expected-db-host> [site-url]}"
SITE_URL="${4:-}"
CLUSTER="yolo-$ENVIRONMENT-$APP"
FAILURES=0
UUIDS=""

exec_in() { # $1=task-arn $2=container $3=command
  aws ecs execute-command \
    --cluster "$CLUSTER" --task "$1" --container "$2" --interactive \
    --command "/bin/sh -c \"$3\"" 2>&1 | grep -v "^$\|Session\|session"
}

tasks_of() {
  aws ecs list-tasks --cluster "$CLUSTER" \
    --service-name "yolo-$ENVIRONMENT-$APP-$1" \
    --query 'taskArns[]' --output text 2>/dev/null || true
}

check() { # $1=label $2=output $3=needle-regex
  if echo "$2" | grep -qE "$3"; then
    echo "    PASS $1"
  else
    echo "    FAIL $1 — wanted /$3/, got: $(echo "$2" | tail -2 | tr '\n' ' ')"
    FAILURES=$((FAILURES + 1))
  fi
}

verify_task() { # $1=group $2=task-arn
  echo "== $1 ${2##*/}"

  OUT=$(exec_in "$2" "$1" "grep '^DB_HOST=' .env")
  check ".env DB_HOST" "$OUT" "DB_HOST=$EXPECTED_HOST"

  OUT=$(exec_in "$2" "$1" "php artisan config:show database.connections.mysql.host")
  check "cached config host" "$OUT" "$EXPECTED_HOST"

  OUT=$(exec_in "$2" "$1" "php artisan tinker --execute=\\\"echo DB::scalar('select @@server_uuid');\\\"")
  check "live query answered" "$OUT" "[0-9a-f]{8}-"
  UUIDS="$UUIDS $(echo "$OUT" | grep -oE '[0-9a-f-]{36}' | head -1)"

  OUT=$(exec_in "$2" "$1" "php artisan about --only=environment")
  check "maintenance mode OFF" "$OUT" "OFF"

  if [ "$1" = queue ]; then
    OUT=$(exec_in "$2" "$1" "ps ax | grep -c 'queue:wor[k]'")
    check "queue workers running" "$OUT" "[1-9]"
  fi
}

WEB_TASKS=$(tasks_of web)
QUEUE_TASKS=$(tasks_of queue)
[ -z "$WEB_TASKS$QUEUE_TASKS" ] && { echo "no running tasks found for $APP in $ENVIRONMENT"; exit 1; }

for T in $WEB_TASKS;   do verify_task web "$T";   done
for T in $QUEUE_TASKS; do verify_task queue "$T"; done

DISTINCT=$(echo "$UUIDS" | tr ' ' '\n' | grep -c . || true)
UNIQUE=$(echo "$UUIDS" | tr ' ' '\n' | grep . | sort -u | wc -l | tr -d ' ')
echo "== server identity: $UNIQUE distinct @@server_uuid across $DISTINCT answering container(s) (want exactly 1)"
[ "$UNIQUE" != "1" ] && FAILURES=$((FAILURES + 1))

if [ -n "$SITE_URL" ]; then
  CODE=$(curl -s -o /dev/null -m 15 -w "%{http_code}" "$SITE_URL")
  echo "== site probe: $SITE_URL → $CODE"
  [ "$CODE" != "200" ] && FAILURES=$((FAILURES + 1))
fi

echo
if [ "$FAILURES" -eq 0 ]; then
  echo "ALL CLEAR — every container on $EXPECTED_HOST, one server identity, site up."
else
  echo "$FAILURES FAILURE(S) — do not consider the cutover complete."
  exit 1
fi
