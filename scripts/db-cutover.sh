#!/usr/bin/env bash
# db-cutover.sh — in-place database endpoint cutover for a YOLO Fargate app.
#
# Flips every running task of an app to a new DB_HOST without waiting for a
# rolling deploy: maintenance page down → patch .env in each container →
# rebuild the config cache → reload Octane workers / restart queue workers →
# verify each container sees the new host → up. Total window is typically a
# couple of minutes; reads are only down while the maintenance page is up.
#
# THE FLIP IS TRANSIENT: env lives in the baked image, so any task the
# scheduler replaces afterwards boots the OLD host. Follow up with
# `yolo env:push` + a deploy promptly to make it permanent.
#
# BEFORE running: freeze writes on the source database so a straggler task or
# in-flight queue job fails loudly instead of writing to the old side. If the
# source is replicating to the new host, remember that REVOKE statements are
# binlogged and will replicate too — re-GRANT on the target after the revoke
# arrives, or freeze via read_only=1 on a parameter group the target does NOT
# share.
#
# Uses your ambient AWS credentials/region (AWS_PROFILE / AWS_REGION).
# Requires session-manager-plugin and ECS exec enabled on the services.
#
# usage: ./db-cutover.sh <environment> <app> <new-db-host>
set -euo pipefail

ENVIRONMENT="${1:?usage: db-cutover.sh <environment> <app> <new-db-host>}"
APP="${2:?usage: db-cutover.sh <environment> <app> <new-db-host>}"
NEW_DB_HOST="${3:?usage: db-cutover.sh <environment> <app> <new-db-host>}"
CLUSTER="yolo-$ENVIRONMENT-$APP"

exec_in() { # $1=task-arn $2=container $3=command
  aws ecs execute-command \
    --cluster "$CLUSTER" --task "$1" --container "$2" --interactive \
    --command "/bin/sh -c \"$3\"" 2>&1 | grep -v "^$\|Session\|session"
}

tasks_of() { # $1=group → task arns (empty if the service doesn't exist)
  aws ecs list-tasks --cluster "$CLUSTER" \
    --service-name "yolo-$ENVIRONMENT-$APP-$1" \
    --query 'taskArns[]' --output text 2>/dev/null || true
}

WEB_TASKS=$(tasks_of web)
QUEUE_TASKS=$(tasks_of queue)
[ -z "$WEB_TASKS$QUEUE_TASKS" ] && { echo "no running tasks found for $APP in $ENVIRONMENT"; exit 1; }
echo "targets: web[$(echo $WEB_TASKS | wc -w | tr -d ' ')] queue[$(echo $QUEUE_TASKS | wc -w | tr -d ' ')]"

each_task() { # $1=callback — invokes callback GROUP TASK for every task
  local T
  for T in $WEB_TASKS;   do "$1" web "$T";   done
  for T in $QUEUE_TASKS; do "$1" queue "$T"; done
}

do_down() {
  echo "[down] $1 ${2##*/}"
  exec_in "$2" "$1" "php artisan down --retry=30"
}

do_patch() {
  echo "[patch] $1 ${2##*/}"
  exec_in "$2" "$1" "sed -i 's|^DB_HOST=.*|DB_HOST=$NEW_DB_HOST|' .env && php artisan optimize"
  if [ "$1" = web ]; then
    exec_in "$2" "$1" "php artisan octane:reload"
  fi
}

do_verify() {
  echo "[verify] $1 ${2##*/}:"
  exec_in "$2" "$1" "grep '^DB_HOST=' .env && php artisan migrate:status 2>&1 | head -3"
}

do_up() {
  echo "[up] $1 ${2##*/}"
  exec_in "$2" "$1" "php artisan up"
}

each_task do_down
each_task do_patch

if [ -n "$QUEUE_TASKS" ]; then
  for T in $QUEUE_TASKS; do
    echo "[queue:restart] ${T##*/}"
    exec_in "$T" queue "php artisan queue:restart"
  done
fi

each_task do_verify
each_task do_up

echo "done — TRANSIENT flip: env:push + deploy promptly, or replaced tasks boot the old env."
