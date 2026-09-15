-- The advertised prize pool a guaranteed contest pays out even when it under-fills. The difference
-- between this and the pool the entry fees actually raised is the house's liability, which
-- settlement reports as `guarantee_shortfall` so an under-filled mega contest is a visible cost
-- rather than a silently absorbed one.
--
-- Zero on every existing row, and zero means "no guarantee", so fill-or-cancel contests are
-- unaffected.
ALTER TABLE "CricketContest" ADD COLUMN "guaranteed_pool" DOUBLE PRECISION NOT NULL DEFAULT 0;
