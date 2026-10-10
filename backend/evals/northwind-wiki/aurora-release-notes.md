# Aurora Firmware Release Notes

Firmware for Aurora Nodes and Aurora Gateways, newest release first. Gateways and nodes use the same version numbers. Releases are rolled out gradually over about two weeks.

## Firmware 3.4.0 (2026-06-01)

- Automatic CO2 baseline correction (ABC) can be turned off per node in the dashboard.
- Temperature offset can be set per node, up to ±3 degrees Celsius.
- Fixed incorrect timestamps from the humidity filter when the gateway clock was adjusted.
- Fixed incorrect timestamps from the Ethernet driver when the gateway clock was adjusted.
- Fixed a race condition in the time-series buffer that could delay readings by up to one reporting interval.

## Firmware 3.3.1 (2026-03-16)

- Fixed: gateways did not renew their TLS client certificate automatically, which caused the outage of 14 March 2026. Certificates are now renewed 30 days before they expire, and the gateway raises an alert in the dashboard if renewal fails.
- The LTE-M modem driver now logs more detail for support cases.
- Reduced memory use of the dashboard sync.
- Fixed incorrect timestamps from the mesh routing when the gateway clock was adjusted.

## Firmware 3.3.0 (2026-01-19)

- Security updates can no longer be postponed in the dashboard.
- Firmware updates can be postponed by up to 30 days (previously 14 days).
- Reduced memory use of the battery gauge.
- Fixed a race condition in the BLE scanning that could delay readings by up to one reporting interval.
- Reduced memory use of the BLE scanning.

## Firmware 3.2.1 (2025-10-10)

- Fixed a race condition in the CO2 sensor driver that could delay readings by up to one reporting interval.
- The OTA updater now logs more detail for support cases.
- The LED driver now logs more detail for support cases.

## Firmware 3.2.0 (2025-09-02)

- Added the 30-second reporting interval. At 30 seconds, the expected battery life is about 2.5 years.
- Gateways with an LTE-M modem can now use LTE-M as their only connection.
- Fixed a race condition in the dashboard sync that could delay readings by up to one reporting interval.

## Firmware 3.1.2 (2025-05-16)

- Fixed incorrect timestamps from the LTE-M modem driver when the gateway clock was adjusted.
- Improved stability of the Ethernet driver on sites with many nodes.
- Reduced memory use of the OTA updater.
- Improved error handling in the humidity filter on unstable networks.

## Firmware 3.1.1 (2025-05-05)

- Reduced memory use of the time-series buffer.
- The battery gauge now logs more detail for support cases.
- Fixed a rare crash in the gateway watchdog after a gateway restart.

## Firmware 3.1.0 (2025-04-10)

- The humidity filter now logs more detail for support cases.
- Fixed a race condition in the BLE scanning that could delay readings by up to one reporting interval.

## Firmware 3.0.0 (2025-03-10)

- New power-saving radio scheduling: expected battery life at the default 60-second interval increased from 3 years to 5 years.
- Support for the Aurora Node Plus (VOC and air pressure).
- Mesh messages can now travel through up to 6 hops (previously 4), allowing larger buildings to be covered without extra gateways.
- Breaking: HTTP proxy support on the gateway was removed.
- The gateway watchdog now logs more detail for support cases.

## Firmware 2.6.2 (2024-07-26)

- Improved error handling in the humidity filter on unstable networks.
- Improved error handling in the OTA updater on unstable networks.
- Fixed a race condition in the OTA updater that could delay readings by up to one reporting interval.
- Reduced memory use of the dashboard sync.

## Firmware 2.6.1 (2024-06-25)

- Fixed a race condition in the dashboard sync that could delay readings by up to one reporting interval.
- Fixed a problem where the LTE-M modem driver reported a stale value after waking up.
- Fixed a problem where the LED driver reported a stale value after waking up.
- Reduced memory use of the OTA updater.

## Firmware 2.6.0 (2024-06-10)

- Added a 5-minute and a 15-minute reporting interval, selectable per site.
- Readings retention on the Enterprise plan extended to 7 years.
- Improved error handling in the BLE scanning on unstable networks.
- Fixed a rare crash in the BLE scanning after a gateway restart.
- Improved stability of the time-series buffer on sites with many nodes.

## Firmware 2.5.2 (2023-12-25)

- Fixed a problem where the LTE-M modem driver reported a stale value after waking up.
- Fixed incorrect timestamps from the humidity filter when the gateway clock was adjusted.

## Firmware 2.5.1 (2023-11-22)

- Improved error handling in the Ethernet driver on unstable networks.
- Fixed a race condition in the Ethernet driver that could delay readings by up to one reporting interval.
- Fixed a rare crash in the time-series buffer after a gateway restart.

## Firmware 2.5.0 (2023-11-08)

- Fixed incorrect timestamps from the time-series buffer when the gateway clock was adjusted.
- Fixed a race condition in the battery gauge that could delay readings by up to one reporting interval.
- The BLE scanning now logs more detail for support cases.
- Fixed a race condition in the humidity filter that could delay readings by up to one reporting interval.

## Firmware 2.4.2 (2023-10-24)

- Improved error handling in the mesh routing on unstable networks.
- Fixed a race condition in the Ethernet driver that could delay readings by up to one reporting interval.

## Firmware 2.4.1 (2023-09-24)

- Fixed incorrect timestamps from the time-series buffer when the gateway clock was adjusted.
- Fixed a race condition in the time-series buffer that could delay readings by up to one reporting interval.

## Firmware 2.4.0 (2023-09-02)

- Reduced memory use of the OTA updater.
- The battery gauge now logs more detail for support cases.
- Improved stability of the Ethernet driver on sites with many nodes.
- Fixed a rare crash in the LTE-M modem driver after a gateway restart.

## Firmware 2.3.0 (2023-08-15)

- Improved error handling in the mesh routing on unstable networks.
- Fixed incorrect timestamps from the LED driver when the gateway clock was adjusted.
- Fixed a race condition in the gateway watchdog that could delay readings by up to one reporting interval.

## Firmware 2.2.2 (2023-07-20)

- Reduced memory use of the LTE-M modem driver.
- Fixed incorrect timestamps from the BLE scanning when the gateway clock was adjusted.

## Firmware 2.2.1 (2023-06-20)

- Improved error handling in the BLE scanning on unstable networks.
- Improved stability of the OTA updater on sites with many nodes.
- Improved error handling in the Ethernet driver on unstable networks.
- Improved stability of the NTP client on sites with many nodes.

## Firmware 2.2.0 (2023-05-15)

- Fixed a problem where the gateway watchdog reported a stale value after waking up.
- Reduced memory use of the dashboard sync.
- Fixed a race condition in the humidity filter that could delay readings by up to one reporting interval.
- The CO2 sensor driver now logs more detail for support cases.

## Firmware 2.1.0 (2023-04-26)

- Improved error handling in the BLE scanning on unstable networks.
- Reduced memory use of the gateway watchdog.
- Fixed incorrect timestamps from the LED driver when the gateway clock was adjusted.
- Fixed a rare crash in the LTE-M modem driver after a gateway restart.

## Firmware 2.0.0 (2023-04-17)

- New gateway radio stack: one gateway now supports up to 250 nodes (previously 100).
- Gateways buffer readings for up to 72 hours when offline (previously 24 hours).
- Breaking: nodes on firmware 1.x must be updated to 2.0 before they can join a 2.0 gateway.
- Reduced memory use of the LTE-M modem driver.
- Reduced memory use of the mesh routing.
- Fixed incorrect timestamps from the dashboard sync when the gateway clock was adjusted.

## Firmware 1.5.0 (2023-01-23)

- Fixed a rare crash in the gateway watchdog after a gateway restart.
- Fixed incorrect timestamps from the mesh routing when the gateway clock was adjusted.
- The BLE scanning now logs more detail for support cases.
- Reduced memory use of the NTP client.

## Firmware 1.4.1 (2022-12-28)

- Improved error handling in the gateway watchdog on unstable networks.
- Improved stability of the dashboard sync on sites with many nodes.
- Improved error handling in the battery gauge on unstable networks.

## Firmware 1.4.0 (2022-11-21)

- Added ambient light measurement to the Aurora Node.
- Gateways buffer readings for up to 24 hours when offline.
- Reduced memory use of the BLE scanning.
- Fixed incorrect timestamps from the time-series buffer when the gateway clock was adjusted.

## Firmware 1.3.2 (2022-09-27)

- The humidity filter now logs more detail for support cases.
- Fixed a problem where the CO2 sensor driver reported a stale value after waking up.
- Fixed a rare crash in the time-series buffer after a gateway restart.

## Firmware 1.3.1 (2022-09-04)

- Fixed incorrect timestamps from the mesh routing when the gateway clock was adjusted.
- Fixed a problem where the dashboard sync reported a stale value after waking up.
- The battery gauge now logs more detail for support cases.
- Fixed incorrect timestamps from the gateway watchdog when the gateway clock was adjusted.

## Firmware 1.3.0 (2022-07-31)

- Reduced memory use of the Ethernet driver.
- Improved error handling in the OTA updater on unstable networks.

## Firmware 1.2.0 (2022-07-10)

- Improved stability of the NTP client on sites with many nodes.
- Improved error handling in the gateway watchdog on unstable networks.
- Fixed incorrect timestamps from the CO2 sensor driver when the gateway clock was adjusted.

## Firmware 1.1.0 (2022-06-30)

- Fixed incorrect timestamps from the mesh routing when the gateway clock was adjusted.
- Fixed a rare crash in the BLE scanning after a gateway restart.
- Reduced memory use of the OTA updater.
- Fixed a problem where the CO2 sensor driver reported a stale value after waking up.

## Firmware 1.0.2 (2022-06-07)

- Reduced memory use of the humidity filter.
- Fixed a rare crash in the OTA updater after a gateway restart.
- Fixed a problem where the mesh routing reported a stale value after waking up.

## Firmware 1.0.1 (2022-05-16)

- Fixed a race condition in the battery gauge that could delay readings by up to one reporting interval.
- Improved error handling in the NTP client on unstable networks.
- Fixed a problem where the dashboard sync reported a stale value after waking up.

## Firmware 1.0.0 (2022-05-02)

- Initial release of the Aurora Node and Aurora Gateway firmware.
- Nodes report readings every 60 seconds; the interval cannot be changed.
- Expected battery life is 3 years.
- One gateway supports up to 100 nodes. Mesh messages can travel through at most 4 hops.
- Improved error handling in the OTA updater on unstable networks.
- Reduced memory use of the NTP client.
- Reduced memory use of the mesh routing.
