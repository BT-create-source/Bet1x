/**
 * Shared dependency-injection point for bot_core, mirroring the pattern already used by
 * backend/lib/cricket/context.js. bot_core must never `require()` anything from backend/ directly —
 * every external thing it needs (the Prisma client, the color-number resolver, tunable config
 * values) is handed in once at boot via index.js's init(), so this folder stays a self-contained
 * module that could be extracted into its own package or microservice without editing a single file
 * inside it.
 */
let deps = { prisma: null, resolveColorNumber: null, config: {} };

function init(d) {
  deps = { ...deps, ...d };
}

function get() {
  return deps;
}

module.exports = { init, get };
