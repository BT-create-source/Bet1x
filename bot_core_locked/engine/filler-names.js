/**
 * Moved verbatim from backend/server.js. Realistic filler names for empty-seat auto-fill and
 * simulated players — no seat is ever named/labeled "bot" anywhere in the app. The only seat that
 * ever wins on purpose is explicitly renamed to "Admin" at the exact moment the takeover algorithm
 * selects it (still handled in server.js's tpStartRound); every other auto-filled seat just gets a
 * plain human-looking name from here.
 */
const TP_SIMULATED_NAMES = [
  'Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Arav', 'Pranav', 'Krishna',
  'Ishaan', 'Shaurya', 'Atharv', 'Rohan', 'Rudra', 'Aryan', 'Dev', 'Karan', 'Dhruv', 'Siddharth',
  'Ananya', 'Diya', 'Ishika', 'Kiara', 'Myra', 'Aria', 'Saanvi', 'Riya', 'Prisha', 'Anika'
];
function randomFillerName() {
  return TP_SIMULATED_NAMES[Math.floor(Math.random() * TP_SIMULATED_NAMES.length)] + '_' + (10 + Math.floor(Math.random() * 90));
}

// Called by every seat-fill path right before it occupies a seat with an ordinary filler player.
//
// This used to also decide whether the seat being filled was "Admin" — rooms were pre-selected at
// toggle time (round(pct/100 * 6) of them, a separate percentage-of-rooms calculation) to reserve a
// random arrival position for the house's own seat, independent of the per-round decision every
// other rig path draws from. That meant a room could win its "one guaranteed Admin seat" from this
// mechanism on top of whatever the per-round engine also produced afterwards — two independent
// percentage-pct mechanisms stacking instead of summing to the one percentage the operator configured
// is exactly why 50% could show up as "8 of 10 games." Every seat filled through here is now always
// an ordinary filler; "Admin" is seated exactly one way, in tpStartRound, for hands that table's own
// ledger selected — one ledger per table, one percentage, no double-booking.
function nextRoomFillerUsername() {
  return { username: randomFillerName(), is_bot: true };
}

module.exports = { TP_SIMULATED_NAMES, randomFillerName, nextRoomFillerUsername };
