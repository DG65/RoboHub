🇩🇪 [Deutsche Fassung](README.md)

# RoboHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Module-0.37.0-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![Check Style](https://github.com/DG65/RoboHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/RoboHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue?logo=paypal)](https://paypal.me/DietmarGureth)

The module follows the language of your Symcon (English or German): forms, variables, states, messages and the tile.
Instance names and variable idents stay German in both languages.

Robot vacuums, mopping robots and robot mowers in Symcon — built vendor-neutral, currently with
**Dreame** and **MOVA** (apps "Dreamehome" and "MOVAhome"), **Roborock** (app "Roborock"),
**Ecovacs** Deebot and GOAT (app "Ecovacs Home"), **Vorwerk Kobold** VR200/VR300 (app "MyKobold"), **Miele Scout** (official API), **Kärcher** RCV/RCF/RVC (app "Kärcher Home Robots"), **Eufy** RoboVac/Omni (app "Eufy Clean"/"Eufy"), **iRobot** Roomba/Braava and **Narwal** Flow/Freo (both local, without cloud), **Husqvarna Automower** and **GARDENA smart system** (each via the official API), as well as
the robot mowers from **Worx** (Landroid), **Kress**, **LandXcape**, **Ferrex**, **Segway Navimow**, **Bosch Indego**, **STIHL iMOW** and **AL-KO Robolinho** — each via the
vendor cloud.

## What does this module do?

RoboHub brings the state, battery, cleaning or mowing progress and the wear of the
consumables of your robots into Symcon and accepts commands: start, pause,
stop, return to base, locate, clean individual rooms, choose suction level/water level/
cleaning mode. This makes robots part of your automations, e.g. "vacuum
when everyone has left the house" or "stop mowing when it rains".

All robots get the same variables and commands, no matter which vendor —
further vendors are added as an additional driver, without anything changing in variables,
scripts or visualization.

## Structure

| Instance | Task |
|---|---|
| **RoboHub Konto** (account) | Signs in to the vendor account, manages the access token, relays all requests. One instance per account (e.g. one for the Dreamehome account, one for the MOVAhome account). |
| **RoboHub Konfigurator** (configurator) | One list for the robots of **all** accounts; creates them with one click. Afterwards each robot connects by itself to the account it is registered in. |
| **RoboHub Roboter** (robot) | One instance per robot: variables, commands, room cleaning. |

## Setup

1. Add the module via the module control: `https://github.com/DG65/RoboHub`
2. Create a **RoboHub Konto** instance (the instance names in "Add instance" are German), choose vendor and region (Germany/Austria/
   Switzerland: "Europe"), enter e-mail and password of the app account, "Apply", then
   "🔑 Sign in now".
3. For every further vendor account (e.g. MOVAhome for the mower) create another
   account instance and sign in.
   **Roborock** signs in without a password: choose vendor "Roborock", enter your e-mail,
   "Apply", "📧 Request code", enter the code from the e-mail into the field, "Apply",
   "🔑 Sign in now". Roborock finds the region by itself.
   **Worx, Kress, LandXcape, Ferrex**: choose the brand, enter e-mail and password of the app,
   "Apply", "🔑 Sign in now" — only the refresh token is kept.
   **Ecovacs**: choose the country of the account, enter e-mail and password, sign in. If Ecovacs asks for
   confirmation: "📧 Request code", enter the code from the e-mail into the password field, "Apply", sign
   in again.
   **iRobot** (local): choose vendor "iRobot", enter the IP address of the robot, "Apply". With the robot
   on its base: click "🔑 Sign in now" once (RoboHub searches for the robot), then hold the HOME button for about
   2 seconds until it plays tones, and immediately click "🔑 Sign in now" again. For further robots
   do the same with their IP — they end up in the same account.
   **Husqvarna**: Sign in at [developer.husqvarnagroup.cloud](https://developer.husqvarnagroup.cloud/) with your
   Husqvarna account, create an application (any redirect URL, e.g. `http://localhost`),
   connect "Authentication API" and "Automower Connect API". Put the application key into the field "Application key",
   the application secret into the field below it, sign in.
   **GARDENA**: the same, only connect "GARDENA smart system API" to the application instead of "Automower Connect API"
   (one application can have both).
   **Vorwerk Kobold**: choose vendor "Vorwerk Kobold", enter the e-mail of the MyKobold account, "Apply", "📧 Request
   code", enter the code from the e-mail, sign in. No password.
   **Miele Scout**: First create an access at [developer.miele.com](https://developer.miele.com) (client ID and
   client secret). Choose vendor "Miele Scout", the country of the account, enter client ID, secret as well as e-mail and password of the Miele app,
   sign in. The password is discarded; client ID and secret stay in the account. Allow remote control on the robot.
   **Kärcher**: choose vendor "Kärcher", region (Europe for DE/AT/CH), enter e-mail and password of the app "Kärcher Home Robots",
   sign in. The password is discarded.
   **Eufy mower E15**: choose vendor "Eufy robot mower E15", enter the IP address of the mower (fixed in your router), e-mail and password of the Eufy account, sign in. The account is only needed once for the device key; the password is discarded.
   **Eufy**: choose vendor "Eufy", enter e-mail and password of the app "Eufy Clean" or "Eufy", sign in. The password
   is discarded.
   **Segway Navimow**: choose vendor "Segway Navimow", "Apply", "🔗 Open sign-in page" and sign in there
   with your Navimow account. Afterwards the browser lands on `http://localhost:1/callback?code=…` — the
   page does not load, that is intended. Copy this address from the address bar into the field "Code", "Apply",
   "🔑 Sign in now" (the code is only valid for a few minutes).
   **Narwal** (local): choose vendor "Narwal", enter the IP address of the robot (ideally fixed in your
   router), "Apply", "🔑 Sign in now". No account, no password. For further Narwal robots do the same with their IP.
   **STIHL iMOW**: choose vendor "STIHL iMOW", e-mail and password as in the iMOW app, sign in. RoboHub
   detects by itself whether it is a new (iMOW 5/6/7) or an older mower (RMI series).
   **AL-KO Robolinho**: First apply for API access with AL-KO
   ([alko-garden.de/iot-api-zugang-anfordern](https://alko-garden.de/iot-api-zugang-anfordern/)) — you receive a client ID and
   client secret. Choose vendor "AL-KO Robolinho", enter client ID, secret as well as e-mail and password of the inTOUCH app,
   sign in. The password is discarded; client ID and secret stay in the account because AL-KO renews the access only with them.
   **Bosch Indego**: choose vendor "Bosch Indego", "Apply". In the browser (Chrome, Edge or Firefox) first
   open the developer console (F12, on a Mac Option + Command + I), then "🔗 Open sign-in page" and sign in with your Bosch
   SingleKey ID. Bosch then redirects to the app address `com.bosch.indegoconnect://login?…code=…`,
   which the browser cannot open — the console shows it as a failed redirect. Copy this address
   (or the whole console line) into the field "Code", "Apply", "🔑 Sign in now". ioBroker does it the same
   way.
4. Create **one** RoboHub Konfigurator — it shows the robots of all accounts. Select the robots,
   "Create". Caution: the trash can icon at the end of a row deletes the instance.

## Variables

A variable is only created once your model actually delivers the value.

- **State** (vendor-neutral): Ready, Cleaning/Mowing, Paused, Returning to base,
  Charging, Docked, Station busy, Error, Sleeping, Mapping, Updating firmware,
  Weather pause (mowers). In addition **State (vendor)** with the original value in plain text.
- **Command**: Start / resume, Pause, Stop, Return to base, Locate.
- **Clean room** (vacuums) or **Mow zone** (mowers): selection of the rooms or mowing zones from the
  stored map, also several at once.
- Error, battery, charging, cleaned area, cleaning time, consumables (main brush,
  side brush, filter, mop/roller, sensors, blades on mowers), station, clean/
  dirty water, dust bag, detergent, total statistics.
- Suction level, water level, cleaning mode: directly switchable.
- **Map image** on the tile and the IPSView page: rooms or mowing zones with names to tap.
- **Clean room with options** like the "custom" window of the app: mode, suction level and
  passes for this start only.
- **Schedules** (robot vacuums and robot mowers) from the vendor app: show them and switch them on/off.
- **Live map** while working: driven path and robot position; the finished path stays visible afterwards.
- **Do not disturb** (on/off, start, end) and **Volume**: directly switchable, for robot vacuums and
  robot mowers.
- **Reset consumables** after replacing or cleaning — in the form (with
  confirmation prompt) and on the tile (↺, then confirm). Every reset is recorded in the
  Symcon log.

## Real-time messages

Every account instance keeps (can be switched off, panel "⚡ Real-time messages") an encrypted
MQTT connection to the vendor's server. When something changes on the robot — state, battery,
error, station —, it shows up immediately in Symcon and on the tile. The connection runs via
an automatically created Client Socket instance; the server certificate is checked against the
vendor's certificate authority (certificate and server name), foreign servers
are rejected. **Symcon 9.1 (Rust):** there the new TLS check rejects the certificates of the
Dreame/MOVA real-time server (they are valid from 31.12.1969; nothing before 1970 is supported). RoboHub
then closes the connection itself and only polls; once a day it tries real-time again.
Regular polling remains as a safety net; the fast
30-second polling during work is skipped as long as messages arrive.

## Tile and IPSView

**Tile visualization:** Every robot instance is a tile itself — state with
icon, battery, buttons for Start/Pause/Return to base/Locate, rooms to tap ("clean pantry,
kitchen"), mode and suction level as well as the consumables that are due, first. The
controls use the sign-in of the visualization. The double arrow shows all variables as
usual.

**IPSView and browser:** IPSView cannot display Symcon tiles. Therefore
every robot also delivers the same view as a standalone page:
`http://<Symcon-IP>:3777/hook/robohub<InstanzID>` — embed it in IPSView as a WebView element;
the page refreshes every 15 seconds.

This page can be reached without a Symcon sign-in and is therefore **display only** by
default. It can only be used for control once you allow it in the form ("📱 Tile and
IPSView") — and then only with the secret key in the address
(`…?key=…`, shown in the form, can be regenerated at any time). Alternatively you bind the
variables "Command" and "Clean room" directly in IPSView; then the
IPSView sign-in applies.

## Scripts

```php
ROBO_Starten($id);              // all return a plain-text result
ROBO_Anhalten($id);
ROBO_Stoppen($id);
ROBO_ZurStation($id);
ROBO_Orten($id);
ROBO_RaeumeReinigen($id, "3,5"); // room IDs are shown in the form
ROBO_RaeumeReinigenMit($id, "3", 0, 0, 1); // mode, suction level, passes for this start only, -1 = as set
ROBO_LiveKarteLaden($id);
ROBO_VerbrauchsteilZuruecksetzen($id, "Filter"); // after replacing/cleaning, ident of the % variable
ROBO_Aktualisieren($id);
IPS_RequestAction($id, 'Befehl', 1); // 1 Start, 2 Pause, 3 Stop, 4 Return to base, 5 Locate
```

A command at first only means "sent" — whether the robot carries it out is shown by the
variable "State" a few seconds later.

### For other modules: `ROBO_GetActivity($id)`

Read-only contract `robot_activity` 1.0 (e.g. for FrigateHub: no false alarm when the mower
drives through the picture). Reads Symcon variables only, never the vendor cloud. Robots are found via
`IPS_GetInstanceListByModuleID('{631DA85B-5985-4C25-B222-E47FDD49E302}')`.

```json
{"contractVersion":"1.0","class":"mower","name":"LiDAX","state":1,"stateVariableID":12345,
 "moving":true,"outsideStation":true,"since":1794300000,"updatedAt":1794300120,
 "validUntil":1794300420,"realtime":true,"pollIntervalS":120}
```

`state` is vendor-neutral: -1 unknown, 0 ready (outside the station), 1 working, 2 paused,
3 returning to base, 4 charging, 5 charged, 6 station task, 7 error, 8 sleeping, 9 mapping,
10 firmware update, 11 weather pause. `validUntil` < now means: stale.

## Security and privacy

The password is only used for the first sign-in and discarded afterwards; what is stored
is the access token. **Exception Ecovacs:** Ecovacs has no refresh token; the
access expires after 7 days and can only be renewed with the password. RoboHub therefore keeps
an md5 hash of the password (not the password itself) in the account to sign in again by itself;
"Sign out" deletes it. **iRobot** works locally: the robot's device password stays in the account, and
because every robot has a self-signed certificate, RoboHub remembers its
fingerprint when pairing and afterwards rejects any connection with a different certificate. **Husqvarna:**
application key and secret stay in the account because the official interface renews the access (24 h) only
with them; you can revoke them in the developer portal at any time. The token is not shown in the form and not written to the log — Symcon
does not encrypt stored values in general, though, so it is stored in plain text in
the Symcon database, as with every cloud module.

RoboHub talks to the vendor cloud via the same interface as the app. It is
not officially documented and may change.

## Limitations

- Tested live with Dreame Aqua10 Ultra Roller Complete and MOVA LiDAX Ultra 1000. Other
  Dreame/MOVA models of the same apps should work but are untested —
  feedback is welcome.
- **Roborock** (since 0.13.0): built following the protocol of python-roborock and checked against its
  test data, but not yet on a real device. Supported are devices with the
  protocol "1.0" (S, Q, Qrevo, Saros series); RoboHub reports newer devices with "A01"/"B01" as
  "not supported yet". No map image, real-time messages or schedules yet.
- **Worx, Kress, LandXcape, Ferrex** (since 0.14.0): built following ioBroker.worx. Sign-in, state,
  settings and weekly schedule confirmed in the forum on a Worx m500 (October 2026); commands still open.
  The state comes from the last status the mower reported to the cloud; commands go via MQTT
  (WebSocket). In addition battery temperature, voltage, charge cycles, Wi-Fi signal, distance, blade run time and
  firmware. No zones, changing the weekly schedule, real-time messages or blade reset yet; "Locate" does
  not exist on these mowers.
- **Ecovacs** (since 0.15.0): Deebot vacuums and GOAT mowers with JSON protocol, not tested on a real device
  (the signed request to the Ecovacs sign-in is confirmed live). RoboHub reports older models with
  XML protocol as "not supported yet". Room names only on models with an
  uncompressed room list; no map image, real-time or schedules yet.
- **iRobot** (since 0.16.0): built locally following roombapy; polling and commands confirmed in the forum on two Roomba 980
  (October 2026). Since 0.19.0 cleaning settings as in the iRobot app (suction power,
  passes, edge cleaning, behavior with a full bin) and a position map with the path of the
  running cleaning — both can be switched off. Models that only hand out their password via the iRobot cloud
  do not work (yet). A robot allows only one local connection at a time — if, for example, Home Assistant
  also runs locally, the two interfere with each other. Older firmware (e.g. Roomba 980 with 2.x) that
  rejects OpenSSL 3 is addressed by RoboHub via its own TLS 1.2 path since 0.18.6. No rooms (names only in the cloud),
  wear parts, floor plan or real-time.
- **Husqvarna Automower** (since 0.17.0): official Automower Connect API following aioautomower, not tested on a
  real mower (token address confirmed live). Data at most 5 minutes old (limit 10,000
  requests/month); "Start" mows for 3 hours or resumes the schedule after a pause. No
  real-time (WebSocket), schedules, cutting height or blade reset yet.
- **GARDENA smart system** (since 0.18.0): robot mowers via the official interface following py-smart-gardena,
  not tested on a real mower. Data at most 15 minutes old (tight request limit). "Pause" does not exist
  on GARDENA. RoboHub does not integrate valves, sensors and power sockets.
- **Segway Navimow** (since 0.22.0): built following home-assistant-navimow and ioBroker.navimow, not tested on a real
  mower. **To be clear:** Segway does not issue separate credentials for smart home systems but
  publishes a smart home identifier named "homeassistant". RoboHub uses it like ioBroker does; the
  Segway sign-in page therefore shows "Home Assistant". You enter your password only at Segway; RoboHub
  only stores the access token. State, battery, Start / resume, Pause, Stop, Return to base; no real-time,
  position, zones, Locate or settings (the interface does not offer them, or only via MQTT).
- **Eufy mower E15** (since 0.34.0): locally in your home network via Tuya 3.5, not tested on a real device. State, battery, cutting height, volume; Start / resume, Pause, Return to base. Map, zones, stopping on the spot and Locate are not possible locally. Only one local connection at a time. E18/C15 not tested.
- **Eufy** (since 0.32.0): based on the protocol knowledge from eufy-clean; not tested on a real robot (sign-in server confirmed
  live). Only robots with Eufy's new cloud connection (among others X8/X9/X10, L60, C10/C20, G50, Omni S1 Pro); older models with
  pure Tuya protocol (e.g. 11S, 30C, G30) not yet. State incl. washing/drying the mop and emptying dust, battery, error,
  statistics, wear, suction level; Start / resume, Pause, Stop, Return to base, Locate. Eufy has no documented
  way to renew without the password — if Eufy rejects the access, you sign in again. Rooms, map and station commands not yet.
- **Kärcher** (since 0.31.0): following python-karcher and karcher-rcv5-ha (MIT); not tested on a real robot (sign-in,
  signature and MQTT connection confirmed live). State, battery, error, area, duration, wear, suction level, water level,
  cleaning mode; Start, Pause, Stop, Return to base, Locate. Kärcher has no official refresh token: RoboHub
  tries renewing the way the app does; if that fails, you have to sign in again (RoboHub deliberately does not
  store the password). Rooms, map and real-time not yet.
- **Miele Scout** (since 0.30.0): official Miele interface following ioBroker.mielecloudservice and astrandb/miele (MIT);
  not tested on a real robot (sign-in server confirmed live). State (with Miele's own German text), errors F1–F8,
  battery, program Auto/Turbo/Silent; Start, Pause, Stop. The Miele interface does not offer "Return to base" and "Locate"; rooms
  and real-time not yet. (The Symcon module "Miele@Home" by demel42 does not support robot vacuums according to its README.)
- **Vorwerk Kobold VR200/VR300** (since 0.29.0): following pybotvac and the Homey app (both MIT, tested live there on a VR300);
  not tested with RoboHub on a real device (both Vorwerk servers reachable live). Sign-in via e-mail code;
  state and commands then keep running with each robot's own key, even when the account token expires.
  Start / resume, Pause, Stop, Return to base, Locate, VR300: stored zones. **The Kobold VR7 does not work (yet)** — it
  uses a different cloud for which no reliable open description exists.
- **AL-KO Robolinho** (since 0.28.0): official AL-KO interface, following alko-cli (MIT, measured on a Robolinho 700 W) and the
  HA integration jonkristian/alko; not tested on a real mower (sign-in server confirmed live). State with the reason for
  returning home, rain/frost pause, PIN lock, error, battery, Wi-Fi, mowing time, blade run time, cycles, remaining blade life,
  rain delay (display), weekly schedule; Mow, Halt, Return to base. No changing settings, edge mowing or mowing duration yet.
- **STIHL iMOW** (since 0.27.0): new generation (iMOW 5/6/7, also EVO) following ha-imow-v2, older RMI series following
  stihl-imow-webapi; not tested on a real mower (both STIHL sign-ins confirmed live up to the password check).
  New generation: password only for sign-in, state as in the app, errors with STIHL's text, battery, statistics, zones,
  Mow (default duration of the mower), Pause, Return to base. **Older RMI series:** STIHL issues an access there for
  30 days without renewal; RoboHub deliberately does not store the password, warns three days in advance and then asks for
  a new sign-in. Via the cloud, RMI can only Mow (2 h) and Return to base. No real-time, position or mowing schedule.
- **Narwal** (since 0.24.0): built locally via WebSocket (port 9002) following NarwalIntegration, not tested on a real
  device. Works with Flow, Flow 2, Freo Z10 Ultra/Pro, Freo X10 Pro, Freo 20/20 Edge and JX; Freo X Ultra, X Plus, J1/J4
  and T10 have no local interface, the Freo Z Ultra needs a cloud device ID (not supported yet).
  A robot in the station is not woken up for polling; if it is asleep, RoboHub shows "Sleeping" with the current battery level.
  "Start" cleans all rooms of the map with vacuuming and mopping (app default) and only works from the station; from a pause
  it resumes. No map image, vacuum/mop settings, station tasks or consumables.
- **Bosch Indego** (since 0.23.0): built following pyIndego and the Home Assistant integration, not tested on a real mower
  (the Bosch sign-in page accepts the redirect address, confirmed live). State, error, mowed share,
  mowing hours, battery (fresh while mowing and charging, at rest the last value — polling would wake the mower), Mow,
  Pause, Return to base. No map image, calendar, SmartMowing or message list; "Stop" and "Locate" do not exist.
- Schedules can be switched on and off, but not newly created or changed in time.
- The room settings stored in the robot are only displayed; different values per
  start are available via "Clean room with options".
- Mowers: the driven mowing path on the live map is not confirmed yet; the position is.
- Regions other than "Europe" are untested.

## Acknowledgements

The Dreame/MOVA cloud interface was reconstructed from three open-source projects (MIT license):
[TA2k/ioBroker.dreame](https://github.com/TA2k/ioBroker.dreame),
[Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) and
[EvotecIT/homeassistant-dreamelawnmower](https://github.com/EvotecIT/homeassistant-dreamelawnmower).
The Roborock driver follows [Python-roborock/python-roborock](https://github.com/Python-roborock/python-roborock)
(Apache-2.0); its response samples are included unchanged in the test bench (`.tools/roborock/`).
The Worx/Kress/LandXcape/Ferrex driver follows
[iobroker-community-adapters/ioBroker.worx](https://github.com/iobroker-community-adapters/ioBroker.worx) (MIT);
the sign-in to the command server was cross-checked with [MTrab/pyworxcloud](https://github.com/MTrab/pyworxcloud)
(GPL-3.0, used only as a protocol description, no code taken over).
The Ecovacs driver was rewritten from the protocol description of
[DeebotUniverse/client.py](https://github.com/DeebotUniverse/client.py) (GPL-3.0, no code
taken over; the list of device classes is derived from it).
The iRobot driver follows [pschmitt/roombapy](https://github.com/pschmitt/roombapy) (MIT), the
Husqvarna driver [Thomas55555/aioautomower](https://github.com/Thomas55555/aioautomower) (MIT), the
GARDENA driver [py-smart-gardena](https://github.com/py-smart-gardena/py-smart-gardena) (MIT), the
Navimow driver [niddu85/home-assistant-navimow](https://github.com/niddu85/home-assistant-navimow) and
[ioBroker.navimow](https://github.com/TA2k/ioBroker.navimow) (both MIT), the STIHL driver
[simontims/ha-imow-v2](https://github.com/simontims/ha-imow-v2), [ChrisHaPunkt/stihl-imow-webapi](https://github.com/ChrisHaPunkt/stihl-imow-webapi)
and [TA2k/ioBroker.imow](https://github.com/TA2k/ioBroker.imow) (all MIT), the AL-KO driver
[trx222/alko-cli](https://github.com/trx222/alko-cli), [jonkristian/alko](https://github.com/jonkristian/alko) and
[chave/homebridge-alko-mower](https://github.com/chave/homebridge-alko-mower) (all MIT), the Vorwerk driver
[stianaske/pybotvac](https://github.com/stianaske/pybotvac) and [SornyKonst/Homey-App-Vorwerk-Kobold](https://github.com/SornyKonst/Homey-App-Vorwerk-Kobold)
(both MIT) as well as [bndtblds/homeassistant-vorwerk](https://github.com/bndtblds/homeassistant-vorwerk) (Apache-2.0, meaning of the
error codes), the Miele driver [Grizzelbee/ioBroker.mielecloudservice](https://github.com/Grizzelbee/ioBroker.mielecloudservice),
[astrandb/miele](https://github.com/astrandb/miele) and [nordicopen/pymiele](https://github.com/nordicopen/pymiele) (all MIT),
the Kärcher driver [lafriks/python-karcher](https://github.com/lafriks/python-karcher) and
[vosadci/karcher-rcv5-ha](https://github.com/vosadci/karcher-rcv5-ha) (both MIT), [Thomvanoorschot/ha-eufy-e15](https://github.com/Thomvanoorschot/ha-eufy-e15) and [jasonacox/tinytuya](https://github.com/jasonacox/tinytuya) (both MIT) for the E15 mower, the protocol knowledge of the Eufy driver
[jeppesens/eufy-clean](https://github.com/jeppesens/eufy-clean) and [martijnpoppen/eufy-clean](https://github.com/martijnpoppen/eufy-clean),
the Indego driver
[sander1988/pyIndego](https://github.com/sander1988/pyIndego) (MIT) and [sander1988/Indego](https://github.com/sander1988/Indego)
(Apache-2.0), the Narwal driver [sjmotew/NarwalIntegration](https://github.com/sjmotew/NarwalIntegration) (MIT);
the instructions using the developer console come from
[ioBroker.boschindego](https://github.com/iobroker-community-adapters/ioBroker.boschindego) (MIT).

## Feedback

Questions, experiences with further models and wishes are welcome in the
[RoboHub thread in the Symcon forum](https://community.symcon.de/t/beta-modul-robohub-dreame-saugroboter-und-mova-maehroboter-in-ip-symcon-mit-karte-live-weg-zeitplaenen-und-raumreinigung/144636),
bugs also as a [GitHub issue](https://github.com/DG65/RoboHub/issues).

## License

PolyForm Noncommercial 1.0.0 — private and non-commercial use is free; commercial
use requires a separate license from the rights holder (DG65). Full text:
[LICENSE](LICENSE). Donations are welcome:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
