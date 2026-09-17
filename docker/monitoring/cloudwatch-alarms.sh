#!/usr/bin/env bash
# Production error alarms for b10cks: log metric filters on /ecs/b10cks-api,
# CloudWatch alarms, and the SNS topic they notify.
#
# Idempotent: create-topic returns the existing topic, put-metric-filter and
# put-metric-alarm overwrite by name. Re-run after changing a threshold.
#
# Thresholds come from a 30-day baseline (Sept 2026): at most 3 Laravel errors
# and 5 5xx responses per 5 minutes in normal operation. The 2026-09-07
# delivery outage produced 19 and 14 5xx in consecutive 5-minute windows.
#
# Usage: AWS_PROFILE=coderscantina docker/monitoring/cloudwatch-alarms.sh
set -euo pipefail

export AWS_REGION="${AWS_REGION:-eu-west-1}"

LOG_GROUP=/ecs/b10cks-api
NAMESPACE=b10cks/production
TARGET_GROUP=targetgroup/b10cks-cms/f8ee55bd721eca04
LOAD_BALANCER=app/nb-clients/7732112bd1a90e36

TOPIC_ARN=$(aws sns create-topic --name b10cks-production-alerts --query TopicArn --output text)
echo "SNS topic: ${TOPIC_ARN}"

# Laravel's own log lines. The colon keeps stack-trace frames that mention a
# level name from matching.
aws logs put-metric-filter \
  --log-group-name "$LOG_GROUP" \
  --filter-name b10cks-laravel-errors \
  --filter-pattern '?"production.ERROR:" ?"production.CRITICAL:" ?"production.ALERT:" ?"production.EMERGENCY:"' \
  --metric-transformations metricName=LaravelErrors,metricNamespace="$NAMESPACE",metricValue=1,unit=Count

# FrankenPHP's access log runs at WARN, so it only emits 5xx lines. The status
# check keeps that true if the log level is ever lowered.
aws logs put-metric-filter \
  --log-group-name "$LOG_GROUP" \
  --filter-name b10cks-http-5xx \
  --filter-pattern '{ $.logger = "http.log.access.log0" && $.status >= 500 }' \
  --metric-transformations metricName=Http5xx,metricNamespace="$NAMESPACE",metricValue=1,unit=Count

alarm() {
  aws cloudwatch put-metric-alarm \
    --alarm-actions "$TOPIC_ARN" \
    --ok-actions "$TOPIC_ARN" \
    "$@"
}

# A flood, not a stray error: 25+ per 5 minutes for 10 minutes straight.
alarm \
  --alarm-name b10cks-production-laravel-error-flood \
  --alarm-description "Sustained Laravel ERROR+ lines in ${LOG_GROUP} (cms and reverb)" \
  --namespace "$NAMESPACE" --metric-name LaravelErrors \
  --statistic Sum --period 300 --evaluation-periods 2 --datapoints-to-alarm 2 \
  --threshold 25 --comparison-operator GreaterThanOrEqualToThreshold \
  --treat-missing-data notBreaching

# A burst of 5xx: bots probing garbage slugs stay below 10 per 5 minutes.
alarm \
  --alarm-name b10cks-production-http-5xx-burst \
  --alarm-description "Burst of 5xx responses from b10cks-cms (FrankenPHP access log)" \
  --namespace "$NAMESPACE" --metric-name Http5xx \
  --statistic Sum --period 300 --evaluation-periods 1 --datapoints-to-alarm 1 \
  --threshold 10 --comparison-operator GreaterThanOrEqualToThreshold \
  --treat-missing-data notBreaching

# The ALB nb-clients is shared; both alarms are scoped to the b10cks-cms target group.
alarm \
  --alarm-name b10cks-production-cms-unhealthy-hosts \
  --alarm-description "A b10cks-cms target has failed ALB health checks for 5 minutes" \
  --namespace AWS/ApplicationELB --metric-name UnHealthyHostCount \
  --dimensions Name=TargetGroup,Value="$TARGET_GROUP" Name=LoadBalancer,Value="$LOAD_BALANCER" \
  --statistic Maximum --period 60 --evaluation-periods 5 --datapoints-to-alarm 5 \
  --threshold 1 --comparison-operator GreaterThanOrEqualToThreshold \
  --treat-missing-data notBreaching

alarm \
  --alarm-name b10cks-production-cms-no-healthy-hosts \
  --alarm-description "b10cks-cms has no healthy ALB target" \
  --namespace AWS/ApplicationELB --metric-name HealthyHostCount \
  --dimensions Name=TargetGroup,Value="$TARGET_GROUP" Name=LoadBalancer,Value="$LOAD_BALANCER" \
  --statistic Minimum --period 60 --evaluation-periods 2 --datapoints-to-alarm 2 \
  --threshold 1 --comparison-operator LessThanThreshold \
  --treat-missing-data breaching

echo "Done. Subscribers on ${TOPIC_ARN}:"
aws sns list-subscriptions-by-topic --topic-arn "$TOPIC_ARN" --query 'Subscriptions[].[Protocol,Endpoint]' --output text
