# Aurora Cloud API Reference

API version v2. Base URL: https://api.aurora.example/v2. Version v1 was retired on 31 December 2025.

## Authentication

Requests are authenticated with an API key in the `Authorization: Bearer <key>` header. API keys are created in the Aurora dashboard under Organisation > API keys by users with the Admin role. A key can be read-only or read-write. Keys do not expire, but unused keys are disabled automatically after 180 days without requests. Each organisation can have at most 20 active keys.

## Rate limits

The API is rate-limited to 600 requests per minute per organisation, shared by all of its API keys. The readings export endpoint has its own limit of 10 requests per hour. When a limit is exceeded, the API responds with HTTP 429 and a `Retry-After` header giving the number of seconds to wait. Every response includes `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` headers.

## Pagination

List endpoints use cursor pagination. Pass `limit` (default 100, maximum 1000) and the `next_cursor` value from the previous response as `cursor`. When `next_cursor` is null, there are no more results. Cursors expire after 10 minutes.

## Timestamps and units

All timestamps are in UTC in ISO 8601 format. Temperatures are in degrees Celsius, humidity in percent, CO2 in ppm, light in lux, air pressure in hPa. The `units=imperial` query parameter returns temperatures in degrees Fahrenheit.

## Endpoints

### GET /sites

List the organisation's sites.

Parameters: None.

Returns: A list of sites with `id`, `name`, `timezone`, `reporting_interval` and `created_at`.

### POST /sites

Create a site.

Parameters: `name` (required), `timezone` (IANA name, default Europe/Helsinki), `reporting_interval` (30, 60, 300 or 900 seconds, default 60).

Returns: The created site. Requires a read-write key.

### GET /sites/{site_id}

Get one site.

Parameters: None.

Returns: The site, including `node_count` and `gateway_count`.

### PATCH /sites/{site_id}

Update a site's name, timezone or reporting interval.

Parameters: Any of `name`, `timezone`, `reporting_interval`.

Returns: The updated site. A changed reporting interval reaches the nodes within 15 minutes.

### DELETE /sites/{site_id}

Delete a site.

Parameters: None.

Returns: HTTP 204. A site can only be deleted after all its gateways have been released. Readings of a deleted site are kept for 30 days and then removed.

### GET /sites/{site_id}/rooms

List the rooms of a site.

Parameters: None.

Returns: A list of rooms with `id`, `name`, `floor` and `node_ids`.

### POST /sites/{site_id}/rooms

Create a room.

Parameters: `name` (required), `floor` (integer).

Returns: The created room.

### GET /gateways

List gateways.

Parameters: `site_id` to filter by site, `status` (online, offline, updating).

Returns: A list of gateways with `id`, `serial`, `site_id`, `status`, `firmware_version`, `connectivity` (ethernet or lte-m) and `last_seen_at`.

### POST /gateways/claim

Claim a gateway for the organisation.

Parameters: `claim_code` (format AUR-XXXX-XXXX, required), `site_id` (required).

Returns: The claimed gateway. Returns HTTP 409 with error code `gateway_already_claimed` if another organisation owns the gateway.

### POST /gateways/{gateway_id}/release

Release a gateway so it can be claimed by another organisation.

Parameters: None.

Returns: HTTP 204. The gateway's nodes are released with it.

### GET /nodes

List nodes.

Parameters: `site_id`, `room_id`, `gateway_id`, `battery_below` (percent) to filter.

Returns: A list of nodes with `id`, `serial`, `model` (node or node_plus), `room_id`, `battery_percent`, `firmware_version` and `last_seen_at`.

### GET /nodes/{node_id}

Get one node.

Parameters: None.

Returns: The node, including its settings `abc_enabled` and `temperature_offset`.

### PATCH /nodes/{node_id}

Update a node's room or settings.

Parameters: `room_id`, `abc_enabled` (boolean), `temperature_offset` (between -3.0 and 3.0).

Returns: The updated node. `abc_enabled` and `temperature_offset` require firmware 3.4.0 or later; older nodes return HTTP 422 with error code `firmware_too_old`.

### GET /nodes/{node_id}/readings

Get a node's readings.

Parameters: `from` and `to` (required, at most 31 days apart), `metrics` (comma-separated: temperature, humidity, co2, light, voc, pressure), `resolution` (raw, 15m, 1h, 1d; default raw).

Returns: Readings in time order. Raw readings are available for 90 days; after that only 15-minute and coarser aggregates are kept.

### GET /sites/{site_id}/readings/latest

Get the latest reading of every node on a site.

Parameters: `metrics` (optional).

Returns: One reading per node, with `node_id`, `room_id` and `measured_at`.

### POST /exports

Start an export of readings to CSV or Parquet.

Parameters: `site_id` (required), `from`, `to` (at most 13 months apart), `format` (csv or parquet, default csv).

Returns: An export with `id` and `status` pending. Limited to 10 requests per hour.

### GET /exports/{export_id}

Get the status of an export.

Parameters: None.

Returns: The export with `status` (pending, running, done, failed) and, when done, a `download_url` that is valid for 24 hours.

### GET /alerts/rules

List alert rules.

Parameters: `site_id` (optional).

Returns: A list of rules with `id`, `metric`, `operator`, `threshold`, `duration_minutes` and `webhook_id`.

### POST /alerts/rules

Create an alert rule.

Parameters: `site_id`, `metric`, `operator` (gt or lt), `threshold`, `duration_minutes` (default 10), `webhook_id`. Example: CO2 above 1000 ppm for 10 minutes.

Returns: The created rule. An organisation can have at most 200 alert rules.

### GET /webhooks

List webhooks.

Parameters: None.

Returns: A list of webhooks with `id`, `url`, `events` and `created_at`.

### POST /webhooks

Create a webhook.

Parameters: `url` (HTTPS only, required), `events` (alert.triggered, alert.resolved, gateway.offline, node.battery_low).

Returns: The created webhook and its `signing_secret`, which is shown only once.

### DELETE /webhooks/{webhook_id}

Delete a webhook.

Parameters: None.

Returns: HTTP 204.

### GET /audit-log

List audit log entries for the organisation.

Parameters: `from`, `to`, `actor` (user id or API key id).

Returns: Entries with `action`, `actor`, `target` and `created_at`. Available on the Enterprise plan only; entries are kept for 2 years.

## Webhooks

Webhook deliveries are HTTP POST requests with a JSON body. Each delivery includes an `X-Aurora-Signature` header containing an HMAC-SHA256 signature of the raw request body, computed with the webhook's signing secret. Verify the signature before trusting the payload, and reject deliveries whose `X-Aurora-Timestamp` header is more than 5 minutes old.

Your endpoint must respond with a 2xx status within 10 seconds. Failed deliveries are retried up to 8 times with exponential backoff over about 24 hours. A webhook that fails continuously for 3 days is disabled, and organisation admins are notified by email.

## Errors

Errors are returned as JSON with `error.code` and `error.message`.

| HTTP status | Error code | Meaning |
|---|---|---|
| 400 | invalid_request | A parameter is missing or has an invalid value. |
| 401 | invalid_api_key | The API key is missing, wrong or disabled. |
| 403 | insufficient_permissions | The key is read-only, or the plan does not include this feature. |
| 404 | not_found | The resource does not exist or belongs to another organisation. |
| 409 | gateway_already_claimed | The gateway belongs to another organisation. |
| 422 | firmware_too_old | The node's firmware does not support this setting. |
| 422 | range_too_large | The requested time range is too long for this endpoint. |
| 429 | rate_limited | Too many requests; wait for the number of seconds in Retry-After. |
| 503 | maintenance | Scheduled maintenance, announced at least 48 hours in advance on the status page. |

## SDKs

Official SDKs are available for Python (`pip install aurora-cloud`) and JavaScript (`npm install @aurora/cloud`). Both handle pagination and retry rate-limited requests automatically.
