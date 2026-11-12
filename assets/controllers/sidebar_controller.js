import { Controller } from '@hotwired/stimulus';

/**
 * Opens the navigation drawer on narrow screens.
 *
 * The controller lives on the body, so it can drive both the drawer and the
 * scrim. The drawer is closed by default on small viewports; the controller
 * only toggles the `data-open` attribute, and the CSS performs the animation.
 */
export default class extends Controller {
    static targets = ['sidebar', 'scrim'];
    static values = { open: Boolean };

    connect() {
        if (this.hasSidebarTarget) {
            this.close();
        }
    }

    toggle() {
        if (!this.hasSidebarTarget) {
            return;
        }

        if (this.openValue) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        if (!this.hasSidebarTarget) {
            return;
        }

        this.openValue = true;
        this.sidebarTarget.dataset.open = 'true';
        this.scrimTarget.dataset.open = 'true';
        document.body.style.overflow = 'hidden';
    }

    close() {
        if (!this.hasSidebarTarget) {
            return;
        }

        this.openValue = false;
        this.sidebarTarget.dataset.open = 'false';
        this.scrimTarget.dataset.open = 'false';
        document.body.style.overflow = '';
    }

    disconnect() {
        document.body.style.overflow = '';
    }
}
