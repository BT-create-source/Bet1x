-- Boundary Baazi: one market per delivery, plus the stakes on it.
--
-- The unique index on (fixture_key, delivery_key) is what makes a redelivered feed message find the
-- existing round instead of opening a second market on the same ball. The push feed is at-least-once
-- by design, so this is load-bearing, not defensive.

CREATE TABLE "BoundaryRound" (
    "id"           TEXT NOT NULL,
    "fixture_key"  TEXT NOT NULL,
    "delivery_key" TEXT NOT NULL,
    "innings"      INTEGER NOT NULL,
    "over"         INTEGER NOT NULL,
    "ball"         INTEGER NOT NULL,
    "question"     TEXT NOT NULL,
    "options"      JSONB NOT NULL,
    "rake_pct"     DOUBLE PRECISION NOT NULL,
    "status"       TEXT NOT NULL DEFAULT 'open',
    "outcome"      TEXT,
    "pool"         DOUBLE PRECISION NOT NULL DEFAULT 0,
    "rake"         DOUBLE PRECISION NOT NULL DEFAULT 0,
    "paid"         DOUBLE PRECISION NOT NULL DEFAULT 0,
    "locked_at"    TIMESTAMP(3),
    "resolved_at"  TIMESTAMP(3),
    "voided_at"    TIMESTAMP(3),
    "void_reason"  TEXT,
    "created_at"   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at"   TIMESTAMP(3) NOT NULL,
    CONSTRAINT "BoundaryRound_pkey" PRIMARY KEY ("id")
);

CREATE UNIQUE INDEX "BoundaryRound_fixture_key_delivery_key_key" ON "BoundaryRound"("fixture_key", "delivery_key");
CREATE INDEX "BoundaryRound_fixture_key_status_idx" ON "BoundaryRound"("fixture_key", "status");

CREATE TABLE "BoundaryBet" (
    "id"         TEXT NOT NULL,
    "round_id"   TEXT NOT NULL,
    "username"   TEXT NOT NULL,
    "option_key" TEXT NOT NULL,
    "stake"      DOUBLE PRECISION NOT NULL,
    "payout"     DOUBLE PRECISION NOT NULL DEFAULT 0,
    "won"        BOOLEAN NOT NULL DEFAULT false,
    "paid_at"    TIMESTAMP(3),
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "BoundaryBet_pkey" PRIMARY KEY ("id")
);

CREATE INDEX "BoundaryBet_round_id_option_key_idx" ON "BoundaryBet"("round_id", "option_key");
CREATE INDEX "BoundaryBet_username_idx" ON "BoundaryBet"("username");

ALTER TABLE "BoundaryRound" ADD CONSTRAINT "BoundaryRound_fixture_key_fkey"
    FOREIGN KEY ("fixture_key") REFERENCES "CricketFixture"("key") ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE "BoundaryBet" ADD CONSTRAINT "BoundaryBet_round_id_fkey"
    FOREIGN KEY ("round_id") REFERENCES "BoundaryRound"("id") ON DELETE RESTRICT ON UPDATE CASCADE;
