import { Controller } from '@hotwired/stimulus';

/**
 * Shows the document-verification card only when the reduced tariff is
 * selected. The card carries no security meaning — the authorization is
 * checked server-side whoever is ticking it.
 */
export default class extends Controller {
    static targets = ['eligibility'];

    connect() {
        this.toggle();
    }

    toggle() {
        const selected = this.element.querySelector('input[name="tariff"]:checked');
        const isReduced = selected && selected.value === 'REDUCED';

        this.eligibilityTarget.hidden = !isReduced;

        if (!isReduced) {
            const input = this.eligibilityTarget.querySelector('input');
            if (input) {
                input.checked = false;
            }
        }
    }
}
