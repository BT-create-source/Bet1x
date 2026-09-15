/**
 * Minimal levelled logger plus request/error logging middleware.
 *
 * Production logs are emitted as single-line JSON so they can be shipped straight into any log
 * aggregator; development keeps the human-readable prefixed format the codebase already used.
 */

const config = require('../config');

const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };
const threshold = LEVELS[config.LOG_LEVEL] !== undefined ? LEVELS[config.LOG_LEVEL] : LEVELS.info;

function emit(level, message, meta) {
  if (LEVELS[level] > threshold) return;
  if (config.IS_PRODUCTION) {
    const line = { ts: new Date().toISOString(), level, msg: message };
    if (meta && Object.keys(meta).length) line.meta = meta;
    (level === 'error' ? console.error : console.log)(JSON.stringify(line));
  } else {
    const fn = level === 'error' ? console.error : level === 'warn' ? console.warn : console.log;
    fn(`[bet1x-backend] [${level}] ${message}`, meta && Object.keys(meta).length ? meta : '');
  }
}

const logger = {
  error: (msg, meta) => emit('error', msg, meta),
  warn: (msg, meta) => emit('warn', msg, meta),
  info: (msg, meta) => emit('info', msg, meta),
  debug: (msg, meta) => emit('debug', msg, meta)
};

/** Logs one line per API request once the response is finished. Static assets are skipped. */
function requestLogger(req, res, next) {
  if (!req.path.startsWith('/api/')) return next();
  const started = process.hrtime.bigint();
  res.on('finish', () => {
    const ms = Number(process.hrtime.bigint() - started) / 1e6;
    logger.debug('request', {
      method: req.method,
      path: req.path,
      status: res.statusCode,
      ms: Math.round(ms),
      user: (req.auth && req.auth.username) || undefined
    });
  });
  next();
}

/**
 * Terminal error handler. Internal failures are logged in full but the client only ever sees a
 * generic message in production — raw `err.message` values leak database schema, file paths and
 * query text, all of which are useful to an attacker.
 */
// eslint-disable-next-line no-unused-vars
function errorHandler(err, req, res, _next) {
  const status = err.status || err.statusCode || 500;
  if (status >= 500) {
    logger.error('unhandled error', { path: req.path, method: req.method, message: err.message, stack: err.stack });
  }
  if (res.headersSent) return;
  const body = { error: status >= 500 && config.IS_PRODUCTION ? 'Internal server error.' : err.message || 'Request failed.' };
  res.status(status).json(body);
}

module.exports = { logger, requestLogger, errorHandler };
