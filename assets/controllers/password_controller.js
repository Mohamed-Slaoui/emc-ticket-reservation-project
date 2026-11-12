import { Controller } from '@hotwired/stimulus';

/**
 * Toggles the visibility of a password field.
 */
export default class extends Controller {
    static targets = ['input', 'toggle'];
    static values = { visible: Boolean };

    toggle() {
        const visible = !this.visibleValue;

        this.visibleValue = visible;
        this.inputTarget.type = visible ? 'text' : 'password';
        this.toggleTarget.setAttribute('aria-label', visible ? 'Hide the password' : 'Show the password');
        this.toggleTarget.setAttribute('title', visible ? 'Hide the password' : 'Show the password');
    }
}
