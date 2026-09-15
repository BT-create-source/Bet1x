-- CreateTable
CREATE TABLE "User" (
    "id" SERIAL NOT NULL,
    "username" TEXT NOT NULL,
    "email" TEXT NOT NULL,
    "password" TEXT NOT NULL,
    "wallet_balance" DOUBLE PRECISION NOT NULL DEFAULT 1000.0,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "User_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Transaction" (
    "id" TEXT NOT NULL,
    "user" TEXT NOT NULL,
    "type" TEXT NOT NULL,
    "amount" DOUBLE PRECISION NOT NULL,
    "details" TEXT NOT NULL,
    "status" TEXT NOT NULL,
    "timestamp" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "Transaction_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Deposit" (
    "deposit_id" TEXT NOT NULL,
    "order_id" TEXT,
    "username" TEXT NOT NULL,
    "amount" DOUBLE PRECISION NOT NULL,
    "utr" TEXT,
    "qr_type" TEXT,
    "custom_qr_data" TEXT,
    "status" TEXT NOT NULL,
    "gateway" TEXT,
    "gateway_id" TEXT,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "Deposit_pkey" PRIMARY KEY ("deposit_id")
);

-- CreateTable
CREATE TABLE "Withdrawal" (
    "withdrawal_id" TEXT NOT NULL,
    "username" TEXT NOT NULL,
    "amount" DOUBLE PRECISION NOT NULL,
    "method" TEXT NOT NULL,
    "details" TEXT NOT NULL,
    "status" TEXT NOT NULL,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "Withdrawal_pkey" PRIMARY KEY ("withdrawal_id")
);

-- CreateTable
CREATE TABLE "PaymentLog" (
    "id" TEXT NOT NULL,
    "payload" JSONB NOT NULL,
    "signature" TEXT,
    "timestamp" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "PaymentLog_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "GameState" (
    "key" TEXT NOT NULL,
    "data" JSONB NOT NULL,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "GameState_pkey" PRIMARY KEY ("key")
);

-- CreateTable
CREATE TABLE "RecentResult" (
    "id" SERIAL NOT NULL,
    "room" TEXT NOT NULL,
    "roundNumber" TEXT NOT NULL,
    "number" INTEGER NOT NULL,
    "color" TEXT NOT NULL,
    "dotClass" TEXT NOT NULL,
    "size" TEXT NOT NULL,
    "timestamp" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "RecentResult_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "ChatMessage" (
    "id" SERIAL NOT NULL,
    "username" TEXT NOT NULL,
    "message" TEXT NOT NULL,
    "timestamp" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT "ChatMessage_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "TeenPattiRoom" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "boot_amount" DOUBLE PRECISION NOT NULL DEFAULT 10,
    "status" TEXT NOT NULL DEFAULT 'waiting',
    "pot" DOUBLE PRECISION NOT NULL DEFAULT 0,
    "current_stake" DOUBLE PRECISION NOT NULL DEFAULT 10,
    "turn_index" INTEGER NOT NULL DEFAULT 0,
    "turn_seat" INTEGER,
    "turn_start" TIMESTAMP(3),
    "round" INTEGER NOT NULL DEFAULT 1,
    "winner_seat" INTEGER,
    "deck_state" JSONB,
    "admin_rig" JSONB,
    "log" JSONB NOT NULL DEFAULT '[]',
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updated_at" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "TeenPattiRoom_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "TeenPattiSeat" (
    "id" SERIAL NOT NULL,
    "room_id" TEXT NOT NULL,
    "seat" INTEGER NOT NULL,
    "username" TEXT,
    "is_bot" BOOLEAN NOT NULL DEFAULT false,
    "cards" JSONB,
    "folded" BOOLEAN NOT NULL DEFAULT false,
    "balance" DOUBLE PRECISION NOT NULL DEFAULT 0,
    "seen" BOOLEAN NOT NULL DEFAULT false,

    CONSTRAINT "TeenPattiSeat_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "GameBet" (
    "id" TEXT NOT NULL,
    "username" TEXT NOT NULL,
    "game" TEXT NOT NULL,
    "bet_amount" DOUBLE PRECISION NOT NULL,
    "payout" DOUBLE PRECISION NOT NULL DEFAULT 0,
    "status" TEXT NOT NULL DEFAULT 'active',
    "metadata" JSONB,
    "created_at" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "settled_at" TIMESTAMP(3),

    CONSTRAINT "GameBet_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "User_username_key" ON "User"("username");

-- CreateIndex
CREATE UNIQUE INDEX "Deposit_order_id_key" ON "Deposit"("order_id");

-- CreateIndex
CREATE UNIQUE INDEX "RecentResult_room_roundNumber_key" ON "RecentResult"("room", "roundNumber");

-- CreateIndex
CREATE UNIQUE INDEX "TeenPattiSeat_room_id_seat_key" ON "TeenPattiSeat"("room_id", "seat");

-- CreateIndex
CREATE INDEX "GameBet_username_game_idx" ON "GameBet"("username", "game");

-- CreateIndex
CREATE INDEX "GameBet_game_status_idx" ON "GameBet"("game", "status");

-- AddForeignKey
ALTER TABLE "TeenPattiSeat" ADD CONSTRAINT "TeenPattiSeat_room_id_fkey" FOREIGN KEY ("room_id") REFERENCES "TeenPattiRoom"("id") ON DELETE RESTRICT ON UPDATE CASCADE;

