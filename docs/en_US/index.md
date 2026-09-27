# Alertes BE

The plugin watches sensors and acts when one of them crosses a threshold.

Jeedom can already colour a command as “warning” or “danger” depending on its
value, but those thresholds are hidden in each command's configuration, with no
overview, no alert tracking, no reminder and no acknowledgement. This plugin
turns them into **watches**: one piece of equipment per thing to watch, with
its rules, its actions, its state and its log.

## A watch

One piece of equipment = one watch: “Kitchen fridge”, “Garage fire”,
“Cellar”. It holds one or more **rules**, and its level is the worst of its
rules:

| Level | Meaning |
|---|---|
| Normal | nothing to report |
| Warning | worth a look: the fridge is warming up, a sensor no longer answers |
| Critical | act now: the fridge is warm, there is water on the floor |

## Rules

Each rule watches an info command from any plugin.

| Type | Example |
|---|---|
| Above a threshold | fridge ≥ 7 °C, CO2 ≥ 1000 ppm |
| Below a threshold | frost protection ≤ 3 °C |
| Outside a range | cellar humidity outside 40–70 % |
| Equal to | leak detector = 1, contact = “open”, or several values: `open\|opened` |
| Rapid rise | +8 °C in 2 minutes |
| Rapid drop | −5 °C in 10 minutes |

And for each one:

- **Warning** and **Critical**: the two thresholds. An empty threshold is not
  used: a rule may have only one level.
- **Confirmation**: the value must stay beyond the threshold for this whole
  time before the alert, each level counting on its own. This is what keeps an
  open fridge door from triggering anything. A single reading back under the
  threshold resets the counter. 0: immediate.
- **Hysteresis**: the alert only ends once the value has come back this far
  past the threshold. A fridge alerting at 7 °C with 1 °C of hysteresis only
  returns to normal at 6 °C: a value hovering around the threshold does not
  make the alert flicker.
- **Silent after**: if the sensor has published nothing for this long, the rule
  goes to warning. Jeedom keeps the last value of a sensor with a dead battery;
  without this check, it would say “all is well” forever. Many sensors only
  publish on change: do not go too low.
- **Equal to**: the comparison ignores case. Several values are separated by
  `|`: `open|opened|1` matches all three, handy when two contacts with the same
  role do not report the same text.
- **Window** (rapid rise and drop): the time over which the change is measured,
  from the lowest (or highest) point of the window.

A rapid rise goes quiet as soon as the temperature levels off, even when high:
for a fire, put two rules on the same sensor, the “Fire — temperature” profile
and the “Fire — rapid rise” profile.

An unreadable value (empty, text in a numeric sensor) changes nothing: the rule
keeps its level. A sensor that is **not found** — deleted, or whose equipment
is disabled — puts the rule in warning: a watch that no longer sees anything
must not say “all is well”.

The page flags settings that will not do what you expect: inverted thresholds,
inverted range, hysteresis wider than the gap between the two thresholds, rule
without a threshold or without a sensor.

### Profiles

The “Add a rule” button offers prefilled rules, all editable afterwards:

| Profile | Rule |
|---|---|
| Fridge | ≥ 7 °C / ≥ 10 °C, confirmation 20 min, hysteresis 1 °C, silent after 3 h |
| Freezer | ≥ −15 °C / ≥ −12 °C, confirmation 30 min |
| Fire — temperature | ≥ 50 °C / ≥ 57 °C, immediate |
| Fire — rapid rise | +5 °C / +8 °C in 2 min, immediate |
| Frost protection | ≤ 5 °C / ≤ 3 °C, confirmation 10 min |
| Water leak | = 1 as critical, immediate |
| Humidity (cellar) | outside 40–70 % / outside 30–80 %, confirmation 1 h |
| CO2 | ≥ 1000 / ≥ 1500 ppm, confirmation 5 min |

## Actions

Three lists, in the scenario format — a command (notification, siren, lamp) or
a block (message, scenario, variable):

- **Warning**: when the watch goes from normal to warning;
- **Critical**: when it goes critical, from normal or from warning. Left
  empty, the warning actions are run: whoever set only one notification also
  gets it when the fridge reaches 12 °C;
- **Return to normal**: when everything is back in order.

Critical → warning triggers nothing: the alert is not over. It is only noted in
the log.

In titles and messages, these words are replaced:

| Word | Replaced by |
|---|---|
| `#equipement#` | the watch name |
| `#objet#` | its room (parent object), empty otherwise |
| `#niveau#` | Warning, Critical, Normal |
| `#message#` | the rules in alert, in plain words: “Fridge: 11.2 °C (≥ 10 °C)” |
| `#regle#`, `#capteur#` | the worst rule and its sensor |
| `#valeur#`, `#unite#`, `#seuil#` | its value, its unit, the threshold crossed |
| `#pic#` | the worst reached during the alert |
| `#depuis#`, `#duree#` | the start time, the duration |
| `#heure#` | the time the action runs |
| `#rappel#` | the reminder number (0 for the first alert) |
| `#acquitte_par#` | on return to normal, who had acknowledged the alert; empty otherwise |

The **Test** button runs the saved actions of a level, with a test message,
without changing the state of the watch.

Blocks that wait (“Wait”, “Sleep”, “Ask”…) are refused: they would hold up the
Jeedom cron. For a sequence, run a scenario.

### Reminders and acknowledgement

As long as the alert lasts and nobody has **acknowledged** it, the actions of
its level are run again at a regular interval (every 30 minutes, three times at
most, by default). Rising to critical clears the acknowledgement and restarts
the count: you acknowledged a fridge at 8 °C, not a fridge at 12 °C.

You acknowledge from the page, the overview, the dashboard or a scenario
(“Acknowledge” command).

### Message center

Each alert is written there (optional): a record in Jeedom even if no action
is set, or if the notification did not go out. One message per alert and per
level — going critical gets its own — which stays after the return to normal.
Suspending, disabling or deleting the watch clears its messages.

## Suspend, disable

- **Suspend** stops the watch for a while — cleaning the fridge, defrosting
  the freezer — then it resumes by itself.
- **Disable** stops it until further notice.

In both cases, an ongoing alert is dropped without running the
return-to-normal actions. The same goes for disabling the equipment itself
(“Enable” box). On resuming, a problem still present goes through its
confirmation delay again and triggers its actions again.

## Commands

| Command | Type | Role |
|---|---|---|
| State | text info | Normal, Warning, Critical, Suspended, Disabled |
| Message | text info | the rules in alert, in plain words |
| Level | numeric info | 0, 1, 2 — historized, for charts and scenarios |
| Alerting | binary info | 1 from warning upwards |
| Since | text info | start of the alert |
| Acknowledged | binary info | |
| Monitoring active | binary info | 0 when suspended or disabled |
| Acknowledge | action | |
| Enable / Disable monitoring | action | |
| Suspend (minutes) | slider action | |
| Resume | action | |
| Refresh | action | re-evaluates right away |

“Alerting”, “Monitoring active”, “Enable” and “Disable monitoring” carry the
generic types of an alarm (state, enabled state, arm, release): the mobile app
and the Homebridge or Google bridges thus show each watch as an alarm, which
can be suspended or re-enabled from the phone. A generic type changed by hand
is never overwritten.

## How it works

No daemon, no dependency. A listener reacts to every new value of a watched
sensor — a fire does not wait for the next minute — and the Jeedom cron runs
every minute for what depends on time alone: confirmation delays, reminders,
silent sensors.

The state of each alert is stored in the database: a Jeedom restart finds an
ongoing alert without running its actions again.
