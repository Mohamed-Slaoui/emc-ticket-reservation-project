import { Controller } from '@hotwired/stimulus';

/**
 * The live summary of the seat selection on the performance page — how many
 * seats and for how much. The server still decides; this is feedback only.
 * When the visitor is not signed in (data-booking-locked="true"), the hold
 * button stays disabled.
 */
export default class extends Controller {
    static targets = ['count', 'total', 'submit'];

    connect() {
        this.update();
    }

    update() {
        const locked = this.element.dataset.bookingLocked === 'true';
        const seats = [...this.element.querySelectorAll('.seat__input:checked:not(:disabled)')];
        const total = seats.reduce((sum, seat) => sum + Number(seat.dataset.price || 0), 0);

        if (this.hasCountTarget) {
            this.countTarget.textContent = String(seats.length);
        }

        if (this.hasTotalTarget) {
            this.totalTarget.textContent = total.toFixed(2) + ' MAD';
        }

        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = locked || seats.length === 0;
        }
    }
}
