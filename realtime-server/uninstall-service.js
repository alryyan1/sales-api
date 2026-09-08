// Removes the SalesRealtimeServer Windows service (see install-service.js).
// Run with: npm run uninstall-service   (from an elevated terminal)
const os = require("os");
const path = require("path");

if (os.platform() !== "win32") {
  console.log("[uninstall-service] Not on Windows — nothing to do.");
  process.exit(0);
}

const { Service } = require("node-windows");

const svc = new Service({
  name: "SalesRealtimeServer",
  script: path.join(__dirname, "server.js"),
});

svc.on("uninstall", () => {
  console.log("[uninstall-service] Service removed.");
});

svc.on("error", (err) => {
  console.error("[uninstall-service] Failed:", err?.message || err);
  process.exit(0);
});

svc.uninstall();
