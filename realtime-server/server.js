// Standalone real-time relay for the POS app.
//
// Laravel (sales-api) POSTs a sale/payment event here whenever a sale is
// created or a payment is added/cancelled (see app/Services/RealtimeNotifier.php
// and app/Observers/{SaleObserver,PaymentObserver}.php). This server simply
// fans that event out to every connected POS browser tab via Socket.IO, so
// cashiers see shift sales and payment status update live, no reload needed.
//
// This process is independent of XAMPP/Apache — start it separately:
//   npm install
//   node server.js        (or: npm start, or double-click start.bat)
require("dotenv").config();

const express = require("express");
const http = require("http");
const cors = require("cors");
const { Server } = require("socket.io");

const PORT = process.env.PORT || 3001;
const INTERNAL_SECRET = process.env.REALTIME_SERVER_SECRET || "";

const app = express();
app.use(cors());
app.use(express.json());

const server = http.createServer(app);
const io = new Server(server, { cors: { origin: "*" } });

io.on("connection", (socket) => {
  console.log(`[realtime] client connected: ${socket.id} (${io.engine.clientsCount} total)`);
  socket.on("disconnect", () => {
    console.log(`[realtime] client disconnected: ${socket.id} (${io.engine.clientsCount} total)`);
  });
});

app.get("/health", (_req, res) => {
  res.json({ ok: true, clients: io.engine.clientsCount });
});

app.post("/notify", (req, res) => {
  if (INTERNAL_SECRET && req.get("X-Internal-Secret") !== INTERNAL_SECRET) {
    return res.status(403).json({ error: "forbidden" });
  }
  const { event, payload } = req.body || {};
  if (!event) {
    return res.status(400).json({ error: "event is required" });
  }
  io.emit(event, payload ?? null);
  res.json({ ok: true });
});

server.listen(PORT, () => {
  console.log(`[realtime] listening on http://0.0.0.0:${PORT}`);
});
