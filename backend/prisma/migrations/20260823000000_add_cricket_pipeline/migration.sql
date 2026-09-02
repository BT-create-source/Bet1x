-- CreateTable
CREATE TABLE "CricketFixture" (
    "key" TEXT NOT NULL,
    "tournament_key" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "short_name" TEXT,
    "format" TEXT NOT NULL,
    "venue" TEXT,
    "team_a_key" TEXT NOT NULL,
    "team_a_name" TEXT NOT NULL,
    "team_b_key" TEXT NOT NULL,
    "team_b_name" TEXT NOT NULL,
    "start_time" TIMESTAMP(3) NOT NULL,
    "status" TEXT NOT NULL DEFAULT 'scheduled',
    "toss_at" TIMESTAMP(3),
    "lineups_confirmed_at" TIMESTAMP(3),
    "completed_at" TIMESTAMP(3),
    "last_event_at" TIMESTAMP(3),
    "official_scorecard" JSONB,
    "editorial" JSONB,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "CricketFixture_pkey" PRIMARY KEY ("key")
);

-- CreateTable
CREATE TABLE "CricketSquadPlayer" (
    "id" SERIAL NOT NULL,
    "fixture_key" TEXT NOT NULL,
    "player_key" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "role" TEXT NOT NULL,
    "team_key" TEXT NOT NULL,
    "in_confirmed_xi" BOOLEAN NOT NULL DEFAULT false,
    "is_substitute" BOOLEAN NOT NULL DEFAULT false,
    "batting_style" TEXT,
    "bowling_style" TEXT,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "CricketSquadPlayer_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "CricketBallEvent" (
    "id" SERIAL NOT NULL,
    "event_id" TEXT NOT NULL,
    "fixture_key" TEXT NOT NULL,
    "event_type" TEXT NOT NULL,
    "innings" INTEGER,
    "over" INTEGER,
    "ball" INTEGER,
    "sequence" INTEGER,
    "payload" JSONB NOT NULL,
    "received_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "CricketBallEvent_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "CricketPlayerCredit" (
    "id" SERIAL NOT NULL,
    "fixture_key" TEXT NOT NULL,
    "player_key" TEXT NOT NULL,
    "credits" DOUBLE PRECISION NOT NULL,
    "source" TEXT NOT NULL DEFAULT 'algo',
    "updated_by" TEXT,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "CricketPlayerCredit_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "CricketContest" (
    "id" TEXT NOT NULL,
    "fixture_key" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "format" TEXT NOT NULL,
    "entry_fee" DOUBLE PRECISION NOT NULL,
    "rake_pct" DOUBLE PRECISION NOT NULL,
    "prize_breakup" JSONB NOT NULL,
    "min_entrants" INTEGER NOT NULL DEFAULT 2,
    "max_entrants" INTEGER NOT NULL,
    "max_entries_per_user" INTEGER NOT NULL DEFAULT 1,
    "guaranteed" BOOLEAN NOT NULL DEFAULT false,
    "invite_code" TEXT,
    "created_by" TEXT,
    "status" TEXT NOT NULL DEFAULT 'open',
    "settled_at" TIMESTAMP(3),
    "voided_at" TIMESTAMP(3),
    "void_reason" TEXT,
    "house_decided" BOOLEAN NOT NULL DEFAULT false,
    "house_rigged" BOOLEAN NOT NULL DEFAULT false,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "CricketContest_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "CricketEntry" (
    "id" TEXT NOT NULL,
    "contest_id" TEXT NOT NULL,
    "username" TEXT NOT NULL,
    "team_name" TEXT NOT NULL,
    "players" JSONB NOT NULL,
    "captain" TEXT NOT NULL,
    "vice_captain" TEXT NOT NULL,
    "credits_used" DOUBLE PRECISION NOT NULL,
    "points" DOUBLE PRECISION NOT NULL DEFAULT 0,
    "rank" INTEGER,
    "prize" DOUBLE PRECISION NOT NULL DEFAULT 0,
    "paid_at" TIMESTAMP(3),
    "is_house" BOOLEAN NOT NULL DEFAULT false,
    "entry_index" INTEGER NOT NULL DEFAULT 1,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "CricketEntry_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE INDEX "CricketFixture_tournament_key_start_time_idx" ON "CricketFixture"("tournament_key", "start_time");

-- CreateIndex
CREATE INDEX "CricketFixture_status_start_time_idx" ON "CricketFixture"("status", "start_time");

-- CreateIndex
CREATE INDEX "CricketSquadPlayer_fixture_key_role_idx" ON "CricketSquadPlayer"("fixture_key", "role");

-- CreateIndex
CREATE UNIQUE INDEX "CricketSquadPlayer_fixture_key_player_key_key" ON "CricketSquadPlayer"("fixture_key", "player_key");

-- CreateIndex
CREATE UNIQUE INDEX "CricketBallEvent_event_id_key" ON "CricketBallEvent"("event_id");

-- CreateIndex
CREATE INDEX "CricketBallEvent_fixture_key_innings_over_ball_idx" ON "CricketBallEvent"("fixture_key", "innings", "over", "ball");

-- CreateIndex
CREATE INDEX "CricketBallEvent_fixture_key_received_at_idx" ON "CricketBallEvent"("fixture_key", "received_at");

-- CreateIndex
CREATE INDEX "CricketBallEvent_fixture_key_event_type_idx" ON "CricketBallEvent"("fixture_key", "event_type");

-- CreateIndex
CREATE UNIQUE INDEX "CricketPlayerCredit_fixture_key_player_key_key" ON "CricketPlayerCredit"("fixture_key", "player_key");

-- CreateIndex
CREATE UNIQUE INDEX "CricketContest_invite_code_key" ON "CricketContest"("invite_code");

-- CreateIndex
CREATE INDEX "CricketContest_fixture_key_status_idx" ON "CricketContest"("fixture_key", "status");

-- CreateIndex
CREATE INDEX "CricketContest_status_idx" ON "CricketContest"("status");

-- CreateIndex
CREATE INDEX "CricketEntry_contest_id_points_idx" ON "CricketEntry"("contest_id", "points");

-- CreateIndex
CREATE INDEX "CricketEntry_username_idx" ON "CricketEntry"("username");

-- CreateIndex
CREATE UNIQUE INDEX "CricketEntry_contest_id_username_entry_index_key" ON "CricketEntry"("contest_id", "username", "entry_index");

-- AddForeignKey
ALTER TABLE "CricketSquadPlayer" ADD CONSTRAINT "CricketSquadPlayer_fixture_key_fkey" FOREIGN KEY ("fixture_key") REFERENCES "CricketFixture"("key") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "CricketBallEvent" ADD CONSTRAINT "CricketBallEvent_fixture_key_fkey" FOREIGN KEY ("fixture_key") REFERENCES "CricketFixture"("key") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "CricketPlayerCredit" ADD CONSTRAINT "CricketPlayerCredit_fixture_key_fkey" FOREIGN KEY ("fixture_key") REFERENCES "CricketFixture"("key") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "CricketContest" ADD CONSTRAINT "CricketContest_fixture_key_fkey" FOREIGN KEY ("fixture_key") REFERENCES "CricketFixture"("key") ON DELETE RESTRICT ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE "CricketEntry" ADD CONSTRAINT "CricketEntry_contest_id_fkey" FOREIGN KEY ("contest_id") REFERENCES "CricketContest"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

