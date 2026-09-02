<?php
/**
 * Live chat. Reads are public; posting requires a session and the display name is taken from it,
 * so nobody can post under someone else's name.
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ratelimit.php';

/** Shared by /api/chat and the legacy /api/chat.php alias. */
function chat_fetch_messages() {
    $out = [];
    $rows = all('SELECT * FROM "ChatMessage" ORDER BY "timestamp" ASC LIMIT 50');
    foreach ($rows as $r) $out[] = map_chat($r);
    return $out;
}

/** Shared by /api/chat and /api/chat.php. Returns the saved row in Prisma's shape. */
function chat_store_message($username, $message) {
    try {
        $ts = ms_to_sql();
        q('INSERT INTO "ChatMessage" ("username","message","timestamp") VALUES (?,?,?)', [$username, $message, $ts]);
        $id = (int) db_or_throw()->lastInsertId();
        return ['id' => $id, 'username' => $username, 'message' => $message, 'timestamp' => js_iso($ts)];
    } catch (Throwable $e) {
        if (!json_fallback_allowed()) throw $e;
        $chat = readJsonTable('chat');
        $saved = [
            'username'  => $username,
            'message'   => $message,
            'timestamp' => js_iso(),
            'id'        => count($chat) + 1,
        ];
        $chat[] = $saved;
        writeJsonTable('chat', array_slice($chat, -100));
        return $saved;
    }
}

function register_chat_routes(Router $app) {

    $app->get('/api/chat', function (Req $req, Res $res) {
        try {
            $messages = [];
            try {
                $messages = chat_fetch_messages();
            } catch (Throwable $e) {
                // Note: no ALLOW_JSON_FALLBACK check here, matching the original.
                $messages = readJsonTable('chat');
            }
            $res->json($messages);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => $err->getMessage()]);
        }
    });

    $app->post('/api/chat', limiter('chat'), 'require_auth', function (Req $req, Res $res) {
        $username = acting_username($req);
        $message = mb_substr(trim((string)($req->b('message') ?? '')), 0, 300);

        if ($message === '') {
            $res->status(400)->json(['error' => 'Message cannot be empty.']);
            return;
        }

        try {
            $saved = chat_store_message($username, $message);
            $res->json(['success' => true, 'message' => $saved]);
        } catch (Throwable $err) {
            $res->status(500)->json(['error' => $err->getMessage()]);
        }
    });
}
