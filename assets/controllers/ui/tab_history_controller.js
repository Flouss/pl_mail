import { Controller } from "@hotwired/stimulus";

/**
 * Keeps a category tab's navigation out of the browser's Back history on a phone.
 *
 * Each tab is a frame navigation, and Turbo pushes a history entry for a frame
 * navigation unless the link says otherwise, so on a phone every tab the user
 * merely looked at became one more Back step. Below md the tab asks for
 * `replace`; at md and up it asks for `advance`, which is what the frame already
 * does, so desktop behaviour is unchanged.
 *
 * The choice is made on click, not on connect. The tab strip is morphed on every
 * list refresh, and a morph removes any attribute the server did not render, so
 * an attribute set once on connect would be gone by the next refresh.
 *
 * It must run before Turbo's own click handling. Turbo reads the link's
 * `data-turbo-action` as the click bubbles to the window; this controller sits
 * on the strip, one level below the window, so its handler runs first.
 *
 * The breakpoint is `(width < 48rem)`, the query mobile_search_controller.js uses
 * for the same question. It is asked on every click rather than cached, so a
 * window resized between two taps is judged by the second.
 *
 * Targets: none. Routes: none.
 * Actions: choose, bound to the strip's click.
 */
export default class extends Controller {
    static MOBILE_QUERY = "(width < 48rem)";

    choose(event) {
        const link = event.target.closest("a");

        if (null === link || false === this.element.contains(link)) {
            return;
        }

        link.dataset.turboAction = window.matchMedia(this.constructor.MOBILE_QUERY).matches ? "replace" : "advance";
    }
}
