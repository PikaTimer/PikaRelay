# PikaRelay API

This is the wire contract between PikaRelay, PikaReader units, and PikaDownloader clients.

## Conventions

- **Base URL.** All paths are relative to where PikaRelay is installed, e.g. `https://relay.example.com` or `https://example.com/relay`. If the web server does not rewrite URLs, insert `/index.php` after the base: `https://example.com/relay/index.php/readerList`.
- **Bodies.** Request and response bodies are JSON. Requests with a body must send `Content-Type: application/json`.
- **Times.** Times are **epoch milliseconds** (UTC) unless a field says otherwise.
- **Errors.** Every error response is `{"error": "<message>"}` with the HTTP status:

  | Status | Meaning |
  |---|---|
  | 401 | Missing or invalid credentials (secure mode) |
  | 403 | The key's role may not make this call |
  | 404 | Unknown reader, command or path |
  | 405 | Wrong method (an `Allow` header lists the right ones) |
  | 413 | Body or batch too large |
  | 415 | Body is not `application/json` |
  | 422 | The input failed validation. Nothing was stored. |
  | 503 | Relay not set up yet, or database unavailable |

- **Validate, don't sanitize.** Invalid input is rejected whole and never partially stored or "cleaned up".
- **Reader ids** match `^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$` and are case-sensitive. Use the PikaReader `unitID`, e.g. `pr4-01`.

## Authentication

In **secure mode** every call sends two headers:

```
X-Pika-Key: <key>
X-Pika-Secret: <secret>
```

The admin creates keys on the Admin page. Each key has one role:

| Endpoint | `reader` (PikaReader) | `client` (PikaDownloader) |
|---|---|---|
| `PUT /readerStatus/{id}` | ✓ | |
| `GET /readerStatus/{id}` | ✓ | ✓ |
| `GET /readerList` | | ✓ |
| `POST /putReads/{id}` | ✓ | |
| `GET /getReads/...` | | ✓ |
| `POST /commands/{id}` | | ✓ |
| `GET /commands/{id}` | ✓ | |
| `DELETE /commands/{id}/{commandId}` | ✓ | |

In **insecure mode** no headers are needed. Use HTTPS in either mode.

## Reader status

### `PUT /readerStatus/{id}` (reader)

The body is the PikaReader STATUS object, the same JSON as PikaReader's `GET /status`. It is stored as-is, replacing the previous status, and its arrival time becomes `updated`. The reader is registered automatically the first time it calls this endpoint or uploads reads.

- **Body limits:** at most 256 KB and 8 levels of nesting.
- **Key names:** must match `[A-Za-z0-9 _/.-]{1,64}`.
- **Strings:** at most 1024 characters, with no control characters and no backslash.

The response is **204 No Content**. Suggested interval: every 5–10 s.

### `GET /readerStatus/{id}` (client, reader)

```json
{"readerId":"pr4-01","serverTime":1795961733000,"updated":1795961730000,"lastRead":1795961729123,
 "status":{"unitID":"pr4-01","reading":true,"totalReads":1234,"battery":87,"profile":{"id":"finish","name":"Finish Mats"}}}
```

- `updated` is when the last status arrived. Compare it with `serverTime` to judge whether the reader is online. PikaDownloader treats under 20 s as online and over 120 s as offline.
- `lastRead` is the newest read time (`epochMilli`) received from this reader.
- Either value is `null` if the relay has never received one.

### `GET /readerList` (client)

```json
{"serverTime":1795961733000,"readers":[
  {"id":"pr4-01","name":"Finish Line","updated":1795961730000,"reading":true,"battery":87,"lastRead":1795961729123}
]}
```

- `name` is the status's `unitID`, or the id if there is none.
- `reading` and `battery` are `null` when the status doesn't include them.

## Reads

A read is the PikaReader READ object:

```json
{"chip":"1234","epc":"4D2","timestamp":"2026-11-24 08:15:30.123","reader":1,"antenna":2,"rssi":-52.5,"tz":"-06:00","epochMilli":1795961730123}
```

| Field | Rule |
|---|---|
| `chip` | **Required.** 1–64 decimal or hex digits, as a string |
| `epochMilli` | **Required.** Integer, greater than 0 |
| `reader`, `antenna` | Optional. Integer 0–65535 |
| `rssi` | Optional. Number |
| Any other key | Allowed, but values must be plain: string, number, boolean or null |

- **Uniqueness.** A read is unique by (reader id, `chip`, `epochMilli`, `reader`, `antenna`). Uploading the same read again is harmless: it is ignored.
- **Stable output.** The relay always returns a stored read as the same JSON text, so a client can de-duplicate overlapping pages by simple equality.

### `POST /putReads/{id}` (reader)

- **Body:** a JSON array of reads, at most 2000 per request and 2 MB.
- **All or nothing:** if any read is invalid, the whole batch is rejected with 422.

```json
{"received": 3, "new": 2}
```

To answer a `rewind` command, upload the reads from that range through this endpoint. Reads the relay already has are skipped.

### `GET /getReads/{id}/{since}` (client): live polling

Returns reads the relay **received** after `{since}`, where `{since}` is epoch ms and the first call uses `0`. The filter is on receive time, so reads uploaded late are still delivered: after a reader was offline, or in answer to a rewind.

```json
{"serverTime":1795961731000,"more":false,"reads":[{...},{...}]}
```

To poll:

1. Send `serverTime` as `{since}` on the next call.
2. If `more` is `true`, call again right away.
3. Otherwise wait for the poll interval (e.g. 2–5 s).

How the relay keeps this reliable:

- **Settle window.** Only reads received at least the *settle time* ago (default 2 s) are returned, so an upload that is still being written is picked up on the next poll instead of being skipped.
- **Page size.** A page holds at most 10,000 reads, and a page never splits the reads received in one millisecond.
- **Overlap.** Pages may overlap the previous page. Drop duplicates by equality.

### `GET /getReads/{id}/{from}/{to}` (client): rewind from the relay

Returns stored reads whose **read time** (`epochMilli`) is in `[from, to)`, ordered by read time:

```json
{"serverTime":1795961731000,"more":true,"next":1795961700000,"reads":[...]}
```

When `more` is `true`, call again with `{from}` set to `next`.

## Commands

The vocabulary is fixed. Any other command or field is rejected with 422. **There is no stop command:** a remote reader cannot be stopped through the relay.

| Body | Reader action |
|---|---|
| `{"command":"start"}` | Start reading (PikaReader `GET /start`) |
| `{"command":"rewind","from":ms,"to":ms}` | Re-upload reads in `[from, to)` through `putReads`. `from < to`, and the range is at most `max_rewind_days` (default 7). |
| `{"command":"setProfile","profile":"<id or name>"}` | Change profile (PikaReader `PUT /profile/{id}`). The reader rejects this while reading. |
| `{"command":"forceSetProfile","profile":"<id or name>"}` | Stop reading, set the profile, and restart reading immediately |

Profile values match `^[A-Za-z0-9][A-Za-z0-9 _.()-]{0,63}$`.

### `POST /commands/{id}` (client)

**201 Created:**

```json
{"id": 17, "created": 1795961730000, "expires": 1795962330000}
```

- A command expires after the command lifetime (default 600 s). A reader that comes online later never runs a stale command.
- Returns 404 if the reader has never contacted the relay.

### `GET /commands/{id}` (reader)

Returns pending commands (not acknowledged, not expired), oldest first. The arguments are flattened into each command:

```json
{"serverTime":1795961731000,"commands":[
  {"id":17,"command":"rewind","from":1795950000000,"to":1795960000000,"created":1795961730000,"expires":1795962330000}
]}
```

Suggested poll interval: 5–10 s.

### `DELETE /commands/{id}/{commandId}` (reader)

Acknowledges a command so it is no longer returned. Returns **204**, and repeating the call is harmless. Returns 404 if the command doesn't exist or belongs to another reader.

Acknowledge a command once you have acted on it. The relay keeps no record of whether a command succeeded.
