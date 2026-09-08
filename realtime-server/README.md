# sales-realtime-server

Standalone relay: Laravel POSTs sale/payment events here (`POST /notify`), and this
server fans them out live to every connected POS browser tab over Socket.IO. See
`app/Services/RealtimeNotifier.php`, `app/Observers/SaleObserver.php`, and
`app/Observers/PaymentObserver.php` in `sales-api` for the Laravel side.

## Quick start (on this machine, for testing)

```
npm install
node server.js
```

`npm install` automatically also registers this as an auto-starting Windows service
(see below) — if that's not what you want on this machine, run `npm run uninstall-service`
afterward, or just ignore the service and keep using `node server.js` / `npm start` for
manual runs.

## Running permanently on a server (incl. offline machines)

1. On a machine **with internet access**, run `npm install` here to fetch dependencies.
2. Copy this whole folder (including the now-populated `node_modules`) to the target
   server — USB drive, network share, or git, whichever is offline-friendly for you.
3. On the target server, open an **Administrator** command prompt, `cd` here, and run:
   ```
   npm run install-service
   ```
   This registers `SalesRealtimeServer` as a Windows service that starts automatically
   on every boot — no need to leave a console window open or re-run anything manually.
4. Configure `.env` here (copy `.env.example`) and `sales-api/.env`
   (`REALTIME_SERVER_URL`, `REALTIME_SERVER_SECRET`) on that same machine — see the main
   project's setup notes for the full walkthrough.

## Updating later

Stop the service, replace the changed files (and `npm install` again if `package.json`
changed), then either restart the service or just re-run `npm run install-service` —
it detects the existing service and restarts it automatically.

```
net stop SalesRealtimeServer
:: copy updated files here ...
npm run install-service
```

## Scripts

| Command | What it does |
|---|---|
| `npm start` | Runs the server directly in the foreground (`node server.js`) — for manual testing. |
| `npm run install-service` | Registers/updates the `SalesRealtimeServer` Windows service and starts it. Requires an Administrator terminal. Runs automatically after `npm install` too. |
| `npm run uninstall-service` | Removes the Windows service. |

Non-Windows machines: the service scripts just print a message and exit — use
`node server.js` directly (e.g. under `pm2` or `systemd`) instead.
