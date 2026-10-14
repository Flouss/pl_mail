import { test, expect, devices, type Locator, type Page } from "./support/test";
import { Finger, recordTouchSamples, touchSamples, velocityOverLast } from "./support/touch";
import { settled } from "./support/motion";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";

/**
 * Row swipe on a phone: right = read/unread, left = archive, fixed defaults.
 *
 * Every figure is derived from the row's own measured width `w` (the li's
 * boundingBox, never a number fitted to output) and the constants in
 * assets/touch_gesture.js and assets/controllers/mail/row_swipe_controller.js:
 *   partial  = 0.35w: release past it opens the row on its action
 *   full     = 0.70w: release past it commits without a tap
 *   FLING    = 0.5 px/ms, with the displacement at least partial
 *   velocity = measured over the last 100ms of samples (VELOCITY_WINDOW_MS)
 * Drags sit at least 0.10w from either threshold: 0.25w (snap back), 0.50w
 * (open, or a fling), 0.85w (commit).
 *
 * A drag that stops for longer than the velocity window before the finger lifts
 * reads as a drop, not a flick: the pause below is 150ms, past the 100ms window.
 *
 * Touch only, below md. Each behaviour has a desktop companion where it matters
 * (the mouse drag test below), and the mouse never starts a swipe.
 */
test.use({ ...devices["Pixel 5"] });

const PARTIAL = 0.35;
const FULL = 0.7;
const FLING_PX_PER_MS = 0.5;
const VELOCITY_WINDOW_MS = 100;
const DROP_PAUSE_MS = 150;

const ROWS = '[data-list-region="rows"]';
const CONTENT = "[data-swipe-content]";

/** Where a left drag starts: near the right edge, clear of the avatar on the left. */
const LEFT_START = 0.9;
/** Where a right drag starts: clear of the star on the right, on the avatar's side. */
const RIGHT_START = 0.12;

const archiveUrl = (id: string) => `/status/thread/${id}/archive`;

function threadId(row: Locator): Promise<string> {
    return row.getAttribute("id").then((id) => (id ?? "").replace("thread_", ""));
}

/** The row's width, measured. Every distance in this file is a fraction of it. */
async function rowWidth(row: Locator): Promise<number> {
    const box = await row.boundingBox();

    expect(box, "the row is rendered").not.toBeNull();

    return box!.width;
}

/** A finger on the row, dragged by `fraction` of its width, to the left or the right. */
async function drag(page: Page, row: Locator, direction: "left" | "right", fraction: number, { pause = 0 } = {}): Promise<Finger> {
    const box = await row.boundingBox();

    expect(box, "the row is rendered").not.toBeNull();

    const sign = direction === "left" ? -1 : 1;
    const start = direction === "left" ? LEFT_START : RIGHT_START;
    const finger = await Finger.down(page, box!.x + box!.width * start, box!.y + box!.height / 2);

    await finger.move(sign * fraction * box!.width, 0);

    if (pause > 0) {
        await page.waitForTimeout(pause);
    }

    await finger.up();

    return finger;
}

async function openInbox(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await settled(page);
    await expect(mailRow(page, INBOX_SUBJECTS.archive)).toBeVisible();
}

test.beforeEach(() => {
    seed("seed-mail");
});

// The vertical-drag case fills the Inbox with bulk mail so the list can scroll. The
// pull spec clears it the same way, and for the same reason: the next spec on this
// worker starts from the base fixture.
test.afterAll(() => {
    seed("seed-bulk --clear");
});

test.describe("row swipe on a phone", () => {
    test("a left drag past partial stays open on Archive; tapping it archives the row and offers Undo", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);
        const w = await rowWidth(row);

        // Presence: the revealed action is not in the accessibility tree until the row opens.
        await expect(row.getByRole("button", { name: "Archive", exact: true })).toBeHidden();

        // The row is the element that carries touch-action, so a horizontal drag is never
        // claimed by the browser. Checked here because the drag below depends on it.
        await expect(row).toHaveCSS("touch-action", "pan-y");

        // 0.5w is between partial (0.35w) and full (0.70w) for any measured w > 0: it opens, and
        // does not commit. The pause keeps the release velocity at zero (see DROP_PAUSE_MS).
        expect(w).toBeGreaterThan(0);
        await drag(page, row, "left", 0.5, { pause: DROP_PAUSE_MS });

        await expect(row).toHaveAttribute("data-swipe-side", "left");

        const archive = row.getByRole("button", { name: "Archive", exact: true });

        await expect(archive).toBeVisible();

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        await archive.click();
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
        await expect(page.getByRole("button", { name: "Undo", exact: true })).toBeVisible();
    });

    test("a left drag short of partial snaps back and sends nothing", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const content = row.locator(CONTENT);
        const requests: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/status/")) {
                requests.push(request.url());
            }
        });

        const w = await rowWidth(row);
        const box = await row.boundingBox();
        const finger = await Finger.down(page, box!.x + box!.width * LEFT_START, box!.y + box!.height / 2);

        await finger.move(-0.25 * w, 0);

        // Presence, while the finger is still down: the row followed it. Without this, "nothing
        // happened" would pass for a row that never listened.
        await expect.poll(() => content.evaluate((el) => getComputedStyle(el).transform)).not.toBe("none");

        await page.waitForTimeout(DROP_PAUSE_MS);
        await finger.up();

        await expect(row).not.toHaveAttribute("data-swipe-side", /.+/);
        await expect(content).toHaveCSS("transform", "none");
        await expect(row.getByRole("button", { name: "Archive", exact: true })).toBeHidden();

        await page.waitForTimeout(300);
        expect(requests, "a snap back is not a write").toEqual([]);
    });

    test("a left drag past full commits without a tap", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        // The pause keeps the release speed at zero, so only the full threshold can commit this:
        // without it, a release at 0.85w is a fling, and the full threshold is never tested.
        await drag(page, row, "left", 0.85, { pause: DROP_PAUSE_MS });
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

    test("a right drag on an unread row offers Mark as read, then Mark as unread on the next drag", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.read);
        const id = await threadId(row);

        await expect(row).toHaveAttribute("data-unread", "true");

        await drag(page, row, "right", 0.5, { pause: DROP_PAUSE_MS });
        await expect(row).toHaveAttribute("data-swipe-side", "right");

        // An unread row's right action reads "Mark as read", and nothing else.
        await expect(row.getByRole("button", { name: "Mark as read", exact: true })).toBeVisible();
        await expect(row.getByRole("button", { name: "Mark as unread", exact: true })).toBeHidden();

        const read = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/read`);

        await row.getByRole("button", { name: "Mark as read", exact: true }).click();
        await read;

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute("data-unread", "false");

        // The reverse, on the now-read row: "Mark as unread", and the state goes back.
        const again = mailRow(page, INBOX_SUBJECTS.read);

        await drag(page, again, "right", 0.5, { pause: DROP_PAUSE_MS });
        await expect(again.getByRole("button", { name: "Mark as unread", exact: true })).toBeVisible();
        await expect(again.getByRole("button", { name: "Mark as read", exact: true })).toBeHidden();

        const unread = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/read`);

        await again.getByRole("button", { name: "Mark as unread", exact: true }).click();
        await unread;

        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveAttribute("data-unread", "true");
    });

    test("in Archive both directions are inert, and the same thread restored to the Inbox swipes again", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);

        expect((await ajaxPost(page, archiveUrl(id))).ok(), "the fixture archives the thread").toBe(true);

        await page.goto("/mail/archive");
        await settled(page);

        const archived = mailRow(page, INBOX_SUBJECTS.archive);

        await expect(archived).toBeVisible();

        const content = archived.locator(CONTENT);
        const before = await content.boundingBox();

        const requests: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/status/")) {
                requests.push(request.url());
            }
        });

        await drag(page, archived, "left", 0.85);
        await page.waitForTimeout(300);
        await drag(page, archived, "right", 0.85);
        await page.waitForTimeout(300);

        expect(await content.boundingBox(), "neither direction moved the row").toEqual(before);
        expect(requests, "neither direction posted").toEqual([]);

        // Presence companion: back in the Inbox, the same thread does swipe.
        expect((await ajaxPost(page, `/status/thread/${id}/restore`)).ok()).toBe(true);

        await page.goto("/mail/inbox");
        await settled(page);

        const restored = mailRow(page, INBOX_SUBJECTS.archive);

        await expect(restored).toBeVisible();

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        await drag(page, restored, "left", 0.85);
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

    test("a vertical drag on a row scrolls the list and does not open the row", async ({ page }) => {
        // Sixty more messages, so the list has somewhere to scroll to: the presence that the drag was a scroll.
        seed("seed-bulk --clear", "seed-bulk --messages=60");

        await openInbox(page);

        const rows = page.locator(ROWS);
        const row = rows.locator('li[data-controller="mail--message-row"]').first();
        const box = await row.boundingBox();

        const finger = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

        // Upwards, so the pull controller (which reads a downward drag from the top) is not involved.
        await finger.move(0, -120);
        await finger.up();
        await page.waitForTimeout(200);

        // Presence: the list scrolled. A drag that did nothing at all would otherwise pass.
        expect(await rows.evaluate((el) => el.scrollTop), "the finger scrolled the list").toBeGreaterThan(0);

        await expect(row).not.toHaveAttribute("data-swipe-side", /.+/);
        await expect(row.getByRole("button", { name: "Archive", exact: true })).toBeHidden();
    });

    test("a fast fling past partial commits; the same distance released slowly only opens", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(row);
        const w = await rowWidth(row);
        const box = await row.boundingBox();
        const distance = 0.5 * w;

        // Companion first, on the row that stays: the same distance, slowly, opens and does not commit.
        await recordTouchSamples(page);

        const slow = await Finger.down(page, box!.x + box!.width * LEFT_START, box!.y + box!.height / 2);

        for (let i = 0; i < 18; i++) {
            await slow.move(-distance / 18, 0, 1);
            await page.waitForTimeout(50);
        }

        // The recorded samples are what the page measured; the slow release must read below half the fling line.
        const slowSpeed = velocityOverLast(await touchSamples(page), VELOCITY_WINDOW_MS);

        expect(slowSpeed, "the slow drag is under 0.5 x the fling constant").toBeLessThan(0.5 * FLING_PX_PER_MS);

        await slow.up();

        await expect(row).toHaveAttribute("data-swipe-side", "left");
        expect(await mailRow(page, INBOX_SUBJECTS.archive).count(), "a slow drag does not commit").toBe(1);

        // Now the fast one: the same distance, flicked. The velocity is 2x the constant or more.
        await page.reload();
        await settled(page);

        const flicked = mailRow(page, INBOX_SUBJECTS.archive);
        const fastBox = await flicked.boundingBox();

        await recordTouchSamples(page);

        const fast = await Finger.down(page, fastBox!.x + fastBox!.width * LEFT_START, fastBox!.y + fastBox!.height / 2);

        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === archiveUrl(id));

        await fast.move(-distance, 0, 6);

        const flickSpeed = velocityOverLast(await touchSamples(page), VELOCITY_WINDOW_MS);

        expect(flickSpeed, "the flick is at least 2x the fling constant").toBeGreaterThanOrEqual(2 * FLING_PX_PER_MS);

        await fast.up();
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
    });

});

test.describe("row swipe with a mouse on a desktop", () => {
    // Overrides the phone device for this block: a desktop window and a mouse, no touch.
    test.use({ viewport: { width: 1280, height: 800 }, isMobile: false, hasTouch: false });

    test("a mouse drag across a row does nothing", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.archive);
        const box = await row.boundingBox();
        const requests: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/status/")) {
                requests.push(request.url());
            }
        });

        // Presence: the mouse moves reached the page, so the drag below was delivered and ignored.
        await page.evaluate(() => {
            const win = window as unknown as { __mouseMoves: number };

            win.__mouseMoves = 0;
            document.addEventListener("mousemove", () => (win.__mouseMoves += 1));
        });

        await page.mouse.move(box!.x + box!.width * 0.9, box!.y + box!.height / 2);
        await page.mouse.down();
        await page.mouse.move(box!.x + box!.width * 0.05, box!.y + box!.height / 2, { steps: 12 });
        await page.mouse.up();
        await page.waitForTimeout(300);

        expect(await page.evaluate(() => (window as unknown as { __mouseMoves: number }).__mouseMoves), "the mouse moves arrived").toBeGreaterThan(0);

        await expect(row).not.toHaveAttribute("data-swipe-side", /.+/);
        expect(requests).toEqual([]);
    });
});

test.describe("row swipe and the list refresh", () => {
    test.beforeEach(async ({ page }) => {
        // The refresh is rate limited (MIN_REFRESH_MS, 15s), and the clock is the only
        // way to get past it without waiting. Installed before the page exists.
        await page.clock.install();
    });

    test("a refresh does not reset an open row, and the server change to another row lands", async ({ page }) => {
        await openInbox(page);

        const open = mailRow(page, INBOX_SUBJECTS.archive);
        const other = mailRow(page, INBOX_SUBJECTS.star);
        const openId = await threadId(open);
        const otherId = await threadId(other);

        await drag(page, open, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(open).toHaveAttribute("data-swipe-side", "left");

        // A change to the open row (read: the seed leaves it unread, so this really changes it)
        // and to a different row (starred), server side. The read route takes read: true to mark read.
        expect((await ajaxPost(page, `/status/thread/${openId}/read`, { read: true })).ok()).toBe(true);
        expect((await ajaxPost(page, `/status/thread/${otherId}/star`)).ok()).toBe(true);

        const fragment = await refreshAndWaitForFragment(page);

        expect(fragment, "the refresh asked for the list fragment").toBe(true);

        // Landed-proof: the other row now renders as starred.
        await expect(mailRow(page, INBOX_SUBJECTS.star).getByRole("button", { name: "Unstar this message", exact: true })).toBeVisible();

        // And the open row is still open, with its action working, and its own change held back.
        await expect(open).toHaveAttribute("data-swipe-side", "left");
        await expect(open.getByRole("button", { name: "Archive", exact: true })).toBeVisible();
        await expect(open).toHaveAttribute("data-unread", "true");

        // Closing the row: a touch that does not move on the open row closes it.
        const box = await open.boundingBox();
        const tap = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

        await tap.up();
        await expect(open).not.toHaveAttribute("data-swipe-side", /.+/);

        // The next refresh applies the held change to the closed row. Its own fragment request is
        // proven too, so this cannot pass on a refresh that never ran.
        expect(await refreshAndWaitForFragment(page), "the second refresh asked for the list fragment").toBe(true);
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveAttribute("data-unread", "false");
    });

    test("a refresh that removes the open row leaves no stuck transform, and a pull works afterwards", async ({ page }) => {
        await openInbox(page);

        const open = mailRow(page, INBOX_SUBJECTS.archive);
        const id = await threadId(open);

        await drag(page, open, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(open).toHaveAttribute("data-swipe-side", "left");

        // Archived somewhere else (another tab, the bulk actions): the row leaves the Inbox.
        expect((await ajaxPost(page, archiveUrl(id))).ok()).toBe(true);

        await refreshAndWaitForFragment(page);
        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0, { timeout: 5000 });

        // No orphaned open state: a pull from the top syncs, which it cannot while a row is open.
        const syncs: string[] = [];

        await page.route("**/mail/sync", (route) => {
            if (route.request().method() === "POST") {
                syncs.push(route.request().url());
            }

            return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ dispatched: 1 }) });
        });
        await page.route("**/mail/sync/status", (route) =>
            route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ pending: 0 }) }),
        );

        const rows = await page.locator(ROWS).boundingBox();
        const pull = await Finger.down(page, rows!.x + rows!.width / 2, rows!.y + 20);

        await pull.move(0, 180);
        await pull.up();

        await expect.poll(() => syncs.length).toBe(1);
    });

    test("a pull from the top is refused over an open row, and allowed once the row is closed", async ({ page }) => {
        await page.route("**/mail/sync", (route) =>
            route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ dispatched: 1 }) }),
        );
        await page.route("**/mail/sync/status", (route) =>
            route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ pending: 0 }) }),
        );

        const syncs: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && new URL(request.url()).pathname === "/mail/sync") {
                syncs.push(request.url());
            }
        });

        await openInbox(page);

        const open = mailRow(page, INBOX_SUBJECTS.archive);

        await drag(page, open, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(open).toHaveAttribute("data-swipe-side", "left");

        const box = await open.boundingBox();
        const pulled = await Finger.down(page, box!.x + box!.width * 0.5, box!.y + box!.height / 2);

        await pulled.move(0, 180);
        await pulled.up();
        await page.waitForTimeout(400);

        expect(syncs, "an open row is a surface: no pull starts over it").toEqual([]);

        // Presence companion: close the row, and the same pull syncs.
        const closeBox = await open.boundingBox();
        const tap = await Finger.down(page, closeBox!.x + closeBox!.width * 0.5, closeBox!.y + closeBox!.height / 2);

        await tap.up();
        await expect(open).not.toHaveAttribute("data-swipe-side", /.+/);

        const rows = await page.locator(ROWS).boundingBox();
        const pull = await Finger.down(page, rows!.x + rows!.width / 2, rows!.y + 20);

        await pull.move(0, 180);
        await pull.up();

        await expect.poll(() => syncs.length).toBe(1);
    });

    test("a second row's drag closes the open one, and the drag itself does nothing", async ({ page }) => {
        await openInbox(page);

        const first = mailRow(page, INBOX_SUBJECTS.archive);
        const second = mailRow(page, INBOX_SUBJECTS.star);
        const requests: string[] = [];

        page.on("request", (request) => {
            if (request.method() === "POST" && request.url().includes("/status/")) {
                requests.push(request.url());
            }
        });

        await drag(page, first, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(first).toHaveAttribute("data-swipe-side", "left");

        await drag(page, second, "left", 0.5);

        await expect(first).not.toHaveAttribute("data-swipe-side", /.+/);
        await expect(second).not.toHaveAttribute("data-swipe-side", /.+/);
        await page.waitForTimeout(300);
        expect(requests).toEqual([]);
    });

    test("a row's checkbox and the toolbar's Archive reach the same selection, so the touch-free path exists", async ({ page }) => {
        await openInbox(page);

        const row = mailRow(page, INBOX_SUBJECTS.star);
        const checkbox = row.getByRole("checkbox", { name: /Select/ });

        await expect(checkbox).toHaveCount(1);

        // The avatar is the checkbox's label: pressing it selects the row, as a tap does.
        await row.locator("label").first().click();
        await expect(checkbox).toBeChecked();

        // The toolbar's own Archive is the other equivalent: it files the selection, as the swipe files the row.
        const archive = page.locator("[data-mail--list-toolbar-target='actions']").getByRole("button", { name: "Archive", exact: true }).first();

        await expect(archive).toBeVisible();
        await archive.click();

        await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(0);
    });
});

test.describe("row swipe with reduced motion", () => {
    test.use({ reducedMotion: "reduce" });

    test.beforeEach(async ({ page }) => {
        await page.clock.install();
    });

    test("the open and the commit behave the same, and the row has no running animation", async ({ page }) => {
        await openInbox(page);

        const open = mailRow(page, INBOX_SUBJECTS.archive);

        await drag(page, open, "left", 0.5, { pause: DROP_PAUSE_MS });
        await expect(open).toHaveAttribute("data-swipe-side", "left");

        // Presence precondition: the row is open. Then nothing on it is animating.
        const running = await open.evaluate((el) => el.getAnimations({ subtree: true }).length);

        expect(running, "no animation runs on the open row under reduced motion").toBe(0);

        // A touch elsewhere closes the open row, and is consumed: the commit below needs the row closed first.
        const closeBox = await open.boundingBox();
        const tap = await Finger.down(page, closeBox!.x + closeBox!.width * 0.5, closeBox!.y + closeBox!.height / 2);

        await tap.up();
        await expect(open).not.toHaveAttribute("data-swipe-side", /.+/);

        // The commit, on a different row, is the same as with motion.
        const committed = mailRow(page, INBOX_SUBJECTS.star);
        const id = await threadId(committed);
        const posted = page.waitForRequest((request) => request.method() === "POST" && new URL(request.url()).pathname === `/status/thread/${id}/archive`);

        await drag(page, committed, "left", 0.85);
        await posted;

        await expect(mailRow(page, INBOX_SUBJECTS.star)).toHaveCount(0);
    });
});

/**
 * Force one list refresh and wait for the fragment request that carries it.
 *
 * The refresh is the mailbox-synced event the body dispatches on a Mercure
 * update, with the clock moved past MIN_REFRESH_MS first. Returns whether the
 * request was seen, so a test can prove the refresh really happened rather than
 * passing because nothing moved.
 */
async function refreshAndWaitForFragment(page: Page): Promise<boolean> {
    await page.clock.fastForward(16_000);

    const seen = page.waitForRequest((request) => request.headers()["x-list-fragment"] !== undefined, { timeout: 5000 }).then(
        () => true,
        () => false,
    );

    await page.evaluate(() => {
        document.body.dispatchEvent(
            new CustomEvent("core--mercure:mailbox-synced", {
                bubbles: true,
                detail: { poll: true },
            }),
        );
    });

    return seen;
}
