/**
 * Dependency holder for the cricket pipeline.
 *
 * server.js already owns the single PrismaClient for this process, and a second one would open a
 * second connection pool against the same database. Rather than construct our own, the pipeline is
 * handed the existing client (and logger) exactly once at boot via `init()`. Every module below
 * reads them from here, so nothing in `lib/cricket` reaches into server.js and server.js needs only
 * one added call to wire the whole thing up.
 */

let ctx = { prisma: null, logger: null, wallet: null, houseEdge: null, ready: false };

/**
 * `wallet` is the adapter the contest layer moves money through: server.js's own `debitWallet`,
 * `creditWallet` and `newRecordId`. It is injected rather than reimplemented because those helpers
 * carry hard-won properties — the debit is a single conditional statement, so two simultaneous
 * joins cannot both pass a balance check — and a second implementation here would be a second
 * chance to get that wrong. It is optional so the pipeline still boots for the read-only surfaces
 * (and for tests) with no money path wired.
 */
function init({ prisma, logger, wallet = null, houseEdge = null }) {
  if (!prisma) throw new Error('cricket: init() requires a PrismaClient');
  if (!logger) throw new Error('cricket: init() requires a logger');
  ctx = { prisma, logger, wallet, houseEdge, ready: true };
  return ctx;
}

/**
 * Access the injected dependencies.
 *
 * Throws rather than returning a half-initialised context: every caller writes to the permanent
 * event log or moves money, and silently doing nothing because a dependency was missing is the
 * worst available failure mode.
 */
function get() {
  if (!ctx.ready) throw new Error('cricket: pipeline used before init() — call cricket.init() at boot');
  return ctx;
}

/** Test seam: lets a suite install a stub store without booting the server. */
function reset() {
  ctx = { prisma: null, logger: null, wallet: null, houseEdge: null, ready: false };
}

module.exports = { init, get, reset };
