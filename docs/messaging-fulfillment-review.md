# Messaging and fulfillment UI review

Reviewed 2026-11-02 against `docs/messaging-fulfillment-contract.md`. The supplied `specs/2026-11-02-messaging-fulfillment-design.md` path was absent. Read-only application review; no application files changed. Findings below follow directly from the inspected event handlers; no browser execution is claimed. Existing test results are owned by the implementation lead.

## Findings

### P2: Previously seen messages revert to “Saved on this device”

`public/assets/js/messages.js:25,55` stores one latest read message ID, then treats only an exact ID match as seen. A read cursor must also cover earlier messages.

Reproduction on the same browser origin with customer and station tabs:

1. Customer opens `?thread=guintas-maria` and sends message A.
2. Station opens that thread; A becomes “Seen in preview” in the customer tab.
3. Station sends reply B, or reads a subsequent customer message C.
4. Station's read key now contains B or C. Customer's A incorrectly reverts to “Saved on this device.”

Compare message positions against the participant's read cursor, or persist per-message receipt information. Add a cross-role regression covering a read message followed by another message.

### P2: Storage failure loses earlier messages before the user leaves

`public/assets/js/messages.js:90-96` re-reads storage before every send and replaces in-memory state with that result. When `localStorage.setItem` fails (quota or denied storage), an unsaved message exists only in `states`, so the next send discards it. The feedback explicitly promises that a message lasts until leaving the page.

Reproduction:

1. Make `Storage.prototype.setItem` throw, or exhaust the origin quota.
2. Open any thread and send A. A appears with “This session only.”
3. Send B without navigating. A disappears because `read(active)` excludes the unsaved in-memory message.

Merge current in-memory extras with freshly read persisted messages by ID before appending. Storage events should also preserve unsaved session messages instead of replacing them.

### P3: Conversation selection destroys keyboard focus

`public/assets/js/messages.js:28,67-78` replaces the entire conversation list after selecting a thread. The focused conversation button is removed, and no focus is placed in the selected chat. On narrow screens its parent pane also becomes hidden. Keyboard and screen-reader users lose their position after activating a conversation.

Reproduction: at a narrow viewport, Tab to a conversation and activate it with Enter. The chat opens, but the focused list button is removed and focus falls back to the document instead of the chat heading or composer. Move focus to a suitable chat target after user-initiated selection, and preserve focused list controls for background updates where practical.

## Other inspected behavior

No important XSS, role fixture disclosure, thread-identity collision, pickup dispatch inclusion, or delivery-address payload issue was found in the inspected new UI. Message bodies use `textContent`; JSON seed escaping is appropriate; stored extras are restricted to authorized seeded threads and participant IDs. Shared browser storage is deliberately a local preview, not an authentication boundary.

Pickup mode disables address controls and removes them from both native FormData and the generic draft serializer. Mode-specific drafts preserve basket scope. Pickup queue actions and delivery fixture filters exclude pickup orders from dispatch. Payment uses the common `cash` payload with explicit fulfillment mode and matching labels. Date defaults/minimum are fixed to the dated sample preview; this should become dynamic if the screen is converted into live ordering.

## Verdict

Two correctness fixes are recommended before calling browser-local messaging persistence/read receipts complete. Fulfillment has no blocking finding from code inspection. The focus issue is a smaller accessibility follow-up. No claim is made about backend authorization or real message delivery, which are intentionally outside this UI preview.


## Final integration verification

Root integrated the live routes, navigation, message entry buttons, icons and deferred modules. Guintas now has six own-customer threads with names matched to the order/customer fixtures. The focused live messaging suite and additional persistence/read/focus regressions pass. Root fixed the seen-marker regression using ordered read watermarks, preserves unsaved in-memory messages across further sends/storage events, and restores keyboard focus after list refresh or mobile thread selection.

The combined UI suite loads app.js, messages.js and fulfillment.js and passes 47 pages / 281 assertions. HTTP checks pass for 47 pages and 56 local links/assets. Pickup switching, conditional address requirements, saved draft isolation, collection actions and dispatch exclusion also pass. Browser screenshots remain unavailable. Messages still stay on this device; no actual Supabase or remote messaging connection exists.