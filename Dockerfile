# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# bet1x — single-image build. The Express app serves both the JSON API and the
# static site, so one container is the whole deployment.
# ---------------------------------------------------------------------------
FROM node:22-alpine AS deps

WORKDIR /app/backend
COPY backend/package.json backend/package-lock.json ./
# Install production dependencies only, from the lockfile, so the image is
# reproducible and carries no dev tooling.
RUN npm ci --omit=dev

# Prisma needs the schema present before it can emit its client.
COPY backend/prisma ./prisma
RUN npx prisma generate


FROM node:22-alpine AS runtime

# dumb-init gives PID 1 correct signal handling, so SIGTERM actually reaches
# Node and the graceful-shutdown path in server.js runs on every redeploy.
RUN apk add --no-cache dumb-init

ENV NODE_ENV=production \
    PORT=5000 \
    HOST=0.0.0.0

WORKDIR /app

COPY --from=deps /app/backend/node_modules ./backend/node_modules
COPY backend ./backend
COPY assets ./assets
COPY *.html ./
COPY package.json ./

# The runtime writes the JSON fallback tables here. Mount a volume over it if
# ALLOW_JSON_FALLBACK is ever enabled; by default production uses Postgres only.
RUN mkdir -p /app/backend/data \
    && rm -f /app/backend/.env /app/backend/server.js.orig-backup \
    && chown -R node:node /app

USER node

EXPOSE 5000

# Readiness, not just liveness: reports unhealthy while the database is down.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||5000)+'/api/ready').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

ENTRYPOINT ["dumb-init", "--"]
CMD ["node", "backend/server.js"]
