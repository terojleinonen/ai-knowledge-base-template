# Postmortem: Aurora gateways offline on 14 March 2026

Status: final. Author: Cloud team. Published 20 March 2026. Severity: SEV-1.

## Summary

On Saturday 14 March 2026, between 06:12 and 09:32 UTC, about 1,240 Aurora Gateways (roughly 38 percent of the fleet) could not connect to Aurora Cloud. Readings were buffered on the gateways and uploaded after the gateways reconnected, so no readings were lost. The dashboard, alerts and webhooks were unavailable for the affected sites during the outage, which lasted 3 hours and 20 minutes.

## Customer impact

Customers with affected gateways saw their sites as offline in the dashboard. Alert rules did not fire during the outage, so threshold alerts such as high CO2 were not sent. 17 customers contacted support. Two enterprise customers received service credits under their SLA (99.9 percent monthly availability).

## Root cause

Each gateway authenticates to Aurora Cloud with a TLS client certificate. The certificates of gateways manufactured in the first production batch of 2023 were issued with a three-year validity and expired on 14 March 2026 at 06:00 UTC. The firmware was expected to renew certificates automatically, but the renewal was only triggered when the gateway restarted. Gateways that had been running without a restart for months kept their old certificates until they expired.

Monitoring did not alert on certificate expiry dates, because expiry was tracked only for server certificates, not for device certificates.

## Timeline (UTC)

06:00 First batch of gateway certificates expires.
06:12 Connection errors from gateways rise sharply; the on-call engineer is paged.
06:40 The incident is declared SEV-1 and the status page is updated.
07:25 The cause is identified as expired client certificates.
07:50 A temporary server-side change accepts expired certificates from known gateway serial numbers for 14 days.
08:30 Gateways start reconnecting as their retry timers fire.
09:32 All affected gateways are connected and buffered readings have been uploaded.

## What went well

Gateways buffered readings as designed (up to 72 hours), so no data was lost. The status page was updated within 30 minutes of the first page.

## What went wrong

Certificate renewal depended on a restart. Device certificate expiry was not monitored. The first customer notification email was sent only at 08:05, almost two hours after the start of the incident.

## Action items

1. Firmware 3.3.1 renews client certificates 30 days before expiry without a restart and raises a dashboard alert if renewal fails. Owner: Platform team. Done 16 March 2026.
2. Monitor device certificate expiry dates and alert 60 days before expiry. Owner: Cloud team. Done 27 March 2026.
3. Send the first customer notification within 30 minutes of a SEV-1 being declared. Owner: Support. Done.
4. Remove the temporary acceptance of expired certificates after all gateways run 3.3.1. Owner: Cloud team. Done 28 March 2026.
