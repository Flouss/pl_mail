import { test, expect, devices, type Page } from "./support/test";
import { Finger, recordTouchMoves, touchMoveCancelable } from "./support/touch";
import { settled } from "./support/motion";
import { seed } from "./support/config";

/**
 * Pull to refresh on a phone, driven by real touches.
 *
 * Derivations (k is the resistance, owner default 0.5):
 *   displayed distance = finger dy from touchstart x k
 *   release point      = displayed 70px, so finger travel 70 / 0.5 = 140px
 *   PULL_PAST  = 180px finger -> displayed 90 >= 70: release, one sync
 *   PULL_SHORT = 120px finger -> displayed 60 <  70: no release, no sync
 * A test that moves the constants must re-derive these two numbers.
 *
 * The sync endpoints are answered by the spec, not by the server. The question is
 * whether the gesture asks for a sync, and how many, not what the sync does; a
 * real run would change the test account's mail. The answers keep the topbar
 * button running until a test says otherwise, so the running guard can be tested.
 */
test.use({ ...devices["Pixel 5"] });

// The base seed has four inbox threads, which cannot overflow a phone's list. Sixty
// messages overflow it, so the scroll case has something to scroll. --messages adds
// to the bulk mailbox rather than replacing it, so each test starts from a clear one
// and the last test leaves none behind for the next spec on this worker.
test.beforeEach(() => {
    seed("seed-bulk --clear", "seed-bulk --messages=60");
});

test.afterAll(() => {
    seed("seed-bulk --clear");
});

const PULL_PAST = 180;
const PULL_SHORT = 120;

const ROWS = '[data-list-region="rows"]';
const INDICATOR = '[data-mail--pull-to-refresh-target="indicator"]';
const BURGER = '[data-ui--sidebar-drawer-target="burger"]';

const isSync = (url: URL) => url.pathname === "/mail/sync";
const isStatus = (url: URL) => url.pathname === "/mail/sync/status";

/**
 * Answer the sync endpoints, and count the POSTs that reach them.
 *
 * `pending` is what the status endpoint reports: 0 lets the button finish, 1
 * keeps it running for the length of the test.
 */
async function mockSync(page: Page, { pending = 0 } = {}): Promise<{ posts: number }> {
    const counter = { posts: 0 };

    await page.route(isSync, async (route) => {
        if (route.request().method() === "POST") {
            counter.posts++;
        }

        await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ dispatched: 1 }) });
    });
    await page.route(isStatus, (route) =>
        route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ pending }) }),
    );

    return counter;
}

/** The list's rows scroller, the surface a pull starts on. */
async function rowsStart(page: Page): Promise<{ x: number; y: number }> {
    const box = await page.locator(ROWS).boundingBox();

    expect(box, "the rows scroller is rendered").not.toBeNull();

    return { x: box!.x + box!.width / 2, y: box!.y + 20 };
}

async function openInbox(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await settled(page);
}

test.describe("pull to refresh on a phone", () => {
    test("a pull past the release point syncs once, and reads release before the finger lifts", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);

        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "release");

        await finger.up();

        await expect.poll(() => sync.posts).toBe(1);
        await page.waitForTimeout(300);
        expect(sync.posts, "exactly one sync for one pull").toBe(1);
    });

    test("a pull short of the release point tracks the finger but does not sync", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_SHORT);

        // Presence companion: the indicator followed the finger. A test that only
        // checked for no POST would pass for a gesture that never started.
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "pulling");

        await finger.up();
        await page.waitForTimeout(500);

        expect(sync.posts).toBe(0);
    });

    test("a pull from a list that is scrolled down scrolls the list and does not sync", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const rows = page.locator(ROWS);

        // Presence precondition: the list has to be able to scroll to 300, or the
        // assertion below would read 0 for a list that never moved.
        await rows.evaluate((el) => {
            el.scrollTop = 300;
        });
        expect(await rows.evaluate((el) => el.scrollTop)).toBe(300);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);
        await finger.up();
        await page.waitForTimeout(300);

        expect(sync.posts).toBe(0);
        expect(await rows.evaluate((el) => el.scrollTop), "the finger scrolled the list").toBeLessThan(300);
    });

    test("a pull while the drawer is open does not sync, and the same pull does once it is closed", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        await page.locator(BURGER).click();
        await expect(page.locator("#sidebar-drawer")).not.toHaveAttribute("inert", "");

        // Where the drawer is open, the backdrop is what a touch lands on, so the
        // list never sees the touch at all. That is what the browser does, and it
        // is recorded here: the open-surface check in the gesture module is covered
        // by its node-side spec, not by this one.
        const start = await rowsStart(page);
        const hit = await page.evaluate(({ x, y }) => {
            const element = document.elementFromPoint(x, y);

            return element?.closest("[data-ui--sidebar-drawer-target]")?.getAttribute("data-ui--sidebar-drawer-target") ?? null;
        }, start);

        expect(hit, "the open drawer's backdrop takes the touch").toBe("backdrop");

        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);
        await finger.up();
        await page.waitForTimeout(300);

        expect(sync.posts).toBe(0);

        // Presence companion: close the drawer, and the same pull syncs.
        await page.keyboard.press("Escape");
        await expect(page.locator("#sidebar-drawer")).toHaveAttribute("inert", "");

        const again = await rowsStart(page);
        const second = await Finger.down(page, again.x, again.y);

        await second.move(0, PULL_PAST);
        await second.up();

        await expect.poll(() => sync.posts).toBe(1);
    });

    test("a horizontal drag at the top does not sync, and a vertical pull after it does", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);

        const start = await rowsStart(page);
        // The drag runs 140px down as well (the release travel, 70 / 0.5), so a pull
        // that ignored the arbiter would release and sync. The arbiter's answer is
        // horizontal, because 180 > 140. Only that decision stops it.
        const sideways = await Finger.down(page, start.x, start.y);

        await sideways.move(PULL_PAST, 140);
        await sideways.up();
        await page.waitForTimeout(300);

        expect(sync.posts).toBe(0);

        // Presence companion, in the same test: the list still takes a vertical pull.
        const down = await Finger.down(page, start.x, start.y);

        await down.move(0, PULL_PAST);
        await down.up();

        await expect.poll(() => sync.posts).toBe(1);
    });

    test("every touchmove during a pull is cancelable, and the pull still syncs once", async ({ page }) => {
        const sync = await mockSync(page);

        await openInbox(page);
        await recordTouchMoves(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        // Two small moves (4px in all), then the rest. Chromium delivers no touchmove
        // for movement inside its touch slop, so those two make no event; the events
        // recorded are the 8 moves of the rest, each one past the lock. The count is
        // the number of moves made after the slop, derived from the steps: 8. The
        // assertion is on delivered events, not on the 10 moves made.
        await finger.move(0, 4, 2);
        await finger.move(0, PULL_PAST - 4, 8);
        await finger.up();

        await expect.poll(() => sync.posts).toBe(1);

        const cancelable = await touchMoveCancelable(page);

        expect(cancelable).toHaveLength(8);
        expect(cancelable.every((value) => value === true), `cancelable per move: ${cancelable}`).toBe(true);
    });

    test("a second pull while the sync is still running does not sync again", async ({ page }) => {
        const sync = await mockSync(page, { pending: 1 });

        await openInbox(page);

        const start = await rowsStart(page);
        const first = await Finger.down(page, start.x, start.y);

        await first.move(0, PULL_PAST);
        await first.up();

        // Presence companion: the first pull started the sync.
        await expect.poll(() => sync.posts).toBe(1);
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "syncing");

        const second = await Finger.down(page, start.x, start.y);

        await second.move(0, PULL_PAST);
        await second.up();
        await page.waitForTimeout(500);

        expect(sync.posts, "the running guard holds the second pull").toBe(1);
    });

    test("a touch drag on a desktop-width window does nothing, and the same drag syncs on a phone width", async ({ page }) => {
        const sync = await mockSync(page);

        await page.setViewportSize({ width: 1280, height: 800 });
        await openInbox(page);

        const start = await rowsStart(page);
        const finger = await Finger.down(page, start.x, start.y);

        await finger.move(0, PULL_PAST);
        await finger.up();
        await page.waitForTimeout(300);

        expect(sync.posts, "no pull at md and up").toBe(0);

        // Presence companion: the same touch, narrowed to a phone width, syncs.
        await page.setViewportSize({ width: 393, height: 727 });
        await openInbox(page);

        const phone = await rowsStart(page);
        const again = await Finger.down(page, phone.x, phone.y);

        await again.move(0, PULL_PAST);
        await again.up();

        await expect.poll(() => sync.posts).toBe(1);
    });

    test("a mouse drag does nothing on a desktop", async ({ page }) => {
        const sync = await mockSync(page);

        await page.setViewportSize({ width: 1280, height: 800 });
        await openInbox(page);

        const start = await rowsStart(page);

        // Presence companion: count the mouse moves the page receives, so "no sync"
        // means the drag was delivered and ignored, not never sent.
        await page.evaluate(() => {
            const win = window as unknown as { __mouseMoves: number };

            win.__mouseMoves = 0;
            window.addEventListener("mousemove", () => win.__mouseMoves++);
        });

        await page.mouse.move(start.x, start.y);
        await page.mouse.down();
        await page.mouse.move(start.x, start.y + PULL_PAST, { steps: 6 });
        await page.mouse.up();
        await page.waitForTimeout(300);

        expect(await page.evaluate(() => (window as unknown as { __mouseMoves: number }).__mouseMoves)).toBeGreaterThan(0);
        expect(sync.posts).toBe(0);
    });

    test("with reduced motion the indicator jumps back, and without it the indicator slides", async ({ page }) => {
        await mockSync(page);

        await page.emulateMedia({ reducedMotion: "no-preference" });
        await openInbox(page);

        const start = await rowsStart(page);
        const first = await Finger.down(page, start.x, start.y);

        await first.move(0, PULL_PAST);
        await first.up();

        // Presence companion: the slide takes time when motion is allowed.
        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "idle");
        const slide = await page.locator(INDICATOR).evaluate((el) => getComputedStyle(el).transitionDuration);

        expect(slide).not.toBe("0s");

        await page.emulateMedia({ reducedMotion: "reduce" });
        await openInbox(page);

        const again = await rowsStart(page);
        const second = await Finger.down(page, again.x, again.y);

        await second.move(0, PULL_PAST);
        await second.up();

        await expect(page.locator(INDICATOR)).toHaveAttribute("data-state", "idle");
        expect(await page.locator(INDICATOR).evaluate((el) => getComputedStyle(el).transitionDuration)).toBe("0s");
    });
});
