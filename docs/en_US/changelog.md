# Changelog

## 0.4

- **23 profiles**, grouped in the list: the nine existing ones are joined by
  overheating room, room too cold, open window, wine cellar, aquarium, smoke /
  CO detector, door or garage left open, power cut, excess humidity, air too
  dry, fine particles, radon, boiler pressure, low battery and strong wind.

## 0.3

- **Message center**: one message per alert and per level. Until now, going
  critical and the following alerts kept the text of the first one, and the
  global “on new message” action no longer fired.
- **Alarm generic types** on the commands: each watch shows up as an alarm in
  the mobile app and the bridges (Homebridge, Google).
- **Equal to**: several values separated by `|`, for example `open|opened`.
- New words in actions: `#objet#`, `#heure#`, `#acquitte_par#`.
- Interface translated into English.

## 0.2

- **Sensor not found**: a rule whose sensor was deleted, or whose equipment is
  disabled, goes to warning instead of staying silent.
- **Critical without actions**: the warning actions are run.
- **Inconsistent settings flagged** on the page: inverted thresholds or range,
  hysteresis too wide, rule without threshold or sensor.
- **Rapid rise and drop**: readings are grouped into slots, the window is no
  longer truncated for a sensor that publishes every second.
- Disabling the equipment drops the ongoing alert; the commands no longer stay
  stuck on “Critical”.
- Page: automatic refresh, state on each tile, confirmation before disabling,
  date shown for a suspension past midnight.
- A critical alert is no longer written as an error in the plugin log.

## 0.1

- First version: watches with several rules (above, below, outside a range,
  equal to, rapid rise and drop), two levels, confirmation delay, hysteresis,
  silent sensor.
- Profiles: fridge, freezer, fire (threshold and rapid rise), frost
  protection, water leak, cellar humidity, CO2.
- Actions per level and on return to normal, in the scenario format, with a
  test button; reminders, acknowledgement, temporary suspension.
- Commands for scenarios and the dashboard, log, overview.
