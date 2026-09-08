// Registers this relay as a Windows service that starts automatically on boot, so it
// survives reboots without anyone needing to double-click start.bat or leave a console
// window open. Runs automatically after `npm install` (see the "postinstall" script in
// package.json) and can also be re-run any time via: npm run install-service
//
// Must be run from an elevated (Administrator) terminal — Windows service registration
// requires it. If it isn't elevated, this prints a clear message and exits without
// breaking `npm install`.
const os = require("os");
const path = require("path");

if (os.platform() !== "win32") {
  console.log("[install-service] Not on Windows — skipping. Run \"node server.js\" (or npm start) directly instead.");
  process.exit(0);
}

const { Service } = require("node-windows");

const svc = new Service({
  name: "SalesRealtimeServer",
  description: "POS realtime relay — pushes sale/payment updates to POS browser tabs live.",
  script: path.join(__dirname, "server.js"),
});

svc.on("install", () => {
  console.log("[install-service] Service installed. Starting it now...");
  svc.start();
});

svc.on("alreadyinstalled", () => {
  console.log("[install-service] Service already installed — restarting so it picks up the latest server.js...");
  svc.restart();
});

svc.on("start", () => {
  console.log("[install-service] SalesRealtimeServer is running and will auto-start on every boot.");
});

svc.on("error", (err) => {
  console.error("[install-service] Could not install/start the service:", err?.message || err);
  console.error("[install-service] This almost always means the terminal isn't running as Administrator.");
  console.error("[install-service] Right-click cmd/PowerShell -> \"Run as administrator\", cd here, then: npm run install-service");
  // Exit 0 on purpose: a failed service install must never make `npm install` itself fail.
  process.exit(0);
});

svc.install();
